<?php
/**
 * Brook's Dog Training Academy - Site Pages List
 * Manage front-end website pages (create, rename, delete, publish).
 */

require_once '../backend/includes/config.php';
requireLogin();

$db   = new Database();
$conn = $db->getConnection();

$pages = $conn->query(
    "SELECT id, slug, title, is_homepage, is_published, sort_order, updated_at
     FROM site_pages ORDER BY sort_order ASC, id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Site Pages';
require_once '../backend/includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-file-code me-2"></i>Site Pages</h2>
        <button class="btn btn-primary" id="btnNewPage">
            <i class="fas fa-circle-plus me-1"></i> New Page
        </button>
    </div>

    <div class="card">
        <div class="card-body">
            <p class="text-muted mb-3">
                Manage the pages of your public-facing website. Use the visual editor to update content and layout.
                <strong>Published</strong> pages are visible to visitors.
            </p>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="pagesTable">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th>Title</th>
                            <th>Slug / URL</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th style="width:200px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($pages)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No pages yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pages as $p): ?>
                        <tr data-page-id="<?php echo intval($p['id']); ?>">
                            <td class="text-muted small"><?php echo intval($p['id']); ?></td>
                            <td>
                                <?php echo escape($p['title']); ?>
                                <?php if ($p['is_homepage']): ?>
                                    <span class="badge bg-info ms-1">Homepage</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code class="small">
                                <?php if ($p['is_homepage']): ?>
                                    /
                                <?php else: ?>
                                    /page.php?slug=<?php echo escape($p['slug']); ?>
                                <?php endif; ?>
                                </code>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $p['is_published'] ? 'success' : 'secondary'; ?> publish-badge">
                                    <?php echo $p['is_published'] ? 'Published' : 'Draft'; ?>
                                </span>
                            </td>
                            <td class="small text-muted">
                                <?php echo $p['updated_at'] ? date('Y-m-d H:i', strtotime($p['updated_at'])) : '—'; ?>
                            </td>
                            <td>
                                <a href="site_editor.php?id=<?php echo intval($p['id']); ?>" class="btn btn-sm btn-outline-primary" title="Edit in visual editor">
                                    <i class="fas fa-pencil me-1"></i> Edit
                                </a>
                                <?php if ($p['is_published'] && $p['is_homepage']): ?>
                                <a href="../index.php" target="_blank" class="btn btn-sm btn-outline-secondary" title="View live page">
                                    <i class="fas fa-eye me-1"></i> View
                                </a>
                                <?php elseif ($p['is_published']): ?>
                                <a href="../page.php?slug=<?php echo escape($p['slug']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="View live page">
                                    <i class="fas fa-eye me-1"></i> View
                                </a>
                                <?php endif; ?>
                                <?php if (!$p['is_homepage']): ?>
                                <button class="btn btn-sm btn-outline-danger btn-delete" title="Delete page">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- New Page Modal -->
<div class="modal fade" id="newPageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-plus me-2"></i>New Page</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Page Title *</label>
                    <input type="text" class="form-control" id="newPageTitle" placeholder="e.g. About Us">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">URL Slug</label>
                    <div class="input-group">
                        <span class="input-group-text text-muted">/page.php?slug=</span>
                        <input type="text" class="form-control" id="newPageSlug" placeholder="auto-generated">
                    </div>
                    <div class="form-text">Leave blank to auto-generate from title.</div>
                </div>
                <div id="newPageError" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnCreatePage">
                    <i class="fas fa-circle-plus me-1"></i> Create &amp; Edit
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const newPageModal = new bootstrap.Modal(document.getElementById('newPageModal'));

    // Open modal
    document.getElementById('btnNewPage').addEventListener('click', function () {
        document.getElementById('newPageTitle').value = '';
        document.getElementById('newPageSlug').value  = '';
        document.getElementById('newPageError').classList.add('d-none');
        newPageModal.show();
        setTimeout(() => document.getElementById('newPageTitle').focus(), 300);
    });

    // Auto-generate slug from title
    document.getElementById('newPageTitle').addEventListener('input', function () {
        const slug = this.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        document.getElementById('newPageSlug').value = slug;
    });

    // Create page
    document.getElementById('btnCreatePage').addEventListener('click', async function () {
        const title = document.getElementById('newPageTitle').value.trim();
        const slug  = document.getElementById('newPageSlug').value.trim();
        const errEl = document.getElementById('newPageError');
        if (!title) { errEl.textContent = 'Title is required.'; errEl.classList.remove('d-none'); return; }
        errEl.classList.add('d-none');
        this.disabled = true;
        try {
            const res = await fetch('site_pages_api.php?action=create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title, slug })
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = 'site_editor.php?id=' + data.id;
            } else {
                errEl.textContent = data.error || 'Failed to create page.';
                errEl.classList.remove('d-none');
                this.disabled = false;
            }
        } catch (e) {
            errEl.textContent = 'Request failed.';
            errEl.classList.remove('d-none');
            this.disabled = false;
        }
    });

    // Delete page
    document.querySelectorAll('.btn-delete').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const row = this.closest('tr');
            const id  = parseInt(row.dataset.pageId);
            const title = row.querySelector('td:nth-child(2)').textContent.trim();
            if (!confirm('Delete page "' + title + '"? This cannot be undone.')) return;
            try {
                const res = await fetch('site_pages_api.php?action=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.success) {
                    row.remove();
                } else {
                    alert(data.error || 'Failed to delete page.');
                }
            } catch (e) {
                alert('Request failed.');
            }
        });
    });
}());
</script>

<?php require_once '../backend/includes/footer.php'; ?>
