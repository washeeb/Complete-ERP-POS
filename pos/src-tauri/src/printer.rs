//! Receipt printers. A target is either the name of an installed Windows printer (the driver's "Generic / Text Only"
//! or the maker's ESC/POS driver), or a network printer written as `tcp://192.168.1.50:9100`. Bytes go to the printer
//! untouched ("RAW"), so ESC/POS commands (bold, cut, QR codes, drawer kick) work.

use std::io::Write;
use std::net::{TcpStream, ToSocketAddrs};
use std::time::Duration;

/// ESC p 0 25 250: pulse the cash-drawer pin through the printer.
pub const DRAWER_KICK: &[u8] = &[0x1B, 0x70, 0x00, 0x19, 0xFA];

pub fn send(target: &str, bytes: &[u8]) -> Result<(), String> {
    if let Some(addr) = target.strip_prefix("tcp://") {
        let sock = addr.to_socket_addrs().map_err(|e| format!("Printer address {addr}: {e}"))?.next().ok_or("Printer address not found")?;
        let mut s = TcpStream::connect_timeout(&sock, Duration::from_secs(4)).map_err(|e| format!("Printer {addr} not reachable: {e}"))?;
        s.set_write_timeout(Some(Duration::from_secs(10))).ok();
        return s.write_all(bytes).map_err(|e| e.to_string());
    }
    spooler::send(target, bytes)
}

pub fn list() -> Vec<String> {
    spooler::list()
}

#[cfg(windows)]
mod spooler {
    use windows::core::{PCWSTR, PWSTR};
    use windows::Win32::Foundation::HANDLE;
    use windows::Win32::Graphics::Printing::{
        ClosePrinter, EndDocPrinter, EndPagePrinter, EnumPrintersW, OpenPrinterW, StartDocPrinterW, StartPagePrinter, WritePrinter, DOC_INFO_1W,
        PRINTER_ENUM_CONNECTIONS, PRINTER_ENUM_LOCAL, PRINTER_INFO_4W,
    };

    fn wide(s: &str) -> Vec<u16> {
        s.encode_utf16().chain(std::iter::once(0)).collect()
    }

    pub fn send(name: &str, bytes: &[u8]) -> Result<(), String> {
        let pname = wide(name);
        let mut doc = wide("Receipt");
        let mut raw = wide("RAW");
        unsafe {
            let mut h = HANDLE::default();
            OpenPrinterW(PCWSTR(pname.as_ptr()), &mut h, None).map_err(|e| format!("Printer \"{name}\" not found: {e}"))?;
            let info = DOC_INFO_1W { pDocName: PWSTR(doc.as_mut_ptr()), pOutputFile: PWSTR::null(), pDatatype: PWSTR(raw.as_mut_ptr()) };
            let result = (|| -> Result<(), String> {
                if StartDocPrinterW(h, 1, &info as *const DOC_INFO_1W as *const _) == 0 {
                    return Err("The printer refused the job (StartDoc).".into());
                }
                StartPagePrinter(h).ok().map_err(|e| e.to_string())?;
                let mut written = 0u32;
                WritePrinter(h, bytes.as_ptr() as *const _, bytes.len() as u32, &mut written).ok().map_err(|e| e.to_string())?;
                EndPagePrinter(h).ok().map_err(|e| e.to_string())?;
                EndDocPrinter(h).ok().map_err(|e| e.to_string())?;
                if written as usize != bytes.len() {
                    return Err("The printer did not take the whole receipt.".into());
                }
                Ok(())
            })();
            let _ = ClosePrinter(h);
            result
        }
    }

    pub fn list() -> Vec<String> {
        unsafe {
            let flags = PRINTER_ENUM_LOCAL | PRINTER_ENUM_CONNECTIONS;
            let (mut needed, mut count) = (0u32, 0u32);
            let _ = EnumPrintersW(flags, PCWSTR::null(), 4, None, &mut needed, &mut count);
            if needed == 0 {
                return vec![];
            }
            let mut buf = vec![0u8; needed as usize];
            if EnumPrintersW(flags, PCWSTR::null(), 4, Some(&mut buf), &mut needed, &mut count).is_err() {
                return vec![];
            }
            let items = std::slice::from_raw_parts(buf.as_ptr() as *const PRINTER_INFO_4W, count as usize);
            items.iter().filter_map(|p| p.pPrinterName.to_string().ok()).collect()
        }
    }
}

#[cfg(not(windows))]
mod spooler {
    use std::io::Write;
    use std::process::{Command, Stdio};

    /// CUPS: `lp -d <printer> -o raw`.
    pub fn send(name: &str, bytes: &[u8]) -> Result<(), String> {
        let mut child = Command::new("lp").args(["-d", name, "-o", "raw"]).stdin(Stdio::piped()).spawn().map_err(|e| e.to_string())?;
        child.stdin.take().ok_or("lp")?.write_all(bytes).map_err(|e| e.to_string())?;
        let status = child.wait().map_err(|e| e.to_string())?;
        if status.success() { Ok(()) } else { Err(format!("lp failed ({status})")) }
    }

    pub fn list() -> Vec<String> {
        Command::new("lpstat").arg("-e").output().ok().map(|o| String::from_utf8_lossy(&o.stdout).lines().map(|l| l.trim().to_string()).filter(|l| !l.is_empty()).collect()).unwrap_or_default()
    }
}
