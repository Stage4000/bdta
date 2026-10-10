"""Synthetic HTTP regression: python tests/test_pet_files_http.py (PHP + SQLite).

Uses real controllers/auth/CSRF with disposable SQLite rows and local sessions.
No production configuration, browser, mail, or external service is used.
"""
import base64
import http.client
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=")
failures = []
checks = 0


def check(condition, label):
    global checks
    checks += 1
    if not condition:
        failures.append(label)
        print("FAIL: " + label)


with tempfile.TemporaryDirectory(prefix="bdta-pet-files-") as temporary:
    work = Path(temporary)
    db = sqlite3.connect(work / "fixtures.sqlite")
    db.executescript("""
        CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT);
        INSERT INTO settings VALUES ('timezone','UTC','text');
        CREATE TABLE clients (id INTEGER PRIMARY KEY, is_archived INTEGER);
        INSERT INTO clients VALUES (1,0),(2,0),(3,1);
        CREATE TABLE pets (id INTEGER PRIMARY KEY, name TEXT, client_id INTEGER);
        INSERT INTO pets VALUES (1,'Synthetic owner pet',1),(2,'Synthetic foreign pet',2);
        CREATE TABLE pet_files (id INTEGER PRIMARY KEY AUTOINCREMENT, pet_id INTEGER,
            file_type TEXT, file_name TEXT, original_name TEXT, file_size INTEGER,
            mime_type TEXT, description TEXT, uploaded_by INTEGER, uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE client_activity_log (id INTEGER PRIMARY KEY, client_id INTEGER, action TEXT, description TEXT, ip_address TEXT);
        CREATE TABLE admin_users (id INTEGER PRIMARY KEY, account_type TEXT, is_active INTEGER);
        INSERT INTO admin_users VALUES (1,'main',1),(2,'standard',1),(3,'accountant',1);
    """)
    db.commit()
    token = secrets.token_hex(32)
    sessions = {}
    for role, identity in {"owner":1, "foreign":2, "archived":3, "admin":1, "standard":2, "accountant":3}.items():
        sid = secrets.token_hex(16)
        sessions[role] = sid
        data = 'csrf_token|s:64:"' + token + '";'
        if role in ("owner", "foreign", "archived"):
            data += "portal_client_id|i:" + str(identity) + ";"
        else:
            account_type = {"admin":"main", "standard":"standard", "accountant":"accountant"}[role]
            data += 'user_type|s:5:"admin";admin_id|i:' + str(identity) + ';admin_account_type|s:' + str(len(account_type)) + ':"' + account_type + '";admin_account_type_refreshed_at|i:' + str(int(time.time())) + ';'
        (work / ("sess_" + sid)).write_text(data)

    prepend = work / "fixture.php"
    prepend.write_text("""<?php
require_once getenv('BDTA_TEST_ROOT') . '/backend/includes/database.php';
$conn = new SafePDO('sqlite:' . getenv('BDTA_TEST_WORK') . '/fixtures.sqlite');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$property = (new ReflectionClass(Database::class))->getProperty('sharedConnection');
$property->setAccessible(true);
$property->setValue(null, $conn);
session_save_path(getenv('BDTA_TEST_WORK'));
session_start();
""")
    env = os.environ.copy()
    env.update(BDTA_TEST_ROOT=str(ROOT), BDTA_TEST_WORK=str(work), PET_FILES_DIRECTORY=str(work / "private-pets"),
               DB_HOST="127.0.0.1", DB_PORT="1", DB_NAME="synthetic_only", DB_USER="synthetic_only", DB_PASSWORD="")
    with socket.socket() as reservation:
        reservation.bind(("127.0.0.1", 0))
        port = reservation.getsockname()[1]
    log = (work / "server.log").open("w")
    server = subprocess.Popen([os.environ.get("PHP_BINARY", "php"), "-d", "auto_prepend_file=" + str(prepend),
        "-d", "allow_url_fopen=0", "-d", "disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,exec,shell_exec,system,passthru,proc_open",
        "-S", "127.0.0.1:" + str(port), "-t", str(ROOT)], cwd=ROOT, env=env, stdout=log, stderr=log)

    def request(path, role=None, fields=None, upload=False, method=None):
        headers = {}
        if role:
            headers["Cookie"] = "PHPSESSID=" + sessions[role]
        body = None
        if fields is not None:
            method = method or "POST"
            if upload:
                boundary = "bdta" + secrets.token_hex(10)
                chunks = []
                for key, value in fields.items():
                    chunks.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n').encode())
                chunks += [(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="synthetic.png"\r\nContent-Type: image/png\r\n\r\n').encode(), PNG, (f'\r\n--{boundary}--\r\n').encode()]
                body = b"".join(chunks)
                headers["Content-Type"] = "multipart/form-data; boundary=" + boundary
            else:
                body = urllib.parse.urlencode(fields)
                headers["Content-Type"] = "application/x-www-form-urlencoded"
        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
        connection.request(method or "GET", path, body, headers)
        response = connection.getresponse()
        result = response.status, dict(response.getheaders()), response.read()
        connection.close()
        return result

    try:
        for attempt in range(100):
            try:
                request("/tests/README.md")
                break
            except (OSError, http.client.HTTPException):
                if server.poll() is not None:
                    raise RuntimeError("Synthetic PHP server did not start")
                time.sleep(0.05)

        for prefix, role in (("portal", "owner"), ("client", "standard")):
            upload_path = "/" + prefix + "/pet_files_upload.php"
            delete_path = "/" + prefix + "/pet_files_delete.php"
            view_path = "/" + prefix + "/pet_files_view.php"
            for csrf in ({}, {"csrf_token":"wrong"}, {"csrf_token[]":token}):
                before = db.execute("SELECT COUNT(*) FROM pet_files").fetchone()[0]
                status, _, body = request(upload_path, role, {"pet_id":1, **csrf}, upload=True)
                check(status == 403 and json.loads(body).get("success") is False, prefix + " rejects missing/invalid/array upload CSRF")
                check(db.execute("SELECT COUNT(*) FROM pet_files").fetchone()[0] == before, prefix + " denied upload leaves rows unchanged")
            status, _, _ = request(upload_path, role)
            check(status == 405, prefix + " upload requires POST")
            status, _, body = request(upload_path, role, {"pet_id":1, "csrf_token":token}, upload=True)
            check(status == 200 and json.loads(body).get("success") is True, prefix + " valid upload succeeds")
            file = json.loads(body)["file"]
            file_id = file["id"]
            filename = file["name"]
            private_path = work / "private-pets" / "1" / filename
            check(private_path.is_file() and private_path.read_bytes() == PNG, prefix + " upload stored outside document root")
            status, _, body = request("/backend/uploads/pets/1/" + filename)
            check(status == 404 and body != PNG, prefix + " anonymous static path cannot read bytes")
            for viewer in ("owner", "admin", "standard"):
                route = "/portal/pet_files_view.php" if viewer == "owner" else "/client/pet_files_view.php"
                for download, disposition in (("", "inline"), ("&download=1", "attachment")):
                    status, headers, body = request(route + "?id=" + str(file_id) + download, viewer)
                    check(status == 200 and body == PNG and headers.get("Content-Disposition", "").startswith(disposition), viewer + " authorized " + disposition)
            for viewer, route in ((None, view_path), ("foreign", "/portal/pet_files_view.php"), ("archived", "/portal/pet_files_view.php"), ("owner", "/client/pet_files_view.php"), ("accountant", "/client/pet_files_view.php")):
                status, _, body = request(route + "?id=" + str(file_id), viewer)
                check(status in (302, 403, 404) and body != PNG, prefix + " unauthorized " + str(viewer) + " download denied (status " + str(status) + ")")
            status, _, _ = request(delete_path, role)
            check(status == 405, prefix + " delete requires POST")
            for csrf in ({}, {"csrf_token":"wrong"}, {"csrf_token[]":token}):
                status, _, body = request(delete_path, role, {"file_id":file_id, **csrf})
                check(status == 403 and json.loads(body).get("success") is False, prefix + " rejects missing/invalid/array delete CSRF")
                check(db.execute("SELECT COUNT(*) FROM pet_files WHERE id=?", (file_id,)).fetchone()[0] == 1 and (private_path.is_file() or (ROOT / "backend/uploads/pets/1" / filename).is_file()), prefix + " denied delete preserves row and bytes")
            status, _, _ = request("/portal/pet_files_delete.php", "foreign", {"file_id":file_id,"csrf_token":token})
            check(status == 404, prefix + " foreign delete denied")
            status, _, _ = request(delete_path, role, {"file_id":file_id,"csrf_token":token})
            check(status == 200 and db.execute("SELECT COUNT(*) FROM pet_files WHERE id=?", (file_id,)).fetchone()[0] == 0 and not private_path.exists(), prefix + " authorized delete removes row and bytes")
        for pet_id in (2, 999999):
            status, _, _ = request("/portal/pet_files_upload.php", "owner", {"pet_id":pet_id,"csrf_token":token}, upload=True)
            check(status == 403, "foreign/nonexistent pet upload denied")
        for prefix in ("portal", "client"):
            status, _, _ = request("/" + prefix + "/pet_files_upload.php", None, {"pet_id":1,"csrf_token":token}, upload=True)
            check(status == 302, "anonymous " + prefix + " upload denied")
        for prefix, role in (("portal", "owner"), ("client", "standard")):
            db.execute("INSERT INTO pet_files (pet_id,file_name) VALUES (1, '../../outside.png')")
            file_id = db.execute("SELECT last_insert_rowid()").fetchone()[0]
            db.commit()
            status, _, _ = request("/" + prefix + "/pet_files_view.php?id=" + str(file_id), role)
            check(status == 404, prefix + " rejects invalid stored path")
            status, _, _ = request("/" + prefix + "/pet_files_delete.php", role, {"file_id":file_id,"csrf_token":token})
            check(status == 404 and db.execute("SELECT COUNT(*) FROM pet_files WHERE id=?", (file_id,)).fetchone()[0] == 1, prefix + " invalid delete path fails closed")
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
        # Delete only generated synthetic files if exercising the vulnerable baseline.
        for pet_id, name in db.execute("SELECT pet_id, file_name FROM pet_files"):
            if pet_id == 1 and name.startswith("pet_1_") and Path(name).name == name:
                legacy = ROOT / "backend/uploads/pets/1" / name
                if legacy.is_file():
                    legacy.unlink()
        db.close()
    print(f"Pet-file HTTP regression: {checks} checks, {len(failures)} failures")
    if failures:
        raise SystemExit(1)
