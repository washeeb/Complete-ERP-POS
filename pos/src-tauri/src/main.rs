// No console window behind the till on Windows release builds.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    erp_till_lib::run()
}
