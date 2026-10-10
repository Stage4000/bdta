# Tests

Legacy PHP integration and workflow verification scripts live here now instead of the repository root.

- `tests/*.php`: executable test and smoke scripts for the BDTA platform
- Run from the project root so relative environment/config assumptions remain predictable
- Most scripts bootstrap the app through `dirname(__DIR__)` to keep the test tree isolated from runtime code

Survey submission ownership regressions run with `php tests/test_public_form_submission_integrity.php`.
They execute the public controller in separate PHP processes using synthetic in-memory SQLite tables;
outbound mail and network transports are disabled. They cover request/template forgery, anonymous
invitations, staff/portal ID access, client linkage, completed analytics, stale requests, and rollback.
SQLite omits MySQL's `FOR UPDATE`; the conditional writes and transactions execute real SQL.

For optional MySQL checks, invoke one named case at a time with `BDTA_SURVEY_TEST_MYSQL=1`,
`DB_HOST=127.0.0.1`, and a freshly created database named `bdta_survey_controller_<suffix>`.
Set `DB_PORT`, `DB_USER`, and `DB_PASSWORD` for that disposable local instance and enable `pdo_mysql`.
Never point these fixtures at application data. The fixture creates its own tables; the caller owns
database creation/cleanup. Deterministic stale-state cases simulate another writer immediately before
the transaction; they do not claim simultaneous multi-process concurrency coverage.

Run reminder login regressions with `php tests/test_portal_reminder_login.php` (requires `pdo_sqlite`).
The test starts a temporary loopback PHP server, uses synthetic in-memory clients/bookings and private
session files, and disables outbound application transports. It checks both reminder actions through
the real login controller, query preservation, login retries, authenticated access, unavailable bookings,
and rejection of unsafe return targets. The router is restricted to CLI-server test mode and loopback.
