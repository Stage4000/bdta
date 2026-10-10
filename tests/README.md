# Tests

Legacy PHP integration and workflow verification scripts live here now instead of the repository root.

- `tests/*.php`: executable test and smoke scripts for the BDTA platform
- Run from the project root so relative environment/config assumptions remain predictable
- Most scripts bootstrap the app through `dirname(__DIR__)` to keep the test tree isolated from runtime code

Run public booking ownership regressions with `php tests/test_public_booking_identity.php`
(requires `pdo_sqlite`). The test uses the real API and existing portal login controller on a
temporary loopback PHP server with disposable synthetic data and outbound transports disabled.
It covers anonymous/foreign/archived/deleted/admin-only sessions, mapped email inputs, owner
profile/credit access, duplicate-email owners, new guests, and rollback/retry. It creates and
removes its own database and session files. The support router refuses ordinary web requests.
`php tests/test_registered_address_booking_regression.php` checks authenticated address updates
and new-guest address capture. No production configuration or external services are required.
