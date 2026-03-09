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
                <strong>Published</strong> pages are visible to visitors. Use the <strong>SEO</strong> button to configure
                search engine and social media metadata for each page.
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
                            <th style="width:240px">Actions</th>
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
                                <button class="btn btn-sm btn-outline-info btn-seo" title="SEO settings">
                                    <i class="fas fa-magnifying-glass me-1"></i> SEO
                                </button>
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

<!-- SEO Settings Modal -->
<div class="modal fade" id="seoModal" tabindex="-1" aria-labelledby="seoModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="seoModalLabel"><i class="fas fa-magnifying-glass me-2"></i>SEO Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="seoPageId">

                <!-- Basic SEO -->
                <h6 class="fw-semibold mb-3 text-primary"><i class="fas fa-tag me-1"></i> Basic SEO</h6>
                <div class="mb-3">
                    <label class="form-label fw-semibold">SEO Title</label>
                    <input type="text" class="form-control" id="seoOgTitle" maxlength="255" placeholder="Leave blank to use the page title">
                    <div class="form-text">Overrides the page title in browser tabs and search results. Recommended: 50–70 characters. <span id="seoOgTitleCount" class="text-muted">0</span>/70</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Meta Description</label>
                    <textarea class="form-control" id="seoMetaDesc" rows="3" maxlength="320" placeholder="Brief summary of this page for search engines"></textarea>
                    <div class="form-text">Recommended: 120–160 characters. <span id="seoMetaDescCount" class="text-muted">0</span>/320</div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Keywords</label>
                    <input type="text" class="form-control" id="seoKeywords" placeholder="e.g. dog training, puppy classes, obedience">
                    <div class="form-text">Comma-separated keywords relevant to this page.</div>
                </div>

                <hr>

                <!-- Open Graph / Social Sharing -->
                <h6 class="fw-semibold mb-3 text-primary"><i class="fas fa-share-nodes me-1"></i> Social Sharing (Open Graph &amp; Twitter Card)</h6>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Social Description</label>
                    <textarea class="form-control" id="seoOgDesc" rows="2" maxlength="320" placeholder="Leave blank to use the meta description"></textarea>
                    <div class="form-text">Description shown in social media link previews (defaults to meta description).</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Social Image</label>
                    <div class="input-group mb-1">
                        <input type="text" class="form-control" id="seoOgImage" placeholder="https://example.com/image.jpg or /assets/image.jpg">
                        <label class="btn btn-outline-secondary" for="seoOgImageFile" title="Upload an image">
                            <i class="fas fa-upload"></i> Upload
                        </label>
                        <input type="file" id="seoOgImageFile" class="d-none" accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                    <div class="form-text">Recommended size: 1200×630 px. Formats: JPG, PNG, WebP. Enter a URL or upload a file.</div>
                    <div id="seoOgImagePreviewWrap" class="mt-2 d-none">
                        <img id="seoOgImagePreview" src="" alt="Social image preview" class="img-thumbnail" style="max-height:140px;">
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" id="btnClearOgImage" title="Remove image">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>
                    <div id="seoImageUploadProgress" class="d-none mt-2">
                        <div class="progress" style="height:6px">
                            <div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div>
                        </div>
                        <small class="text-muted">Uploading…</small>
                    </div>
                </div>

                <div id="seoError" class="alert alert-danger d-none mt-3"></div>
                <div id="seoSuccess" class="alert alert-success d-none mt-3">SEO settings saved successfully.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="btnSaveSeo">
                    <i class="fas fa-floppy-disk me-1"></i> Save SEO Settings
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    // Open modal — initialise lazily so Bootstrap JS (loaded in footer) is available
    document.getElementById('btnNewPage').addEventListener('click', function () {
        const newPageModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('newPageModal'));
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

    // ------------------------------------------------------------------ SEO modal
    const seoModal      = document.getElementById('seoModal');
    const seoPageIdEl   = document.getElementById('seoPageId');
    const seoOgTitle    = document.getElementById('seoOgTitle');
    const seoMetaDesc   = document.getElementById('seoMetaDesc');
    const seoKeywords   = document.getElementById('seoKeywords');
    const seoOgDesc     = document.getElementById('seoOgDesc');
    const seoOgImage    = document.getElementById('seoOgImage');
    const seoErrorEl    = document.getElementById('seoError');
    const seoSuccessEl  = document.getElementById('seoSuccess');

    // Character counters
    function updateCounter(inputEl, countEl, max) {
        const len = inputEl.value.length;
        countEl.textContent = len;
        countEl.classList.toggle('text-danger', len > max * 0.9);
    }
    seoOgTitle.addEventListener('input', () => updateCounter(seoOgTitle, document.getElementById('seoOgTitleCount'), 70));
    seoMetaDesc.addEventListener('input',  () => updateCounter(seoMetaDesc,  document.getElementById('seoMetaDescCount'),  320));

    // OG image preview
    function updateOgPreview(url) {
        const wrap = document.getElementById('seoOgImagePreviewWrap');
        const img  = document.getElementById('seoOgImagePreview');
        if (url) {
            img.src = url;
            wrap.classList.remove('d-none');
        } else {
            wrap.classList.add('d-none');
            img.src = '';
        }
    }
    seoOgImage.addEventListener('input', () => updateOgPreview(seoOgImage.value.trim()));

    document.getElementById('btnClearOgImage').addEventListener('click', function () {
        seoOgImage.value = '';
        updateOgPreview('');
    });

    // Image file upload
    document.getElementById('seoOgImageFile').addEventListener('change', async function () {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            seoErrorEl.textContent = 'Only JPG, PNG, WebP, or GIF images are allowed.';
            seoErrorEl.classList.remove('d-none');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            seoErrorEl.textContent = 'Image must be smaller than 5 MB.';
            seoErrorEl.classList.remove('d-none');
            return;
        }
        seoErrorEl.classList.add('d-none');
        const progress = document.getElementById('seoImageUploadProgress');
        progress.classList.remove('d-none');
        const fd = new FormData();
        fd.append('image', file);
        fd.append('page_id', seoPageIdEl.value);
        try {
            const res = await fetch('site_pages_api.php?action=upload_og_image', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                seoOgImage.value = data.url;
                updateOgPreview(data.url);
            } else {
                seoErrorEl.textContent = data.error || 'Upload failed.';
                seoErrorEl.classList.remove('d-none');
            }
        } catch (e) {
            seoErrorEl.textContent = 'Upload request failed.';
            seoErrorEl.classList.remove('d-none');
        } finally {
            progress.classList.add('d-none');
            this.value = '';
        }
    });

    // Open SEO modal and load current data
    document.querySelectorAll('.btn-seo').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const row = this.closest('tr');
            const id  = parseInt(row.dataset.pageId, 10);
            if (!id || id <= 0) return;
            const pageTitle = row.querySelector('td:nth-child(2)').textContent.trim();
            // Safely set modal label text (no innerHTML with user data)
            const labelEl = document.getElementById('seoModalLabel');
            labelEl.textContent = '';
            const icon = document.createElement('i');
            icon.className = 'fas fa-magnifying-glass me-2';
            labelEl.appendChild(icon);
            labelEl.appendChild(document.createTextNode('SEO Settings — ' + pageTitle));
            seoPageIdEl.value = id;

            // Reset fields
            [seoOgTitle, seoMetaDesc, seoKeywords, seoOgDesc, seoOgImage].forEach(el => el.value = '');
            updateOgPreview('');
            seoErrorEl.classList.add('d-none');
            seoSuccessEl.classList.add('d-none');
            updateCounter(seoOgTitle,  document.getElementById('seoOgTitleCount'),  70);
            updateCounter(seoMetaDesc, document.getElementById('seoMetaDescCount'), 320);

            const modal = bootstrap.Modal.getOrCreateInstance(seoModal);
            modal.show();

            // Load existing data
            try {
                const res  = await fetch('site_pages_api.php?action=get&id=' + id);
                const data = await res.json();
                if (data.success && data.page) {
                    const p = data.page;
                    seoOgTitle.value   = p.og_title         || '';
                    seoMetaDesc.value  = p.meta_description || '';
                    seoKeywords.value  = p.meta_keywords    || '';
                    seoOgDesc.value    = p.og_description   || '';
                    seoOgImage.value   = p.og_image         || '';
                    updateOgPreview(p.og_image || '');
                    updateCounter(seoOgTitle,  document.getElementById('seoOgTitleCount'),  70);
                    updateCounter(seoMetaDesc, document.getElementById('seoMetaDescCount'), 320);
                }
            } catch (e) {
                // non-fatal; modal still opens with empty fields
            }
        });
    });

    // Save SEO settings
    document.getElementById('btnSaveSeo').addEventListener('click', async function () {
        const id = parseInt(seoPageIdEl.value, 10);
        if (!id || id <= 0) return;

        seoErrorEl.classList.add('d-none');
        seoSuccessEl.classList.add('d-none');
        this.disabled = true;

        const payload = {
            id:               id,
            meta_description: seoMetaDesc.value.trim(),
            meta_keywords:    seoKeywords.value.trim(),
            og_title:         seoOgTitle.value.trim(),
            og_description:   seoOgDesc.value.trim(),
            og_image:         seoOgImage.value.trim()
        };

        try {
            const res  = await fetch('site_pages_api.php?action=save_seo', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                seoSuccessEl.classList.remove('d-none');
                setTimeout(() => seoSuccessEl.classList.add('d-none'), 4000);
            } else {
                seoErrorEl.textContent = data.error || 'Failed to save SEO settings.';
                seoErrorEl.classList.remove('d-none');
            }
        } catch (e) {
            seoErrorEl.textContent = 'Request failed.';
            seoErrorEl.classList.remove('d-none');
        } finally {
            this.disabled = false;
        }
    });
}());
</script>

<?php require_once '../backend/includes/footer.php'; ?>

