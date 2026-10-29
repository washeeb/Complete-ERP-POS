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
    /// Paper width in mm: 80 (48 characters a line, the default) or 58 (32).
    paper_mm: Option<u16>,
    /// Receipts printed per sale (1 by default; 2 when the shop keeps a copy).
    copies: Option<u8>,
    /// The company logo at the top of each receipt (on by default).
    print_logo: Option<bool>,
    /// Dosage labels on their own printer; none = the receipt printer.
    label_printer: Option<String>,
}

/// The till's own Settings screen: everything about this computer's hardware, in one go.
#[derive(Deserialize)]
struct Hardware {
    printer: Option<String>,
    drawer: bool,
    scale_port: Option<String>,
    scale_baud: Option<u32>,
    paper_mm: Option<u16>,
    copies: Option<u8>,
    print_logo: Option<bool>,
    label_printer: Option<String>,
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

/// Everything at once, from the till's Settings screen (a printer that only saves files is refused).
#[tauri::command]
fn set_settings(state: State<AppState>, hw: Hardware) -> Result<(), String> {
    for p in [&hw.printer, &hw.label_printer].into_iter().flatten() {
        printer::usable(p)?;
    }
    {
        let mut cfg = state.config.lock().map_err(|e| e.to_string())?;
        let some = |p: Option<String>| p.filter(|p| !p.trim().is_empty());
        cfg.printer = some(hw.printer);
        cfg.drawer = hw.drawer;
        cfg.scale_port = some(hw.scale_port);
        cfg.scale_baud = hw.scale_baud;
        cfg.paper_mm = hw.paper_mm.filter(|w| *w == 58 || *w == 80);
        cfg.copies = hw.copies.map(|c| c.clamp(1, 3));
        cfg.print_logo = hw.print_logo;
        cfg.label_printer = some(hw.label_printer);
    }
    state.save()
}

#[tauri::command]
fn set_hardware(state: State<AppState>, printer: Option<String>, drawer: bool, scale_port: Option<String>, scale_baud: Option<u32>) -> Result<(), String> {
    if let Some(p) = printer.as_deref().filter(|p| !p.trim().is_empty()) {
        printer::usable(p)?;
    }
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

/// The printers with what they look like: a receipt printer (to pick by itself) or one that only saves files.
#[tauri::command]
fn printers_info() -> Vec<serde_json::Value> {
    printer::list()
        .into_iter()
        .map(|name| serde_json::json!({ "name": name, "receipt": printer::looks_like_receipt(&name), "saves_files": printer::usable(&name).is_err() }))
        .collect()
}

/// Raw ESC/POS bytes (base64) to the printer for that kind of print (receipt, slip or label), as many copies as set
/// for receipts, then kick the drawer if asked. `printer`: a test print on a printer not saved yet (till Settings).
#[tauri::command]
fn print_raw(state: State<AppState>, data: String, open_drawer: Option<bool>, kind: Option<String>, printer: Option<String>) -> Result<(), String> {
    use base64::Engine;
    let cfg = state.config.lock().map_err(|e| e.to_string())?.clone();
    let target = match (printer.filter(|p| !p.trim().is_empty()), kind.as_deref()) {
        (Some(p), _) => p,
        (None, Some("label")) => cfg.label_printer.clone().or(cfg.printer.clone()).ok_or("No receipt printer chosen yet (till Settings).")?,
        (None, _) => cfg.printer.clone().ok_or("No receipt printer chosen yet (till Settings).")?,
    };
    printer::usable(&target)?;
    let one = base64::engine::general_purpose::STANDARD.decode(data).map_err(|e| e.to_string())?;
    let copies = if kind.as_deref().unwrap_or("receipt") == "receipt" { cfg.copies.unwrap_or(1).clamp(1, 3) } else { 1 };
    let mut bytes = Vec::with_capacity(one.len() * copies as usize + 8);
    for _ in 0..copies {
        bytes.extend_from_slice(&one);
    }
    if open_drawer.unwrap_or(false) && cfg.drawer {
        bytes.extend_from_slice(printer::DRAWER_KICK);
    }
    printer::send(&target, &bytes)
}

/// The set-up page shows itself only when the form is needed; otherwise the window stays hidden until the till is drawn.
#[tauri::command]
fn show_window(app: tauri::AppHandle) -> Result<(), String> {
    app.get_webview_window("main").ok_or("no window")?.show().map_err(|e| e.to_string())
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
                // Hidden until the till itself is drawn: the local set-up page (shown first, while it reaches the server)
                // stays out of sight unless its form is needed (it then calls show_window), so a till already set up
                // goes straight from nothing to the till, with no white window and no set-up page in between.
                .visible(false)
                .on_page_load(|window, payload| {
                    if payload.event() == PageLoadEvent::Finished && !is_local(payload.url()) {
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
                std::thread::sleep(std::time::Duration::from_secs(10)); // a slow server: show what there is
                if let Some(w) = late.get_webview_window("main") {
                    let _ = w.show();
                }
            });
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            get_config, set_server, open_setup, open_back_office, set_hardware, set_settings, client_info, list_printers, printers_info, print_raw, open_drawer, show_window, save_unsent, list_scale_ports, read_scale
        ])
        .run(tauri::generate_context!())
        .expect("error while running the till");
}
