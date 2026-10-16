# Phase 3: Customer management

## Screens and routes
| Route | Who | Purpose |
|---|---|---|
| `GET /customers` | search permission (reception, call operator, social tracker, admin) | Search by ID, name or phone, paginated |
| `GET /customers/lookup?q=` | same | JSON for the reusable picker (max 8 rows, masked phone) |
| `GET/POST /customers/new`, `/customers` | create permission | Registration with duplicate warning |
| `GET /customers/{id}` | search permission | Detail; entry staff see only interactions of their own channel |
| `POST /customers/{id}/select`, `/customers/selection/clear` | search permission | Existing-customer selection (kept in the user's session) |
| `GET/POST /customers/{id}/edit`, `POST /customers/{id}/archive` | administrator (`customers.manage`) | Correct name/phone, archive/restore |
| `GET/POST /customers/{id}/source` | administrator (`customers.correct_source`) | The only way to change original acquisition |
Marketing holds none of these permissions: every route returns 403.

## Rules
- **Customer ID**: `BDC-nnnnnn` from an atomic counter table (safe under concurrent registrations).
- **Required**: name or phone (either, or both). Phone is normalised to digits (`+251 911 000 111` = `0911000111`) for matching; the country code is the `phone_country_code` setting.
- **Search box**: `BDC-123` = ID; 4+ digits (punctuation allowed) = phone, contains-match; otherwise name, every word must match anywhere in the name (any order). Minimum lengths prevent bulk listing. LIKE wildcards in input are escaped.
- **Results**: phone masked to the last 4 digits; shared numbers are flagged. Full phone only on the detail page.
- **Duplicates**: same normalised phone or same name (case-insensitive) among active customers. First submit shows the matches (no record created). Staff can pick one or confirm "different people"; the confirmation only covers the matches that were shown. Never blocks, never merges. Overrides are audited.
- **Double submit**: each form carries a one-time token stored with the customer (unique), so a repeated POST returns the existing record.
- **Channel**: set from the role (reception / phone / social DM). Administrators must choose one.
- **Original acquisition**: source, platform, "other" text, campaign, first channel and time. Write-once (database trigger). Only the administrator correction (reason of 10+ characters) can change it; it writes a correction row and an audit event.
- **Archive**: archived customers disappear from staff search, lookup, detail and selection.
- **Audit events**: `customer.create`, `customer.duplicate_override`, `customer.update` (field names only), `customer.archive`, `customer.unarchive`, `customer.source_correct`. No names or phone numbers are written to the audit log.

## Reusing the picker in Phase 4
Include `resources/views/components/customer_picker.php` with `$pickerMode = 'field'` and a field name; it fills a hidden `customer_id`. The server must still call `CustomerService::selectable($id)` before using it.

## Decisions to confirm
- Only administrators correct a customer's name/phone after registration. Staff typos must go to an administrator.
- Campaigns offered on the form are those not in "draft"; there are none until Phase 5 adds campaign management.
