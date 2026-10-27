//! The desktop till. A window on the till served by the shop's own server, plus what a browser cannot
//! do: print receipts silently on an ESC/POS printer (installed Windows printer or network printer on port 9100),
//! open the cash drawer through the printer, and read a weighing scale on a serial (COM) port.
//!
//! The server address is chosen once on the set-up page and kept in the app's config folder. The window only ever
//! opens that server (and the local set-up page); any other address is refused, so the hardware commands are only
//! reachable from the shop's own till.

mod printer;
mod scale;

use std::fs;
use std::path::PathBuf;
use std::sync::Mutex;

use serde::{Deserialize, Serialize};
use tauri::webview::PageLoadEvent;
use tauri::{Manager, State, WebviewUrl};

#[derive(Default, Serialize, Deserialize, Clone)]
#[serde(default)]
struct Config {
    server: Option<String>,
    /// The server answered at least once from this till. Only then does the till open it without a check passing (to
    /// sell offline); a server that never answered (a mistyped address) sends the till back to the set-up page.
    verified: bool,
    printer: Option<String>,
    drawer: bool,
    scale_port: Option<String>,
    scale_baud: Option<u32>,
}

struct AppState {
    path: PathBuf,
    config: Mutex<Config>,
}

impl AppState {
    fn save(&self) -> Result<(), String> {
        let cfg = self.config.lock().map_err(|e| e.to_string())?;
        if let Some(dir) = self.path.parent() {
            fs::create_dir_all(dir).map_err(|e| e.to_string())?;
        }
        fs::write(&self.path, serde_json::to_vec_pretty(&*cfg).map_err(|e| e.to_string())?).map_err(|e| e.to_string())
    }
}

/// "https://192.168.1.10" → origin used to allow navigation and to open the till.
fn origin(server: &str) -> Result<url::Url, String> {
    let u = url::Url::parse(server.trim()).map_err(|_| "That is not a web address, e.g. https://192.168.1.10".to_string())?;
    if u.scheme() != "http" && u.scheme() != "https" {
        return Err("The address must start with http:// or https://".into());
    }
    Ok(u)
}

#[tauri::command]
fn get_config(state: State<AppState>) -> Result<Config, String> {
    Ok(state.config.lock().map_err(|e| e.to_string())?.clone())
}

/// Remember the server and open its till. `verified`: the set-up page reached the server just now.
#[tauri::command]
fn set_server(app: tauri::AppHandle, state: State<AppState>, server: String, verified: bool) -> Result<(), String> {
    let u = origin(&server)?;
    let base = format!("{}://{}", u.scheme(), u.host_str().unwrap_or("localhost")) + &u.port().map(|p| format!(":{p}")).unwrap_or_default();
    {
        let mut cfg = state.config.lock().map_err(|e| e.to_string())?;
        let same = cfg.server.as_deref() == Some(base.as_str());
        cfg.verified = verified || (same && cfg.verified);
        cfg.server = Some(base.clone());
    }
    state.save()?;
    let window = app.get_webview_window("main").ok_or("no window")?;
    window.navigate(url::Url::parse(&format!("{base}/pos/")).map_err(|e| e.to_string())?).map_err(|e| e.to_string())
}

/// Back to the set-up page (to change server or hardware).
#[tauri::command]
fn open_setup(app: tauri::AppHandle) -> Result<(), String> {
    let window = app.get_webview_window("main").ok_or("no window")?;
    window.navigate(setup_url("?setup=1")).map_err(|e| e.to_string())
}

#[tauri::command]
fn set_hardware(state: State<AppState>, printer: Option<String>, drawer: bool, scale_port: Option<String>, scale_baud: Option<u32>) -> Result<(), String> {
    {
        let mut cfg = state.config.lock().map_err(|e| e.to_string())?;
        cfg.printer = printer.filter(|p| !p.trim().is_empty());
        cfg.drawer = drawer;
        cfg.scale_port = scale_port.filter(|p| !p.trim().is_empty());
        cfg.scale_baud = scale_baud;
    }
    state.save()
}

