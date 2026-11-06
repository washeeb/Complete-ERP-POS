fn main() {
    // Tauri refuses pages served from elsewhere unless each of the app's commands is named (capabilities/office.json).
    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(tauri_build::AppManifest::new().commands(&[
        "get_config", "set_server", "open_setup", "show_window",
    ])))
    .expect("error while building");
}
