# MySQL Runtime Summary

## Overview

The application now runs on MySQL or MariaDB only. SQLite fallback behavior, SQLite test bootstrapping, and SQLite-specific admin tooling have been removed from the runtime.

## What Changed

- `backend/includes/database.php` now initializes MySQL only and rejects non-MySQL `DB_TYPE` values.
- Admin database utilities now target MySQL for connection testing, backup, and export.
- Legacy in-app SQLite auto-migration has been retired; older SQLite data must be imported into MySQL before startup.
- Test scripts were updated to stop relying on SQLite-specific setup and SQL syntax.

## Operational Notes

- Configure `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` before running the app.
- Schema creation and follow-up migrations still happen automatically on first MySQL startup.
- Legacy DDL normalization remains in place so older schema definitions continue to map cleanly into MySQL.

## Legacy Data

If you still have historical SQLite data, treat it as a one-time source for import. The supported runtime path is:

1. Create and configure the target MySQL database.
2. Export or transform the legacy SQLite data externally.
3. Import that data into MySQL.
4. Start the application against MySQL only.
