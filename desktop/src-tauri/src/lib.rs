//! The ZionEX back office as a desktop app: a window on the back office served by the business's own server, with
//! what a browser tab does not give — its own icon and window, the server remembered, exports saved straight to
//! Downloads, links to other sites opened in the normal browser, and the computer's name sent with each page so the
//! audit log can tell computers apart (as the desktop till does).
//!
//! The server address is chosen once on the set-up page and kept in the app's config folder. The window only ever
//! opens that server (and the local set-up page); any other address opens in the computer's browser instead.

use std::fs;
use std::path::PathBuf;
use std::sync::Mutex;

use serde::{Deserialize, Serialize};
use tauri::webview::{DownloadEvent, PageLoadEvent};
use tauri::{Manager, State, WebviewUrl};

#[derive(Default, Serialize, Deserialize, Clone)]
#[serde(default)]
struct Config {
    server: Option<String>,
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

/// "https://erp.yourshop.com/admin" → "https://erp.yourshop.com" (scheme, host and port only).
fn base(server: &str) -> Result<String, String> {
    let u = url::Url::parse(server.trim()).map_err(|_| "That is not a web address, e.g. https://erp.yourshop.com".to_string())?;
    if u.scheme() != "http" && u.scheme() != "https" {
        return Err("The address must start with https:// (or http://)".into());
    }
    let host = u.host_str().ok_or("The address has no server name.")?;
    Ok(format!("{}://{}", u.scheme(), host) + &u.port().map(|p| format!(":{p}")).unwrap_or_default())
}

#[tauri::command]
fn get_config(state: State<AppState>) -> Result<Config, String> {
    Ok(state.config.lock().map_err(|e| e.to_string())?.clone())
}

/// Remember the server and open its back office.
#[tauri::command]
fn set_server(app: tauri::AppHandle, state: State<AppState>, server: String) -> Result<(), String> {
    let b = base(&server)?;
    state.config.lock().map_err(|e| e.to_string())?.server = Some(b.clone());
    state.save()?;
    let window = app.get_webview_window("main").ok_or("no window")?;
    window.navigate(url::Url::parse(&format!("{b}/admin")).map_err(|e| e.to_string())?).map_err(|e| e.to_string())
}

/// Back to the set-up page (to change the server).
#[tauri::command]
fn open_setup(app: tauri::AppHandle) -> Result<(), String> {
    let window = app.get_webview_window("main").ok_or("no window")?;
    window.navigate(setup_url("?setup=1")).map_err(|e| e.to_string())
}

/// The set-up page shows itself only when its form is needed; otherwise the window stays hidden until the back office is drawn.
#[tauri::command]
fn show_window(app: tauri::AppHandle) -> Result<(), String> {
    app.get_webview_window("main").ok_or("no window")?.show().map_err(|e| e.to_string())
}

/// Which computer this is: its name, network card (MAC) and local address — what the computer says about itself, not
/// proof. The server keeps it in the audit log next to each change.
fn machine(version: &str) -> serde_json::Value {
    let computer = std::env::var("COMPUTERNAME").or_else(|_| std::env::var("HOSTNAME")).unwrap_or_default();
    let mac = mac_address::get_mac_address().ok().flatten().map(|m| m.to_string()).unwrap_or_default();
    let local_ip = local_ip_address::local_ip().map(|ip| ip.to_string()).unwrap_or_default();
    serde_json::json!({ "computer": computer, "mac": mac, "local_ip": local_ip, "app": format!("ZionEX Back Office {version}") })
}

/// The local set-up page (the app's own files: tauri://localhost, or http://tauri.localhost on Windows).
fn setup_url(query: &str) -> url::Url {
    let base = if cfg!(windows) { "http://tauri.localhost/index.html" } else { "tauri://localhost/index.html" };
    url::Url::parse(&format!("{base}{query}")).expect("set-up page address")
}

fn is_local(u: &url::Url) -> bool {
    u.scheme() == "tauri" || u.host_str() == Some("tauri.localhost") || u.scheme() == "about" || u.scheme() == "blob" || u.scheme() == "data"
}

/// A file name for a download: from the address, made safe, never empty.
fn file_name(u: &url::Url) -> String {
    let raw = u.path_segments().and_then(|mut s| s.next_back().map(|x| x.to_string())).unwrap_or_default();
    let decoded = url::form_urlencoded::parse(format!("x={raw}").as_bytes()).next().map(|(_, v)| v.into_owned()).unwrap_or(raw);
    let safe: String = decoded.chars().map(|c| if r#"\/:*?"<>|"#.contains(c) || c.is_control() { '_' } else { c }).collect();
    let safe = safe.trim().trim_matches('.').to_string();
    if safe.is_empty() { "download".into() } else { safe }
}

/// "report.xlsx" → "report (2).xlsx" when the name is taken, so nothing in Downloads is overwritten.
fn free_path(dir: &std::path::Path, name: &str) -> PathBuf {
    let path = dir.join(name);
    if !path.exists() {
        return path;
    }
    let (stem, ext) = match name.rsplit_once('.') {
        Some((s, e)) if !s.is_empty() => (s.to_string(), format!(".{e}")),
        _ => (name.to_string(), String::new()),
    };
    (2..1000).map(|n| dir.join(format!("{stem} ({n}){ext}"))).find(|p| !p.exists()).unwrap_or(path)
}

pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .setup(|app| {
            let path = app.path().app_config_dir()?.join("backoffice.json");
            let config: Config = fs::read(&path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default();
            app.manage(AppState { path, config: Mutex::new(config) });
            let downloads = app.path().download_dir().unwrap_or_else(|_| std::env::temp_dir());

            // Every page gets a cookie saying which computer this is (the server reads it as what the computer reports).
            let info = machine(&app.package_info().version.to_string()).to_string();
            // Ctrl+Shift+S anywhere: back to the set-up page (to change the server address).
            let cookie = format!(
                "try{{if(location.protocol==='https:'||location.protocol==='http:'){{document.cookie='ses_client='+encodeURIComponent({})+';path=/;max-age=31536000;SameSite=Lax'+(location.protocol==='https:'?';Secure':'')}}}}catch(e){{}}\
                 document.addEventListener('keydown',function(e){{if(e.ctrlKey&&e.shiftKey&&(e.key==='S'||e.key==='s')){{e.preventDefault();try{{window.__TAURI__.core.invoke('open_setup')}}catch(x){{}}}}}});",
                serde_json::to_string(&info).unwrap_or_else(|_| "\"\"".into())
            );
            let handle = app.handle().clone();
            tauri::WebviewWindowBuilder::new(app, "main", WebviewUrl::App("index.html".into()))
                .initialization_script(&cookie)
                .title(app.package_info().name.clone())
                .inner_size(1366.0, 800.0)
                .min_inner_size(900.0, 600.0)
                .maximized(true)
                // Hidden until the back office is drawn: no white window, no set-up page flashing by.
                .visible(false)
                .on_page_load(|window, payload| {
                    if payload.event() == PageLoadEvent::Finished && !is_local(payload.url()) {
                        let _ = window.show();
                    }
                })
                // The window opens only the business's server; anything else (help pages, WhatsApp, maps, a supplier's
                // site) opens in the computer's normal browser.
                .on_navigation(move |u| {
                    if is_local(u) {
                        return true;
                    }
                    let server = handle.state::<AppState>().config.lock().ok().and_then(|c| c.server.clone());
                    if server.as_deref().is_some_and(|s| u.as_str() == s || u.as_str().starts_with(&format!("{s}/"))) {
                        return true;
                    }
                    if matches!(u.scheme(), "http" | "https" | "mailto" | "tel") {
                        let _ = tauri_plugin_opener::open_url(u.as_str(), None::<&str>);
                    }
                    false
                })
                // Exports and documents go straight into Downloads (never overwriting a file), then open.
                .on_download(move |_webview, event| {
                    match event {
                        DownloadEvent::Requested { url, destination } => {
                            let name = destination.file_name().and_then(|n| n.to_str()).filter(|n| !n.is_empty()).map(str::to_string)
                                .unwrap_or_else(|| file_name(&url));
                            *destination = free_path(&downloads, &name);
                        }
                        DownloadEvent::Finished { path: Some(path), success: true, .. } => {
                            let _ = tauri_plugin_opener::open_path(path, None::<&str>);
                        }
                        _ => {}
                    }
                    true
                })
                .build()?;
            // Never left hidden: shown after a few seconds even if the page has not finished loading.
            let late = app.handle().clone();
            std::thread::spawn(move || {
                std::thread::sleep(std::time::Duration::from_secs(10));
                if let Some(w) = late.get_webview_window("main") {
                    let _ = w.show();
                }
            });
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![get_config, set_server, open_setup, show_window])
        .run(tauri::generate_context!())
        .expect("error while running the back office");
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn keeps_only_the_server_part_of_the_address() {
        assert_eq!(base("https://erp.shop.com/admin/sales?x=1").unwrap(), "https://erp.shop.com");
        assert_eq!(base(" https://192.168.1.10:8443/ ").unwrap(), "https://192.168.1.10:8443");
        assert!(base("erp.shop.com").is_err());
        assert!(base("ftp://erp.shop.com").is_err());
    }

    #[test]
    fn makes_safe_download_names() {
        assert_eq!(file_name(&url::Url::parse("https://x.com/exports/Till%20sales%3A%20Nov.xlsx").unwrap()), "Till sales_ Nov.xlsx");
        assert_eq!(file_name(&url::Url::parse("https://x.com/").unwrap()), "download");
    }

    #[test]
    fn never_overwrites_a_download() {
        let dir = std::env::temp_dir().join(format!("zionex-dl-{}", std::process::id()));
        fs::create_dir_all(&dir).unwrap();
        fs::write(dir.join("report.xlsx"), b"x").unwrap();
        assert_eq!(free_path(&dir, "report.xlsx"), dir.join("report (2).xlsx"));
        assert_eq!(free_path(&dir, "new.pdf"), dir.join("new.pdf"));
        let _ = fs::remove_dir_all(&dir);
    }
}