/// Which computer this till is: its name, network card (MAC) and address on the shop network. Sent with the till's
/// requests so the audit log and the device list can tell tills apart. What the computer says about itself, not proof.
#[tauri::command]
fn client_info(app: tauri::AppHandle) -> serde_json::Value {
    machine(&app.package_info().version.to_string())
}

fn machine(version: &str) -> serde_json::Value {
    let computer = std::env::var("COMPUTERNAME").or_else(|_| std::env::var("HOSTNAME")).unwrap_or_default();
    let mac = mac_address::get_mac_address().ok().flatten().map(|m| m.to_string()).unwrap_or_default();
    let local_ip = local_ip_address::local_ip().map(|ip| ip.to_string()).unwrap_or_default();
    serde_json::json!({ "computer": computer, "mac": mac, "local_ip": local_ip, "app": format!("SES Labs Till {version}") })
}

/// Open the business server's back office in this window (it then also knows which computer this is).
#[tauri::command]
fn open_back_office(app: tauri::AppHandle, state: State<AppState>) -> Result<(), String> {
    let server = state.config.lock().map_err(|e| e.to_string())?.server.clone().ok_or("Set the server address first.")?;
    let window = app.get_webview_window("main").ok_or("no window")?;
    window.navigate(url::Url::parse(&format!("{server}/admin")).map_err(|e| e.to_string())?).map_err(|e| e.to_string())
}

#[tauri::command]
fn list_printers() -> Vec<String> {
    printer::list()
}

/// Raw ESC/POS bytes (base64) to the chosen printer, then kick the drawer if asked.
#[tauri::command]
fn print_raw(state: State<AppState>, data: String, open_drawer: Option<bool>) -> Result<(), String> {
    use base64::Engine;
    let cfg = state.config.lock().map_err(|e| e.to_string())?.clone();
    let target = cfg.printer.ok_or("No receipt printer chosen yet (Hardware set-up).")?;
    let mut bytes = base64::engine::general_purpose::STANDARD.decode(data).map_err(|e| e.to_string())?;
    if open_drawer.unwrap_or(false) && cfg.drawer {
        bytes.extend_from_slice(printer::DRAWER_KICK);
    }
    printer::send(&target, &bytes)
}

#[tauri::command]
fn open_drawer(state: State<AppState>) -> Result<(), String> {
    let cfg = state.config.lock().map_err(|e| e.to_string())?.clone();
    let target = cfg.printer.ok_or("No receipt printer chosen yet (Hardware set-up).")?;
    printer::send(&target, printer::DRAWER_KICK)
}

/// Unsent sales kept on the till, newest last: 48 files is 12 hours at one every 15 minutes.
const UNSENT_KEEP: usize = 48;

/// A copy of the till's unsent sales (the same signed file as "Export unsent sales"), saved by the till itself every
/// 15 minutes while any are waiting, in Documents\SES Labs Till\Unsent sales. If the PC or the browser data is lost
/// before the sales reach the server, the newest file is loaded in the back office. Returns the file's path.
#[tauri::command]
fn save_unsent(app: tauri::AppHandle, data: String) -> Result<String, String> {
    if data.len() > 64 * 1024 * 1024 || !data.starts_with('{') {
        return Err("Not a till export.".into());
    }
    let dir = app.path().document_dir().map_err(|e| e.to_string())?.join("SES Labs Till").join("Unsent sales");
    fs::create_dir_all(&dir).map_err(|e| e.to_string())?;
    let ms = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_err(|e| e.to_string())?.as_millis();
    let path = dir.join(format!("unsent-{ms}.json"));
    fs::write(&path, data).map_err(|e| e.to_string())?;
    let mut old: Vec<PathBuf> = fs::read_dir(&dir).map_err(|e| e.to_string())?
        .filter_map(|e| e.ok().map(|e| e.path()))
        .filter(|p| p.file_name().and_then(|n| n.to_str()).is_some_and(|n| n.starts_with("unsent-") && n.ends_with(".json")))
        .collect();
    old.sort(); // unsent-<milliseconds>.json: same length for centuries, so name order is time order
    if old.len() > UNSENT_KEEP {
        for p in &old[..old.len() - UNSENT_KEEP] {
            let _ = fs::remove_file(p);
        }
    }
    Ok(path.to_string_lossy().into_owned())
}

