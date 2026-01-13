# Implementation Notes

## Recent Changes

### TinyMCE Self-Hosted Implementation
- Replaced CDN version requiring API key with self-hosted version
- Installed via npm in `/client/` directory
- Used in: contracts_create.php, contract_templates_edit.php, blog_edit.php
- Path: `node_modules/tinymce/tinymce.min.js` (relative to `/client/` directory)

**Note on Path**: All admin pages using TinyMCE are in `/client/` directory, so the relative path is appropriate and will work correctly when accessed through the web server.

### Form URIs
- All forms verified to use correct POST/GET methods
- Fixed hardcoded URLs to use `Settings::get('base_url')` for portability
- Affected files: api_bookings.php, quotes_view.php, contracts_view.php

### Database Path Fix
- Changed from relative path `bdta.db` to absolute path `__DIR__ . '/../bdta.db'`
- Prevents multiple database files being created when scripts run from different directories
- Database location: `/backend/bdta.db`

### Booking Functionality
- Verified booking page structure
- Tested API endpoints (availability check and booking creation)
- Confirmed database integration works correctly
- Calendar link generation tested

## Testing Notes

All changes tested using PHP CLI in the development environment:
- Database initialization and table creation
- Booking API availability endpoint
- Booking API creation endpoint
- Database persistence across different working directories

Email functionality depends on proper SMTP/mail service configuration in settings.
