# DIFF SUMMARY

## Scope

Repository-wide security review of production-exposed attack surfaces, with emphasis on:

- public unauthenticated endpoints under `backend/public/`
- authenticated client portal flows under `portal/`
- admin authorization boundaries under `client/`
- reminder/email/notification link generation under `backend/includes/` and `backend/cron/`

## Findings

### 1. Public contracts were accessible and signable by raw numeric ID

- Severity: `critical`
- Affected surfaces:
  - `backend/public/contract.php`
  - `backend/cron/tasks/contract_reminder.php`
  - `backend/includes/notifications.php`
  - `portal/agreements.php`
- Impact:
  - anyone who could guess a contract ID could view contract contents
  - unsigned contracts could be signed without possessing the contract share token
- Remediation:
  - public contract access is now gated by `contracts.access_token`
  - portal owners may still access their own records while authenticated
  - reminder, portal, and notification links now emit tokenized URLs

### 2. Public quotes were accessible and mutable by raw numeric ID

- Severity: `critical`
- Affected surfaces:
  - `backend/public/quote.php`
  - `backend/includes/email_service.php`
  - `backend/includes/notifications.php`
  - `backend/cron/tasks/quote_reminder.php`
  - `backend/cron/tasks/workflow_processor.php`
  - `client/quotes_create.php`
  - `client/quotes_view.php`
- Impact:
  - anyone who could guess a quote ID could read quote contents
  - quote status could be changed to accepted or declined without authenticated ownership
- Remediation:
  - added `quotes.access_token` with unique indexing
  - public quote links now use tokens
  - unauthenticated raw-ID access is blocked; authenticated portal owners retain access to their own quotes

### 3. Pending public form submissions were accessible by raw numeric ID

- Severity: `high`
- Affected surfaces:
  - `backend/public/form.php`
  - `backend/includes/form_link_requests.php`
  - `backend/cron/tasks/form_reminder.php`
- Impact:
  - pending form links exposed prefilled client data and allowed unauthorized submission of pending forms
- Remediation:
  - added `form_submissions.access_token` with unique indexing
  - public form request URLs now use tokens
  - raw-ID access is limited to authenticated owners/admin usage paths

### 4. iCalendar downloads exposed booking details by raw numeric ID

- Severity: `high`
- Affected surfaces:
  - `backend/public/download_ical.php`
  - `backend/includes/email_service.php`
  - `backend/public/api_bookings.php`
  - `portal/api_book_credit.php`
  - `backend/cron/tasks/booking_reminder.php`
- Impact:
  - booking dates, service names, notes, and client-identifying metadata were downloadable without authorization
- Remediation:
  - added `bookings.ical_token` with unique indexing
  - public iCal links now use tokens
  - raw-ID access is limited to authenticated portal owners

### 5. Several admin routes bypassed the accountant authorization boundary

- Severity: `high`
- Affected surfaces:
  - `client/appointment_types_edit.php`
  - `client/appointment_types_list.php`
  - `client/packages_edit.php`
  - `client/packages_list.php`
  - `client/quotes_create.php`
  - `client/quotes_list.php`
  - `client/contract_templates_edit.php`
  - `client/client_packages_manage.php`
  - `client/contract_templates_get.php`
  - `client/time_tracker.php`
- Impact:
  - accountant-scoped admins could reach non-financial management pages and actions because those routes skipped `requireLogin()`
- Remediation:
  - normalized these routes onto the real admin guard path
  - JSON endpoints now return explicit `401`/`403` responses instead of silently bypassing authorization rules

### 6. Multiple destructive admin actions were reachable through GET requests

- Severity: `medium`
- Affected surfaces:
  - `client/appointment_types_delete.php`
  - `client/contract_templates_delete.php`
  - `client/form_templates_delete.php`
  - `client/workflows_delete.php`
  - package deletion in `client/packages_edit.php`
  - corresponding action controls in the related list pages
- Impact:
  - a logged-in admin could be tricked into destructive actions by following links or loading attacker-controlled pages
- Remediation:
  - converted destructive actions to POST-only handlers
  - added CSRF validation
  - updated UI actions to submit signed forms instead of GET links

## Implementation Notes

- Added a shared helper layer in `backend/includes/public_access_links.php` for:
  - token generation
  - token validation
  - lazy token provisioning for legacy rows
  - consistent public URL generation
- Existing outstanding raw-ID links intentionally stop working for unauthenticated users.
- New links are generated lazily for existing rows, so resending quotes/contracts/forms/booking confirmations will automatically issue secure URLs.

## Verification

Completed locally:

- `php -l` syntax checks across all changed PHP files
- `tests/test_public_contract_contact_info.php`
- `tests/test_accountant_admin_access.php`
- `tests/test_public_portal_return_links.php`
- `tests/test_admin_route_guards.php`