## New back office, part 32: Sensors (branch `office-sensors`, base `main`)
- **Server:** `App\Domain\Office\SensorsScreen` (sensors list, one sensor with reading, 24-hour chart data, alarms, checks and archive state; alarms; the temperature log through the current page's own queries; sensor clouds without their secrets) and `Office\SensorsController` (save, record a check, address, new address, archive / restore; acknowledge; cloud save and read now). Routes `office-api/sensors/…`.
- **Client:** `SensorParts` (AlarmTable, AcknowledgeAsk, DayChart), `SensorsPage`, `SensorPage`, `SensorAlarmsPage`, `TemperatureLogPage` (prints only the log), `SensorCloudsPage`. Paths `/admin/{sensors…, iot-alarms, temperature-log, iot-clouds}` → `/office/sensors{…, /alarms, /log, /clouds}`.
- **office-check:** opens the four from Stock, the first sensor with its reading now and Record a check (nothing signed). Its "old page inside" step now uses Sales → Online orders; the path test's "not rebuilt" example moved there too.
- **Tests:** `OfficeSensorsTest` (3).
- **Next:** Online (Online orders, Channels), then Distribution, Manufacturing, Fashion and Electronics.