#[tauri::command]
fn list_scale_ports() -> Vec<String> {
    scale::ports()
}

/// The weight on the scale in kilograms (e.g. "1.235"), waiting up to 2 seconds for a stable reading.
#[tauri::command]
fn read_scale(state: State<AppState>) -> Result<String, String> {
    let cfg = state.config.lock().map_err(|e| e.to_string())?.clone();
    let port = cfg.scale_port.ok_or("No scale chosen yet (Hardware set-up).")?;
    scale::read(&port, cfg.scale_baud.unwrap_or(9600))
}

/// The local set-up page (the app's own files: tauri://localhost, or http://tauri.localhost on Windows).
fn setup_url(query: &str) -> url::Url {
    let base = if cfg!(windows) { "http://tauri.localhost/index.html" } else { "tauri://localhost/index.html" };
    url::Url::parse(&format!("{base}{query}")).expect("set-up page address")
}

fn is_local(u: &url::Url) -> bool {
    u.scheme() == "tauri" || u.host_str() == Some("tauri.localhost") || u.scheme() == "about"
}

pub fn run() {
    tauri::Builder::default()
        .setup(|app| {
            let path = app.path().app_config_dir()?.join("till.json");
            let config: Config = fs::read(&path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default();
            // Always start on the local set-up page: it opens the saved server when that answers (or, once it has
            // answered before, when the till must work offline), and otherwise shows what is wrong so it can be fixed.
            let start = WebviewUrl::App("index.html".into());
            app.manage(AppState { path, config: Mutex::new(config) });

            // The window may only show the local set-up page or the chosen server.
            let handle = app.handle().clone();
            // Every page gets a cookie saying which computer this is, so the back office opened here records it too
            // (the till also sends it in a header). The server reads it as what the computer reports, not as proof.
            let info = machine(&app.package_info().version.to_string()).to_string();
            let cookie = format!(
                "try{{if(location.protocol==='https:'||location.protocol==='http:'){{document.cookie='ses_client='+encodeURIComponent({})+';path=/;max-age=31536000;SameSite=Lax'+(location.protocol==='https:'?';Secure':'')}}}}catch(e){{}}",
                serde_json::to_string(&info).unwrap_or_else(|_| "\"\"".into())
            );
            tauri::WebviewWindowBuilder::new(app, "main", start)
                .initialization_script(&cookie)
                .title(app.package_info().name.clone()) // the product name (tauri.conf.json productName)
                .inner_size(1366.0, 800.0)
                .min_inner_size(1024.0, 640.0)
                .maximized(true)
                .visible(false) // shown once the first page is drawn (below), so it never opens as a white window
                .on_page_load(|window, payload| {
                    if payload.event() == PageLoadEvent::Finished {
                        let _ = window.show();
                    }
                })
                .on_navigation(move |u| {
                    if is_local(u) {
                        return true;
                    }
                    let server = handle.state::<AppState>().config.lock().ok().and_then(|c| c.server.clone());
                    server.map(|s| u.as_str().starts_with(&s)).unwrap_or(false)
                })
                .build()?;
            // Never left hidden: shown after a few seconds even if the page has not finished loading.
            let late = app.handle().clone();
            std::thread::spawn(move || {
                std::thread::sleep(std::time::Duration::from_secs(4));
                if let Some(w) = late.get_webview_window("main") {
                    let _ = w.show();
                }
            });
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            get_config, set_server, open_setup, open_back_office, set_hardware, client_info, list_printers, print_raw, open_drawer, save_unsent, list_scale_ports, read_scale
        ])
        .run(tauri::generate_context!())
        .expect("error while running the till");
}
