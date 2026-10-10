# Reviewed audit integration candidate

This candidate starts from main `5281e8fd7911e3f3f024df4cd3f9a492daa4c31f`,
including the survey/reminder releases #575/#576. It combines these exact reviewed
heads without modifying their source branches:

| Repair | Source PR | Reviewed head |
| --- | --- | --- |
| M1 package-history preservation | [#577](https://github.com/Stage4000/bdta/pull/577) | `5bf785b1088530b60848fef8041f01a4f497690a` |
| B1 booking ownership | [#578](https://github.com/Stage4000/bdta/pull/578) | `d57ac3a3fc53dc254303ece845dea6c78628fb8f` |
| S1/S2 client administration | [#579](https://github.com/Stage4000/bdta/pull/579) | `0fff1555d9b2aa5a7988124fb31fe242b57b9615` |
| U1 private pet files | [#580](https://github.com/Stage4000/bdta/pull/580) | `dab654fc32d96a770433942613075c3bbacd5b3f` |
| P1 refund retries | [#581](https://github.com/Stage4000/bdta/pull/581) | `5a7c309f672ddda3b5734a249b093bde339f22d9` |
| C1 offline checkout retries | [#582](https://github.com/Stage4000/bdta/pull/582) | `dabe90ec7a351fba82f3dcddf430159d23bca5ae` |
| P2/P3 invoice settlement/fulfillment | [#583](https://github.com/Stage4000/bdta/pull/583) | `e781bbb337c6455a457b267bf51196e98c07eaee` |
| B2/B4/S5 booking/reschedule availability | [#584](https://github.com/Stage4000/bdta/pull/584) | `4f6dccb692e6e89bd03e0d5cfd571b1743a1accc` |
| B3/S6 website credit/cancellation atomicity | [#586](https://github.com/Stage4000/bdta/pull/586) | `9d97bb26606271473b8fdbd88cbeffbeda70f4f9` |
| B2/S5 class trainer overlap | [#587](https://github.com/Stage4000/bdta/pull/587) | `92a8a8d274d049277380a306f9affdd082b059cd` |

The only merge conflicts were in CI and test documentation. All survey, reminder,
booking-ownership, pet-file and invoice-payment regression groups and failure
gates are retained. `database.php` contains the four reviewed changes together.
The C1/P3 bridge also records the already-issued checkout credits' fulfillment
identity in the original purchase transaction; settling its unpaid invoice then
reuses that purchase. Existing identities are checked before validating today's
package definition. Other runtime files retain their reviewed contents.
Test-harness adjustments remove an unnecessary PHP 8.5-deprecated reflection call,
load shared PDO/MySQL-native-driver dependencies where Linux requires them, and accommodate the new fulfillment table
in the legacy/SQLite fixtures. CI adds a disposable MariaDB service for the four
MySQL repair suites, the cross-fix regression and invoice concurrency cases.
The availability head descends from B1, which is retained only once. B3/S6 then
extends its three website endpoints without changing the availability helper or
adding schema. Its booking transaction includes the checked debit, profile/form
and queued workflow changes; cancellation commits its current ownership/status
check, verified credit refund and audit records together. External Calendar/mail
calls follow commit. CI uses the same disposable service for separate random
scheduling and credit-atomicity schemas, retaining both failure gates.
The bounded class-overlap follow-up reuses the existing buffered trainer-conflict
predicate in class slot validation and date advertising. It preserves same-type
participant/resource capacity and the reviewed B3/S6 transaction boundaries.

## Runtime manifest

| Repair | Runtime files |
| --- | --- |
| M1 | `backend/includes/database.php` |
| B1 | `backend/public/api_bookings.php` |
| S1/S2 | `client/clients_edit.php`, `client/client_set_password.php` |
| U1 | `backend/includes/pet_files.php`; `client/` and `portal/` `pet_files_upload.php`, `pet_files_delete.php`, `pet_files_view.php`; `client/pets_edit.php`, `portal/pets.php`; `backend/uploads/pets/.htaccess` |
| P1 | `backend/includes/invoice_refund_operation.php`, `backend/includes/stripe_config.php`, `client/invoices_view.php` |
| C1 | `backend/includes/package_checkout.php`, `client/package_detail.php` |
| P2/P3 | `backend/includes/invoice_payment.php`, `client/invoices_payment.php`, `portal/invoice_pay_return.php` |
| B2/B4/S5 | `backend/includes/booking_availability.php`, `backend/includes/google_calendar.php`, `backend/public/api_bookings.php`, `portal/api_book_credit.php`, `portal/api_appointments.php` |
| B3/S6 | `backend/public/api_bookings.php`, `portal/api_book_credit.php`, `portal/api_appointments.php` |
| B2/S5 class overlap | `backend/includes/booking_availability.php`, `backend/public/api_bookings.php` (date advertising) |

Storage configuration/example and ignore rules accompany U1. No shared auth,
CSRF, survey or reminder runtime helper is changed by this integration.
Existing MySQL runtime and PHP PDO/extensions remain required. Refund serialization
uses MySQL/MariaDB `GET_LOCK` on the same connection through provider/local completion.
Booking and client reschedule checks/writes share a separate MySQL schedule lock;
the availability repair adds no database schema. Existing manual admin overrides
remain available. External Calendar updates are not protected by the website lock.
U1 requires a PHP-writable `PET_FILES_DIRECTORY` outside **every** served root/alias;
the default is `bdta-private/pets` beside the application directory.

## Migration and activation gates

1. Preserve a verified database and private-file backup; pause application and
   scheduled writes while validating schema conversion or relocating files.
2. M1 checks `package_items`, `client_package_credits` and
   `package_credit_transactions` before bootstrap DDL. Any `session_type` column
   or missing `appointment_type_id` blocks startup without deleting history.
   Legacy/mixed schemas need reviewed offline, data-preserving conversion as in
   [MYSQL_MIGRATION.md](../backend/MYSQL_MIGRATION.md). No automatic mapping or
   recovery of previously deleted history is provided.
3. On compatible schemas, normal bootstrap adds P1's InnoDB
   `invoice_refund_operations` table and C1's nullable
   `client_packages.checkout_attempt_token VARCHAR(64)` column with full unique
   `idx_client_packages_checkout_attempt`. Existing invoice/refund/purchase/credit
   rows remain intact. A missing C1 index is created; an invalid/nonunique/prefix
   index or DDL error blocks startup rather than weakening idempotency. Inspect
   and reconcile any prior partial migration before activation.
   P2/P3 also adds InnoDB `invoice_checkout_receipts` (unique provider intent and
   session; received/applied/excess cents) and `invoice_package_fulfillments`
   (unique invoice-item/unit mapped to its purchase). No existing data is backfilled.
4. **Private-file relocation is a deployment gate.** Move existing
   `backend/uploads/pets/<pet_id>/<file_name>` files into the private directory,
   preserve filenames/metadata, verify bytes and authorized downloads, and remove
   public originals. Deny the legacy URL on the actual web server; Apache's
   `.htaccess` requires enabled overrides and other servers need their own deny
   rule. Controllers have no public-storage fallback. See
   [PET_FILE_STORAGE.md](PET_FILE_STORAGE.md).
5. **Legacy refund reconciliation gates affected refund operations.** Review any provider
   success whose local record was interrupted before P1 supplied durable identity.
   Do not retry those old refunds blindly or invent operation rows from incomplete
   evidence. New unresolved P1 operations retain their identity; older ambiguous
   outcomes use read-only reconciliation and stay blocked without a unique match.
   See [INVOICE_REFUND_RETRY.md](INVOICE_REFUND_RETRY.md).
   Unrelated code can be staged while affected refunds are held closed. These old
   calls have no operation rows and are not automatically quarantined by P1; if
   the affected cohort cannot be isolated, hold all invoice refund mutations.
6. Reconcile historical package credits before backfill or settling pre-existing
   unpaid checkout-generated invoices whose credits were already issued without
   fulfillment markers. The bridge writes identities only for new purchase
   transactions; it does not guess old fulfillment from matching clients/packages
   or notes. Review excess receipts separately from invoice income/refunds as in
   [INVOICE_PAYMENT_SETTLEMENT.md](INVOICE_PAYMENT_SETTLEMENT.md); no automatic
   refund or excess allocation occurs.
   Hold settlement/backfill of affected historical invoices until reviewed; the
   rest of the application need not wait if the request boundary enforces that
   restriction. See [AUDIT_RELEASE_PREFLIGHT.md](AUDIT_RELEASE_PREFLIGHT.md) for
   the precise backup, relocation, cutover and rollback sequence.

No production schema conversion, file relocation, provider reconciliation, merge
into main or deployment was performed to prepare this candidate. Those gates require actual
environment validation before activation.

## Rollback

Pause writes and preserve `invoice_refund_operations`, checkout attempt tokens
and their unique index, checkout receipts and fulfillment markers, original
history, and private files. Do not drop these
records or replay provider calls to make older code appear consistent. Do not
restore public-file access or the destructive legacy-package bootstrap. A rollback
must retain those safeguards or keep affected writes disabled until a reviewed
compatible version is ready. MySQL DDL commits implicitly; restoring code does
not undo migrations or safely reverse an offline legacy conversion. Use the
verified backup and migration-specific reconciliation plan if data restoration
is needed.

## Verification

The focused tests are `test_legacy_package_schema_preservation.php`,
`test_public_booking_identity.php`, `test_registered_address_booking_regression.php`,
`test_portal_credit_booking_pet_overwrite_followups.php`,
`test_client_admin_mutation_guards.php`, `test_pet_file_paths.php`,
`test_pet_files_http.py`, `test_invoice_refund_retry.php`, and
`test_offline_package_checkout_retries.php`, plus `test_invoice_settlement.php`,
`test_invoice_payment_atomicity.php`, `test_invoice_payment_concurrency.php` and
`test_package_checkout_invoice_settlement.php`. The cross-fix regression covers
manual/online settlement, changed definitions, replay and marker-write rollback.
The availability regression scripts are `test_booking_availability.php`,
`test_booking_schedule_concurrency.php` and
`test_admin_reschedule_google_calendar_override.php`; concurrent scheduling uses
new random MySQL schemas and independent loopback HTTP workers.
`test_booking_credit_atomicity.php` exercises controller persistence failures,
retry, final-credit and cancellation races, credit evidence, transaction rollback
and post-commit fake-provider failures in its own disposable schema.
Survey/reminder regressions also run
against the combined candidate. Opt-in database tests must use disposable loopback
schemas, never application data; the individual test headers document variables.
Payment workers use local fakes with network transports disabled. CI also runs
the static analyzers, existing HTTP/SQLite groups and disposable MySQL suites,
including independent-process invoice races on both SQLite and MariaDB.

Local validation uses PHP 8.5.5 and MariaDB 11.4.13 with synthetic records/files.
Exact head, combined focused results, same-main aggregate comparison, independent
review and CI results are recorded in the integration PR. This does not establish
compatibility with older database versions or production-specific histories.
