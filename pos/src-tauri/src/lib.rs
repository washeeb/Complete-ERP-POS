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
use tauri::{Manager, State, WebviewUrl};

#[derive(Default, Serialize, Deserialize, Clone)]
struct Config {
    server: Option<String>,
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

/// "http://192.168.1.10:8010" → origin used to allow navigation and to open the till.
fn origin(server: &str) -> Result<url::Url, String> {
    let u = url::Url::parse(server.trim()).map_err(|_| "That is not a web address, e.g. http://192.168.1.10:8010".to_string())?;
    if u.scheme() != "http" && u.scheme() != "https" {
        return Err("The address must start with http:// or https://".into());
    }
    Ok(u)
}

#[tauri::command]
fn get_config(state: State<AppState>) -> Result<Config, String> {
    Ok(state.config.lock().map_err(|e| e.to_string())?.clone())
}

/// Remember the server and open its till.
#[tauri::command]
fn set_server(app: tauri::AppHandle, state: State<AppState>, server: String) -> Result<(), String> {
    let u = origin(&server)?;
    let base = format!("{}://{}", u.scheme(), u.host_str().unwrap_or("localhost")) + &u.port().map(|p| format!(":{p}")).unwrap_or_default();
    state.config.lock().map_err(|e| e.to_string())?.server = Some(base.clone());
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
            let start = match &config.server {
                Some(server) => WebviewUrl::External(url::Url::parse(&format!("{server}/pos/"))?),
                None => WebviewUrl::App("index.html".into()),
            };
            app.manage(AppState { path, config: Mutex::new(config) });

            // The window may only show the local set-up page or the chosen server.
            let handle = app.handle().clone();
            tauri::WebviewWindowBuilder::new(app, "main", start)
                .title(app.package_info().name.clone()) // the product name (tauri.conf.json productName)
                .inner_size(1366.0, 800.0)
                .min_inner_size(1024.0, 640.0)
                .maximized(true)
                .on_navigation(move |u| {
                    if is_local(u) {
                        return true;
                    }
                    let server = handle.state::<AppState>().config.lock().ok().and_then(|c| c.server.clone());
                    server.map(|s| u.as_str().starts_with(&s)).unwrap_or(false)
                })
                .build()?;
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            get_config, set_server, open_setup, set_hardware, list_printers, print_raw, open_drawer, list_scale_ports, read_scale
        ])
        .run(tauri::generate_context!())
        .expect("error while running the till");
}
