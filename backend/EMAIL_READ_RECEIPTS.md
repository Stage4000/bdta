# Optional client email read receipts

The original client composer offers a default-off **Request read receipt** checkbox.
It sends the standard `Disposition-Notification-To` header to the configured sender
address, and saves one generated outgoing Message-ID before either immediate or
scheduled transport. No tracking pixel, remote read endpoint or third-party
tracking service is added. Other automated emails and the inbox composer remain
unchanged. Existing emails are not enrolled by the additive migration.

The sender history distinguishes normal send/delivery state from receipt evidence:
- Not requested.
- Will be requested when sent (pending/scheduled).
- Requested; no receipt yet (unknown, never “unread”).
- Receipt reported displayed (unverified).
- Unavailable when sending failed or has an uncertain outcome.

Recipients can decline; mail clients may not support receipts. A displayed MDN is
not proof of human reading or delivery and is not authenticated evidence. Anyone
who knows a message's identity and recipient could forge a matching report. It
must not trigger billing, legal, delivery or workflow decisions. Other dispositions
and malformed/unsupported reports remain ordinary incoming correspondence.

The IMAP receiver recognizes only a bounded, top-level multipart/report with a
second message/disposition-notification part. It requires an exact Original-Message-ID,
matching Final-Recipient and one matching outer From, an opted-in actually-sent
outgoing record, and an unambiguous database match. It saves only the first local
observation timestamp and incoming Message-ID, independently of email status.
Incoming report contents and attachments are retained through the existing path.
A receipt arriving before send status is committed may remain uncorrelated; no
historical backfill or assumption of read state is made.

## Release acceptance

Synthetic tests do not establish production version, mailbox routing or recipient
support. Before release, back up the application and database, verify the exact
runtime manifest, apply/test the additive columns without suspending the site,
and verify the new composer/history visually, including Cancel, reply, repeated
open/close and scheduled flows. Confirm that the configured sender address receives
mail in the existing IMAP intake. Only use explicitly authorized synthetic recipients
for any real provider/recipient-client acceptance; no customer test sends.

Rollback may restore the prior runtime while retaining harmless additive columns;
never restore an old whole database over newer mail or other business records.

Checks: test_email_read_receipt_parser.php, test_email_read_receipts.php,
test_email_read_receipt_ui.cjs, expanded attachment controller/HTTP fixtures, and
actual additive/idempotent migration on CI's disposable loopback MariaDB service.

Protocol reference: https://www.rfc-editor.org/rfc/rfc8098.html
