# Operations guide

For clinic administrators and staff. Technical installation is in `README.md`; deployment and security in `docs/DEPLOYMENT_CHECKLIST.md`.

## 1. Roles and what they can do
Each person has exactly one role, fixed by the administrator. The role (not a form field) decides the channel a staff member records.

| Permission area | Administrator | Receptionist | Call Operator | Social DM Tracker | Marketing |
|---|:--:|:--:|:--:|:--:|:--:|
| Register and search customers (name, masked phone, ID) | yes | yes | yes | yes | no |
| See full customer name/phone on the customer page | yes | yes | yes | yes | no |
| Edit/archive a customer, correct original source | yes | no | no | no | no |
| Record an interaction | no (reviews/corrects only) | reception only | telephone only | social DM only | no |
| See interactions | all channels | own channel, own recent records | same | same | no |
| Edit an interaction | any, any time | only own, in own channel, within 24 hours | same | same | no |
| Appointments: view, change status, reschedule | yes | yes | yes | yes | no |
| Services: create, edit, activate/deactivate | yes | no | no | no | no |
| Campaigns: create/edit | yes | no | no | no | view list only |
| Analytics dashboards | yes (plus data-quality panel) | no | no | no | yes (aggregate) |
| Reports and CSV/print exports | yes | no | no | no | yes (aggregate only) |
| Users and roles, audit log | yes | no | no | no | no |

Notes: entry roles can also see appointments of other channels (they can already search all customers). Four permissions exist in the database but no screen uses them yet: `exports.identifiable` (there is **no** export of personal records), `settings.manage`, `sources.manage`, `dataquality.review`.

## 2. The three interaction channels
| Channel | Who | What is recorded |
|---|---|---|
| Reception (in person) | Receptionist | Whether the customer **used a service** (used / not used, and why not). Reception never creates appointments |
| Telephone | Call Operator | Whether an **appointment was booked** (confirmed / requested / not booked, and why not) |
| Social media DM | DM Tracker | The platform, and the same booking outcome as telephone |

* All channels use the same customer and interaction records. The channel comes from the person's role, is saved by the server and cannot be changed afterwards.
* An interaction can be dated up to 30 days back and never in the future. Staff can correct their own record for **24 hours** after saving; after that only an administrator can, and every refused attempt is audited.
* Confirmed bookings need a date/time after the interaction and within a year; requested bookings may have none.

## 3. Original acquisition source vs returning customers
* **Original acquisition** is how the customer first came to the clinic (source, social platform, campaign, first channel, first date). It is saved once, at registration, and cannot be changed by staff. Only an administrator can correct it (reason of at least 10 characters), and the correction is audited.
* **Returning customers**: for an existing customer the interaction must state why they came: follow-up / existing appointment, another service, or responding to an advertisement/campaign.
* A **campaign response by an existing customer is re-engagement**, never a new customer. Analytics count new customers only by first-ever contact and original source.
* **Existing customers must be selected, not re-registered.** Registration warns about the same phone number or name; the warning never merges or blocks, staff decide.
* Customer ID format: `BDC-` plus six digits, assigned automatically.

## 4. Appointments
Statuses: **requested**, **confirmed**, **completed**, **cancelled**, **no-show**.

| From | Allowed next status |
|---|---|
| requested | confirmed (needs a date/time), cancelled |
| confirmed | completed, cancelled, no-show |
| completed / cancelled / no-show | none (final) |

* Completed and no-show need an appointment time that has already started (clinic time). Confirming needs a time not in the past and within a year.
* **Rescheduling** works for requested and confirmed appointments; it is audited but is not a status change.
* Every status change writes one history row and one audit row in a single transaction, so the history always matches the status. A booking is not a completed visit.
* Appointments are created only by the telephone and DM forms, never from the Appointments page and never by reception.
* Reception does not change a linked appointment automatically. Staff update the status on the Appointments page.

