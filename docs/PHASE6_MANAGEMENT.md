# Phase 6 - Service, campaign and appointment management

Migration `005_phase6_management.sql` (additive; no schema or data change): adds the permission `campaigns.view` for the Administrator and Marketing roles. Everything else uses the Phase 1 tables (`services`, `campaigns`, `appointments`, `appointment_events`, `interaction_appointments`).

| Area | URL | Who | Notes |
|---|---|---|---|
| Services | `/admin/services` (+ `/new`, `/{id}/edit`, `/{id}/active`) | `services.manage` (admin) | Create, edit, activate/deactivate. Never deleted. Inactive services disappear from new questionnaires but old interactions/appointments keep them. |
| Campaigns | `/campaigns` | `campaigns.view` (admin, marketing) | Read-only for marketing: names, dates, status, response counts; no customer data. |
| Campaigns | `/campaigns/new`, `/campaigns/{id}/edit` | `campaigns.manage` (admin) | Platform (or offline), code, dates, status. A campaign with recorded responses cannot go back to draft. Never deleted. Never writes to `customers`. |
| Appointments | `/appointments`, `/appointments/{id}` | `appointments.manage` (admin, reception, call operator, social DM tracker) | Filters: status, service, booked-via channel, date range, customer code (name too when the role may view contact details). |

## Appointment rules
- Appointments are still created only by the phone / DM questionnaires. This module never creates them.
- Allowed status changes: requested -> confirmed | cancelled; confirmed -> completed | cancelled | no_show. Completed, cancelled and no-show are final.
- Confirming needs a date/time (not in the past, at most one year ahead). Completed / no-show need a scheduled time that has already started, judged in the application timezone (`Clock::now()`).
- Each change runs in one transaction: row lock, status update guarded by the old status, one `appointment_events` row, one audit row. Any failure rolls everything back.
- Rescheduling (requested/confirmed only) changes `appointment_at` and writes an `appointment.reschedule` audit entry. It deliberately does not write a status event, so history-based counts are never inflated. The detail page merges both into the history view.
- `origin_interaction_id` and `interaction_appointments` links are never modified.
- Lists and detail pages show no phone numbers or interaction notes; names appear only for roles holding `customers.view_contact`.

## Audit actions
`service.create|update|activate|deactivate`, `campaign.create|update|status_change`, `appointment.status_change|reschedule`. Payloads hold ids, names of services/campaigns, statuses and times only.

## Tests
`bash tests/run.sh` also runs `tests/phase6.php` (107 checks). `tests/run.php` now expects migration `005_phase6_management` in the legacy-upgrade check.
