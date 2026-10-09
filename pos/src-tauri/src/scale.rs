//! Weighing scales on a serial (COM) port. Most shop scales send the weight continuously or when asked (ENQ, or "W"),
//! as text such as "ST,GS,+  1.235kg" or "   1.235 kg". The first stable reading (or the first reading after 2 seconds)
//! is returned in kilograms with three decimals.

use std::io::{Read, Write};
use std::time::{Duration, Instant};

pub fn ports() -> Vec<String> {
    serialport::available_ports().map(|v| v.into_iter().map(|p| p.port_name).collect()).unwrap_or_default()
}

pub fn read(port: &str, baud: u32) -> Result<String, String> {
    let mut p = serialport::new(port, baud).timeout(Duration::from_millis(300)).open().map_err(|e| format!("Scale on {port}: {e}"))?;
    let _ = p.write_all(&[0x05]); // ENQ: scales that answer on request
    let _ = p.write_all(b"W\r\n");
    let started = Instant::now();
    let mut buf = Vec::new();
    let mut last: Option<String> = None;
    while started.elapsed() < Duration::from_secs(2) {
        let mut chunk = [0u8; 64];
        if let Ok(n) = p.read(&mut chunk) {
            buf.extend_from_slice(&chunk[..n]);
        }
        while let Some(pos) = buf.iter().position(|b| *b == b'\n' || *b == b'\r' || *b == 0x03) {
            let line: Vec<u8> = buf.drain(..=pos).collect();
            let text = String::from_utf8_lossy(&line).to_string();
            if let Some(kg) = parse(&text) {
                if text.contains("ST") || !text.contains("US") {
                    return Ok(kg);
                }
                last = Some(kg);
            }
        }
    }
    last.ok_or_else(|| "No weight from the scale. Check the cable, the COM port and the speed (baud).".into())
}

/// "ST,GS,+  1.235kg" → "1.235"; grams ("1235 g") are converted.
pub fn parse(text: &str) -> Option<String> {
    let lower = text.to_lowercase();
    let num: String = lower.chars().skip_while(|c| !(c.is_ascii_digit() || *c == '-' || *c == '.')).take_while(|c| c.is_ascii_digit() || *c == '.' || *c == '-').collect();
    let mut v: f64 = num.trim().parse().ok()?;
    if lower.contains('g') && !lower.contains("kg") && !lower.contains("lb") {
        v /= 1000.0;
    }
    Some(format!("{:.3}", v))
}

#[cfg(test)]
mod tests {
    use super::parse;

    #[test]
    fn reads_common_scale_formats() {
        assert_eq!(parse("ST,GS,+  1.235kg").as_deref(), Some("1.235"));
        assert_eq!(parse("   0.500 kg\r").as_deref(), Some("0.500"));
        assert_eq!(parse("1235 g").as_deref(), Some("1.235"));
        assert_eq!(parse("ST,GS,+  0.000kg").as_deref(), Some("0.000"));
        assert_eq!(parse("ERR").as_deref(), None);
    }
}
