# Audit release preflight and rollback

Owner deadline: **October 12, 2026, 6:00 p.m. Pacific**
(`America/Los_Angeles`; October 13, 01:00 UTC). This plan applies to the reviewed
integration draft #585. All ten reviewed source heads are composed; the final
combined head requires successful CI and independent verification before cutover.
No production access, migration, relocation, reconciliation, merge or deployment
has been performed in preparing this plan.

## Release and operation gates

| Condition | What it blocks | Required evidence before reopening |
| --- | --- | --- |
| Legacy/mixed package schema, metadata-read error, incompatible checkout attempt-token index or failed bootstrap DDL | Application startup through `Database`; a source-only cutover is unsafe | Restore-rehearsed copy passes M1 preflight, explicit legacy conversion if needed, additive DDL and index checks; historical IDs/counts/balances/links retained |
| Pet files still public, missing private copies, unsafe root/alias, or PHP cannot access private storage | Pet-file upload/delete/view/download activation; rest of the application can be staged with these routes held closed | Inventory, byte verification, authorized-download/denial checks, public originals removed and actual server denial confirmed |
| Pre-P1 provider-success/local-save interruption without a durable operation identity | Refunds on affected invoices; if the cohort cannot be established, hold all invoice refund mutations | Separately authorized operator reconciliation of provider identity/outcome with local accounting; no speculative operation backfill or replacement refund |
| Pre-bridge unpaid checkout invoice already issued package credits but lacks fulfillment identities | Manual/online settlement and backfill of affected invoices; if the cohort cannot be safely isolated, hold invoice-payment/return processing | Review exact invoice-item/unit to purchase/credit mapping and existing ledger, then a reviewed correction; never infer identity solely from mutable notes or a client/package match |
| New unresolved P1 operation | Other refunds on its invoice; code already enforces this | Resume the original identity or reconcile its unique provider outcome; retain the operation until completion |
| Verified checkout receipt has excess money | Allocation/refund of that excess, not unrelated invoice processing or code startup | Owner accounting decision on the exact receipt; retain received/applied/excess amounts; no automatic refund |

The reviewed code has **no quarantine switch for pre-identity historical cases**.
Holding operations therefore requires an operator-enforced request boundary or
maintenance window, not merely hiding a button. Refund mutation is the
`refund_invoice` POST to `client/invoices_view.php`; manual settlement is
`client/invoices_payment.php`; hosted checkout creation is
`portal/invoice_checkout.php` in both guest/pay-token and portal modes; verified
online returns settle through
`portal/invoice_pay_return.php`. Hold checkout creation as well as settlement so
customers cannot initiate another payment while local settlement is restricted.
Protect direct requests and scheduled callers as
well as the UI. Do not use `stripe_enabled` as a substitute for cohort isolation,
clear durable records, acknowledge an unrecorded payment as successfully settled,
or replay a provider call. If safe route isolation is unavailable, keep the
affected module or application in maintenance until reconciliation is complete.
The rest of the release need not wait for unrelated historical cases once these
restrictions are demonstrably enforced.

## Minimal cutover sequence

1. Pin the final independently reviewed integration commit and successful workflow
   run. Confirm the target main baseline and the
   release artifact include the already released survey/reminder changes. Record
   the actual production PHP/extensions, database version/engines, served roots,
   aliases and PHP service identity; current validation covers PHP 8.2 hosted CI,
   PHP 8.5 local fixtures and MariaDB 11.4.13, not every older version.
2. Pause application/scheduled writes and establish the operation restrictions
   above. Take the backups below and verify restoration into an isolated,
   non-serving copy with external transports disabled. Run bootstrap/schema
   preflight on that copy before the live cutover. MySQL DDL commits implicitly;
   a transaction cannot undo the whole migration.
3. On the copy, verify all existing allocation/credit/ledger tables have
   `appointment_type_id` and no `session_type`. Review any legacy conversion
   explicitly as in `backend/MYSQL_MIGRATION.md`. Confirm the new InnoDB refund,
   checkout-receipt and fulfillment tables plus the nullable 64-character attempt
   token and full unique single-column index. Confirm tables participating in
   payment/purchase/booking transactions use transactional engines. Do not
   fabricate historical refund operations or fulfillment markers during bootstrap.
   B3/S6 adds no schema; its booking, credit/ledger, profile/form, queued workflow,
   notification and audit writes still depend on transactional table engines.
4. Relocate pet files as below while writes remain paused. Stage the pinned code
   and operator configuration, then perform authorized local smoke checks on the
   restored copy. Do not use customer/provider transactions as a deployment test.
5. An authorized operator may then perform the validated cutover, verify startup,
   private-file serving/denial and synthetic request boundaries, and reopen only
   the cleared modules/cohorts. Keep identified unresolved financial operations
   restricted. This document is preparation, not deployment authorization.

