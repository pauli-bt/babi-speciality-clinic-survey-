# Phase 4: The three channel questionnaires

## Routes
| Route | Who | Purpose |
|---|---|---|
| `GET/POST /reception/interactions[/new]` | receptionist only | Reception form (outcome: service used?) |
| `GET/POST /call-operator/interactions[/new]` | call operator only | Telephone form (outcome: appointment booked?) |
| `GET/POST /social-media/interactions[/new]` | social media tracker only | DM form (platform + appointment booked?) |
| `GET /interactions`, `GET /interactions/{id}` | entry roles (own channel) / administrator (all, channel filter) | List and detail. Other channels look like 404 to staff. Marketing: 403 |
| `GET/POST /interactions/{id}/edit` | recorder within 24 h / administrator any time | Corrections only |
| `GET /customers/{id}/appointments` | entry roles / administrator | JSON list for the follow-up selector (active customers only) |
The channel is **never a form field**: it comes from the role, is stored by the server, and is immutable (form route binding + database trigger).

## One pipeline (`InteractionService::create`)
1. Channel from role; one-time form token (unique in the database).
2. Customer: **existing** (id re-validated on the server with `CustomerService::selectable`: unknown, malformed, array or archived ids are rejected) or **new** (Phase 3 validation + duplicate warning).
3. Interaction validation (channel rules below).
4. One database transaction: new customer (+ID counter, audit) -> interaction -> appointment (+status history, link) -> audit. Any failure rolls back everything, including the ID counter.
5. A repeated token returns the original record; a token from another user is refused (403); concurrent identical submissions are settled by the unique keys.

## Channel rules
| | Reception | Telephone | Social DM |
|---|---|---|---|
| Outcome field | `used` = used / not_used (/ unknown on follow-ups) | `booking` = confirmed / requested / not_booked (/ not_applicable on follow-ups) | same as telephone |
| Creates appointment | never | confirmed or requested only | same |
| Rejects (422) | booking, appointment_at, dm_platform_id | used, dm_platform_id | used |
| Extra | | | DM platform required (kept separate from the customer's original source) |
| "Why not" reason | needed for not_used (optional on follow-ups) | needed for not_booked (optional on follow-ups) | same |
Existing customers must give a reason: follow-up, another service, or advertisement/campaign (`interaction_reason`). New customers are always `first_contact` with `is_first_contact = 1`. Campaign responses are re-engagement: they never create a customer or touch the original acquisition.
Campaigns are optional, must exist and not be drafts, and are never assigned automatically.

## Appointments
- Created only for a confirmed/requested booking, with the interaction as `origin_interaction_id` (unique: one creating interaction per appointment, one appointment per interaction), customer and service, status = the booking outcome (never `completed`), an `appointment_events` row (NULL -> status) and an `interaction_appointments` link `booked`.
- Follow-ups can link an existing appointment of the same customer (`followup`, or `fulfilled` at reception when the service was used). Linking never creates or completes anything.
- Time rules: confirmed needs a date/time after the interaction and within a year; requested may have none.

## Editing (24-hour rule)
Staff may edit only interactions they recorded, in their own channel, while `created_at` is within `entry_edit_window_hours` (24). Later attempts get 403 and are audited (`interaction.edit_denied`). Administrators may correct at any time; the audit event is flagged `admin_override`. Editable: notes, campaign, service (not once an appointment was booked from it), reception usage outcome (with reason), non-conversion reason, DM platform. Never editable: channel, customer, recorder, reason, first-contact flag, booking outcome.

## Audit events (no names, phones or note text)
`interaction.create`, `appointment.create`, `interaction.update` (changed field names + coded values), `interaction.edit_denied`, plus Phase 3's `customer.create` / `customer.duplicate_override` when a customer is created with the interaction.

## Migration
`004_phase4_interactions.sql` only adds the setting `interaction_max_backdate_days` (30). The Phase 1 tables already held every field. `Customer::insert` now accepts an acquisition time so a new customer's first acquisition equals the (possibly back-dated) first interaction.

## Business decisions to confirm
1. Interactions may be dated up to 30 days back and not in the future (setting). Administrators cannot create interactions (they review/correct).
2. Reception does not change appointment status (a linked appointment is not auto-completed); status management belongs to Phase 5.
3. Requested bookings may have no appointment time; confirmed ones must.
4. Follow-ups may answer "not applicable / not known" instead of a booking/usage outcome.
5. For DMs the conversation platform doubles as the advertising platform on a campaign response. Reception/phone ask for the advertisement source separately.
6. Service of interest is optional unless a booking or service use needs it.
7. Application and database time zones should match (`created_at` uses the database clock; interaction times use the application clock).
