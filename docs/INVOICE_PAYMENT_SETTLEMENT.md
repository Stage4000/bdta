# Invoice payment settlement

Verified invoice Checkout returns and manual payments serialize on the invoice row, recalculate the current balance, and commit the payment, invoice status, package instances, credits, purchase transactions and fulfillment identities in one transaction. A failure rolls the transition back; a verified return can be retried without charging again. Package fulfillment uses a unique invoice-item/unit identity. Receipts are sent after the transaction commits.

## Stale paid Checkout sessions

A provider may already have collected an old checkout amount before this application receives the return. For example, a $100 checkout returns after a separate $40 cash payment. The application records the full provider receipt in `invoice_checkout_receipts`: received10000, applied6000, excess4000 cents. Only $60 enters `invoice_payments`, so applied invoice income remains $100. An admin notification and a customer warning explicitly flag the $40 excess. A second distinct paid checkout on a closed invoice is recorded entirely as excess. Repeated returns conserve the original receipt and do not duplicate payments, credits or notices.

`invoice_checkout_receipts` is a reconciliation ledger. Its `excess_cents` is received money requiring review, not additional invoice income or automatically available package credits. This change sends no automatic refund and does not alter refund operations. An administrator must reconcile the provider payment identified by `payment_intent_id` with its applied amount and excess before deciding how to return or allocate the excess. Retain the original receipt; document that resolution in the operational accounting record. Do not use a normal invoice refund as an assumed refund of this separate excess.

The return handler cannot prevent a charge already accepted by the provider, expire a session already paid, or recover a paid session whose return never arrives. This repair prevents stale amounts from silently overcrediting the invoice and makes excess received funds explicit. Checkout creation continues to request the current remaining balance; provider/webhook recovery and broader installment design are separate work.

## Existing records and handoff

The bootstrap adds two InnoDB tables: `invoice_checkout_receipts` and `invoice_package_fulfillments`. It changes no existing data and performs no historical credit backfill. Old paid invoices may already have manually assigned credits with no invoice-item identity; reconcile those records before any manual backfill. Invoices whose package is unavailable or has no credit definition fail the new payment transition atomically instead of silently completing without credits; correct the package definition and retry the confirmed return.

These schema additions are separate from the refund-operation table in the refund retry repair. They do not change scheduling locks, booking schema, `stripe_config.php`, `client/invoices_view.php`, or public/package-detail checkout fulfillment. No production migration or payment was performed while preparing this change.

## Local verification

Run `php tests/test_invoice_settlement.php`, `php tests/test_invoice_payment_atomicity.php` and `php tests/test_invoice_payment_concurrency.php`. The controller driver replaces disabled cURL functions with strict local responses and disables mail and outbound transports in its children. It exercises the real return and manual-payment controllers with synthetic SQLite fixtures. Atomicity checks cover interrupted credit, purchase-audit and fulfillment-identity writes followed by retry. Concurrency runs use independent processes against a temporary SQLite database.

For optional MySQL concurrency checks, set `BDTA_INVOICE_TEST_MYSQL=1`, `DB_HOST=127.0.0.1`, and `DB_NAME=bdta_invoice_test_concurrency_<unique_suffix>` with an empty disposable schema and the matching local port/user/password. The test rejects other schema names and non-loopback hosts. It creates synthetic fixtures in that schema; never point it at an application/customer database. Database bootstrap compatibility is also covered by the repository's full local suite using separate disposable main and repair schemas.