## Exact pet-file relocation

- Set `PET_FILES_DIRECTORY` to a PHP-readable/writable **absolute** directory
  outside every served document root and alias. Its default is
  `<application-parent>/bdta-private/pets`; that default is unsuitable if the parent
  or another alias is served. Check resolved ancestors/symlinks, not only the text
  path. Backups must also remain outside served storage and the repository.
- Inventory database `pet_files` records and existing
  `backend/uploads/pets/<pet_id>/<file_name>` files. Copy to
  `<PET_FILES_DIRECTORY>/<pet_id>/<file_name>` preserving the exact pet-ID folder,
  stored filename, bytes and existing database IDs/metadata. Do not rename or
  rewrite database records to simplify relocation. Stop and separately resolve
  missing files, invalid stored names, path escapes or conflicting copies.
- Compare counts, sizes and cryptographic hashes of source/private copies. Verify
  access under the PHP service identity and owner/admin inline/download behavior;
  verify anonymous, foreign-owner, archived and accountant denial using synthetic
  checks. Directory creation in the helper requests mode `0700`; Windows requires
  appropriate service-account ACLs. No permission changes are performed here.
- After verified private copies and backup exist, remove the public originals.
  Deny `/backend/uploads/pets/` on the actual server and all equivalent aliases.
  Apache `.htaccess` depends on enabled overrides; other servers and PHP's built-in
  server do not obtain protection from that file. Confirm legacy static URLs cannot
  serve bytes. Keep uploads/deletes paused until all these checks pass. Controllers
  have no public fallback, so deploying before relocation gives legacy downloads 404.

## Required backup scope

Preserve a consistent **complete database**, including schema/rows, indexes,
foreign keys, triggers and any views/routines/events. Include admin/client/pet
identities, bookings/pet/form links and logs, purchases/credits/signed transactions,
invoices/items/payments/refunds/installments, operation/receipt/fulfillment identities
and settings. Pause nontransactional writers; do not substitute a few new-table
exports for the full backup. Record row counts/balances/key links for comparison
and rehearse restoration on the isolated copy.

Back up public pet files before removal and existing private files, with their
relative paths, hashes and access metadata. Preserve the current application
artifact/commit, required assets, private `.env` configuration, PHP/runtime
configuration and relevant web-server roots/aliases/deny rules and scheduled-job
definitions. These operator backups may contain secrets/customer data: keep them
private and outside the Git repository/served roots. No such data is collected by
this task.

## Minimal rollback

Pause writes first and preserve a fresh post-cutover snapshot plus newly written
identities/files. Prefer a reviewed compatible code rollback while retaining the
additive schema. Keep refund operations, unique checkout tokens, receipts,
fulfillment markers and all financial/credit history; never clear them to unblock
old code. Keep pet files private and legacy URL denial in place. The pre-release
refund/checkout handlers and destructive package bootstrap are unsafe rollback
targets for reopened affected writes; hold those modules closed until compatible
code is ready.

A full pre-cutover database restore is possible only as an operator-reviewed
recovery with all subsequent application writes accounted for. Restoring an old
snapshot does not reverse an external provider outcome and may erase its local
identity; reconcile post-cutover outcomes and records before reopening. Do not
replay charges/refunds or move private files back into public storage. Code rollback
does not undo DDL or safely reverse an explicit legacy conversion.

B3/S6 preserves unmatched historical credit evidence without inventing a refund.
It does not reconcile prior partial booking/debit/refund mutations, serialize
existing admin credit writers, or supply durable recovery after a lost commit
acknowledgment. Preserve those records for separate operator review; do not clear
ledger rows or resend a booking merely because its response was interrupted.
These scope limits are distinct from the verified website transaction repairs.

## Booking acceptance boundary

The original B2 proof and acceptance target concerned duplicate **ordinary** slots,
off-grid/off-hours/past dates, inactive types and canonical GET/POST availability;
B4 adds configured venues, S5 rescheduling and the repair covers class/resource
capacity and managed/external Calendar conflicts. These checks pass on the reviewed
repair, including concurrent ordinary-slot protection.

An additional bounded comparison found an inherited asymmetry on main, PR #584
and the earlier integration: a group class was advertised and accepted over an
ordinary booking for the same trainer/time. This was not a composition regression.
The reviewed follow-up #587 now rejects other appointment types occupying that
trainer, using the existing buffered, half-open overlap predicate in both slot
validation and date advertising. Same-type class participant/resource capacity
remains unchanged. Its regression checks cover both request orders, different and
shared trainers, buffers, end-boundary adjacency and rejected reschedules with no
writes. This closes the concrete ordinary/class case; no broader scheduling audit
or speculative policy change is part of this preflight.
