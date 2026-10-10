# Tests

Legacy PHP integration and workflow verification scripts live here now instead of the repository root.

- `tests/*.php`: executable test and smoke scripts for the BDTA platform
- Run from the project root so relative environment/config assumptions remain predictable
- Most scripts bootstrap the app through `dirname(__DIR__)` to keep the test tree isolated from runtime code

Run reminder login regressions with `php tests/test_portal_reminder_login.php` (requires `pdo_sqlite`).
The test starts a temporary loopback PHP server, uses synthetic in-memory clients/bookings and private
session files, and disables outbound application transports. It checks both reminder actions through
the real login controller, query preservation, login retries, authenticated access, unavailable bookings,
and rejection of unsafe return targets. The router is restricted to CLI-server test mode and loopback.
