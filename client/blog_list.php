<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SELECT * FROM blog_posts ORDER BY publish_date DESC");
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$now = (new DateTime())->format('Y-m-d H:i:s');

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
                            <?php
                                $post_title = array_string_value($post, 'title');
                                $post_author = array_string_value($post, 'author');
                                $effective_date = array_string_value($post, 'publish_date', array_string_value($post, 'created_at'));
                                $post_published = array_int_value($post, 'published') === 1;
                                $post_id = array_int_value($post, 'id');
                                $isScheduled = $post_published && strtotime($effective_date) > strtotime($now);
                                $statusLabel = $post_published ? ($isScheduled ? 'Scheduled' : 'Published') : 'Draft';
                                $statusClass = $post_published ? ($isScheduled ? 'warning' : 'success') : 'secondary';
                            ?>
                            <tr>
                                <td><?php echo escape($post_title); ?></td>
                                <td><?php echo escape($post_author); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                        <?php echo $statusLabel; ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d', safe_timestamp(strtotime($effective_date))); ?></td>
                                <td>
                                    <a href="blog_edit.php?id=<?php echo $post_id; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-pencil"></i>
                                    </a>
                                    <form method="POST" action="blog_delete.php" class="d-inline" onsubmit="return confirm('Delete this post?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(scalar_string($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="id" value="<?php echo $post_id; ?>">
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
