# Phase 7 - Analytics dashboard

No database migration is required: every figure is computed from existing tables with SQL aggregation. Chart.js 4.4.7 (MIT) is vendored at `public/assets/vendor/chartjs/` (it was not part of the Phase 4-6 project); no CDN and no inline script is used, so the existing Content-Security-Policy is unchanged.

## Pages (all GET, all aggregate-only)
| URL | Content |
|---|---|
| `/analytics` (also `/marketing` for marketing users) | Summary cards, new-customer and interaction/booking trends, "what stands out", data-quality panel (admin only) |
| `/analytics/acquisition` | New customers by original source (doughnut + performance table), by acquisition channel, over time, original social platform |
| `/analytics/channels` | Interactions per channel and over time, booking outcomes, booking / service-use rates, appointments by originating channel |
| `/analytics/campaigns` | Per campaign: responses, distinct customers, new vs returning, original-attribution acquisitions, bookings and their outcomes |
| `/analytics/appointments` | Status of appointments booked in the period, bookings over time, rates, status movements, services, bookings by channel |

## Date filter
Presets: today, last 7 days, last 30 days (default), this month, custom range. Days are clinic-local calendar days from `Clock::now()` / `APP_TIMEZONE`; both ends are included. SQL uses `[start 00:00:00, day-after-end 00:00:00)`. Custom ranges are validated server-side (valid dates, start <= end, end not in the future, at most 1095 days); invalid input falls back to last 30 days with a visible message. "This month" is the 1st of the local month to today. The comparison period is the equally long period immediately before (for "this month" that is not the previous calendar month). Charts group by day (<= 62 days), week starting Monday (<= 400 days) or month. Stored DATETIMEs are never converted or modified.

## Definitions
- **Total customers** - all customers with status other than `merged`, as of now (not period based).
- **New customers** - `first_acquisition_at` in the period, status not `merged`, attributed to the stored *original* source (`original_source_id`, write-once; changes only via the audited administrator correction, and then the corrected value is reported). A later campaign response is never a new customer.
- **Interactions** - `interaction_at` in the period; also distinct customers. An interaction is an inquiry or visit, not an appointment.
- **Appointment bookings (cohort)** - appointments whose *booking date* is in the period; booking date = `interaction_at` of the origin interaction, or `created_at` if there is none. One row per appointment: status-history rows and extra interaction links are never counted.
- **Confirmed / Completed / Cancelled / No-show cards** - the *current status* of that cohort. Requested is never counted as confirmed; confirmed is never counted as completed. Completed means staff marked the appointment completed; it is not independent proof of service delivery.
- **Moved into status** - distinct appointments with a status event (excluding the creation event) in the period, regardless of booking date.
- **Closed** = completed + cancelled + no-show. **Completion / cancellation / no-show rate** = count / closed. Open = requested + confirmed.
- **Booking rate (telephone, social DM)** = interactions with outcome requested or confirmed / interactions with outcome not booked, requested or confirmed.
- **Service-use rate (reception only)** = used / (used + not used); "unknown" is shown separately. Reception has no booking rate by design.
- **Appointments by channel** - channel of the origin interaction (`none` when absent). Each appointment once.
- **Campaign response** - an interaction with that campaign selected. *New* = `is_first_contact = 1`; *returning* = otherwise (re-engagement). *Acquired* = customers whose `original_campaign_id` is that campaign. Interactions with no campaign are unknown, not "non-campaign".
- **Service popularity** - appointments booked per service, plus interactions that name the service (interest/used/booked as recorded).
- Every rate shows its numerator/denominator and displays `n/a` when the denominator is 0. Status cards are compared with the previous period only for counts (new customers, interactions, bookings); status cards are not compared because older bookings have had longer to close.

## Roles
`analytics.marketing` gates every route (server-side). Granted to Administrator and Marketing. Receptionist, call operator and social-media tracker have no analytics permission (403). The data-quality panel requires `analytics.full` (Administrator). No page selects names, phone numbers, customer codes or interaction notes. Viewing analytics does not write to the database or audit log.

## Limitations
- No campaign cost or revenue is stored: ROI, cost per acquisition and revenue cannot be calculated.
- Reception records service usage only; no reception booking-conversion exists. Reception interactions are not linked to appointments.
- "With >= 1 appointment" per source uses appointments to date, so recent customers have had less time to book.
- A campaign response is only counted when staff selected the campaign. Data-quality counts show how many campaign responses lack one.
- Appointments without an origin interaction fall back to `created_at` and appear under channel "none".
- Interaction dates can be backdated (Phase 4 rules); analytics follows the recorded `interaction_at`.
- Indexes: `idx_c_first_acq`, `idx_i_time_channel`, `idx_i_campaign` and `idx_ae_status_time` already match the period filters. The booking cohort uses `COALESCE(origin interaction date, created_at)`, which scans the appointments table (one row per booking); no new index was added. Not benchmarked on large data.

## Tests
`tests/phase7.php` (+ `tests/p7_child.php`) is part of `bash tests/run.sh`. Fixtures sit in March 2025 with hand-derived expected values and are removed afterwards; timezone checks run each configuration in its own process.
