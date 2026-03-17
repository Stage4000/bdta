<?php
/**
 * Brook's Dog Training Academy - Visual Site Editor (GrapesJS)
 * Full-screen in-browser page editor for front-end website pages.
 */

require_once '../backend/includes/config.php';
requireLogin();

$db   = new Database();
$conn = $db->getConnection();

$page_id = safe_int($_GET['id'] ?? 0);
if (!$page_id) {
    setFlashMessage('No page selected.', 'danger');
    redirect('site_pages_list.php');
}

$stmt = $conn->prepare("SELECT * FROM site_pages WHERE id = ?");
$stmt->execute([$page_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($page)) {
    setFlashMessage('Page not found.', 'danger');
    redirect('site_pages_list.php');
}

/**
 * Convert relative asset paths in HTML to root-absolute paths.
 * Ensures images, scripts, and local links work correctly both inside the
 * GrapesJS canvas iframe (served from /client/) and on the published front-end.
 *
 * Transforms:  src="assets/..."  →  src="/assets/..."
 *              href="js/..."      →  href="/js/..."
 * Leaves alone: absolute URLs (http/https/://), fragment-only (#), data URIs.
 */
function makeHtmlPathsAbsolute(string $html): string {
    // Fix src="<relative>"
    $html = preg_replace(
        '/\bsrc="(?!\/|https?:|data:|#)([^"]+)"/i',
        'src="/$1"',
        $html
    ) ?? $html;
    // Fix href="<relative>" (skip anchors, mailto, external URLs)
    $html = preg_replace(
        '/\bhref="(?!\/|https?:|mailto:|tel:|data:|#)([^"]+)"/i',
        'href="/$1"',
        $html
    ) ?? $html;
    // Fix CSS url(...) for inline background images using unquoted or quoted relative paths
    $html = preg_replace(
        '/url\((?![\'"]?(?:\/|https?:|data:))([\'"]?)([^\'"\)]+)\1\)/i',
        'url($1/$2$1)',
        $html
    ) ?? $html;
    return $html;
}
/** @var array<string, mixed> $page */

// If this is the homepage and html_content is empty, seed from index.html
if (array_int_value($page, 'is_homepage') === 1 && trim(array_string_value($page, 'html_content')) === '') {
    $index_file = dirname(__DIR__) . '/index.html';
    if (file_exists($index_file)) {
        $raw_html = scalar_string(file_get_contents($index_file));
        // Extract <style> blocks from <head> as seed CSS (before we strip the head)
        $seed_css = '';
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $raw_html, $styles)) {
            $seed_css = implode("\n", $styles[1]);
        }
        // Only take the <body> content for the editor canvas
        $seed_html = $raw_html;
        if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $raw_html, $m)) {
            $seed_html = trim($m[1]);
        }
        // Convert relative paths → absolute so images load in the canvas
        $seed_html = makeHtmlPathsAbsolute($seed_html);
        $seed_css  = makeHtmlPathsAbsolute($seed_css);

        $upd = $conn->prepare(
            "UPDATE site_pages SET html_content=?, css_content=? WHERE id=?"
        );
        $upd->execute([$seed_html, $seed_css, $page_id]);
        $page['html_content'] = $seed_html;
        $page['css_content']  = $seed_css;
    }
}

