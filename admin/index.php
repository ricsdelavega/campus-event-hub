<?php
session_start();

require __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['user_role'] ?? 'student') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrators only.');
}

function escape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$activeEvents      = (int) $pdo->query(
    "SELECT COUNT(*) FROM events WHERE status = 'active'"
)->fetchColumn();

$totalRegistrations = (int) $pdo->query(
    "SELECT COUNT(*) FROM registrations"
)->fetchColumn();

$totalStudents = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'student'"
)->fetchColumn();

$pendingCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM registrations WHERE status = 'pending'"
)->fetchColumn();

$confirmedCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM registrations WHERE status = 'registered'"
)->fetchColumn();

$totalCapacity = (int) $pdo->query(
    "SELECT COALESCE(SUM(capacity), 0) FROM events WHERE status = 'active'"
)->fetchColumn();

$upcomingStmt = $pdo->query("
    SELECT
        e.id,
        e.title,
        e.event_date,
        e.start_time,
        e.venue,
        e.capacity,
        e.status,
        (
            SELECT COUNT(*)
            FROM registrations r
            WHERE r.event_id = e.id
              AND r.status IN ('registered', 'pending')
        ) AS registered_count
    FROM events e
    WHERE e.status = 'active'
      AND e.event_date >= CURDATE()
    ORDER BY e.event_date ASC, e.start_time ASC
");

$upcomingEvents = $upcomingStmt->fetchAll();

$mostPopular = null;

foreach ($upcomingEvents as $ev) {
    if (
        $mostPopular === null
        || (int) $ev['registered_count'] > (int) $mostPopular['registered_count']
    ) {
        $mostPopular = $ev;
    }
}

$fillRate = $totalCapacity > 0
    ? round(($confirmedCount / $totalCapacity) * 100)
    : 0;

$attendOverviewStmt = $pdo->query("
    SELECT
        e.id,
        e.title,
        e.event_date,
        e.venue,
        (
            SELECT COUNT(*)
            FROM registrations r
            WHERE r.event_id = e.id
              AND r.status IN ('registered', 'pending')
        ) AS registered_count,
        (
            SELECT COUNT(*)
            FROM registrations r
            WHERE r.event_id = e.id
              AND r.attended_at IS NOT NULL
        ) AS attended_count
    FROM events e
    WHERE e.status IN ('active', 'archived')
    ORDER BY e.event_date DESC
    LIMIT 8
");

$attendOverview = $attendOverviewStmt->fetchAll();

$totalAttendedAll = (int) $pdo->query(
    "SELECT COUNT(*) FROM registrations WHERE attended_at IS NOT NULL"
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard - Pateros Technological College</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .admin-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 24px 32px;
        }

        .dash-hero {
            background-image:
linear-gradient(rgba(9, 46, 34, 0.85),
rgba(13, 71, 53, 0.92)),
url('../assets/images/PTC_background.png');
            background-size: cover;
            background-position: center;
            border-radius: 18px;
            padding: 44px 42px;
            color: white;
            margin-bottom: 28px;
            box-shadow: 0 10px 34px rgba(11, 61, 46, 0.25);

            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .dash-hero .dash-kicker {
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

        .dash-hero h1 {
            margin: 0 0 8px;
            font-size: 32px;
            color: #ffffff;
            font-weight: 800;
            text-shadow: 0 3px 12px rgba(0, 0, 0, 0.55);
        }

        .dash-hero p {
            margin: 0;
            color: #ffffff;
            font-size: 15.5px;
            font-weight: 600;
            max-width: 620px;
            line-height: 1.6;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.55);
            margin-left: auto;
            margin-right: auto;
        }

        .dash-hero strong {
            color: #7bf1b8;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.6);
        }

        .admin-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
            margin: 0 0 32px;
        }

        .admin-stat {
            background: linear-gradient(180deg, #ffffff 60%, #f6fcf8 100%);
            border: 1px solid #e3efe8;
            padding: 22px;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(20, 83, 45, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .admin-stat::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .admin-stat.stat-green::before {
            background: linear-gradient(90deg, var(--green-400), var(--green-600));
        }

        .admin-stat.stat-amber::before {
            background: linear-gradient(90deg, #fbbf24, #f59e0b);
        }

        .admin-stat.stat-blue::before {
            background: linear-gradient(90deg, #60a5fa, #2563eb);
        }

        .admin-stat.stat-rose::before {
            background: linear-gradient(90deg, #fb7185, #e11d48);
        }

        .admin-stat:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 16px 32px rgba(20, 83, 45, 0.18);
        }

        .admin-stat .stat-icon {
            font-size: 26px;
            margin-bottom: 6px;
        }

        .admin-stat h3 {
            margin: 0;
            font-size: 12.5px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #5b7268;
        }

        .admin-stat p {
            margin: 6px 0 0;
            font-size: 36px;
            font-weight: 800;
            line-height: 1.1;
            color: var(--green-900);
        }

        .admin-stat small {
            display: block;
            margin-top: 4px;
            font-size: 12.5px;
            color: #7a8d80;
        }

        .admin-stat.stat-amber p {
            color: #b45309;
        }

        .admin-stat.stat-blue p {
            color: #1d4ed8;
        }

        .admin-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
            margin: 0 0 34px;
        }

        .admin-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            background: linear-gradient(180deg, #ffffff 55%, #f6fcf8 100%);
            border: 1px solid #dff0e7;
            border-radius: 16px;
            padding: 20px 22px;
            text-decoration: none;
            color: var(--green-900);
            font-weight: 700;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(20, 83, 45, 0.07);
            transition: transform 0.22s ease, box-shadow 0.22s ease,
border-color 0.22s ease;
        }


        .admin-links a::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, var(--green-400), var(--green-600));
            opacity: 0;
            transition: opacity 0.22s ease;
        }

        .admin-links a:hover::before {
            opacity: 1;
        }

        .admin-links a .qa-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--green-100), #d3f2e2);
            box-shadow:
inset 0 1px 0 rgba(255, 255, 255, 0.8),
0 2px 8px rgba(30, 133, 99, 0.12);
            font-size: 24px;
            flex-shrink: 0;
        }

        .admin-links a small {
            display: block;
            font-weight: 500;
            font-size: 12.5px;
            color: #7a8d80;
            margin-top: 2px;
        }

        .admin-links a:hover {
            transform: translateY(-4px) scale(1.02);
            border-color: var(--green-400);
            box-shadow: 0 16px 34px rgba(20, 83, 45, 0.20);
        }

        .admin-links a:hover .qa-icon {
            transform: scale(1.14) rotate(6deg);
            background: linear-gradient(135deg, #d3f2e2, var(--green-100));
        }

        .admin-links a .qa-icon {
            transition: transform 0.25s ease, background 0.25s ease;
        }

        main h2 {
            color: var(--green-900);
            font-size: 21px;
            margin: 0 0 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        main h2 .h2-link {
            margin-left: auto;
            font-size: 13.5px;
            font-weight: 700;
        }

        .table-wrapper {
            background: white;
            border: 1px solid #e3efe8;
            border-radius: 16px;
            box-shadow: 0 8px 26px rgba(20, 83, 45, 0.08);
            overflow-x: auto;
            margin-bottom: 34px;
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }

        .admin-table th {
            background: linear-gradient(90deg, var(--green-800), var(--green-600));
            color: white;
            padding: 13px 16px;
            font-size: 12.5px;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            text-align: left;
            white-space: nowrap;
        }

        .admin-table td {
            padding: 13px 16px;
            border-bottom: 1px solid #eef6f1;
            font-size: 14.5px;
            vertical-align: middle;
        }

        .admin-table tbody tr {
            transition: background 0.15s ease;
        }

        .admin-table tbody tr:hover {
            background: #f4fbf7;
        }

        .event-title-cell strong {
            color: var(--green-900);
        }

        .event-title-cell small {
            display: block;
            color: #7a8d80;
            font-size: 12px;
        }

        .fill-bar {
            width: 130px;
            height: 9px;
            background: #e4f2ea;
            border-radius: 999px;
            overflow: hidden;
        }

        .fill-bar>span {
            display: block;
            height: 100%;
            background: linear-gradient(90deg, var(--green-400), var(--green-600));
            border-radius: 999px;
        }

        .fill-num {
            font-size: 12.5px;
            font-weight: 700;
            color: #456052;
            white-space: nowrap;
        }

        .calendar-card {
            background: white;
            border: 1px solid #e3efe8;
            border-radius: 16px;
            box-shadow: 0 8px 26px rgba(20, 83, 45, 0.08);
            margin-bottom: 34px;
            overflow: hidden;
        }

        .calendar-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            background: linear-gradient(90deg, var(--green-800), var(--green-600));
            color: white;
        }

        .calendar-head h3 {
            margin: 0;
            font-size: 17px;
            letter-spacing: 0.5px;
        }

        .calendar-nav {
            display: flex;
            gap: 8px;
        }

        .calendar-nav a {
            color: white;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            transition: background 0.15s ease;
        }

        .calendar-nav a:hover {
            background: rgba(255, 255, 255, 0.32);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }

        .calendar-dow {
            text-align: center;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #5b7268;
            padding: 10px 4px;
            background: #f2faf5;
            border-bottom: 1px solid #e3efe8;
        }

        .calendar-day {
            min-height: 96px;
            border-right: 1px solid #eef6f1;
            border-bottom: 1px solid #eef6f1;
            padding: 7px;
            font-size: 12.5px;
            background: white;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .calendar-day:nth-child(7n) {
            border-right: none;
        }

        .calendar-day .day-num {
            font-weight: 800;
            color: #456052;
        }

        .calendar-day.is-today {
            background: #eafaf1;
            box-shadow: inset 0 0 0 2px var(--green-400);
        }

        .calendar-day.is-today .day-num {
            color: var(--green-800);
        }

        .calendar-day.is-blank {
            background: #fafcfb;
            cursor: default;
        }

        .cal-event {
            display: block;
            background: linear-gradient(90deg, var(--green-100), #d9f3e5);
            border-left: 3px solid var(--green-500);
            border-radius: 6px;
            padding: 4px 6px;
            font-size: 11.5px;
            line-height: 1.3;
            color: var(--green-900);
            text-decoration: none;
            overflow: hidden;
        }

        .cal-event strong {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 11.5px;
        }

        .cal-event small {
            display: block;
            color: #5b7268;
            font-size: 10.5px;
        }

        .cal-event:hover {
            background: var(--green-100);
            transform: translateY(-1px) scale(1.03);
            box-shadow: 0 4px 10px rgba(11, 61, 46, 0.15);
        }

        .cal-event {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            padding: 12px 20px;
            background: #f8fcf9;
            border-top: 1px solid #eef6f1;
            font-size: 12.5px;
            color: #5b7268;
        }

        .legend-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 3px;
            background: linear-gradient(90deg, var(--green-400), var(--green-600));
            margin-right: 6px;
            vertical-align: middle;
        }

        .dash-empty {
            background: white;
            border: 1px dashed #b7d8c4;
            border-radius: 14px;
            text-align: center;
            padding: 44px 20px;
            color: #5b7268;
            margin-bottom: 34px;
        }

        @media (max-width: 700px) {
            .dash-hero {
padding: 30px 22px;
            }

            .dash-hero h1 {
font-size: 24px;
            }

            .admin-stat p {
font-size: 28px;
            }
        }


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
            backdrop-filter: blur(10px);
        }

        .nav-links a {
            position: relative;
            font-weight: 600;
        }

        .nav-links a::after {
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

        .nav-links a:hover::after {
            transform: scaleX(1);
        }

        .dash-hero {
            position: relative;
            overflow: hidden;
        }

        .dash-hero::after {
            content: "";
            position: absolute;
            inset: auto -15% -55% -15%;
            height: 65%;
            pointer-events: none;
            background: radial-gradient(ellipse at center, rgba(79, 214, 156, 0.20), transparent 65%);
            animation: dashGlow 6s ease-in-out infinite alternate;
        }

        @keyframes dashGlow {
            from { transform: translateX(-3%) scale(1); opacity: 0.75; }
            to   { transform: translateX(3%) scale(1.06); opacity: 1; }
        }

        .dash-hero h1 {
            letter-spacing: -0.6px;
        }

        .admin-stat {
            border-radius: 16px;
        }

        .admin-stat .stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--green-100), #ffffff);
            box-shadow: 0 4px 10px rgba(20, 83, 45, 0.10);
            margin-bottom: 10px;
        }

        main h2 {
            letter-spacing: -0.2px;
        }

        .calendar-card,
        .table-wrapper {
            border-radius: 18px;
        }

        .admin-table th {
            border-bottom: 3px solid var(--green-400);
        }

        .cal-event {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .admin-links a,
        .admin-stat {
            will-change: transform;
        }

        @media (prefers-reduced-motion: no-preference) {
            .admin-stats,
            .admin-links,
            .calendar-card {
animation: dashFadeUp 0.6s ease-out both;
            }
        }

        @keyframes dashFadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        footer {
            margin-top: 50px;
            padding: 30px 20px;
            background: linear-gradient(160deg, var(--green-900), var(--green-800));
            color: #cfeee0;
            border-top: 4px solid var(--green-400);
        }
    </style>
</head>

<body>

    <nav class="navbar">
        <a class="brand" href="../index.php">
            <img class="brand-logo"
src="../assets/images/logo-ptc.png"
alt="PTC Logo">
            <span class="brand-text">
Pateros Technological College
<small>Campus Event Hub</small>
            </span>
        </a>

        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="scan_attendance.php">QR Scanner</a>
            <a href="../index.php">View Website</a>
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

    <main class="admin-container">

        <header class="dash-hero">
            <span class="dash-kicker">Admin Dashboard</span>

            <h1>
Welcome back,
<strong><?= escape($_SESSION['user_name'] ?? 'Administrator') ?></strong>!
            </h1>

            <p>
Manage campus events, approve registrations, and monitor
attendance — everything you need is one click away.
            </p>
        </header>

        <section class="admin-stats">

            <article class="admin-stat stat-green">
<div class="stat-icon">📅</div>
<h3>Active Events</h3>
<p><?= $activeEvents ?></p>
<small><?= $totalCapacity ?> total slots open</small>
            </article>

            <article class="admin-stat stat-amber">
<div class="stat-icon">⏳</div>
<h3>Pending Approvals</h3>
<p><?= $pendingCount ?></p>
<small><?= $pendingCount > 0 ? 'Needs your review' : 'All caught up!' ?></small>
            </article>

            <article class="admin-stat stat-blue">
<div class="stat-icon">📝</div>
<h3>Total Registrations</h3>
<p><?= $totalRegistrations ?></p>
<small><?= $confirmedCount ?> confirmed</small>
            </article>

            <article class="admin-stat stat-rose">
<div class="stat-icon">🎓</div>
<h3>Student Accounts</h3>
<p><?= $totalStudents ?></p>
<small><?= $fillRate ?>% average fill rate</small>
            </article>

        </section>

        <h2>⚡ Quick Actions</h2>

        <div class="admin-links">
            <a href="events.php">
<span class="qa-icon">📅</span>
<span>Manage Events
<small>Create, edit or delete events</small>
</span>
            </a>

            <a href="registrations.php">
<span class="qa-icon">✅</span>
<span>Approve Registrations
<small><?= $pendingCount ?> pending approval<?= $pendingCount === 1 ? '' : 's' ?></small>
</span>
            </a>

            <a href="attendees.php">
<span class="qa-icon">👥</span>
<span>Attendee List
<small>Filter and export attendees</small>
</span>
            </a>

            <a href="../index.php">
<span class="qa-icon">🌐</span>
<span>View Website
<small>See the public event page</small>
</span>
            </a>
        </div>

        <h2 id="calendar-top">
            Upcoming Events
            <a class="h2-link" href="events.php">Manage all</a>
        </h2>

        
        <section class="att-overview" id="attendance-overview">
            <div class="att-overview-head">
                <div>
<h2 style="margin:0; border:none; padding:0;">🎯 Student Attendance Overview</h2>
<p class="att-overview-sub">
Who actually showed up — attended students out of everyone registered, per event.
</p>
                </div>
                <span class="att-total-chip">
<strong><?= $totalAttendedAll ?></strong> total check-ins
                </span>
            </div>

            <?php if (!$attendOverview): ?>
<p class="att-empty">No events yet — attendance tracking will appear here once events are created.</p>
            <?php else: ?>

            <div class="att-grid">
<?php foreach ($attendOverview as $ev):
$reg = (int) $ev['registered_count'];
$att = (int) $ev['attended_count'];
$pct = $reg > 0 ? round($att / $reg * 100) : 0;
$pctClass = $pct >= 70 ? 'good' : ($pct >= 40 ? 'mid' : 'low');
?>
<a class="att-card" href="attendees.php?event_id=<?= (int) $ev['id'] ?>">
<div class="att-card-top">
<span class="att-title"><?= escape($ev['title']) ?></span>
<span class="att-pct pct-<?= $pctClass ?>"><?= $pct ?>%</span>
</div>
<small class="att-meta">📅 <?= escape(date('M j, Y', strtotime($ev['event_date']))) ?> · 📍 <?= escape($ev['venue']) ?></small>

<div class="att-bar">
<div class="att-bar-fill pct-fill-<?= $pctClass ?>" style="width: <?= $pct ?>%"></div>
</div>

<div class="att-nums">
<span class="att-n attended"><strong><?= $att ?></strong> attended</span>
<span class="att-n registered"><strong><?= $reg ?></strong> registered</span>
</div>
</a>
<?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <style>
            .att-overview {
                background: white;
                border: 1px solid #e3efe8;
                border-radius: 18px;
                box-shadow: 0 8px 28px rgba(20, 83, 45, 0.09);
                padding: 26px 28px;
                margin: 26px 0 34px;
            }

            .att-overview-head {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 16px;
                flex-wrap: wrap;
                margin-bottom: 20px;
            }

            .att-overview-sub {
                margin: 5px 0 0;
                color: #5b7268;
                font-size: 13.5px;
            }

            .att-total-chip {
                padding: 8px 18px;
                border-radius: 999px;
                background: linear-gradient(135deg, #0b3d2e, #14603f);
                color: #a7f3d0;
                font-size: 13px;
                font-weight: 700;
                white-space: nowrap;
                box-shadow: 0 4px 14px rgba(11, 61, 46, 0.28);
            }

            .att-total-chip strong { color: #fff; font-size: 16px; }

            .att-empty {
                color: #7a8d80;
                text-align: center;
                padding: 26px 0;
                margin: 0;
            }

            .att-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 14px;
            }

            .att-card {
                display: block;
                background: linear-gradient(165deg, #ffffff, #f5fbf7);
                border: 1px solid #e0efe7;
                border-radius: 14px;
                padding: 16px 18px;
                text-decoration: none;
                transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            }

            .att-card:hover {
                transform: translateY(-3px);
                border-color: #9fceba;
                box-shadow: 0 12px 26px rgba(20, 83, 45, 0.14);
            }

            .att-card-top {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 10px;
            }

            .att-title {
                font-weight: 800;
                font-size: 14.5px;
                color: #0b3d2e;
                line-height: 1.35;
            }

            .att-pct {
                flex-shrink: 0;
                font-size: 13px;
                font-weight: 900;
                padding: 4px 12px;
                border-radius: 999px;
            }

            .pct-good  { background: #dcfce7; color: #15803d; }
            .pct-mid   { background: #fef3c7; color: #b45309; }
            .pct-low   { background: #fee2e2; color: #b91c1c; }

            .att-meta {
                display: block;
                color: #7a8d80;
                font-size: 12px;
                margin-top: 5px;
            }

            .att-bar {
                height: 9px;
                border-radius: 999px;
                background: #e2efe8;
                overflow: hidden;
                margin-top: 12px;
            }

            .att-bar-fill {
                height: 100%;
                border-radius: 999px;
                transition: width 0.6s cubic-bezier(0.34, 1.3, 0.64, 1);
            }

            .pct-fill-good { background: linear-gradient(90deg, #34c98e, #157347); }
            .pct-fill-mid  { background: linear-gradient(90deg, #fbbf24, #d97706); }
            .pct-fill-low  { background: linear-gradient(90deg, #f87171, #dc2626); }

            .att-nums {
                display: flex;
                gap: 18px;
                margin-top: 11px;
                font-size: 12.5px;
                color: #5b7268;
            }

            .att-n strong { font-size: 15px; }

            .att-n.attended strong { color: #15803d; }
            .att-n.registered strong { color: #1d4ed8; }
        </style>

        <?php
$todayStamp = strtotime(date('Y-m-d'));

        $monthParam = $_GET['cal_month'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
            $monthParam = date('Y-m');
        }

        $calFirst = strtotime($monthParam . '-01');
        $calYear  = (int) date('Y', $calFirst);
        $calMonth = (int) date('n', $calFirst);

$hasEventThisMonth = false;
        foreach ($upcomingEvents as $ev) {
            if (date('Y-m', strtotime($ev['event_date'])) === date('Y-m')) {
$hasEventThisMonth = true;
break;
            }
        }

        if (!$hasEventThisMonth && count($upcomingEvents) > 0) {
            $earliest = $upcomingEvents[0]['event_date'];
            if (date('Y-m', strtotime($earliest)) !== date('Y-m')
&& $monthParam === date('Y-m')) {
$calFirst = strtotime(date('Y-m-01', strtotime($earliest)));
$calYear  = (int) date('Y', $calFirst);
$calMonth = (int) date('n', $calFirst);
            }
        }

$prevMonth = date('Y-m', strtotime('-1 month', $calFirst));
        $nextMonth = date('Y-m', strtotime('+1 month', $calFirst));

$eventsByDate = [];
        foreach ($upcomingEvents as $ev) {
            $eventsByDate[date('Y-m-d', strtotime($ev['event_date']))][] = $ev;
        }

$gridStart = strtotime(date('Y-m-01', $calFirst));
        while (date('w', $gridStart) != 0) {
            $gridStart = strtotime('-1 day', $gridStart);
        }
        ?>

        <?php if (count($upcomingEvents) > 0): ?>

            <div class="calendar-card">
<div class="calendar-head">
<h3><?= escape(date('F Y', $calFirst)) ?></h3>
<div class="calendar-nav">
<a id="calendar" href="index.php?cal_month=<?= $prevMonth ?>#calendar-top">Prev</a>
<a href="index.php#calendar-top">Today</a>
<a href="index.php?cal_month=<?= $nextMonth ?>#calendar-top">Next</a>
</div>
</div>

<div class="calendar-grid">
<?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
<div class="calendar-dow"><?= $dow ?></div>
<?php endforeach; ?>

<?php
$cursor = $gridStart;
for ($cell = 0; $cell < 42; $cell++):
$dayKey    = date('Y-m-d', $cursor);
$dayEvents = $eventsByDate[$dayKey] ?? [];
$inMonth   = date('n', $cursor) === $calMonth;
$classes   = 'calendar-day';
if (!$inMonth) $classes .= ' is-blank';
if ($dayKey === date('Y-m-d', $todayStamp)) $classes .= ' is-today';
?>
<div class="<?= $classes ?>">
<span class="day-num"><?= date('j', $cursor) ?></span>

<?php foreach ($dayEvents as $ev):
$cap = max(1, (int) $ev['capacity']);
$reg = (int) $ev['registered_count'];
$pct = min(100, round(($reg / $cap) * 100));
$full = $reg >= $cap;
?>
<a class="cal-event"
href="events.php"
title="<?= escape($ev['title']) ?> — <?= escape($ev['venue']) ?> · <?= $reg ?>/<?= (int) $ev['capacity'] ?> (<?= $pct ?>%)<?= $full ? ' — FULL' : '' ?>">
<strong><?= escape($ev['title']) ?></strong>
<small><?= escape(date('g:i A', strtotime($ev['start_time']))) ?> · <?= escape($ev['venue']) ?></small>
<small><?= $reg ?>/<?= (int) $ev['capacity'] ?> (<?= $pct ?>%)<?= $full ? ' — FULL' : '' ?></small>
</a>
<a class="cal-event"
href="attendees.php?event_id=<?= (int) $ev['id'] ?>"
title="View attendees of <?= escape($ev['title']) ?>">
<small>View attendees</small>
</a>
<?php endforeach; ?>
</div>
<?php
$cursor = strtotime('+1 day', $cursor);
endfor;
?>
</div>

<div class="calendar-legend">
<span><span class="legend-dot"></span>Upcoming event</span>
<span>Highlighted box = today</span>
<span>FULL = event slots are full</span>
<span><?= count($upcomingEvents) ?> upcoming event<?= count($upcomingEvents) === 1 ? '' : 's' ?></span>
</div>
            </div>

        <?php else: ?>

            <div class="dash-empty">
<p style="font-size: 40px; margin: 0 0 8px;">🗓️</p>
<h3 style="margin: 0 0 6px;">No upcoming events</h3>
<p style="margin: 0 0 14px;">
All active events have already happened.
Create a new event to get things going!
</p>
<a class="button-secondary" href="events.php">+ Create Event</a>
            </div>

        <?php endif; ?>

    </main>

    <footer>
        <p>Pateros Technological College &copy; <?= date('Y') ?></p>
    </footer>

</body>

</html>