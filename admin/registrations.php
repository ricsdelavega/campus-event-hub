
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Admins only.');
}

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$event_id = filter_input(
    INPUT_GET,
    'event_id',
    FILTER_VALIDATE_INT
);

if ($event_id !== false && $event_id !== null && $event_id < 1) {
    $event_id = false;
}

if ($event_id === false) {
    http_response_code(400);
    exit('Invalid event ID.');
}

$summary_sql = "
    SELECT
        e.id,
        e.title,
        e.event_date,
        e.venue,
        e.capacity,
        e.status,
        SUM(
            CASE WHEN r.status IN ('registered', 'pending')
THEN 1 ELSE 0 END
        ) AS registered_count,
        SUM(
            CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END
        ) AS pending_count
    FROM events e
    LEFT JOIN registrations r
        ON r.event_id = e.id
    GROUP BY
        e.id,
        e.title,
        e.event_date,
        e.venue,
        e.capacity,
        e.status
    ORDER BY e.event_date ASC
";

$summary_stmt = $pdo->query($summary_sql);
$event_summaries = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "
    SELECT
        r.id AS registration_id,
        r.status AS registration_status,
        r.registered_at,
        r.confirmed_at,
        u.full_name,
        u.email,
        e.id AS event_id,
        e.title AS event_title,
        e.event_date,
        e.capacity,
        e.status AS event_status
    FROM registrations r
    INNER JOIN users u ON u.id = r.user_id
    INNER JOIN events e ON e.id = r.event_id
";

$params = [];

if ($event_id !== null) {
    $sql .= " WHERE e.id = :event_id";
    $params['event_id'] = $event_id;
}

$sql .= " ORDER BY
    CASE r.status
        WHEN 'pending' THEN 0
        WHEN 'registered' THEN 1
        ELSE 2
    END,
    r.registered_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
$safe_csv = static function ($value) {
        $value = (string) $value;

        if (preg_match('/^[\s]*[=+\-@]/', $value)) {
            return "'" . $value;
        }

        return $value;
    };

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="event-attendees.csv"'
    );

    $output = fopen('php://output', 'w');

fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Event',
        'Event Date',
        'Student Name',
        'Email',
        'Registration Status',
        'Registered At',
        'Confirmed At'
    ]);

    foreach ($registrations as $row) {
        fputcsv($output, [
            $safe_csv($row['event_title']),
            $safe_csv($row['event_date']),
            $safe_csv($row['full_name']),
            $safe_csv($row['email']),
            $safe_csv($row['registration_status']),
            $safe_csv($row['registered_at']),
            $safe_csv($row['confirmed_at'] ?? '')
        ]);
    }

    fclose($output);
    exit;
}

$pendingRegistrations = array_values(
    array_filter(
        $registrations,
        fn ($r) => $r['registration_status'] === 'pending'
    )
);

$total_registrations = count($registrations);
$total_events = count($event_summaries);
$total_pending = count($pendingRegistrations);

$total_confirmed = 0;

foreach ($registrations as $row) {
    if ($row['registration_status'] === 'registered') {
        $total_confirmed++;
    }
}

$total_capacity = 0;

