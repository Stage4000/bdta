# Private pet-file storage

Pet uploads are stored outside the application's document root. Both admin and
portal view/download controllers resolve files only after their existing login,
role and owner checks. Upload and delete requests require the current session's
CSRF token; the pet pages include it in both requests.

`PET_FILES_DIRECTORY` accepts an absolute directory outside the document root.
The default is `bdta-private/pets` beside the application directory. Set it
explicitly if the parent directory is served by the web server, if the server has
additional document roots/aliases, or if the default is not writable. The selected
directory must not be published through any web-server root or alias. Symlinks
into the application root and paths containing traversal components are rejected.

## Deployment prerequisite for existing files

Before activating this change, pause pet-file writes and relocate the existing
`backend/uploads/pets/<pet_id>/<file_name>` files to
`<PET_FILES_DIRECTORY>/<pet_id>/<file_name>`. Preserve the relative pet-ID folders
and filenames; no database changes are needed. Use a private backup and verify
the copied bytes and owner/admin downloads before removing the public originals.
Uploads must stay paused until originals are no longer publicly accessible and
the new directory is writable by PHP. Existing file IDs, links, names and metadata
continue to work after relocation. The controllers deliberately have no fallback
to public storage.

The legacy directory's `.htaccess` also denies direct access on Apache where
overrides are enabled. Other web servers must deny `/backend/uploads/pets/`
explicitly, including while files are being relocated. PHP's built-in server does
not enforce `.htaccess`; removing public originals is required on every server.
Keep the private directory and any backups outside the repository and document
root. A source-only deploy before relocation will make legacy downloads return
404, so storage relocation is a required deployment step.

## Focused regression

From the repository root, run:

```text
php tests/test_pet_file_paths.php
python tests/test_pet_files_http.py
```

The HTTP regression launches PHP on a disposable loopback port, with generated
PNG bytes, temporary SQLite fixtures and temporary synthetic sessions. It runs
the real controllers, auth helpers and CSRF validation. It checks successful
owner/admin upload, inline/download and delete, plus missing/invalid tokens,
anonymous/foreign/archived/accountant denial and absence of a public static copy.
No browser or external service is required.
