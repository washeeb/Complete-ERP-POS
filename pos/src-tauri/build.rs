fn main() {
    // Tauri refuses pages served from elsewhere unless each of the app's commands is named (capabilities/till.json).
    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(tauri_build::AppManifest::new().commands(&[
        "get_config", "set_server", "open_setup", "open_back_office", "set_hardware", "set_settings", "client_info", "list_printers", "printers_info", "print_raw", "open_drawer", "show_window", "save_unsent", "list_scale_ports", "read_scale",
    ])))
    .expect("error while building");
}
