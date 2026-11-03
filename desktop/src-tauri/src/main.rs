// No console window behind the back office on Windows release builds.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    zionex_backoffice_lib::run()
}