foreach ($event_summaries as $summary) {
    $total_capacity += (int) $summary['capacity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Manage Registrations | Admin</title>

    <link rel="stylesheet"
          href="../assets/css/style.css">

    <style>
        body {
            font-family: "Poppins", "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(800px circle at 90% -5%, rgba(63, 193, 137, 0.10), transparent 55%),
                linear-gradient(180deg, var(--green-050) 0%, #f6fcf9 100%);
            background-attachment: fixed;
        }

        ::selection {
            background: var(--green-300);
            color: var(--green-900);
        }

        .navbar {
            background: linear-gradient(90deg, rgba(11, 61, 46, 0.97), rgba(23, 105, 79, 0.97));
            box-shadow: 0 2px 14px rgba(11, 61, 46, 0.35);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar a {
            padding: 8px 12px;
            border-radius: 8px;
            transition: background 0.25s ease;
        }

        .navbar a:hover {
            background: rgba(255, 255, 255, 0.14);
        }

        .navbar .nav-links a {
            position: relative;
            font-weight: 600;
        }

        .navbar .nav-links a::after {
            content: "";
            position: absolute;
            left: 12px;
            right: 12px;
            bottom: 2px;
            height: 2px;
            border-radius: 2px;
            background: linear-gradient(90deg, var(--green-300), var(--green-400));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.25s ease;
        }

        .navbar .nav-links a:hover::after {
            transform: scaleX(1);
        }

        .container {
            max-width: 1400px;
        }

        .admin-page-hero {
            background-image:
                linear-gradient(rgba(9, 46, 34, 0.85), rgba(13, 71, 53, 0.92)),
                url('../assets/images/PTC_background.png');
            background-size: cover;
            background-position: center;
            border-radius: 18px;
            padding: 40px 38px;
            color: white;
            margin-bottom: 28px;
            box-shadow: 0 10px 34px rgba(11, 61, 46, 0.25);
            position: relative;
            overflow: hidden;
        }

        .admin-page-hero::after {
            content: "";
            position: absolute;
            inset: auto -15% -55% -15%;
            height: 65%;
            pointer-events: none;
            background: radial-gradient(ellipse at center, rgba(79, 214, 156, 0.20), transparent 65%);
            animation: pageGlow 6s ease-in-out infinite alternate;
        }

        @keyframes pageGlow {
            from { transform: translateX(-3%) scale(1); opacity: 0.75; }
            to   { transform: translateX(3%) scale(1.06); opacity: 1; }
        }

        .admin-page-hero .admin-kicker {
            display: inline-block;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.28);
            color: #9df3cd;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            padding: 6px 16px;
            border-radius: 999px;
            margin-bottom: 14px;
        }

        .admin-page-hero h1 {
            margin: 0 0 8px;
            font-size: 30px;
            color: #ffffff;
            font-weight: 800;
            text-shadow: 0 3px 12px rgba(0, 0, 0, 0.55);
            letter-spacing: -0.6px;
        }

        .admin-page-hero p {
            margin: 0;
            color: #ffffff;
            font-size: 15px;
            font-weight: 600;
            max-width: 640px;
            line-height: 1.6;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.55);
        }

        .stat-cards {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }

        .stat-card {
            border-radius: 16px;
            padding: 20px;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--green-400), var(--green-600));
        }

        .stat-card.stat-card-pending::before {
            background: linear-gradient(90deg, #fbbf24, #f59e0b);
        }

        .stat-card.stat-card-confirmed::before {
            background: linear-gradient(90deg, #22c55e, #15803d);
        }

        .stat-card:hover {
            transform: translateY(-4px) scale(1.01);
            box-shadow: 0 14px 30px rgba(20, 83, 45, 0.16);
        }

        .stat-card strong {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .stat-card span {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.4px;
            color: var(--text-soft);
        }

        .pending-approvals,
        .event-summary {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 26px rgba(20, 83, 45, 0.08);
            padding: 26px 28px;
            margin-bottom: 26px;
            overflow: hidden;
        }

        .pending-approvals h2,
        .event-summary h2 {
            margin-top: 0;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--green-050);
            color: var(--green-900);
            letter-spacing: -0.2px;
        }


        .event-summary table td:last-child {
            white-space: nowrap;
        }

        .pending-approvals table {
            min-width: 860px;
        }

        .pending-approvals table td:last-child {
            white-space: normal;
            min-width: 250px;
        }


        .pending-approvals table td:last-child .inline-form {
            margin: 0;
        }

        .pending-approvals table td:last-child {
            display: table-cell;
        }

        .pending-approvals .action-cell {
            white-space: nowrap;
        }

        .pending-approvals .btn-approve,
        .pending-approvals .btn-decline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            padding: 0;
            margin: 0 4px 0 0;
            border-radius: 12px;
            font-size: 16px;
            line-height: 1;
            vertical-align: middle;
        }

        .pending-approvals .btn-view {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: auto;
            min-width: 74px;
            height: 38px;
            padding: 0 16px;
            margin: 0;
            border-radius: 12px;
            font-size: 13px;
            vertical-align: middle;
            position: relative;
            overflow: hidden;
        }


        .pending-approvals .btn-approve,
        .pending-approvals .btn-decline,
        .pending-approvals .btn-view {
            position: relative;
            overflow: hidden;
        }

        .pending-approvals .btn-approve::after,
        .pending-approvals .btn-decline::after,
        .pending-approvals .btn-view::after {
            content: "";
            position: absolute;
            top: 0;
            left: -80%;
            width: 45%;
            height: 100%;
            background: linear-gradient(100deg, transparent, rgba(255,255,255,0.55), transparent);
            transform: skewX(-20deg);
            transition: left 0.5s ease;
            pointer-events: none;
        }

        .pending-approvals .btn-approve:hover::after,
        .pending-approvals .btn-decline:hover::after,
        .pending-approvals .btn-view:hover::after {
            left: 130%;
        }

        .pending-approvals .btn-approve:hover,
        .pending-approvals .btn-decline:hover,
        .pending-approvals .btn-view:hover {
            transform: translateY(-2px) scale(1.06);
        }

        .pending-approvals .btn-approve:active,
        .pending-approvals .btn-decline:active,
        .pending-approvals .btn-view:active {
            transform: translateY(0) scale(1);
        }

        .pending-count-pill {
            animation: pillPulse 2s ease-in-out infinite;
        }

        @keyframes pillPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.45); }
            50%      { box-shadow: 0 0 0 7px rgba(245, 158, 11, 0.12); }
        }

        footer {
            margin-top: 50px;
            padding: 30px 20px;
            background: linear-gradient(160deg, var(--green-900), var(--green-800));
            color: #cfeee0;
            border-top: 4px solid var(--green-400);
            text-align: center;
        }

        footer p {
            margin: 4px 0;
        }

        .footer-school {
            color: #ffffff;
            font-weight: 700;
        }

        @media (max-width: 700px) {
            .admin-page-hero {
                padding: 28px 22px;
            }

            .admin-page-hero h1 {
                font-size: 24px;
            }

            .stat-card strong {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <a class="brand" href="index.php">
            <img class="brand-logo"
                 src="../assets/images/logo-ptc.png"
                 alt="PTC Logo">
            <span class="brand-text">
                Pateros Technological College
                <small>Admin Portal</small>
            </span>
        </a>

        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="events.php">Events</a>
            <a href="registrations.php" class="active">Registrations</a>
            <a href="attendees.php">Attendees</a>
            <a href="../index.php">View Website</a>
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

<main class="container">

    <header class="admin-page-hero">
        <span class="admin-kicker">📋 Registration Management</span>
        <h1>
            Hello, <?= e($_SESSION['user_name'] ?? 'Admin') ?>!
        </h1>
        <p>
            Review and approve student registrations, monitor event
            capacity, and keep the campus events running smoothly.
        </p>
    </header>

    <?php if (!empty($_SESSION['admin_message'])): ?>
        <div class="message success">
            <?= e($_SESSION['admin_message']) ?>
        </div>
        <?php unset($_SESSION['admin_message']); ?>
    <?php endif; ?>

    <div class="stat-cards">
        <div class="stat-card stat-card-pending">
            <strong><?= $total_pending ?></strong>
            <span>⏳ Pending Approvals</span>
        </div>

        <div class="stat-card stat-card-confirmed">
            <strong><?= $total_confirmed ?></strong>
            <span>✅ Confirmed</span>
        </div>

        <div class="stat-card">
            <strong><?= $total_registrations ?></strong>
            <span>📋 Total Registrations</span>
        </div>

        <div class="stat-card">
            <strong><?= $total_capacity ?></strong>
            <span>🎟️ Total Slots</span>
        </div>
    </div>

    <p>
        <a href="index.php">Back to Admin Dashboard</a>
        |
        <a href="../index.php">View Website</a>
    </p>

    <section class="pending-approvals">
        <h2 id="pending-approvals">⏳ Pending Approvals
            <?php if ($total_pending > 0): ?>
<span class="pending-count-pill"><?= $total_pending ?></span>
            <?php endif; ?>
        </h2>

        <?php if (empty($pendingRegistrations)): ?>
            <p class="empty-note">
🎉 No pending registrations. All caught up!
            </p>
        <?php else: ?>
            <div class="table-responsive">
<table class="admin-table">
<thead>
<tr>
<th>Student</th>
<th>Event</th>
<th>Registered At</th>
<th>Status</th>
<th>Actions</th>
</tr>
</thead>

<tbody>
<?php foreach ($pendingRegistrations as $row): ?>
<tr>
<td>
<strong><?= e($row['full_name']) ?></strong><br>
<small class="muted"><?= e($row['email']) ?></small>
</td>
<td><?= e($row['event_title']) ?></td>
<td><?= e($row['registered_at']) ?></td>
<td>
<span class="status-badge status-pending">
Pending
</span>
</td>
<td class="action-cell">
<form action="confirm_registration.php"
method="POST"
class="inline-form">
<input type="hidden"
name="registration_id"
value="<?= (int) $row['registration_id'] ?>">
<input type="hidden"
name="action"
value="approve">
<input type="hidden"
name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">
<button type="submit" class="btn-approve" title="Approve" aria-label="Approve">✔</button>
</form>

<form action="confirm_registration.php"
method="POST"
class="inline-form"
onsubmit="return confirm('Decline this registration? The slot will be freed for other students.');">
<input type="hidden"
name="registration_id"
value="<?= (int) $row['registration_id'] ?>">
<input type="hidden"
name="action"
value="decline">
<input type="hidden"
name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">
<button type="submit" class="btn-decline" title="Decline" aria-label="Decline">✖</button>
</form>

<a class="btn-view"
href="view_registration.php?id=<?= (int) $row['registration_id'] ?>">
View
</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
            </div>
        <?php endif; ?>
    </section>

    <section class="event-summary">
        <h2>Event Summary</h2>

        <?php if (empty($event_summaries)): ?>
            <p>No events found.</p>
        <?php else: ?>
            <div class="table-responsive">
<table>
<thead>
<tr>
<th>Event</th>
<th>Date</th>
<th>Status</th>
<th>Registered</th>
<th>Capacity</th>
<th>Slots Left</th>
<th>Action</th>
</tr>
</thead>

<tbody>
<?php foreach ($event_summaries as $summary): ?>
<?php
$registered = (int) $summary['registered_count'];
$capacity = (int) $summary['capacity'];
$remaining = max(0, $capacity - $registered);
$pending = (int) $summary['pending_count'];
?>

<tr>
<td><?= e($summary['title']) ?></td>
<td><?= e($summary['event_date']) ?></td>
<td><?= e($summary['status']) ?></td>
<td>
<?= $registered ?>
<?php if ($pending > 0): ?>
<span class="status-badge status-pending">
(<?= $pending ?> pending)
</span>
<?php endif; ?>
</td>
<td><?= $capacity ?></td>
<td><?= $remaining ?></td>
<td>
<a class="btn-view-attendees" href="attendees.php?event_id=<?= (int) $summary['id'] ?>">
View Attendees
</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
            </div>
        <?php endif; ?>
    </section>

    <section class="attendee-cta">
        <div class="cta-banner">
            <div class="cta-text">
<h2>👥 Attendee List</h2>
<p>
Viewing the full attendee list, with per-event filters, status
filters, and one-click CSV export — now on its own dedicated page.
</p>
            </div>
            <div class="cta-actions">
<a class="btn-cta-primary" href="attendees.php">
Open Attendee List
</a>
            </div>
        </div>
    </section>
</main>

    <footer>
        <p class="footer-school">Pateros Technological College &copy; <?= date('Y') ?></p>
        <p>Campus Event Hub — Admin Portal</p>
    </footer>

</body>
</html>
