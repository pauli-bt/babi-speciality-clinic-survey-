# UI customization merge (Phase 7 → Phase 8)

This package uses Phase 8 as the functional source of truth and restores the UI customizations from the supplied customized Phase 7 archive.

## Preserved customizations

- Centered navigation and right-side user dropdown in `resources/views/layouts/app.php`.
- Custom administrator dashboard cards in `resources/views/dashboard/admin.php`.
- Analytics overview and filter layout in `resources/views/analytics/_head.php` and `resources/views/analytics/overview.php`.
- Custom global table styling in `public/assets/css/app.css`.
- `babi-logo.png` branding in the authentication and main application layouts.

## Phase 8 functionality retained

- Phase 8 route definitions and `ReportController` routes.
- Reports navigation entry, permission-gated by `exports.aggregate`.
- Report views, CSV writer, report builder, print view, and print CSS.
- Phase 8 tests and documentation; Phase 8 `AnalyticsRange.php` implementation and README remain authoritative.

## Security note

The user's local `.env`, runtime application log, and session files are intentionally not included in the merged ZIP. Keep the local `.env` file separately and configure it from `.env.example` when setting up a new environment.
