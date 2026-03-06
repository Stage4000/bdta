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
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
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

        $stmt = $conn->prepare(
            "INSERT INTO site_pages (slug, title, html_content, css_content, is_homepage, is_published, sort_order, updated_by)
             VALUES (?, ?, '', '', 0, 0, (SELECT COALESCE(MAX(sort_order),0)+1 FROM site_pages), ?)"
        );
        $stmt->execute([$slug, $title, $_SESSION['admin_id']]);
        $new_id = $conn->lastInsertId();
        echo json_encode(['success' => true, 'id' => $new_id, 'slug' => $slug]);
        break;

    // ------------------------------------------------------------------ SAVE (editor content)
    case 'save':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
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

    // ------------------------------------------------------------------ RENAME
    case 'rename':
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $id    = intval($data['id'] ?? 0);
        $title = trim($data['title'] ?? '');
        if (!$id || $title === '') { echo json_encode(['success' => false, 'error' => 'Missing id or title']); break; }
        $stmt = $conn->prepare("UPDATE site_pages SET title=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([$title, $id]);
        echo json_encode(['success' => true]);
        break;

    // ------------------------------------------------------------------ DELETE
    case 'delete':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
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
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
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
