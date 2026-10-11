# Invoice refund retries

Refund form submissions carry a random operation key. The server commits that
operation and its immutable invoice, amount, date, note and provider configuration
before contacting Stripe. A MySQL advisory lock serializes refunds per database
and invoice, including balance validation. Stripe receives the same
`Idempotency-Key` and request parameters on every retry of that operation.

After Stripe returns an accepted refund, its ID is saved before the local ledger
transaction. Ledger insertion, invoice status and operation completion commit
together. An interrupted local save resumes with the saved provider ID. Completed
submissions return their current summary, including after a full refund. A new
form after completion has a new key, allowing multiple equal partial refunds.
Reloading an unresolved form preserves its original details; other operations on
that invoice remain blocked until it is resolved.

Stripe may prune idempotency keys after at least 24 hours. Starting 23 hours after
the first attempt, a missing provider ID is recovered only by listing refunds for
the original PaymentIntent and matching `metadata.bdta_refund_operation`. Recovery
validates the amount, currency, PaymentIntent, accepted status and unique match
across pagination. No match, duplicates, provider failure, incomplete pagination,
changed provider configuration, or failed/canceled refunds require manual review;
the application does not send a replacement refund. Read-only recovery is bounded
to 100 pages. Pending/requires_action refunds retain the existing accepted-refund
ledger behavior; later provider lifecycle changes are outside this repair.

## Schema and rollout

`database.php` adds the InnoDB `invoice_refund_operations` table using the existing
bootstrap migration mechanism. It does not alter or backfill existing
`invoice_refunds` rows. Existing refund history continues to count against the
paid balance. This addition may overlap other changes to the bootstrap file.

Before deploying, review any previously interrupted provider refunds against the
local ledger. Those older calls have no durable operation key and cannot be
automatically identified by this repair. Preserve the new operation table during
rollback: returning to the old refund handler while operations remain unresolved
would reintroduce the duplicate-refund risk. Finish or explicitly reconcile open
operations before permitting refunds through older code. No deployment is part
of this change.

For an unresolved operation, inspect its stored PaymentIntent, operation key,
amount, original provider account and Stripe refund history. Retry its original
form to recover an exact match. Where automatic recovery cannot establish a
unique accepted refund, perform a separately reviewed ledger correction and
operation completion against the verified provider ID. Do not clear the
operation or issue a new refund merely because a lookup fails. Key rotation or
currency changes deliberately require review while the provider ID is unknown.

## Verification

Run `tests/test_invoice_refund_retry.php` with `BDTA_REFUND_TEST_DISPOSABLE=1`,
`DB_HOST=127.0.0.1`, an explicitly disposable `bdta_p1_*` or
`bdta_refund_test_*` schema, the normal MySQL test configuration and `pdo_mysql`
available and PHP on PATH. It refuses to bootstrap without that opt-in. Workers
use a fixed launcher, request data over stdin, a private ini with an empty extra
ini scan directory, no cURL extension, and disabled URL/socket/mail/command
functions. Cleanup deletes only fixed filenames in the private fixture directory.
The `.inc` fixtures implement an in-process provider fake only; they
reject all endpoints except refund creation and read-only refund listing.
The test uses synthetic `example.test` data, restores Stripe settings, removes
its own fixtures and never requires a provider account or credentials.

Coverage includes the provider-success/local-insert failure, response loss,
identity/completion persistence failures, concurrent same-key submissions,
concurrent balance validation, distinct partial refunds, full-refund replays,
immutable retry parameters, expired-key recovery, unresolved-operation blocking,
failed/missing reconciliation, provider failed status, accountant restrictions
and CSRF validation.

Provider references:

- [Idempotent requests](https://docs.stripe.com/api/idempotent_requests)
- [Create a refund](https://docs.stripe.com/api/refunds/create)
- [List refunds](https://docs.stripe.com/api/refunds/list)
