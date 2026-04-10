<?php

require_once __DIR__ . '/invoice_status.php';

/**
 * @return array<string, mixed>
 */
function bdta_notification_normalize_row(array $row): array {
    $notification_id = $row['id'] ?? null;
    $persistent_id = is_numeric($notification_id) ? (int) $notification_id : null;
    $title = trim((string) ($row['title'] ?? 'Notification'));
    $message = trim((string) ($row['message'] ?? ''));
    $created_at = trim((string) ($row['created_at'] ?? ''));
    $url = bdta_notification_sanitize_path((string) ($row['url'] ?? ''), '#');
    $is_read = !empty($row['is_read']);

    return [
        'id' => $persistent_id !== null ? (string) $persistent_id : $title . '-' . md5($message . $created_at . $url),
        'persistent_id' => $persistent_id,
        'entity_type' => trim((string) ($row['entity_type'] ?? 'notification')),
        'entity_id' => (int) ($row['entity_id'] ?? 0),
        'title' => $title !== '' ? $title : 'Notification',
        'message' => $message,
        'url' => $url,
        'created_at' => $created_at,
        'is_read' => $is_read,
        'can_mark_read' => $persistent_id !== null && !$is_read,
        'can_delete' => $persistent_id !== null,
        'is_sticky' => false,
    ];
}

