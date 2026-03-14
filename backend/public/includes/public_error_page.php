<?php
/**
 * Shared public-facing error page renderer.
 *
 * @param string $page_title Browser/page title prefix
 * @param string $heading Main heading shown to users
 * @param string $message Supporting error message
 * @param int    $status HTTP status code to send
 * @param string $action_href Link target for recovery action
 * @param string $action_label Link button label
 */
function renderPublicErrorPage(
    string $page_title,
    string $heading,
    string $message,
    int $status = 404,
    string $action_href = '/',
    string $action_label = 'Go Home'
): void {
    http_response_code($status);
    require __DIR__ . '/public_head.php';
    ?>
    <body class="bg-body-tertiary">
        <a href="#main-content" class="visually-hidden-focusable position-absolute top-0 start-0 m-3 px-3 py-2 bg-white text-dark rounded shadow-sm">
            Skip to main content
        </a>
        <main id="main-content" class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-6 col-md-8">
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-4 p-md-5 text-center">
                            <div class="display-4 text-primary mb-3">
                                <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                            </div>
                            <h1 class="h2 mb-3"><?php echo escape($heading); ?></h1>
                            <p class="text-muted mb-4"><?php echo escape($message); ?></p>
                            <a href="<?php echo escape($action_href); ?>" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-2" aria-hidden="true"></i><?php echo escape($action_label); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    <?php
    require __DIR__ . '/public_footer.php';
    exit;
}