// If this is a new non-homepage page with no content, seed with the standard
// site shell (navbar + placeholder content + footer) extracted from index.html.
// Assumes index.html contains exactly one top-level <nav> and one <footer>
// with no nested elements of the same type (matching the current site structure).
if (array_int_value($page, 'is_homepage') !== 1 && trim(array_string_value($page, 'html_content')) === '') {
    $index_file = dirname(__DIR__) . '/index.html';
    if (file_exists($index_file)) {
        $raw_html = scalar_string(file_get_contents($index_file));

        // Extract <style> blocks from <head> as seed CSS
        $seed_css = '';
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $raw_html, $styles)) {
            $seed_css = implode("\n", $styles[1]);
        }

        $seed_html = '';
        if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $raw_html, $m)) {
            $body_html = $m[1];

            // Extract the main navbar (no nested <nav> in index.html)
            $navbar = '';
            if (preg_match('/<nav\b[^>]*>.*?<\/nav>/si', $body_html, $nav_m)) {
                $navbar = $nav_m[0];
            }

            // Extract the footer (no nested <footer> in index.html)
            $footer = '';
            if (preg_match('/<footer\b[^>]*>.*?<\/footer>/si', $body_html, $footer_m)) {
                $footer = $footer_m[0];
            }

            // Only seed when both structural elements are successfully extracted;
            // otherwise the editor opens with blank content for the user to build from scratch.
            if ($navbar && $footer) {
                $page_heading = escape(array_string_value($page, 'title'));
                $seed_html = $navbar . "\n"
                    . '<main class="bdta-seeded-page-main">' . "\n"
                    . '<section class="py-5">' . "\n"
                    . '<div class="container">' . "\n"
                    . '<h1 class="display-5 fw-bold mb-4">' . $page_heading . '</h1>' . "\n"
                    . '<p class="lead text-muted">Add your content here.</p>' . "\n"
                    . '</div>' . "\n"
                    . '</section>' . "\n"
                    . '</main>' . "\n"
                    . $footer;
            }
        }

        if ($seed_html) {
            $seed_css = trim($seed_css . "\n\n.bdta-seeded-page-main {\n    padding-top: 80px;\n    min-height: 60vh;\n}\n");
            $seed_html = makeHtmlPathsAbsolute($seed_html);
            $seed_css  = makeHtmlPathsAbsolute($seed_css);

            $upd = $conn->prepare(
                "UPDATE site_pages SET html_content=?, css_content=? WHERE id=?"
            );
            $upd->execute([$seed_html, $seed_css, $page_id]);
            $page['html_content'] = $seed_html;
            $page['css_content']  = $seed_css;
        }
    }
}

// Also fix any previously saved content that still has relative paths
// (e.g. seeded before this fix was applied).  This is idempotent.
$page['html_content'] = makeHtmlPathsAbsolute(array_string_value($page, 'html_content'));
$page['css_content']  = makeHtmlPathsAbsolute(array_string_value($page, 'css_content'));

