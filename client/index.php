<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/invoice_status.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$fetch_count = static function (PDOStatement $stmt, array $params = []): int {
    $stmt->execute($params);
    return safe_int($stmt->fetchColumn());
};

$fetch_sum = static function (PDOStatement $stmt, array $params = []): float {
    $stmt->execute($params);
    return safe_float($stmt->fetchColumn());
};

$fetch_rows = static function (PDOStatement $stmt, array $params = []): array {
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$currency_symbol = '$';
$format_money = static function (float $amount) use ($currency_symbol): string {
    return $currency_symbol . number_format($amount, 2);
};
$format_amount_detail = static function (string $primary, float $amount) use ($format_money): string {
    return $primary . ' · ' . $format_money($amount);
};
$date_only_pattern = '/^\d{4}-\d{2}-\d{2}$/';

$now = new DateTimeImmutable();
$now_sql = $now->format('Y-m-d H:i:s');
$thirty_days_ago = $now->sub(new DateInterval('P30D'));
$thirty_days_ago_sql = $thirty_days_ago->format('Y-m-d H:i:s');
$thirty_days_ago_date = $thirty_days_ago->format('Y-m-d');
$ninety_days_ago = $now->sub(new DateInterval('P90D'));
$ninety_days_ago_sql = $ninety_days_ago->format('Y-m-d H:i:s');
$ninety_days_ago_date = $ninety_days_ago->format('Y-m-d');

// Dashboard totals
$total_posts = $fetch_count($conn->prepare("SELECT COUNT(*) FROM blog_posts"));
$published_posts = $fetch_count($conn->prepare("SELECT COUNT(*) FROM blog_posts WHERE published = 1 AND publish_date <= ?"), [$now_sql]);
$total_bookings = $fetch_count($conn->prepare("SELECT COUNT(*) FROM bookings"));
$pending_bookings = $fetch_count($conn->prepare("SELECT COUNT(*) FROM bookings WHERE status = 'pending'"));
$total_form_submissions = $fetch_count($conn->prepare("SELECT COUNT(*) FROM form_submissions"));
$forms_last_30_days = $fetch_count($conn->prepare("SELECT COUNT(*) FROM form_submissions WHERE submitted_at >= ?"), [$thirty_days_ago_sql]);
$appointments_last_30_days = $fetch_count($conn->prepare("SELECT COUNT(*) FROM bookings WHERE created_at >= ?"), [$thirty_days_ago_sql]);
$quotes_accepted_last_30_days = $fetch_count($conn->prepare("SELECT COUNT(*) FROM quotes WHERE status = 'accepted' AND accepted_at IS NOT NULL AND accepted_at >= ?"), [$thirty_days_ago_sql]);
$contracts_signed_last_30_days = $fetch_count($conn->prepare("SELECT COUNT(*) FROM contracts WHERE status = 'signed' AND signed_date IS NOT NULL AND signed_date >= ?"), [$thirty_days_ago_date]);
$income_events_last_30_days = bdta_invoice_get_income_events($conn, $thirty_days_ago_date, $now->format('Y-m-d'));
$income_last_30_days = 0.0;
$invoice_ids_with_payments_last_30_days = [];
foreach ($income_events_last_30_days as $income_event) {
    $income_last_30_days += $income_event['amount'];
    $invoice_ids_with_payments_last_30_days[$income_event['invoice_id']] = true;
}
$paid_invoices_last_30_days = count(array_filter(array_keys($invoice_ids_with_payments_last_30_days), static fn (int $invoice_id): bool => $invoice_id > 0));

$dashboard_cards = [
    [
        'href' => 'blog_list.php',
        'icon' => 'fa-file-lines',
        'icon_color' => 'text-primary',
        'value' => (string) $total_posts,
        'label' => 'Blog Posts',
        'meta' => $published_posts . ' published',
        'meta_class' => 'text-success-emphasis',
    ],
    [
        'href' => 'bookings_list.php',
        'icon' => 'fa-calendar-check',
        'icon_color' => 'text-success',
        'value' => (string) $total_bookings,
        'label' => 'Bookings',
        'meta' => $pending_bookings . ' pending',
        'meta_class' => 'text-warning-emphasis',
    ],
    [
        'href' => 'form_submissions_list.php',
        'icon' => 'fa-file-circle-check',
        'icon_color' => 'text-info',
        'value' => (string) $total_form_submissions,
        'label' => 'Form Submissions',
        'meta' => $forms_last_30_days . ' in the last 30 days',
        'meta_class' => 'text-info-emphasis',
    ],
    [
        'href' => 'invoices_list.php',
        'icon' => 'fa-money-bill-wave',
        'icon_color' => 'text-warning',
        'value' => (string) $format_money($income_last_30_days),
        'label' => 'Income (30 Days)',
        'meta' => $paid_invoices_last_30_days . ' invoices with payments',
        'meta_class' => 'text-warning-emphasis',
    ],
];

$quick_stats = [
    [
        'href' => 'bookings_list.php',
        'label' => 'Appointments booked',
        'value' => $appointments_last_30_days,
        'meta' => 'Last 30 days',
        'icon' => 'fa-calendar-day',
    ],
    [
        'href' => 'form_submissions_list.php',
        'label' => 'Forms completed',
        'value' => $forms_last_30_days,
        'meta' => 'Last 30 days',
        'icon' => 'fa-list-check',
    ],
    [
        'href' => 'quotes_list.php?status=accepted',
        'label' => 'Quotes accepted',
        'value' => $quotes_accepted_last_30_days,
        'meta' => 'Last 30 days',
        'icon' => 'fa-file-signature',
    ],
    [
        'href' => 'contracts_list.php',
        'label' => 'Contracts signed',
        'value' => $contracts_signed_last_30_days,
        'meta' => 'Last 30 days',
        'icon' => 'fa-file-contract',
    ],
];

$recent_actions = [];

foreach ($fetch_rows($conn->prepare("
    SELECT *
    FROM (
        SELECT
            b.created_at AS action_at,
            'booking' AS action_type,
            b.id AS record_id,
            b.client_name AS subject_name,
            b.service_type AS detail_primary,
            b.appointment_date AS detail_date,
            b.appointment_time AS detail_time,
            NULL AS amount_value
        FROM bookings b
        WHERE b.created_at >= ?

        UNION ALL

        SELECT
            fs.submitted_at AS action_at,
            'form' AS action_type,
            fs.id AS record_id,
            COALESCE(c.name, 'Client') AS subject_name,
            COALESCE(ft.name, 'Form submission') AS detail_primary,
            NULL AS detail_date,
            NULL AS detail_time,
            NULL AS amount_value
        FROM form_submissions fs
        LEFT JOIN clients c ON fs.client_id = c.id
        LEFT JOIN form_templates ft ON fs.template_id = ft.id
        WHERE fs.submitted_at >= ?

        UNION ALL

        SELECT
            q.accepted_at AS action_at,
            'quote' AS action_type,
            q.id AS record_id,
            c.name AS subject_name,
            q.quote_number AS detail_primary,
            NULL AS detail_date,
            NULL AS detail_time,
            q.amount AS amount_value
        FROM quotes q
        INNER JOIN clients c ON q.client_id = c.id
        WHERE q.status = 'accepted' AND q.accepted_at IS NOT NULL AND q.accepted_at >= ?

        UNION ALL

        SELECT
            co.signed_date AS action_at,
            'contract' AS action_type,
            co.id AS record_id,
            c.name AS subject_name,
            co.title AS detail_primary,
            NULL AS detail_date,
            NULL AS detail_time,
            NULL AS amount_value
        FROM contracts co
        INNER JOIN clients c ON co.client_id = c.id
        WHERE co.status = 'signed' AND co.signed_date IS NOT NULL AND co.signed_date >= ?

        UNION ALL

        SELECT
            ip.payment_date AS action_at,
            'invoice_payment' AS action_type,
            i.id AS record_id,
            c.name AS subject_name,
            i.invoice_number AS detail_primary,
            NULL AS detail_date,
            NULL AS detail_time,
            ip.amount AS amount_value
        FROM invoice_payments ip
        INNER JOIN invoices i ON ip.invoice_id = i.id
        INNER JOIN clients c ON i.client_id = c.id
        WHERE TRIM(COALESCE(ip.payment_date, '')) <> '' AND ip.payment_date >= ?

        UNION ALL

        SELECT
            ii.payment_date AS action_at,
            'invoice_payment' AS action_type,
            i.id AS record_id,
            c.name AS subject_name,
            i.invoice_number AS detail_primary,
            NULL AS detail_date,
            NULL AS detail_time,
            ii.amount AS amount_value
        FROM invoice_installments ii
        INNER JOIN invoices i ON ii.invoice_id = i.id
        INNER JOIN clients c ON i.client_id = c.id
        WHERE ii.status = 'paid'
          AND TRIM(COALESCE(ii.payment_date, '')) <> ''
          AND ii.payment_date >= ?

        UNION ALL

        SELECT
            i.payment_date AS action_at,
            'invoice_payment' AS action_type,
            i.id AS record_id,
            c.name AS subject_name,
            i.invoice_number AS detail_primary,
            NULL AS detail_date,
            NULL AS detail_time,
            i.total_amount AS amount_value
        FROM invoices i
        INNER JOIN clients c ON i.client_id = c.id
        WHERE TRIM(COALESCE(i.payment_date, '')) <> ''
          AND i.payment_date >= ?
          AND i.status NOT IN ('draft', 'sent', 'overdue', 'cancelled', 'void')
          AND NOT EXISTS (
              SELECT 1
              FROM invoice_payments ip
              WHERE ip.invoice_id = i.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM invoice_installments ii
              WHERE ii.invoice_id = i.id
                AND ii.status = 'paid'
                AND TRIM(COALESCE(ii.payment_date, '')) <> ''
          )
    ) dashboard_recent_activity
    WHERE action_at IS NOT NULL AND action_at <> ''
    ORDER BY action_at DESC
    LIMIT 12
"), [$ninety_days_ago_sql, $ninety_days_ago_sql, $ninety_days_ago_sql, $ninety_days_ago_date, $ninety_days_ago_date, $ninety_days_ago_date, $ninety_days_ago_date]) as $row) {
    $action_type = scalar_string($row['action_type'] ?? '');
    $record_id = safe_int($row['record_id'] ?? 0);
    $detail_primary = scalar_string($row['detail_primary'] ?? '');
    $detail_date = scalar_string($row['detail_date'] ?? '');
    $detail_time = scalar_string($row['detail_time'] ?? '');
    $amount_value = safe_float($row['amount_value'] ?? 0);

    $label = 'Recent activity';
    $details = $detail_primary;
    $href = '#';
    $badge_class = 'bg-secondary-subtle text-secondary-emphasis';

    if ($action_type === 'booking') {
        $label = 'Appointment booked';
        $details = trim($detail_primary . ' · ' . $detail_date . ' ' . $detail_time);
        $href = 'bookings_list.php';
        $badge_class = 'bg-primary-subtle text-primary-emphasis';
    } elseif ($action_type === 'form') {
        $label = 'Form completed';
        $href = 'form_submissions_view.php?id=' . $record_id;
        $badge_class = 'bg-info-subtle text-info-emphasis';
    } elseif ($action_type === 'quote') {
        $label = 'Quote accepted';
        $details = $format_amount_detail($detail_primary, $amount_value);
        $href = 'quotes_view.php?id=' . $record_id;
        $badge_class = 'bg-success-subtle text-success-emphasis';
    } elseif ($action_type === 'contract') {
        $label = 'Contract signed';
        $href = 'contracts_view.php?id=' . $record_id;
        $badge_class = 'bg-warning-subtle text-warning-emphasis';
    } elseif ($action_type === 'invoice_payment') {
        $label = 'Invoice payment received';
        $details = $format_amount_detail($detail_primary, $amount_value);
        $href = 'invoices_view.php?id=' . $record_id;
        $badge_class = 'bg-secondary-subtle text-secondary-emphasis';
    }

    $recent_actions[] = [
        'action_at' => scalar_string($row['action_at'] ?? ''),
        'label' => $label,
        'subject' => scalar_string($row['subject_name'] ?? ''),
        'details' => $details,
        'href' => $href,
        'badge_class' => $badge_class,
    ];
}

$format_action_timestamp = static function (string $value) use ($date_only_pattern): string {
    if ($value === '') {
        return '';
    }

    $parsed_timestamp = strtotime($value);
    if ($parsed_timestamp === false || $parsed_timestamp === -1) {
        return $value;
    }

    if (preg_match($date_only_pattern, $value) === 1) {
        return formatDate($value);
    }

    return formatDateTime($value);
};

$page_title = 'Dashboard';
require_once '../backend/includes/header.php';
?>

<style>
    .dashboard-link {
        color: inherit;
        display: block;
        text-decoration: none;
    }

    .dashboard-link .card,
    .dashboard-activity-card {
        border: 0;
        box-shadow: 0 0.5rem 1.5rem rgba(15, 23, 42, 0.08);
    }

    .dashboard-link .card {
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .dashboard-link:hover .card,
    .dashboard-link:focus .card {
        box-shadow: 0 0.75rem 1.75rem rgba(15, 23, 42, 0.14);
        transform: translateY(-2px);
    }

    .dashboard-stat-value {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.1;
    }

    .dashboard-quick-stat .card-body {
        min-height: 100%;
    }

    .dashboard-activity-table {
        margin-bottom: 0;
    }
</style>

<div class="py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-gauge me-2"></i>Dashboard</h2>
            <p class="text-muted mb-0">A quick view of recent activity and the parts of the admin portal that need attention.</p>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <?php foreach ($dashboard_cards as $card): ?>
            <div class="col-md-6 col-xl-3">
                <a href="<?php echo escape($card['href']); ?>" class="dashboard-link">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="dashboard-stat-value"><?php echo escape((string) $card['value']); ?></div>
                                    <p class="text-muted mb-1"><?php echo escape($card['label']); ?></p>
                                    <small class="<?php echo escape($card['meta_class']); ?>"><?php echo escape($card['meta']); ?></small>
                                </div>
                                <i class="fas <?php echo escape($card['icon']); ?> fs-2 <?php echo escape($card['icon_color']); ?>"></i>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0 pt-0">
                            <span class="small fw-semibold">Open <?php echo escape($card['label']); ?> &rarr;</span>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">30-Day Snapshot</h4>
        <span class="text-muted small">Recent trends at a glance</span>
    </div>
    <div class="row g-3 mb-4">
        <?php foreach ($quick_stats as $stat): ?>
            <div class="col-sm-6 col-xl-3 dashboard-quick-stat">
                <a href="<?php echo escape($stat['href']); ?>" class="dashboard-link">
                    <div class="card h-100">
                        <div class="card-body d-flex justify-content-between align-items-center gap-3">
                            <div>
                                <div class="h3 mb-1"><?php echo escape((string) $stat['value']); ?></div>
                                <div class="fw-semibold"><?php echo escape($stat['label']); ?></div>
                                <small class="text-muted"><?php echo escape($stat['meta']); ?></small>
                            </div>
                            <i class="fas <?php echo escape($stat['icon']); ?> fs-3 text-muted"></i>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Recent Activity</h4>
        <div class="d-flex gap-2 flex-wrap">
            <a href="bookings_list.php" class="btn btn-sm btn-outline-primary">Bookings</a>
            <a href="form_submissions_list.php" class="btn btn-sm btn-outline-primary">Forms</a>
            <a href="quotes_list.php" class="btn btn-sm btn-outline-primary">Quotes</a>
            <a href="contracts_list.php" class="btn btn-sm btn-outline-primary">Contracts</a>
            <a href="invoices_list.php" class="btn btn-sm btn-outline-primary">Invoices</a>
        </div>
    </div>

    <div class="card dashboard-activity-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover dashboard-activity-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Action</th>
                            <th>Client</th>
                            <th>Details</th>
                            <th class="text-end">Open</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_actions !== []): ?>
                            <?php foreach ($recent_actions as $action): ?>
                                <tr>
                                    <td><?php echo escape($format_action_timestamp($action['action_at'])); ?></td>
                                    <td>
                                        <span class="badge rounded-pill <?php echo escape($action['badge_class']); ?>">
                                            <?php echo escape($action['label']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo escape($action['subject']); ?></td>
                                    <td><?php echo escape($action['details']); ?></td>
                                    <td class="text-end">
                                        <a href="<?php echo escape($action['href']); ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No recent activity to show yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../backend/includes/footer.php'; ?>