function bdta_notification_sanitize_path(string $path, string $default = ''): string {
    $path = trim($path);
    if ($path === '') {
        return $default;
    }

    $parts = parse_url($path);
    if ($parts === false) {
        return $default;
    }

    if (isset($parts['scheme']) || isset($parts['host'])) {
        return $default;
    }

    $normalized_path = $parts['path'] ?? '';
    if ($normalized_path === '') {
        return $default;
    }

    if ($normalized_path[0] !== '/') {
        $normalized_path = '/' . ltrim($normalized_path, '/');
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

    return $normalized_path . $query . $fragment;
}

function bdta_notification_escape(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bdta_notification_format_time(string $created_at): string {
    if ($created_at === '') {
        return '';
    }

    if (function_exists('formatDateTime')) {
        return (string) formatDateTime($created_at, 'M j, Y g:i A');
    }

    $timestamp = strtotime($created_at);
    if ($timestamp === false) {
        return $created_at;
    }

    return date('M j, Y g:i A', $timestamp);
}

function bdta_notification_current_path(string $fallback): string {
    $request_uri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
    return bdta_notification_sanitize_path($request_uri, $fallback);
}

function bdta_create_notification(
    PDO $conn,
    string $audience,
    int $recipient_id,
    string $entity_type,
    int $entity_id,
    string $title,
    string $message,
    string $url
): void {
    if ($recipient_id <= 0) {
        return;
    }

    $audience = strtolower(trim($audience));
    if (!in_array($audience, ['admin', 'portal'], true)) {
        return;
    }

    $title = trim($title);
    $message = trim($message);
    $url = bdta_notification_sanitize_path($url);

    if ($title === '' || $url === '') {
        return;
    }

    $existing_stmt = $conn->prepare("
        SELECT id
        FROM notifications
        WHERE audience = ?
          AND recipient_id = ?
          AND entity_type = ?
          AND entity_id = ?
          AND title = ?
          AND message = ?
          AND url = ?
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $existing_stmt->execute([$audience, $recipient_id, $entity_type, $entity_id, $title, $message, $url]);
    if ($existing_stmt->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO notifications
            (audience, recipient_id, entity_type, entity_id, title, message, url, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$audience, $recipient_id, $entity_type, $entity_id, $title, $message, $url]);
}

function bdta_create_admin_notifications(
    PDO $conn,
    string $entity_type,
    int $entity_id,
    string $title,
    string $message,
    string $url
): void {
    $admins = $conn->query("SELECT id FROM admin_users")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($admins as $admin) {
        $admin_id = isset($admin['id']) ? (int) $admin['id'] : 0;
        if ($admin_id <= 0) {
            continue;
        }

        bdta_create_notification($conn, 'admin', $admin_id, $entity_type, $entity_id, $title, $message, $url);
    }
}

function bdta_mark_notification_read(PDO $conn, string $audience, int $recipient_id, int $notification_id): void {
    if ($recipient_id <= 0 || $notification_id <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1,
            read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
        WHERE id = ?
          AND audience = ?
          AND recipient_id = ?
          AND deleted_at IS NULL
    ");
    $stmt->execute([$notification_id, strtolower(trim($audience)), $recipient_id]);
}

function bdta_delete_notification(PDO $conn, string $audience, int $recipient_id, int $notification_id): void {
    if ($recipient_id <= 0 || $notification_id <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        UPDATE notifications
        SET deleted_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND audience = ?
          AND recipient_id = ?
          AND deleted_at IS NULL
    ");
    $stmt->execute([$notification_id, strtolower(trim($audience)), $recipient_id]);
}

/**
 * @return list<array<string, mixed>>
 */
function bdta_get_persistent_notifications(PDO $conn, string $audience, int $recipient_id): array {
    if ($recipient_id <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT id, entity_type, entity_id, title, message, url, is_read, created_at
        FROM notifications
        WHERE audience = ?
          AND recipient_id = ?
          AND deleted_at IS NULL
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute([strtolower(trim($audience)), $recipient_id]);

    $notifications = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $notifications[] = bdta_notification_normalize_row(is_array($row) ? $row : []);
    }

    return $notifications;
}

/**
 * @return list<array<string, mixed>>
 */
function bdta_get_client_sticky_notifications(PDO $conn, int $client_id): array {
    if ($client_id <= 0) {
        return [];
    }

    $notifications = [];

    $invoice_stmt = $conn->prepare("
        SELECT id, invoice_number, status, due_date, total_amount, created_at
        FROM invoices
        WHERE client_id = ?
        ORDER BY created_at DESC, id DESC
    ");
    $invoice_stmt->execute([$client_id]);
    foreach ($invoice_stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        $invoice_id = isset($invoice['id']) ? (int) $invoice['id'] : 0;
        if ($invoice_id <= 0) {
            continue;
        }

        $invoice_status = strtolower(trim((string) ($invoice['status'] ?? 'draft')));
        if ($invoice_status === 'draft' || !bdta_invoice_is_payable(is_array($invoice) ? $invoice : [])) {
            continue;
        }

        $invoice_number = trim((string) ($invoice['invoice_number'] ?? ('#' . $invoice_id)));
        $due_date = trim((string) ($invoice['due_date'] ?? ''));
        $total_amount = isset($invoice['total_amount']) ? (float) $invoice['total_amount'] : 0.0;
        $message = $invoice_number . ' — $' . number_format($total_amount, 2);
        if ($due_date !== '') {
            $message .= ' due ' . $due_date;
        }

        $notifications[] = [
            'id' => 'invoice-' . $invoice_id,
            'persistent_id' => null,
            'entity_type' => 'invoice',
            'entity_id' => $invoice_id,
            'title' => 'Invoice awaiting payment',
            'message' => $message,
            'url' => '/portal/invoice_view.php?id=' . $invoice_id,
            'created_at' => trim((string) ($invoice['created_at'] ?? '')),
            'is_read' => false,
            'can_mark_read' => false,
            'can_delete' => false,
            'is_sticky' => true,
        ];
    }

    $quote_stmt = $conn->prepare("
        SELECT id, quote_number, title, created_at
        FROM quotes
        WHERE client_id = ?
          AND status IN ('sent', 'viewed')
        ORDER BY created_at DESC
    ");
    $quote_stmt->execute([$client_id]);
    foreach ($quote_stmt->fetchAll(PDO::FETCH_ASSOC) as $quote) {
        $quote_id = isset($quote['id']) ? (int) $quote['id'] : 0;
        if ($quote_id <= 0) {
            continue;
        }

        $quote_number = trim((string) ($quote['quote_number'] ?? ('#' . $quote_id)));
        $title = trim((string) ($quote['title'] ?? 'Quote'));
        $notifications[] = [
            'id' => 'quote-' . $quote_id,
            'persistent_id' => null,
            'entity_type' => 'quote',
            'entity_id' => $quote_id,
            'title' => 'Quote awaiting your response',
            'message' => $quote_number . ' — ' . $title,
            'url' => '/backend/public/quote.php?id=' . $quote_id,
            'created_at' => trim((string) ($quote['created_at'] ?? '')),
            'is_read' => false,
            'can_mark_read' => false,
            'can_delete' => false,
            'is_sticky' => true,
        ];
    }

    $contract_stmt = $conn->prepare("
        SELECT id, contract_number, title, access_token, created_at
        FROM contracts
        WHERE client_id = ?
          AND status = 'sent'
        ORDER BY created_at DESC
    ");
    $contract_stmt->execute([$client_id]);
    foreach ($contract_stmt->fetchAll(PDO::FETCH_ASSOC) as $contract) {
        $contract_id = isset($contract['id']) ? (int) $contract['id'] : 0;
        if ($contract_id <= 0) {
            continue;
        }

        $contract_number = trim((string) ($contract['contract_number'] ?? ('#' . $contract_id)));
        $title = trim((string) ($contract['title'] ?? 'Contract'));
        $access_token = trim((string) ($contract['access_token'] ?? ''));
        $contract_url = $access_token !== ''
            ? '/backend/public/contract.php?token=' . rawurlencode($access_token)
            : '/backend/public/contract.php?id=' . $contract_id;

        $notifications[] = [
            'id' => 'contract-' . $contract_id,
            'persistent_id' => null,
            'entity_type' => 'contract',
            'entity_id' => $contract_id,
            'title' => 'Contract awaiting your signature',
            'message' => $contract_number . ' — ' . $title,
            'url' => $contract_url,
            'created_at' => trim((string) ($contract['created_at'] ?? '')),
            'is_read' => false,
            'can_mark_read' => false,
            'can_delete' => false,
            'is_sticky' => true,
        ];
    }

    return $notifications;
}

/**
 * @return list<array<string, mixed>>
 */
function bdta_get_notifications(PDO $conn, string $audience, int $recipient_id, int $limit = 15): array {
    $notifications = bdta_get_persistent_notifications($conn, $audience, $recipient_id);
    if (strtolower(trim($audience)) === 'portal') {
        $notifications = array_merge($notifications, bdta_get_client_sticky_notifications($conn, $recipient_id));
    }

    usort($notifications, static function (array $left, array $right): int {
        $left_time = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
        $right_time = strtotime((string) ($right['created_at'] ?? '')) ?: 0;
        if ($left_time === $right_time) {
            return strcmp((string) ($right['id'] ?? ''), (string) ($left['id'] ?? ''));
        }

        return $right_time <=> $left_time;
    });

    return array_slice($notifications, 0, max(1, $limit));
}

function bdta_get_unread_notification_count(PDO $conn, string $audience, int $recipient_id): int {
    if ($recipient_id <= 0) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE audience = ?
          AND recipient_id = ?
          AND deleted_at IS NULL
          AND is_read = 0
    ");
    $stmt->execute([strtolower(trim($audience)), $recipient_id]);
    $count = (int) $stmt->fetchColumn();

    if (strtolower(trim($audience)) === 'portal') {
        $count += count(bdta_get_client_sticky_notifications($conn, $recipient_id));
    }

    return $count;
}

/**
 * @return array<string, mixed>|null
 */
function bdta_get_notification_by_id(PDO $conn, string $audience, int $recipient_id, int $notification_id): ?array {
    if ($recipient_id <= 0 || $notification_id <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT id, entity_type, entity_id, title, message, url, is_read, created_at
        FROM notifications
        WHERE id = ?
          AND audience = ?
          AND recipient_id = ?
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$notification_id, strtolower(trim($audience)), $recipient_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return bdta_notification_normalize_row($row);
}

function bdta_render_notification_widget(
    PDO $conn,
    string $audience,
    int $recipient_id,
    string $action_url,
    string $redirect_url,
    string $fallback_return_to
): void {
    if ($recipient_id <= 0) {
        return;
    }

    $audience = strtolower(trim($audience));
    $widget_id = $audience === 'portal' ? 'portal' : 'admin';
    $notifications = bdta_get_notifications($conn, $audience, $recipient_id, 15);
    $unread_count = bdta_get_unread_notification_count($conn, $audience, $recipient_id);
    $current_path = bdta_notification_current_path($fallback_return_to);
    $csrf = function_exists('csrfToken') ? (string) csrfToken() : '';
    ?>
    <div class="app-notification-widget" data-notification-widget="<?php echo bdta_notification_escape($widget_id); ?>">
        <button
            type="button"
            class="btn btn-light app-notification-toggle"
            data-notification-toggle
            aria-expanded="false"
            aria-controls="appNotificationPanel-<?php echo bdta_notification_escape($widget_id); ?>"
        >
            <i class="fas fa-bell" aria-hidden="true"></i>
            <span class="visually-hidden">Toggle notifications</span>
            <?php if ($unread_count > 0): ?>
                <span class="badge bg-danger app-notification-count"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </button>

        <div class="app-notification-backdrop" data-notification-close></div>
        <aside
            id="appNotificationPanel-<?php echo bdta_notification_escape($widget_id); ?>"
            class="app-notification-panel"
            aria-hidden="true"
        >
            <div class="app-notification-panel__header">
                <div>
                    <h2 class="app-notification-panel__title">Notifications</h2>
                    <p class="app-notification-panel__subtitle mb-0"><?php echo $unread_count; ?> unread</p>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-notification-close aria-label="Close notifications">
                    <i class="fas fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <div class="app-notification-panel__body">
                <?php if ($notifications === []): ?>
                    <div class="app-notification-empty">
                        <i class="fas fa-bell-slash mb-2" aria-hidden="true"></i>
                        <p class="mb-0">No notifications right now.</p>
                    </div>
                <?php else: ?>
                    <ul class="app-notification-list list-unstyled mb-0">
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            $persistent_id = $notification['persistent_id'];
                            $item_url = $persistent_id !== null
                                ? $redirect_url . '?id=' . rawurlencode((string) $persistent_id)
                                : (string) ($notification['url'] ?? '#');
                            $item_url = bdta_notification_sanitize_path($item_url, '#');
                            $is_unread = empty($notification['is_read']);
                            ?>
                            <li class="app-notification-item<?php echo $is_unread ? ' is-unread' : ''; ?>">
                                <a href="<?php echo bdta_notification_escape($item_url); ?>" class="app-notification-item__link">
                                    <div class="app-notification-item__meta">
                                        <span class="app-notification-item__status badge <?php echo $is_unread ? 'bg-primary' : 'bg-secondary'; ?>">
                                            <?php echo $is_unread ? 'Unread' : 'Read'; ?>
                                        </span>
                                        <?php if (!empty($notification['created_at'])): ?>
                                            <time class="app-notification-item__time"><?php echo bdta_notification_escape(bdta_notification_format_time((string) $notification['created_at'])); ?></time>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="app-notification-item__title"><?php echo bdta_notification_escape($notification['title'] ?? 'Notification'); ?></h3>
                                    <?php if (!empty($notification['message'])): ?>
                                        <p class="app-notification-item__message mb-0"><?php echo bdta_notification_escape($notification['message']); ?></p>
                                    <?php endif; ?>
                                </a>

                                <?php if (!empty($notification['can_mark_read']) || !empty($notification['can_delete'])): ?>
                                    <div class="app-notification-item__actions">
                                        <?php if (!empty($notification['can_mark_read']) && $persistent_id !== null): ?>
                                            <form method="post" action="<?php echo bdta_notification_escape($action_url); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo bdta_notification_escape($csrf); ?>">
                                                <input type="hidden" name="notification_id" value="<?php echo $persistent_id; ?>">
                                                <input type="hidden" name="action" value="read">
                                                <input type="hidden" name="return_to" value="<?php echo bdta_notification_escape($current_path); ?>">
                                                <button type="submit" class="btn btn-outline-primary btn-sm">Mark read</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (!empty($notification['can_delete']) && $persistent_id !== null): ?>
                                            <form method="post" action="<?php echo bdta_notification_escape($action_url); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo bdta_notification_escape($csrf); ?>">
                                                <input type="hidden" name="notification_id" value="<?php echo $persistent_id; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="return_to" value="<?php echo bdta_notification_escape($current_path); ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <script>
    (function () {
        const widget = document.querySelector('[data-notification-widget="<?php echo bdta_notification_escape($widget_id); ?>"]');
        if (!widget || widget.dataset.notificationReady === 'true') {
            return;
        }

        widget.dataset.notificationReady = 'true';

        const toggle = widget.querySelector('[data-notification-toggle]');
        const panel = widget.querySelector('.app-notification-panel');
        const closeButtons = widget.querySelectorAll('[data-notification-close]');

        if (!toggle || !panel) {
            return;
        }

        const setOpenState = function (isOpen) {
            widget.classList.toggle('is-open', isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            document.body.classList.toggle('app-notification-panel-open', isOpen);
        };

        toggle.addEventListener('click', function () {
            setOpenState(!widget.classList.contains('is-open'));
        });

        closeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setOpenState(false);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && widget.classList.contains('is-open')) {
                setOpenState(false);
            }
        });
    })();
    </script>
    <?php
}
