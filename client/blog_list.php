<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SELECT * FROM blog_posts ORDER BY publish_date DESC");
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Blog Posts';
require_once '../backend/includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-blog me-2"></i>Blog Posts</h2>
        <a href="blog_edit.php" class="btn btn-primary">
            <i class="fas fa-circle-plus me-1"></i> New Post
        </a>
    </div>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Status</th>
                            <th>Publish Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($posts) > 0): ?>
                            <?php foreach ($posts as $post): ?>
                            <tr>
                                <td><?php echo escape($post['title']); ?></td>
                                <td><?php echo escape($post['author']); ?></td>
                                <td>
                                    <?php
                                        $effectiveDate = $post['publish_date'] ?? $post['created_at'];
                                        $isScheduled = $post['published'] && strtotime($effectiveDate) > time();
                                        $statusLabel = $post['published'] ? ($isScheduled ? 'Scheduled' : 'Published') : 'Draft';
                                        $statusClass = $post['published'] ? ($isScheduled ? 'warning' : 'success') : 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                        <?php echo $statusLabel; ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($effectiveDate)); ?></td>
                                <td>
                                    <a href="blog_edit.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-pencil"></i>
                                    </a>
                                    <form method="POST" action="blog_delete.php" class="d-inline" onsubmit="return confirm('Delete this post?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="id" value="<?php echo $post['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">No blog posts yet</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../backend/includes/footer.php'; ?>