$page_title = 'Edit: ' . array_string_value($page, 'title');
$is_homepage  = array_int_value($page, 'is_homepage') === 1;
$is_published = array_int_value($page, 'is_published') === 1;
$view_url = $is_homepage ? '../index.php' : '../page.php?slug=' . urlencode(array_string_value($page, 'slug'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($page_title); ?> — BDTA Editor</title>
    <!-- GrapesJS -->
    <link rel="stylesheet" href="https://unpkg.com/grapesjs@0.21.7/dist/css/grapes.min.css" integrity="sha384-y+b/FrTlQekasqcb9/Bb93YxG8pk4fd3wznRwaLWipnsTBkfVB1UGYWX0nTgbNV8" crossorigin="anonymous">
    <!-- Bootstrap (for editor UI chrome only) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; overflow: hidden; font-family: Arial, sans-serif; background: #1a1a2e; }

        /* ---- Top Bar ---- */
        #editor-topbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
            height: 54px;
            background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);
            display: flex; align-items: center; gap: 8px; padding: 0 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.4);
        }
        #editor-topbar .brand {
            color: #fff; font-weight: 700; font-size: 14px; margin-right: 8px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px;
        }
        #editor-topbar .spacer { flex: 1; }
        #editor-topbar .btn-topbar {
            background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3);
            color: #fff; border-radius: 6px; padding: 5px 14px; font-size: 13px;
            cursor: pointer; display: flex; align-items: center; gap: 6px; white-space: nowrap;
            transition: background .2s;
        }
        #editor-topbar .btn-topbar.btn-topbar-icon {
            padding: 5px 10px;
        }
        #editor-topbar .btn-topbar:hover { background: rgba(255,255,255,.28); }
        #editor-topbar .btn-topbar.btn-publish {
            background: #198754; border-color: #198754;
        }
        #editor-topbar .btn-topbar.btn-publish:hover { background: #157347; border-color: #157347; }
        #editor-topbar .btn-topbar.btn-unpublish {
            background: #6c757d; border-color: #6c757d;
        }
        #editor-topbar .status-badge {
            padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;
            background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.3);
        }
        #editor-topbar .status-badge.published { background: rgba(25,135,84,.5); }

        /* ---- GrapesJS container ---- */
        #gjs {
            position: fixed;
            top: 54px; left: 0; right: 0; bottom: 0;
        }

        /* Tweak GrapesJS default colours to match brand */
        .gjs-cv-canvas { background: #f8f9fa; }
        .gjs-one-bg { background: #2d2d2d; }
        .gjs-two-bg { background: #3b3b3b; }
        .gjs-three-bg { background: #444; }
        .gjs-four-color, .gjs-four-color-h:hover { color: #9a0073 !important; }
        .gjs-pn-btn.gjs-pn-active { color: #9a0073 !important; }

        /* Toast notifications */
        #toast-container {
            position: fixed; bottom: 24px; right: 24px; z-index: 99999;
            display: flex; flex-direction: column; gap: 8px;
        }
        .bdta-toast {
            background: #222; color: #fff; padding: 10px 18px; border-radius: 8px;
            font-size: 13px; box-shadow: 0 4px 12px rgba(0,0,0,.4);
            animation: fadeInUp .3s ease;
        }
        .bdta-toast.success { border-left: 4px solid #198754; }
        .bdta-toast.error   { border-left: 4px solid #dc3545; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Rename inline */
        #page-title-display { cursor: pointer; border-bottom: 1px dashed rgba(255,255,255,.4); }
        #page-title-input {
            background: transparent; border: none; border-bottom: 2px solid #fff;
            color: #fff; font-size: 14px; font-weight: 700; outline: none; width: 200px;
        }
        .code-editor-textarea {
            font-family: 'SFMono-Regular', Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
        }
    </style>
</head>
<body>

<!-- Top Bar -->
<div id="editor-topbar">
    <a href="site_pages_list.php" class="btn-topbar btn-topbar-icon" title="Back to pages list">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div class="brand">
        <i class="fas fa-paw me-1"></i>
        <span id="page-title-display" title="Click to rename"><?php echo escape(array_string_value($page, 'title')); ?></span>
        <input type="text" id="page-title-input" class="d-none" value="<?php echo escape(array_string_value($page, 'title')); ?>">
    </div>
    <div class="spacer"></div>

    <!-- Status badge -->
    <span class="status-badge <?php echo $is_published ? 'published' : ''; ?>" id="status-badge">
        <?php echo $is_published ? '&#9679; Published' : '&#9675; Draft'; ?>
    </span>

    <!-- Undo / Redo -->
    <button class="btn-topbar" id="btn-undo" title="Undo"><i class="fas fa-undo"></i></button>
    <button class="btn-topbar" id="btn-redo" title="Redo"><i class="fas fa-redo"></i></button>

    <!-- Preview -->
    <button class="btn-topbar btn-topbar-icon" id="btn-preview" title="Toggle preview" aria-label="Toggle preview">
        <i class="fas fa-eye"></i>
    </button>

    <!-- HTML / Code -->
    <button class="btn-topbar btn-topbar-icon" id="btn-html" title="Edit HTML/CSS" aria-label="Edit HTML and CSS">
        <i class="fas fa-code"></i>
    </button>

    <!-- View Live -->
    <?php if ($is_published): ?>
    <a href="<?php echo escape($view_url); ?>" target="_blank" class="btn-topbar btn-topbar-icon" title="View live page" aria-label="View live page">
        <i class="fas fa-external-link-alt"></i>
    </a>
    <?php endif; ?>

    <!-- Save Draft -->
    <button class="btn-topbar btn-topbar-icon" id="btn-save" title="Save draft" aria-label="Save draft">
        <i class="fas fa-floppy-disk"></i>
    </button>

    <!-- Publish / Unpublish -->
    <?php if ($is_published): ?>
    <button class="btn-topbar btn-topbar-icon btn-unpublish" id="btn-publish" title="Unpublish page" aria-label="Unpublish page">
        <i class="fas fa-eye-slash"></i>
    </button>
    <?php else: ?>
    <button class="btn-topbar btn-topbar-icon btn-publish" id="btn-publish" title="Save &amp; Publish" aria-label="Save and publish">
        <i class="fas fa-rocket"></i>
    </button>
    <?php endif; ?>
</div>

<!-- GrapesJS Canvas -->
<div id="gjs"></div>

<!-- Toast Container -->
<div id="toast-container"></div>

<!-- HTML / CSS Modal -->
<div class="modal fade" id="htmlModal" tabindex="-1" aria-labelledby="htmlModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="htmlModalLabel"><i class="fas fa-code me-2"></i>Edit HTML / CSS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="html-code-input">HTML</label>
                    <textarea id="html-code-input" class="form-control code-editor-textarea" rows="12" spellcheck="false"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="css-code-input">CSS</label>
                    <textarea id="css-code-input" class="form-control code-editor-textarea" rows="8" spellcheck="false"></textarea>
                    <div class="form-text">Edits here replace the current page markup and styles in the canvas.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btn-apply-html">Apply Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://unpkg.com/grapesjs@0.21.7/dist/grapes.min.js" integrity="sha384-Ol8eSqsd+xNeTBzPS5Gfeegc/JOwF0cST9fnFnXtFWKHIkJLiH8CgM1jXSpH29la" crossorigin="anonymous"></script>
<script src="https://unpkg.com/grapesjs-blocks-basic@1.0.1/dist/index.js" integrity="sha384-SkIXug4RSsC5wFGGLiKysFuWYowk4PyiQkVvCeADdNWfOlNRdm5OODEneqIHKunq" crossorigin="anonymous"></script>
<script src="https://unpkg.com/grapesjs-preset-webpage@1.0.2/dist/index.js" integrity="sha384-MLuiMl6BIeFtU1LtLWxAIg/hFukQ3yG/4WBUVoGj5SPgNL8Q0vFxn46h2ZqSNc3n" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<script>
(function () {
    'use strict';

    const PAGE_ID   = <?php echo intval($page_id); ?>;
    const IS_HOME   = <?php echo $is_homepage ? 'true' : 'false'; ?>;
    let isPublished = <?php echo $is_published ? 'true' : 'false'; ?>;

    // ------------------------------------------------------------------
    // Toast helper
    // ------------------------------------------------------------------
    function toast(msg, type) {
        type = type || 'success';
        const el = document.createElement('div');
        el.className = 'bdta-toast ' + type;
        el.textContent = msg;
        document.getElementById('toast-container').appendChild(el);
        setTimeout(function () { el.remove(); }, 3500);
    }

    // ------------------------------------------------------------------
    // GrapesJS initialisation
    // ------------------------------------------------------------------
    const savedHtml = <?php echo json_encode(array_string_value($page, 'html_content')); ?>;
    const savedCss  = <?php echo json_encode(array_string_value($page, 'css_content')); ?>;

    const editor = grapesjs.init({
        container: '#gjs',
        height: '100%',
        width: 'auto',
        storageManager: false,   // we handle saving manually
        plugins: ['gjs-blocks-basic', 'gjs-preset-webpage'],
        pluginsOpts: {
            'gjs-blocks-basic': { flexGrid: true },
            'gjs-preset-webpage': {}
        },
        canvas: {
            styles: [
                'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
                'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
                'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap',
                '/css/style.css',
                '/backend/public/theme.css.php'
            ],
            scripts: [
                'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
                '/js/bdta-modules.js'
            ]
        },
        // Custom panels
        panels: {
            defaults: [
                {
                    id: 'devices-c',
                    buttons: [
                        { id: 'set-desk',   command: 'set-device-desktop', label: '<i class="fas fa-desktop"></i>',  active: true, togglable: false },
                        { id: 'set-tablet', command: 'set-device-tablet',  label: '<i class="fas fa-tablet-screen-button"></i>', togglable: false },
                        { id: 'set-mobile', command: 'set-device-mobile',  label: '<i class="fas fa-mobile-screen"></i>', togglable: false }
                    ]
                },
                {
                    id: 'panel-switcher',
                    el: '.panel__switcher',
                    buttons: [
                        { id: 'show-blocks', active: true, label: '<i class="fas fa-th-large"></i>', command: 'show-blocks', togglable: false },
                        { id: 'show-styles', label: '<i class="fas fa-paint-brush"></i>', command: 'show-styles', togglable: false },
                        { id: 'show-traits', label: '<i class="fas fa-sliders"></i>', command: 'show-traits', togglable: false },
                        { id: 'show-layers', label: '<i class="fas fa-layer-group"></i>', command: 'show-layers', togglable: false }
                    ]
                },
                {
                    id: 'views',
                    buttons: [
                        { id: 'open-sm', command: 'open-sm', label: '<i class="fas fa-paint-brush"></i>', active: false },
                        { id: 'open-tm', command: 'open-tm', label: '<i class="fas fa-sliders"></i>' },
                        { id: 'open-layers', command: 'open-layers', label: '<i class="fas fa-layer-group"></i>' },
                        { id: 'open-blocks', command: 'open-blocks', label: '<i class="fas fa-th-large"></i>', active: true }
                    ]
                }
            ]
        }
    });

    // Extend RTE with lists, headings, and HTML/code quick access
    (function configureRTE() {
        const rte = editor.RichTextEditor;
        const addAction = function (name, icon, title, handler) {
            rte.add(name, {
                icon: icon,
                attributes: { title: title },
                result: handler
            });
        };
        addAction('bdta-heading1', '<b>H1</b>', 'Heading 1', rte => rte.exec('formatBlock', '<h1>'));
        addAction('bdta-heading2', '<b>H2</b>', 'Heading 2', rte => rte.exec('formatBlock', '<h2>'));
        addAction('bdta-heading3', '<b>H3</b>', 'Heading 3', rte => rte.exec('formatBlock', '<h3>'));
        addAction('bdta-paragraph', '<b>P</b>', 'Paragraph', rte => rte.exec('formatBlock', '<p>'));
        addAction('bdta-ul', '<i class="fas fa-list-ul"></i>', 'Bulleted list', rte => rte.exec('insertUnorderedList'));
        addAction('bdta-ol', '<i class="fas fa-list-ol"></i>', 'Numbered list', rte => rte.exec('insertOrderedList'));
        addAction('bdta-open-code', '<i class="fas fa-code"></i>', 'Edit HTML / CSS', () => openHtmlModal());
        const desiredActions = [
            'bold', 'italic', 'underline', 'link',
            'bdta-heading1', 'bdta-heading2', 'bdta-heading3', 'bdta-paragraph',
            'bdta-ul', 'bdta-ol', 'bdta-open-code'
        ];
        editor.getConfig().richTextEditor = editor.getConfig().richTextEditor || {};
        editor.getConfig().richTextEditor.actions = desiredActions;
    }());

    // Set initial content
    if (savedHtml || savedCss) {
        editor.setComponents(savedHtml || '');
        editor.setStyle(savedCss || '');
    }

    // ------------------------------------------------------------------
    // Inject <base href="/"> into the GrapesJS canvas iframe so that any
    // remaining relative paths in the editor content resolve from the site
    // root rather than from /client/.  This acts as a belt-and-suspenders
    // fix alongside the PHP-side path normalisation.
    // ------------------------------------------------------------------
    function injectBaseTag() {
        try {
            var frames = editor.Canvas.getFrames ? editor.Canvas.getFrames() : [];
            if (!frames.length) {
                // Fallback for GrapesJS 0.21.x single-frame mode
                var frameEl = editor.Canvas.getFrameEl ? editor.Canvas.getFrameEl() : null;
                if (frameEl && frameEl.contentDocument && frameEl.contentDocument.head) {
                    if (!frameEl.contentDocument.head.querySelector('base')) {
                        var base = frameEl.contentDocument.createElement('base');
                        base.href = window.location.origin + '/';
                        frameEl.contentDocument.head.insertBefore(base, frameEl.contentDocument.head.firstChild);
                    }
                }
                return;
            }
            frames.forEach(function (frame) {
                var doc = frame.view && frame.view.getWindow ? frame.view.getWindow().document : null;
                if (doc && doc.head && !doc.head.querySelector('base')) {
                    var base = doc.createElement('base');
                    base.href = window.location.origin + '/';
                    doc.head.insertBefore(base, doc.head.firstChild);
                }
            });
        } catch (e) { /* cross-origin or not yet ready */ }
    }

    editor.on('load', injectBaseTag);
    // Also run after a short delay to cover async canvas init
    setTimeout(injectBaseTag, 800);

    // ------------------------------------------------------------------
    // Custom blocks: BDTA-specific components
    // ------------------------------------------------------------------
    const bm = editor.BlockManager;

    bm.add('bdta-hero', {
        label: 'Hero Section',
        category: 'BDTA',
        content: `
<section class="bdta-section-hero text-white text-center py-5">
  <div class="container py-4 py-md-5">
    <h1 class="display-5 fw-bold mb-3">Teaching Humans to Speak Dog</h1>
    <p class="lead mb-4 mx-auto bdta-content-narrow">Professional dog training in Highlands County, Florida.</p>
    <a href="#" class="btn btn-light btn-lg px-4 bdta-hero-button">Book Now</a>
  </div>
</section>`
    });

    bm.add('bdta-cta', {
        label: 'Call to Action',
        category: 'BDTA',
        content: `
<section class="bg-light text-center py-5">
  <div class="container py-4">
    <h2 class="fw-bold mb-3">Ready to get started?</h2>
    <p class="text-muted mb-4">Book a session with Brook today.</p>
    <a href="/backend/public/book.php?type=1" class="btn btn-primary btn-lg px-4 bdta-cta-button">Book Now</a>
  </div>
</section>`
    });

    bm.add('bdta-cards-row', {
        label: '3-Column Cards',
        category: 'BDTA',
        content: `
<section class="py-5 bg-light">
  <div class="container">
    <div class="row g-4 justify-content-center">
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card bdta-feature-card h-100 text-center">
          <div class="card-body p-4">
            <div class="bdta-feature-icon mb-3">🐾</div>
            <h4 class="mb-2">Feature One</h4>
            <p class="text-muted mb-0">Short description of this feature or service.</p>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card bdta-feature-card h-100 text-center">
          <div class="card-body p-4">
            <div class="bdta-feature-icon mb-3">⭐</div>
            <h4 class="mb-2">Feature Two</h4>
            <p class="text-muted mb-0">Short description of this feature or service.</p>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card bdta-feature-card h-100 text-center">
          <div class="card-body p-4">
            <div class="bdta-feature-icon mb-3">❤️</div>
            <h4 class="mb-2">Feature Three</h4>
            <p class="text-muted mb-0">Short description of this feature or service.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>`
    });

    bm.add('bdta-testimonial', {
        label: 'Testimonial',
        category: 'BDTA',
        content: `
<section class="py-4">
  <div class="container">
    <blockquote class="bdta-testimonial bg-white p-4 p-md-5 mb-0">
      <p class="fs-5 fst-italic mb-3">"This training academy completely transformed our dog's behaviour. Highly recommended!"</p>
      <cite class="fw-semibold">— Happy Client, Golden Retriever Owner</cite>
    </blockquote>
  </div>
</section>`
    });

    bm.add('bdta-contact', {
        label: 'Contact Bar',
        category: 'BDTA',
        content: `
<div class="bdta-contact-bar text-white py-3">
  <div class="container">
    <div class="d-flex flex-column flex-md-row justify-content-center align-items-center gap-2 text-center small">
      <span><i class="fas fa-location-dot me-1" aria-hidden="true"></i>Highlands County, FL</span>
      <span class="d-none d-md-inline text-white-50">|</span>
      <span><i class="fas fa-envelope me-1" aria-hidden="true"></i>bookings@brooksdogtrainingacademy.com</span>
      <span class="d-none d-md-inline text-white-50">|</span>
      <div class="d-flex gap-2">
        <a href="https://www.facebook.com/BrooksDogTrainingAcademy" class="bdta-contact-link" target="_blank" rel="noopener noreferrer" aria-label="Visit us on Facebook (opens in new tab)">Facebook</a>
        <span class="text-white-50">|</span>
        <a href="https://www.instagram.com/brooksdogtrainingacademy" class="bdta-contact-link" target="_blank" rel="noopener noreferrer" aria-label="Visit us on Instagram (opens in new tab)">Instagram</a>
      </div>
    </div>
  </div>
</div>`
    });

    bm.add('bdta-packages', {
        label: 'Training Packages',
        category: 'BDTA',
        content: `
<section class="bdta-packages-module py-5">
  <div class="container py-5">
    <div class="text-center mb-5">
      <h2 class="display-5 fw-bold mb-3">Our Training Packages</h2>
      <p class="lead text-muted">Bundled training programs designed to set your dog up for success</p>
    </div>
    <div class="bdta-packages-grid row g-4">
      <div class="bdta-packages-loading col-12 text-center py-5">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Loading packages…</span>
        </div>
        <p class="text-muted mt-3">Loading packages…</p>
      </div>
    </div>
    <div class="bdta-packages-empty text-center py-5 d-none">
      <i class="fas fa-box-open display-4 text-muted mb-3"></i>
      <p class="lead text-muted">No packages are currently available. Check back soon!</p>
      <a href="#contact" class="btn btn-outline-primary">Contact Us</a>
    </div>
  </div>
</section>`
    });

    bm.add('bdta-events', {
        label: 'Group Events & Workshops',
        category: 'BDTA',
        content: `
<section class="bdta-events-module py-5 bg-light">
  <div class="container py-5">
    <div class="text-center mb-5">
      <h2 class="display-5 fw-bold mb-3">Group Events &amp; Workshops</h2>
      <p class="lead text-muted">Join our upcoming in-person workshops and community events</p>
    </div>
    <div class="bdta-events-grid row g-4">
      <div class="bdta-events-loading col-12 text-center py-5">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Loading events…</span>
        </div>
        <p class="text-muted mt-3">Loading events…</p>
      </div>
    </div>
    <div class="bdta-events-empty text-center py-5 d-none">
      <i class="fas fa-calendar-xmark display-4 text-muted mb-3"></i>
      <p class="lead text-muted">No upcoming events are scheduled right now. Follow us on social media for announcements!</p>
    </div>
  </div>
</section>`
    });

    // ------------------------------------------------------------------
    // Save helper
    // ------------------------------------------------------------------
    async function savePage(publishFlag) {
        const html = editor.getHtml();
        const css  = editor.getCss();
        const payload = { id: PAGE_ID, html_content: html, css_content: css };
        if (publishFlag !== undefined) payload.is_published = publishFlag ? 1 : 0;

        try {
            const res  = await fetch('site_pages_api.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                if (publishFlag !== undefined) {
                    isPublished = !!publishFlag;
                    updatePublishUI();
                }
                toast(publishFlag ? 'Page published!' : (publishFlag === false ? 'Page unpublished.' : 'Saved.'), 'success');
            } else {
                toast('Save failed: ' + (data.error || 'Unknown error'), 'error');
            }
        } catch (e) {
            toast('Save request failed.', 'error');
        }
    }

    function updatePublishUI() {
        const badge = document.getElementById('status-badge');
        const btn   = document.getElementById('btn-publish');
        if (isPublished) {
            badge.className = 'status-badge published';
            badge.innerHTML = '&#9679; Published';
            btn.className   = 'btn-topbar btn-topbar-icon btn-unpublish';
            btn.innerHTML   = '<i class="fas fa-eye-slash"></i>';
            btn.title       = 'Unpublish page';
            btn.setAttribute('aria-label', 'Unpublish page');
        } else {
            badge.className = 'status-badge';
            badge.innerHTML = '&#9675; Draft';
            btn.className   = 'btn-topbar btn-topbar-icon btn-publish';
            btn.innerHTML   = '<i class="fas fa-rocket"></i>';
            btn.title       = 'Save and publish';
            btn.setAttribute('aria-label', 'Save and publish');
        }
    }

    // ------------------------------------------------------------------
    // Top-bar button wiring
    // ------------------------------------------------------------------
    document.getElementById('btn-save').addEventListener('click', function () { savePage(); });

    document.getElementById('btn-publish').addEventListener('click', function () {
        savePage(isPublished ? false : true);
    });

    document.getElementById('btn-undo').addEventListener('click', function () { editor.UndoManager.undo(); });
    document.getElementById('btn-redo').addEventListener('click', function () { editor.UndoManager.redo(); });
    document.getElementById('btn-html').addEventListener('click', function () { openHtmlModal(); });

    let previewing = false;
    document.getElementById('btn-preview').addEventListener('click', function () {
        previewing = !previewing;
        if (previewing) {
            editor.runCommand('core:preview');
        } else {
            // stopCommand correctly exits preview mode and restores all panels/content.
            // Never use 'core:canvas-clear' here — that destroys the component tree.
            editor.stopCommand('core:preview');
        }
        this.innerHTML = previewing
            ? '<i class="fas fa-edit"></i>'
            : '<i class="fas fa-eye"></i>';
        this.title = previewing ? 'Exit preview' : 'Toggle preview';
        this.setAttribute('aria-label', previewing ? 'Exit preview' : 'Toggle preview');
    });

    // ------------------------------------------------------------------
    // Inline page rename
    // ------------------------------------------------------------------
    const titleDisplay = document.getElementById('page-title-display');
    const titleInput   = document.getElementById('page-title-input');
    let originalTitle  = titleDisplay.textContent;

    titleDisplay.addEventListener('click', function () {
        originalTitle = titleDisplay.textContent;
        titleDisplay.classList.add('d-none');
        titleInput.classList.remove('d-none');
        titleInput.focus();
        titleInput.select();
    });

    async function commitRename() {
        const newTitle = titleInput.value.trim();
        titleDisplay.textContent = newTitle || originalTitle;
        titleDisplay.classList.remove('d-none');
        titleInput.classList.add('d-none');
        if (!newTitle || newTitle === originalTitle) return;
        originalTitle = newTitle;

        try {
            const res  = await fetch('site_pages_api.php?action=rename', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: PAGE_ID, title: newTitle })
            });
            const data = await res.json();
            if (!data.success) toast('Rename failed.', 'error');
        } catch (e) {
            toast('Rename request failed.', 'error');
        }
    }

    titleInput.addEventListener('blur',  commitRename);
    titleInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') titleInput.blur();
        if (e.key === 'Escape') {
            titleInput.value = originalTitle;
            titleInput.blur();
        }
    });

    // ------------------------------------------------------------------
    // Keyboard shortcuts
    // ------------------------------------------------------------------
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            savePage();
        }
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'P') {
            e.preventDefault();
            savePage(isPublished ? false : true);
        }
        if ((e.ctrlKey || e.metaKey) && e.key === 'z') { editor.UndoManager.undo(); }
        if ((e.ctrlKey || e.metaKey) && e.key === 'y') { editor.UndoManager.redo(); }
    });

    // ------------------------------------------------------------------
    // HTML / CSS modal
    // ------------------------------------------------------------------
    const htmlModalEl = document.getElementById('htmlModal');
    const htmlModal   = new bootstrap.Modal(htmlModalEl);
    const htmlInput   = document.getElementById('html-code-input');
    const cssInput    = document.getElementById('css-code-input');

    function openHtmlModal() {
        htmlInput.value = editor.getHtml();
        cssInput.value  = editor.getCss();
        htmlModal.show();
    }

    document.getElementById('btn-apply-html').addEventListener('click', function () {
        try {
            editor.setComponents(htmlInput.value || '');
            editor.setStyle(cssInput.value || '');
            htmlModal.hide();
            toast('HTML/CSS updated. Save to persist changes.', 'success');
        } catch (e) {
            toast('Failed to apply HTML/CSS.', 'error');
        }
    });

}());
</script>
<?php
require_once '../backend/includes/tawk_to.php';
bdta_render_tawk_to_widget();
?>
</body>
</html>
