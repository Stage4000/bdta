# Email attachments (client profiles and unmatched inbox)

## Behavior and boundaries

- Client compose/reply, scheduled client email, unmatched compose and unmatched reply accept multiple uploaded files. The limit is 10 files, 10 MiB per file, 15 MiB combined; server/provider limits can be lower.
- Files are persisted before sending, including scheduled and failed messages. A missing or changed selected attachment fails the whole send; it is never silently omitted. The scheduled worker claims a message before transport to avoid concurrent duplicate sends. A crashed send can remain `pending`; do not automatically retry an uncertain delivery.
- The unmatched composer records exactly one explicit history row: a known recipient goes to client history, an unknown recipient stays in unmatched history. Failed/pending unmatched sends are labelled as such rather than “sent.”
- Future incoming mail captures named MIME parts, attachment-disposition parts and inline CID files, including nested multipart and RFC2231/encoded filenames. Message bodies exclude attached text files. The receiver uses PEEK until processing succeeds. Oversized, invalidly encoded or excess attachment parts are represented as unavailable metadata, with a reason, rather than silently disappearing.
- Assignment copies metadata pointing to immutable private files. Deleting the original unmatched message does not break its assigned client copy. Repeated assignment does not duplicate that copy.
- Download links require the existing admin authentication/account-role checks and an existing parent email. There is no portal/public attachment route. Files are always forced downloads (`application/octet-stream`, `nosniff`, private/no-store), not inline HTML/SVG/PDF previews. BDTA does not scan files for malware: only open files from trusted senders.
- **Email viewing is now plain-text-first in both screens.** HTML-only mail is converted to text in an inert template; incoming HTML never runs in the active page. Rich formatting/remote images are intentionally not displayed. Reply text is escaped before insertion into the rich-text composer.

## Historical mail is not automatically recovered

The old database stored only email bodies, not raw MIME, attachment bytes or mailbox UID/UIDVALIDITY. Existing message-ID deduplication still skips those messages. `attachments_processed=0` is retained for historical rows, and the UI instructs the user to check the original mailbox. Absence of stored metadata must not be reported as proof that an old message had no attachments.

A future recovery operation would require a separately reviewed mailbox fetch: mailbox identity + UIDVALIDITY/UID binding, exact message identity checks, explicit bounded selection/window, read-only PEEK, idempotent attachment-part mapping, and audit results for unavailable/deleted source messages. Do not delete old mail rows or weaken message deduplication as a workaround. No such operation is included here.

## Private storage and schema

Set `EMAIL_ATTACHMENTS_DIRECTORY` to an absolute directory outside **every** document root and alias, writable only by the PHP service account. The default is `bdta-private/email-attachments` beside the application root. It must not be served by a broader parent-root or alias. Paths inside the known application/server document roots, traversal components and file symlinks are rejected. Files use random 64-hex `.bin` names, mode 0600, size and SHA-256 integrity checks. Original filenames are display metadata only; they are never filesystem paths.

Database bootstrap adds the `email_attachments` table with an `(email_kind,email_id)` index and explicit utf8mb4 collation, `attachments_processed` markers on both email histories, and nullable `unmatched_emails.delivery_status`. No previous history/body rows are rewritten or removed. Message + attachment metadata persistence is transactional; newly staged files are removed on failure. Do not overwrite private storage during source deployment.

The CLI-only `backend/cleanup_email_attachments.php` defaults to a count-only dry run. `--apply` removes orphan metadata and unreferenced generated files only after a 24-hour grace period. Live shared references, pending/scheduled/failed message files and unknown/non-generated files are preserved. This is not a mailbox cleaner. Schedule it only as part of reviewed deployment operations; no scheduler is created by this change.

## Deployment gate (not yet accepted in this working patch)

1. Verify current production commit/manual changes against the pinned baseline. Back up source, DB and private storage, verify restoration, and preserve runtime config/uploads.
2. Exercise schema bootstrap on a disposable MySQL/MariaDB copy, including a legacy non-utf8mb4 default, Unicode filenames and repeat migration. MySQL DDL auto-commits; do not assume application rollback reverses a migration.
3. Provision the private directory with service-account ownership. Verify no direct HTTP route, alias, static server or backup URL exposes it, including nonexistent/missing parent cases.
4. Set `upload_max_filesize >= 10M`, `post_max_size >= 20M`, `max_file_uploads >= 10`, and appropriate request-body limits in the web server/proxy. The browser supplies an expected attachment count, and the server rejects any truncation/mismatch instead of sending fewer files. Validate provider message size against base64 expansion (~20 MiB for 15 MiB raw plus body/headers).
5. In isolated staging, run true HTTP multipart/session/CSRF/download-header tests, malicious HTML/filenames, cancellation/reopen/repeated click UI checks, missing/changed files, failure retention, scheduled send/claim, assignment/deletion and storage-failure rollback. Use synthetic `.invalid` messages and a local capture transport; do not use real client mail. Enable a real provider only for an explicitly authorized synthetic recipient test.
6. Run a mock IMAP server/extension acceptance test for MIME traversal, encoding and flags; these cloud tests inject the IMAP API and do not contact a mailbox. Apply staging acceptance before production rollout. On health/security/deployment failure restore the verified source/config/data backup; retain private attachment files until reconciliation so a rollback does not destroy newly captured files.

## Tests and limits

```sh
php tests/test_email_attachments.php
python tests/test_email_attachment_controllers.py
python tests/test_email_attachment_http.py
python tests/test_email_attachment_source_checks.py
```

The HTTP suite additionally uses a loopback-only PHP server with native multipart/session parsing, role/CSRF checks, real download headers, actual PHP upload-count/size truncation, and private-storage failure checks. It uses a local capture transport and no provider. These passed locally; actual staging web-server aliases/proxy limits still require acceptance.

The first test covers MIME/private-storage and synthetic SQLite ownership. The second runs real controllers/auth/CSRF/PHPMailer under CLI with generated SQLite/session fixtures, a local MIME-capture `mail()` stub, strictly scoped synthetic `is_uploaded_file()` stub and outbound transports disabled. It includes inbound processing through an injected IMAP API. It **does not** prove HTTP multipart parsing, HTTP headers, live IMAP/provider behavior or browser UI. Set `BDTA_TEST_PHP` and `BDTA_TEST_PHP_EXTENSIONS` (path-separator-delimited extension paths) for a non-default PHP executable. Session and pdo_sqlite are required for the controller suite; the injected IMAP test expects the real IMAP extension not to be loaded.

`node tests/test_email_attachment_ui.cjs` is a separate real-browser check requiring Playwright and Chromium. It intercepts/aborts all network requests and uses only synthetic content. It is not in CI by default. The Chromium Unix singleton socket was blocked in the implementation executor (loopback TCP/HTTP worked), so this browser test remains unrun past browser launch; do not treat static checks as browser acceptance.