## 5. Analytics, date ranges and definitions
Date choices: Today, Last 7 days, Last 30 days (default), This month, Custom range. Days are whole clinic-local days (see section 8), both ends included. Custom ranges cannot end in the future or exceed 1,095 days; a wrong range falls back to Last 30 days with a message. Comparisons are with the equally long period before.

Key definitions (full list in `docs/PHASE7_ANALYTICS.md`):
* **New customers** = first-ever contact in the period, by original source. **Interactions** are inquiries/visits. **Appointment bookings** = appointments created by an interaction dated in the period, counted once each. The Confirmed/Completed/Cancelled/No-show cards are the **current status of those bookings**.
* Completion/cancellation/no-show rate = count ÷ closed appointments (completed + cancelled + no-show). Rates show `n/a` when there is nothing to divide.
* Booking rate (phone, DM) = interactions ending in a requested or confirmed appointment ÷ interactions with a recorded outcome. Reception has a service-use rate instead, never a booking rate.
* There is no cost or revenue data, so the system reports **no** revenue, profit, advertising ROI or cost per acquisition.

## 6. Reports and exports (privacy limits)
Reports (summary, acquisition, channels, campaigns, appointments, services, status movements) can be viewed, downloaded as **CSV**, or opened in a **print view** and saved as PDF with the browser. The server does not create PDF files.
* Reports contain only totals: **no** customer names, IDs, phone numbers or notes. Marketing and the administrator can export; nobody can export personal records.
* CSV files are UTF-8 (Excel-friendly). Text that could act as a spreadsheet formula (`=`, `+`, `-`, `@`) is neutralised with a leading `'`.
* Downloads and print views reject an invalid date range instead of exporting a different period. Each download is recorded in the audit log (report, period, row count only).
* CSV files are still business data: store and share them only with people entitled to them.

## 7. Deactivating staff, services and campaigns safely
Nothing is ever deleted; history stays intact.
* **Staff**: Users → *Edit* the person → *Deactivate account*. The person cannot sign in and any open session ends immediately; their past records and audit entries remain. Do this the day someone leaves. *Reactivate account* if they return. When a role changes, the person is signed out and must sign in again. Reset a forgotten password with *Issue password reset link* on the person's Edit page (a one-time link valid 60 minutes that you pass to them privately).
* **Services**: Services → *Deactivate*. The service disappears from new forms; old interactions and appointments keep it and reports still show it.
* **Campaigns**: there is no delete. Change the status: *Paused* (temporarily), *Ended* (finished). A campaign that already has responses cannot go back to *Draft*. Existing responses keep their campaign.
* **Customers**: *Archive customer* (on the customer page; *Restore customer* undoes it) hides a customer from staff search/selection; their history remains. Only administrators archive.

## 8. Time zone assumptions
* The clinic time zone is `APP_TIMEZONE` (`Africa/Addis_Ababa`, UTC+03:00, no daylight saving). Dates and times are stored in the database as clinic-local wall-clock time; the database connection and PHP both use that zone.
* **Do not change `APP_TIMEZONE` after you have real data**: existing times would be read in the new zone.
* `php bin/check-timezone.php` (read-only) checks the setting, the database zone, clock skew between the web server and database, and warns about records stamped in the future.
* Keep the server clock correct (automatic time sync).

## 9. Audit logs: what they are and how to review them
Administrators open **Audit log** from the menu. Entries record who did what and when (and the address), without names, phone numbers or note text.
Review at least weekly, and after any incident. Things to look for:
| Action | Why it matters |
|---|---|
| `auth.login_failed`, `auth.login_blocked` (many, or for one account/address) | Password guessing |
| `auth.access_denied` | Someone tried a page their role does not allow |
| `user.create`, user role/active/reset events | Unexpected accounts or privilege changes |
| `customer.source_correct` | Every change of original acquisition |
| `interaction.edit_denied`, `interaction.update` | Edits outside the 24-hour rule, corrections |
| `export.csv`, `export.print` | Who exported which report and period |
| `appointment.status_change`, `appointment.reschedule` | Appointment activity |
The audit log keeps growing; it is included in the database backup. There is no automatic deletion.
