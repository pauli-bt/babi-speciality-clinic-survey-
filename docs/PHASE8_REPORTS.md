# Phase 8 - Reports and exports

No database migration is required. No new library is used (CSV is written by `App\Services\CsvWriter`; Chart.js is not involved). Reports are generated on demand from the Phase 7 `Analytics` service, so every figure uses the Phase 7 definitions (see `docs/PHASE7_ANALYTICS.md`); no SQL is duplicated.

## Pages
| URL | What |
|---|---|
| `/reports` | Choose a period (same presets and custom range as analytics) and open any report |
| `/reports/{type}` | On-screen report with period, timezone, generated time and notes |
| `/reports/{type}/csv` | CSV download |
| `/reports/{type}/print` | Print-friendly page (logo, title, period, generated date, definitions) |

## Report types
`summary`, `acquisition`, `channels`, `campaigns`, `appointments`, `services`, `status_movements`. Each type is registered as its own route (anything else is 404).

| Type | Contents |
|---|---|
| summary | New customers, interactions, distinct customers, bookings with previous-period comparison (counts only), booking cohort by current status, open/closed, completion / cancellation / no-show rates |
| acquisition | New customers by original source (share, with >=1 appointment, with >=1 completed appointment), by acquisition channel, by social platform, over time |
| channels | Per channel: interactions, distinct customers, booking outcomes and booking rate (phone, DM), service-use outcomes and rate (reception); appointments by originating channel; interactions over time by channel |
| campaigns | Per campaign: responses, distinct customers, responses from new vs returning customers, customers acquired by original attribution, booking outcome counts and rate, appointments by outcome |
| appointments | Appointments booked in the period by current status (+ share), outcome rates, bookings over time |
| services | Per service: interactions naming it, appointments booked, open/completed/cancelled/no-show, completion rate |
| status_movements | Distinct appointments that *moved into* each status during the period, shown next to the booking cohort for contrast |

## Export formats
- **CSV** - UTF-8 with BOM (Excel on Windows reads it correctly), CRLF line ends, RFC 4180 quoting (commas, quotes and line breaks are safe). Metadata rows first (report, reporting period, timezone, generated time, notes), then each table preceded by its title and a header row; multi-table reports are stacked, separated by a blank line. Numbers are written as numbers; "not applicable / zero denominator" is an empty cell. Filename: `babi-<type>_<from>_to_<to>.csv`. Headers: `text/csv; charset=utf-8`, `attachment`, `no-store`, `nosniff`.
- **Formula-injection protection** - every text cell that starts (after optional whitespace) with `=`, `+`, `-`, `@`, tab or CR gets a leading `'`, so a campaign or service named `=HYPERLINK(...)` is shown as text in Excel/Sheets. Numeric cells (including negatives) are not touched.
- **Print / PDF** - a print layout; save as PDF with the browser's print dialog. The server does **not** generate PDF files.
- Excel `.xlsx` and server-side PDF are not provided.

## Period rules
Same as Phase 7: clinic-local calendar days (`APP_TIMEZONE`), both ends inclusive, SQL `[start, day after end)`. On-screen views fall back to last 30 days with a warning when the input is invalid; **downloads and print views reject invalid input (HTTP 422)** instead of silently exporting another period. Custom ranges: valid dates, start <= end, end not in the future, at most 1095 days.

## Roles and privacy
- Permission `exports.aggregate` guards every report route (administrator and marketing). Receptionist, call operator and social-media tracker get 403 on all of them; hiding links is not the only control.
- Reports are aggregate only: no customer names, customer codes, phone numbers or interaction notes. There is **no** record-level / identifiable export (`exports.identifiable` is not used by any route).
- All queries are prepared statements; output is escaped; parameters are validated; the report type is whitelisted; filenames are built only from the whitelist and validated dates; internal errors are never shown.
- Report tables are bounded (daily series at most 1095 rows); nothing loads customer datasets into memory.
- Generating a report never writes to customer, interaction, appointment or history tables.

## Audit
Each CSV download writes `export.csv` and each print view `export.print` (entity `report`, id = report type, payload = report type, from, to, row count). No exported data, names or notes are logged. Viewing `/reports` or an on-screen report, and rejected (422) or forbidden (403) requests, write nothing.

## Metric reminders
Booking cohort = appointments created by an interaction dated in the period, each once, by current status. Status movements are a different question (when the status changed). Requested is not confirmed; confirmed is not completed; completed means staff marked it so. No cost or revenue is stored, so revenue, profit, advertising ROI and cost per acquisition are not reported.

## Tests
`tests/phase8.php` (+ `tests/p8_child.php`) is part of `bash tests/run.sh`: CSV unit cases, authorization for 24 routes x 3 entry roles, headers, report contents against hand-derived numbers, privacy scans, print view, date validation and boundaries, timezone invariance (UTC, Addis Ababa, New York, Auckland), audit rows and data-integrity snapshot.

## Limitations
- CSV only, plus browser print; no .xlsx, no server PDF, no scheduling or e-mail delivery.
- Multi-table CSVs are stacked and need manual splitting if used as a data source.
- Opening a CSV in Excel/Sheets and the print/PDF output in real browsers, and Windows/XAMPP, are not verified.
- Chart images are not included in exports.
