<?php
/**
 * Brook's Dog Training Academy - Site Pages API
 * Handles CRUD operations for front-end site pages used by the visual editor.
 */

require_once '../backend/includes/config.php';
requireLogin();

header('Content-Type: application/json');

$db   = new Database();
$conn = $db->getConnection();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ------------------------------------------------------------------ LIST
    case 'list':
        $rows = $conn->query(
            "SELECT id, slug, title, is_homepage, is_published, sort_order, updated_at
             FROM site_pages ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'pages' => $rows]);
        break;

    // ------------------------------------------------------------------ GET
    case 'get':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); break; }
        $stmt = $conn->prepare("SELECT * FROM site_pages WHERE id = ?");
        $stmt->execute([$id]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) { echo json_encode(['success' => false, 'error' => 'Page not found']); break; }
        echo json_encode(['success' => true, 'page' => $page]);
        break;

    // ------------------------------------------------------------------ CREATE
    case 'create':
        $data = json_decode(scalar_string(file_get_contents('php://input')), true) ?? [];
        $title = trim($data['title'] ?? '');
        $slug  = trim($data['slug']  ?? '');

        if ($title === '') { echo json_encode(['success' => false, 'error' => 'Title is required']); break; }
        if ($slug  === '') { $slug = slugify($title); }
        $slug = slugify($slug);

        // Ensure uniqueness
        $check = $conn->prepare("SELECT COUNT(*) FROM site_pages WHERE slug = ?");
        $check->execute([$slug]);
        if ($check->fetchColumn() > 0) {
            $slug = $slug . '-' . time();
        }

        // Calculate sort_order before INSERT to avoid subquery race condition
        $max_order_stmt = $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM site_pages");
        $next_order = intval($max_order_stmt->fetchColumn());

        $stmt = $conn->prepare(
            "INSERT INTO site_pages (slug, title, html_content, css_content, is_homepage, is_published, sort_order, updated_by)
             VALUES (?, ?, '', '', 0, 0, ?, ?)"
        );
        $stmt->execute([$slug, $title, $next_order, $_SESSION['admin_id']]);
        $new_id = $conn->lastInsertId();
        echo json_encode(['success' => true, 'id' => $new_id, 'slug' => $slug]);
        break;

    // ------------------------------------------------------------------ SAVE (editor content)
    case 'save':
        $data = json_decode(scalar_string(file_get_contents('php://input')), true) ?? [];
        $id           = intval($data['id'] ?? 0);
        $html_content = $data['html_content'] ?? '';
        $css_content  = $data['css_content']  ?? '';
        $is_published = isset($data['is_published']) ? intval($data['is_published']) : null;

        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); break; }

        if ($is_published !== null) {
            $stmt = $conn->prepare(
                "UPDATE site_pages SET html_content=?, css_content=?, is_published=?, updated_at=CURRENT_TIMESTAMP, updated_by=? WHERE id=?"
            );
            $stmt->execute([$html_content, $css_content, $is_published, $_SESSION['admin_id'], $id]);
        } else {
            $stmt = $conn->prepare(
                "UPDATE site_pages SET html_content=?, css_content=?, updated_at=CURRENT_TIMESTAMP, updated_by=? WHERE id=?"
            );
            $stmt->execute([$html_content, $css_content, $_SESSION['admin_id'], $id]);
        }
        echo json_encode(['success' => true]);
        break;

    // ------------------------------------------------------------------ SAVE SEO
    case 'save_seo':
        $data = json_decode(scalar_string(file_get_contents('php://input')), true) ?? [];
        $id              = intval($data['id'] ?? 0);
        $meta_desc       = trim($data['meta_description'] ?? '');
        $meta_keywords   = trim($data['meta_keywords']    ?? '');
        $og_title        = trim($data['og_title']         ?? '');
        $og_description  = trim($data['og_description']   ?? '');
        $og_image        = trim($data['og_image']         ?? '');

        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); break; }

        // Validate lengths
        if (mb_strlen($meta_desc) > 320) {
            echo json_encode(['success' => false, 'error' => 'Meta description is too long (max 320 characters)']);
            break;
        }
        if (mb_strlen($og_title) > 255) {
            echo json_encode(['success' => false, 'error' => 'OG title is too long (max 255 characters)']);
            break;
        }
        if ($og_image !== '' && !filter_var($og_image, FILTER_VALIDATE_URL)) {
            // Must be an absolute path starting with / and must not contain path traversal sequences
            if (strpos($og_image, '/') !== 0 || strpos($og_image, '..') !== false) {
                echo json_encode(['success' => false, 'error' => 'OG image must be a valid URL or an absolute path starting with /']);
                break;
            }
        }

        $stmt = $conn->prepare(
            "UPDATE site_pages SET meta_description=?, meta_keywords=?, og_title=?, og_description=?, og_image=?,
             updated_at=CURRENT_TIMESTAMP, updated_by=? WHERE id=?"
        );
        $stmt->execute([$meta_desc, $meta_keywords, $og_title, $og_description, $og_image, $_SESSION['admin_id'], $id]);
        echo json_encode(['success' => true]);
        break;

    // ------------------------------------------------------------------ UPLOAD OG IMAGE
    case 'upload_og_image':
        $page_id = intval($_POST['page_id'] ?? 0);
        if (!$page_id) { echo json_encode(['success' => false, 'error' => 'Missing page_id']); break; }
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $upload_err = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
            $err_messages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload size limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload size limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
            ];
            echo json_encode(['success' => false, 'error' => $err_messages[$upload_err] ?? 'Upload error.']);
            break;
        }

        $allowed_mime  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $max_size      = 5 * 1024 * 1024; // 5 MB

        $tmp_path  = $_FILES['image']['tmp_name'];
        $orig_name = $_FILES['image']['name'];
        $file_size = $_FILES['image']['size'];

        if ($file_size > $max_size) { echo json_encode(['success' => false, 'error' => 'Image must be smaller than 5 MB.']); break; }

        // Validate MIME type via finfo (not trusting client-reported type)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            echo json_encode(['success' => false, 'error' => 'File type validation is unavailable.']);
            break;
        }
        $mime_type = finfo_file($finfo, $tmp_path);
        finfo_close($finfo);
        if (!in_array($mime_type, $allowed_mime)) {
            echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, WebP, or GIF images are allowed.']);
            break;
        }

        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        if (!in_array($ext, $allowed_exts)) { $ext = $mime_to_ext[$mime_type] ?? 'jpg'; }

        $upload_dir = __DIR__ . '/../backend/uploads/seo/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

        $filename   = 'og_' . $page_id . '_' . uniqid() . '.' . $ext;
        $dest_path  = $upload_dir . $filename;

        if (!move_uploaded_file($tmp_path, $dest_path)) {
            echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file.']);
            break;
        }

        $url = '/backend/uploads/seo/' . $filename;
        echo json_encode(['success' => true, 'url' => $url]);
        break;

    // ------------------------------------------------------------------ RENAME
    case 'rename':
        $data  = json_decode(scalar_string(file_get_contents('php://input')), true) ?? [];
        $id    = intval($data['id'] ?? 0);
        $title = trim($data['title'] ?? '');
        if (!$id || $title === '') { echo json_encode(['success' => false, 'error' => 'Missing id or title']); break; }
        $stmt = $conn->prepare("UPDATE site_pages SET title=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([$title, $id]);
        echo json_encode(['success' => true]);
        break;

    // ------------------------------------------------------------------ DELETE
    case 'delete':
        $data = json_decode(scalar_string(file_get_contents('php://input')), true) ?? [];
        $id   = intval($data['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); break; }
        // Prevent deleting the homepage
        $chk = $conn->prepare("SELECT is_homepage FROM site_pages WHERE id=?");
        $chk->execute([$id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Page not found']); break; }
        if ($row['is_homepage']) { echo json_encode(['success' => false, 'error' => 'Cannot delete the homepage']); break; }
        $stmt = $conn->prepare("DELETE FROM site_pages WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    // ------------------------------------------------------------------ PUBLISH TOGGLE
    case 'toggle_publish':
        $data = json_decode(scalar_string(file_get_contents('php://input')), true) ?? [];
        $id   = intval($data['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); break; }
        $stmt = $conn->prepare("UPDATE site_pages SET is_published = 1-is_published, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([$id]);
        $row = $conn->prepare("SELECT is_published FROM site_pages WHERE id=?");
        $row->execute([$id]);
        $val = $row->fetchColumn();
        echo json_encode(['success' => true, 'is_published' => intval($val)]);
        break;

    // ------------------------------------------------------------------ REORDER
    case 'reorder':
        $data  = json_decode(scalar_string(file_get_contents('php://input')), true) ?? [];
        $order = $data['order'] ?? []; // array of page ids in new order
        $stmt  = $conn->prepare("UPDATE site_pages SET sort_order=? WHERE id=?");
        foreach ($order as $pos => $pid) {
            $stmt->execute([$pos, intval($pid)]);
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

// ------------------------------------------------------------------ helpers
function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text ?: 'page';
}
