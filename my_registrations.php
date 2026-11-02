
<?php
session_start();

require __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION['user_id'];

$sql = "SELECT
            r.id AS registration_id,
            r.status AS registration_status,
            r.registered_at,
            r.confirmed_at,
            r.attendance_token,
            r.attended_at,
            e.id AS event_id,
            e.title,
            e.description,
            e.category,
            e.event_date,
            e.start_time,
            e.end_time,
            e.venue,
            e.capacity,
            e.event_image,
            (
SELECT COUNT(*)
FROM registrations r2
WHERE r2.event_id = e.id
AND r2.status IN ('registered', 'pending')
            ) AS taken_slots
        FROM registrations r
        INNER JOIN events e ON r.event_id = e.id
        WHERE r.user_id = :user_id
          AND r.status IN ('registered', 'pending')
        ORDER BY r.registered_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $userId]);
$registrations = $stmt->fetchAll();

$declinedStmt = $pdo->prepare("
    SELECT
        r.id AS registration_id,
        r.status AS registration_status,
        r.registered_at,
        r.confirmed_at,
        e.title,
        e.event_date
    FROM registrations r
    INNER JOIN events e ON r.event_id = e.id
    WHERE r.user_id = :user_id
      AND r.status = 'declined'
    ORDER BY r.confirmed_at DESC
");

$declinedStmt->execute(['user_id' => $userId]);
$declinedRegistrations = $declinedStmt->fetchAll();

$historyStmt = $pdo->prepare("
    SELECT
        r.id AS registration_id,
        r.status AS registration_status,
        r.registered_at,
        r.confirmed_at,
        r.attended_at,
        e.id AS event_id,
        e.title,
        e.category,
        e.event_date,
        e.start_time,
        e.end_time,
        e.venue,
        e.event_image
    FROM registrations r
    INNER JOIN events e ON r.event_id = e.id
    WHERE r.user_id = :user_id
    ORDER BY e.event_date DESC, e.start_time DESC
");

$historyStmt->execute(['user_id' => $userId]);
$eventHistory = $historyStmt->fetchAll();

$nowTs = time();
$pastHistory = [];
$upcomingHistory = [];
foreach ($eventHistory as $h) {
    $hEnd = strtotime($h['event_date'] . ' ' . (!empty($h['end_time']) ? $h['end_time'] : '23:59:59'));
    if ($nowTs > $hEnd
        || in_array($h['registration_status'], ['cancelled', 'declined'], true)) {
        $pastHistory[] = $h;
    } else {
        $upcomingHistory[] = $h;
    }
}

$histAttended  = count(array_filter($pastHistory, function ($h) { return !empty($h['attended_at']); }));
$histFinished  = count(array_filter($pastHistory, function ($h) {
    return $h['registration_status'] === 'registered' && empty($h['attended_at']);
}));
$histCancelled = count(array_filter($pastHistory, function ($h) { return $h['registration_status'] === 'cancelled'; }));
$histDeclined  = count(array_filter($pastHistory, function ($h) { return $h['registration_status'] === 'declined'; }));

$histMinutes = 0;
$histKnownEndCount = 0;
foreach ($pastHistory as $h) {
    if (empty($h['attended_at'])) {
        continue;
    }

    $start = strtotime($h['attended_at']);
    if (!empty($h['end_time'])) {
        $end = strtotime($h['event_date'] . ' ' . $h['end_time']);
        $histKnownEndCount++;
    } else {


        $end = min($start + 3600, time());
    }

    $histMinutes += max(0, ($end - $start) / 60);
}
$histHours = $histMinutes > 0
    ? number_format($histMinutes / 60, 1)
    : '0.0';

$histCategories = [];
foreach ($pastHistory as $h) {
    if (!empty($h['attended_at']) && !empty($h['category'])) {
        $histCategories[$h['category']] = true;
    }
}
$histCategoryCount = count($histCategories);

function escape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatDate($value)
{
    return date('M j, Y', strtotime($value));
}

function formatTime($value)
{
    return date('g:i A', strtotime($value));
}

function formatDateTime($value)
{
    if (empty($value)) {
        return '—';
    }

    return date('M j, Y g:i A', strtotime($value));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>My Registrations - Pateros Technological College</title>

    <link rel="stylesheet"
          href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<style>

    .qr-pass-link-row {
        margin: 16px 24px 4px;
        padding-top: 16px;
        border-top: 1px dashed rgba(255, 255, 255, 0.28);
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .qr-pass-link-row .qr-pass-button,
    .qr-pass-link-row .qr-checked-chip {
        position: relative;
        z-index: 2;
    }

    .event-card:not(.has-image) .qr-pass-link-row {
        border-top: 1px dashed #cfe3d6;
    }

    .qr-checked-chip {
        align-self: flex-start;
        font-size: 12.5px;
        font-weight: 700;
        color: #15803d;
        background: rgba(232, 248, 239, 0.95);
        border: 1px solid #9de0bd;
        padding: 5px 14px;
        border-radius: 999px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
    }

    .qr-pass-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        box-sizing: border-box;
        padding: 14px 18px;
        border-radius: 999px;
        font-weight: 800;
        font-size: 14.5px;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        text-decoration: none;
        color: white;
        background: linear-gradient(135deg, #2fb986, #157347);
        border: 1px solid rgba(255, 255, 255, 0.35);
        box-shadow:
            0 6px 18px rgba(21, 115, 71, 0.45),
            inset 0 1px 0 rgba(255, 255, 255, 0.3);
        position: relative;
        overflow: hidden;
        transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
    }


    .event-card form .button-cancel {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        box-sizing: border-box;
        padding: 14px 18px;
        margin: 12px 0 2px;
        border-radius: 999px;
        font-weight: 800;
        font-size: 14.5px;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        color: white;
        background: linear-gradient(135deg, #ef4444, #b91c1c);
        border: 1px solid rgba(255, 255, 255, 0.35);
        box-shadow:
            0 6px 18px rgba(185, 28, 28, 0.45),
            inset 0 1px 0 rgba(255, 255, 255, 0.3);
        position: relative;
        overflow: hidden;
        cursor: pointer;
        transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
    }


    .event-card form .button-cancel::before {
        content: "";
        position: absolute;
        top: 0;
        left: -80%;
        width: 50%;
        height: 100%;
        background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.45), transparent);
        transform: skewX(-20deg);
        transition: left 0.55s ease;
        pointer-events: none;
        z-index: 1;
    }

    .event-card form .button-cancel:hover::before {
        left: 130%;
    }

    .event-card form .button-cancel:hover {
        transform: translateY(-2px);
        filter: brightness(1.07);
        box-shadow:
            0 9px 24px rgba(185, 28, 28, 0.5),
            inset 0 1px 0 rgba(255, 255, 255, 0.3);
    }

    .event-card form .button-cancel:active {
        transform: translateY(0);
        box-shadow: 0 3px 10px rgba(185, 28, 28, 0.4);
    }


    .event-card form .button-cancel {
        z-index: 0;
    }


    .qr-pass-button::before {
        content: "";
        position: absolute;
        top: 0;
        left: -80%;
        width: 45%;
        height: 100%;
        background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.5), transparent);
        transform: skewX(-20deg);
        transition: left 0.55s ease;
        pointer-events: none;
        z-index: 1;
    }

    .qr-pass-button:hover {
        transform: translateY(-2px);
        filter: brightness(1.08);
        box-shadow:
            0 10px 26px rgba(21, 115, 71, 0.55),
            inset 0 1px 0 rgba(255, 255, 255, 0.3);
    }

    .qr-pass-button:hover::before {
        left: 130%;
    }

    .qr-pass-button::after {
        content: "";
        transition: transform 0.18s ease;
    }

    .qr-pass-button:hover::after {
        transform: none;
    }

    .qr-pass-button:active {
        transform: translateY(0);
        box-shadow: 0 3px 10px rgba(21, 115, 71, 0.4);
    }


    .event-card .details {
        background: rgba(0, 0, 0, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.14);
        border-radius: 14px;
        padding: 14px 16px;
    }

    .event-card:not(.has-image) .details {
        background: linear-gradient(165deg, #fbfdfc, #f2faf5);
        border: 1px solid #e3efe8;
    }

    .event-card h2 {
        letter-spacing: -0.2px;
    }

    .event-card .button-cancel {
        border-radius: 999px;
        letter-spacing: 0.3px;
    }


    .history-section {
        margin: 40px 0 24px;
    }

    .history-head {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    .history-head h2 {
        margin: 0;
        font-size: 26px;
        letter-spacing: -0.3px;
    }

    .history-head .history-sub {
        margin: 6px 0 0;
        color: #5b6b62;
        font-size: 14.5px;
    }

    .history-filter {
        display: inline-flex;
        gap: 4px;
        background: #ffffff;
        border: 1.5px solid #dde9e1;
        border-radius: 999px;
        padding: 5px;
        flex-wrap: wrap;
        box-shadow: 0 3px 12px rgba(20, 60, 40, 0.06);
    }

    .history-filter a {
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
        color: #41544a;
        padding: 8px 18px;
        border-radius: 999px;
        transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
    }

    .history-filter a:hover {
        background: #e2f2e9;
        transform: translateY(-1px);
    }

    .history-filter a.is-active {
        background: linear-gradient(135deg, #2fb986, #157347);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(21, 115, 71, 0.35);
    }

    .hist-live-count {
        display: none;
        font-size: 12.5px;
        font-weight: 700;
        color: #157347;
        background: #e6f5ec;
        border: 1.5px solid #b5dfcb;
        padding: 6px 14px;
        border-radius: 999px;
        vertical-align: middle;
    }

    .hist-live-count.show { display: inline-block; }

    .history-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .history-stat {
        background: linear-gradient(165deg, #ffffff, #f4faf6);
        border: 1px solid #e3efe8;
        border-radius: 16px;
        padding: 18px 20px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 3px 12px rgba(20, 60, 40, 0.06);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .history-stat:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 22px rgba(20, 60, 40, 0.10);
    }

    .history-stat .hs-icon {
        width: 46px;
        height: 46px;
        flex: 0 0 46px;
        display: grid;
        place-items: center;
        font-size: 20px;
        border-radius: 13px;
        background: linear-gradient(135deg, #e6f5ec, #d2ecdf);
    }

    .history-stat.hs-green .hs-icon {
        background: linear-gradient(135deg, #dcfce7, #bbf7d0);
        color: #15803d;
    }

    .history-stat.hs-blue .hs-icon {
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        color: #1d4ed8;
    }

    .history-stat.hs-purple .hs-icon {
        background: linear-gradient(135deg, #ede9fe, #ddd6fe);
        color: #6d28d9;
    }

    .history-stat.hs-amber .hs-icon {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        color: #b45309;
    }

    .history-stat strong {
        display: block;
        font-size: 22px;
        line-height: 1.1;
        font-variant-numeric: tabular-nums;
    }

    .history-stat span {
        font-size: 12px;
        color: #63756a;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .history-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
        position: relative;
    }

    .history-item {
        position: relative;
        background: #ffffff;
        border: 1px solid #e4ece7;
        border-radius: 16px;
        padding: 16px 20px 16px 24px;
        display: flex;
        gap: 16px;
        align-items: center;
        box-shadow: 0 2px 10px rgba(20, 60, 40, 0.05);
        transition: transform 0.16s ease, box-shadow 0.16s ease;
        overflow: hidden;
    }


    .history-item[hidden] {
        display: none !important;
    }

    .history-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 22px rgba(20, 60, 40, 0.12);
    }


    .history-item::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 5px;
    }

    .history-item.hist-attended::before  { background: linear-gradient(180deg, #34c98e, #157347); }
    .history-item.hist-missed::before    { background: linear-gradient(180deg, #94a3b8, #64748b); }
    .history-item.hist-cancelled::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
    .history-item.hist-declined::before  { background: linear-gradient(180deg, #f87171, #dc2626); }

    .history-thumb {
        width: 72px;
        height: 72px;
        flex: 0 0 72px;
        border-radius: 13px;
        background: linear-gradient(135deg, #d9efe4, #b7dcc9);
        display: grid;
        place-items: center;
        font-size: 30px;
        overflow: hidden;
    }

    .history-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .history-info {
        flex: 1;
        min-width: 0;
    }

    .history-info h4 {
        margin: 0 0 4px;
        font-size: 16.5px;
    }

    .history-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 4px 16px;
        font-size: 13px;
        color: #5b6b62;
    }

    .history-meta strong {
        color: #34453b;
    }

    .history-tags {
        margin-top: 7px;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .history-tag {
        font-size: 11.5px;
        font-weight: 700;
        padding: 3px 11px;
        border-radius: 999px;
        letter-spacing: 0.3px;
    }

    .history-tag.tag-category { background: #e8f1fa; color: #1d4ed8; }
    .history-tag.tag-attended { background: #dcfce7; color: #15803d; }
    .history-tag.tag-missed   { background: #e9edf2; color: #475569; }
    .history-tag.tag-cancelled{ background: #fef3c7; color: #b45309; }
    .history-tag.tag-declined { background: #fee2e2; color: #b91c1c; }


    .history-tag.tag-registered-only {
        background: repeating-linear-gradient(
            45deg,
            #fff7e0,
            #fff7e0 6px,
            #fdf0cd 6px,
            #fdf0cd 12px
        );
        color: #92400e;
        border: 1px dashed #e5b95c;
    }


    .hs-note {
        display: block;
        margin-top: 2px;
        font-size: 11.5px;
        font-weight: 600;
        color: #8ba397;
        font-style: italic;
    }


    .history-item {
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .history-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 26px rgba(20, 83, 45, 0.14);
    }

    .history-item:hover h4 a {
        color: var(--green-700, #17694f);
    }

    .history-item h4 a {
        transition: color 0.18s ease;
    }


    .history-item.hist-attended:hover::before {
        box-shadow: 0 0 12px rgba(52, 201, 142, 0.7);
    }

    .history-item.hist-missed:hover::before {
        box-shadow: 0 0 12px rgba(100, 116, 139, 0.6);
    }

    .history-item.hist-cancelled:hover::before {
        box-shadow: 0 0 12px rgba(245, 158, 11, 0.65);
    }

    .history-item.hist-declined:hover::before {
        box-shadow: 0 0 12px rgba(220, 38, 38, 0.65);
    }

    .history-date-col {
        text-align: right;
        flex-shrink: 0;
    }

    .history-date-col .hd-day {
        font-size: 26px;
        font-weight: 800;
        color: #1f3b2d;
        line-height: 1;
    }

    .history-date-col .hd-month {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #7b8d82;
    }

    .history-date-col .hd-year {
        font-size: 12px;
        color: #9aa8a0;
    }

    .history-empty {
        background: linear-gradient(165deg, #fbfdfc, #f2faf5);
        border: 1px dashed #c8ddcf;
        border-radius: 16px;
        padding: 36px 24px;
        text-align: center;
    }

    .history-empty .empty-icon { font-size: 40px; margin: 0 0 6px; }
    .history-empty h3 { margin: 0 0 6px; }
    .history-empty p { margin: 0 0 14px; color: #5b6b62; }

    @media (max-width: 640px) {
        .history-item {
            flex-direction: column;
            align-items: flex-start;
        }

        .history-date-col {
            text-align: left;
        }
    }


    @media (prefers-reduced-motion: no-preference) {
        .event-card {
            animation: fadeUp 0.45s ease both;
        }

        .event-card:nth-child(2) { animation-delay: 0.06s; }
        .event-card:nth-child(3) { animation-delay: 0.12s; }
        .event-card:nth-child(4) { animation-delay: 0.18s; }
        .event-card:nth-child(5) { animation-delay: 0.24s; }
    }
</style>

<body>

    <nav class="navbar">

        <a class="brand" href="index.php">
            <img class="brand-logo"
src="assets/images/logo-ptc.png"
alt="PTC Logo">
            <span class="brand-text">
Pateros Technological College
<small>Campus Event Hub</small>
            </span>
        </a>

        <div class="nav-links">

            <a href="index.php">Home</a>

            <a href="index.php#events">Events</a>

            <a href="my_registrations.php" class="active">
My Registrations
            </a>

            <span class="user-chip">
<span class="user-avatar"><?= escape(strtoupper(mb_substr($_SESSION['user_name'] ?? 'S', 0, 1))) ?></span>
Hello,
<?= escape($_SESSION['user_name'] ?? 'Student') ?>
            </span>

            <a href="logout.php">Logout</a>

        </div>

    </nav>

    <header class="hero">

        <span class="hero-badge">My Registrations</span>

        <h1>
            My Campus Journey —
            <span class="hero-highlight">Events I've Joined</span>
        </h1>

        <p>
            View, track, or cancel your campus event registrations
            anytime. Registrations marked <strong>Pending</strong>
            still need admin approval before your slot is confirmed.
        </p>

        <div class="hero-stats">

            <div class="hero-stat">
<strong><?= count($registrations) ?></strong>
<span>My Registrations</span>
            </div>

            <div class="hero-stat">
<strong><?= count(array_filter($registrations, function ($r) { return $r['registration_status'] === 'registered'; })) ?></strong>
<span>Confirmed</span>
            </div>

            <div class="hero-stat">
<strong><?= count(array_filter($registrations, function ($r) { return $r['registration_status'] === 'pending'; })) ?></strong>
<span>Pending</span>
            </div>

        </div>

        <div class="hero-actions">
            <a class="hero-button" href="index.php#events">
Browse More Events
            </a>
            <a class="hero-button hero-button-outline" href="#calendar-top">
View My Calendar
            </a>
        </div>

    </header>

    <main style="max-width:1400px">

        <?php if (!empty($_SESSION['registration_message'])): ?>
            <div class="message success registration-flash">
<?= escape($_SESSION['registration_message']) ?>
            </div>
            <?php unset($_SESSION['registration_message']); ?>
        <?php endif; ?>

        <?php
$todayStamp = strtotime(date('Y-m-d'));

        $monthParam = $_GET['cal_month'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
            $monthParam = date('Y-m');
        }

        $calFirst = strtotime($monthParam . '-01');
        $calMonth = (int) date('n', $calFirst);

$hasEventThisMonth = false;
        foreach ($registrations as $ev) {
            if (date('Y-m', strtotime($ev['event_date'])) === date('Y-m')) {
$hasEventThisMonth = true;
break;
            }
        }

        if (!$hasEventThisMonth && count($registrations) > 0
            && $monthParam === date('Y-m')) {
            $calFirst = strtotime(date('Y-m-01', strtotime($registrations[0]['event_date'])));
            $calMonth = (int) date('n', $calFirst);
        }

        $prevMonth = date('Y-m', strtotime('-1 month', $calFirst));
        $nextMonth = date('Y-m', strtotime('+1 month', $calFirst));

$myEventsByDate = [];
        foreach ($registrations as $ev) {
            $myEventsByDate[date('Y-m-d', strtotime($ev['event_date']))][] = $ev;
        }

$gridStart = strtotime(date('Y-m-01', $calFirst));
        while (date('w', $gridStart) != 0) {
            $gridStart = strtotime('-1 day', $gridStart);
        }
        ?>

        <div class="calendar-card" id="calendar-top">
            <div class="calendar-head">
<h3><?= escape(date('F Y', $calFirst)) ?></h3>
<div class="calendar-nav">
<a href="my_registrations.php?cal_month=<?= $prevMonth ?>#calendar-top">Prev</a>
<a href="my_registrations.php#calendar-top">Today</a>
<a href="my_registrations.php?cal_month=<?= $nextMonth ?>#calendar-top">Next</a>
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
$dayEvents = $myEventsByDate[$dayKey] ?? [];
$inMonth   = date('n', $cursor) === $calMonth;
$classes   = 'calendar-day';
if (!$inMonth) $classes .= ' is-blank';
if ($dayKey === date('Y-m-d', $todayStamp)) $classes .= ' is-today';
?>
<div class="<?= $classes ?>">
<span class="day-num"><?= date('j', $cursor) ?></span>

<?php foreach ($dayEvents as $ev):
$evPending = $ev['registration_status'] === 'pending';
?>
<a class="cal-event<?= $evPending ? ' is-pending' : '' ?>"
href="event-details.php?id=<?= (int) $ev['event_id'] ?>"
title="<?= escape($ev['title']) ?> — <?= escape($ev['venue']) ?> · <?= $evPending ? '⏳ Pending' : '✅ Confirmed' ?>">
<strong><?= escape($ev['title']) ?></strong>
<small><?= escape(date('g:i A', strtotime($ev['start_time']))) ?> · <?= escape($ev['venue']) ?></small>
<small><?= $evPending ? '⏳ Pending' : '✅ Confirmed' ?></small>
</a>
<?php endforeach; ?>
</div>
<?php
$cursor = strtotime('+1 day', $cursor);
endfor;
?>
            </div>

            <div class="calendar-legend">
<span><span class="legend-dot"></span>My event</span>
<span class="legend-pending"><span class="legend-dot"></span>⏳ Pending</span>
<span>🗓️ Highlighted box = today</span>
<span>Click an event for full details</span>
            </div>
        </div>

        <?php if (!empty($declinedRegistrations)): ?>

            <section class="declined-section">
<h2>Declined Registrations</h2>

<div class="table-responsive">
<table>
<thead>
<tr>
<th>Event</th>
<th>Event Date</th>
<th>Registered At</th>
<th>Decision At</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php foreach ($declinedRegistrations as $row): ?>
<tr>
<td><?= escape($row['title']) ?></td>
<td><?= formatDate($row['event_date']) ?></td>
<td><?= formatDateTime($row['registered_at']) ?></td>
<td><?= formatDateTime($row['confirmed_at']) ?></td>
<td>
<span class="status-badge status-declined">
Declined
</span>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
            </section>

        <?php endif; ?>

        <?php if (count($registrations) > 0): ?>

            
            <?php
            $histFilter = $_GET['history'] ?? 'all';
            $allowedHistFilters = ['all', 'attended', 'missed', 'cancelled', 'declined'];
            if (!in_array($histFilter, $allowedHistFilters, true)) {
                $histFilter = 'all';
            }

            $histBase = 'my_registrations.php';


            $histItems = [];
            foreach ($pastHistory as $h) {
                if (!empty($h['attended_at'])) {
                    $outcome = 'attended';
                    $outcomeLabel = 'Attended';
                } elseif ($h['registration_status'] === 'cancelled') {
                    $outcome = 'cancelled';
                    $outcomeLabel = 'Cancelled by me';
                } elseif ($h['registration_status'] === 'declined') {
                    $outcome = 'declined';
                    $outcomeLabel = 'Declined';
                } else {
                    $outcome = 'missed';
                    $outcomeLabel = 'Did Not Attend';
                }

                $h['_outcome'] = $outcome;
                $h['_outcomeLabel'] = $outcomeLabel;
                $histItems[] = $h;
            }
            ?>

            <section class="history-section" id="event-history">
                <div class="history-head">
                    <div>
<h2>📜 My Event History</h2>
<p class="history-sub">
A complete record of every campus event tied to your account —
events you attended, missed, cancelled, or were declined from.
</p>
                    </div>

                    <nav class="history-filter" aria-label="Filter event history">
<?php
$filters = [
    'all'       => 'All (' . count($histItems) . ')',
    'attended'  => 'Attended (' . $histAttended . ')',
    'missed'    => 'Missed (' . $histFinished . ')',
    'cancelled' => 'Cancelled (' . $histCancelled . ')',
    'declined'  => 'Declined (' . $histDeclined . ')',
];
foreach ($filters as $key => $label):
?>
<a href="<?= $histBase . '?history=' . urlencode($key) . '#event-history' ?>" data-filter="<?= $key ?>"
   class="<?= $histFilter === $key ? 'is-active' : '' ?>">
<?= escape($label) ?>
</a>
<?php endforeach; ?>
<span class="hist-live-count" id="hist-live-count"></span>
                    </nav>
                </div>

                <div class="history-stats">
                    <div class="history-stat hs-green">
<span class="hs-icon">✔</span>
<div>
<strong><?= $histAttended ?></strong>
<span>Events Attended</span>
</div>
                    </div>

                    <div class="history-stat hs-blue">
<span class="hs-icon">◷</span>
<div>
<strong><?= $histHours ?> hrs</strong>
<span>Time Invested</span>
<?php if ($histHours !== '0.0'): ?>
<small class="hs-note"><?= $histKnownEndCount < $histAttended
    ? 'estimated from check-ins'
    : 'from event schedules' ?></small>
<?php endif; ?>
</div>
                    </div>

                    <div class="history-stat hs-purple">
<span class="hs-icon">◈</span>
<div>
<strong><?= $histCategoryCount ?></strong>
<span>Fields Explored</span>
</div>
                    </div>

                    <div class="history-stat hs-amber">
<span class="hs-icon">▣</span>
<div>
<strong><?= count($eventHistory) ?></strong>
<span>Total Registrations</span>
</div>
                    </div>
                </div>

                <?php if (count($histItems) > 0): ?>

                    <div class="history-list" id="history-list">
<?php foreach ($histItems as $h):
$hidden = $histFilter !== 'all' && $histFilter !== $h['_outcome'];
?>
<div class="history-item hist-<?= $h['_outcome'] ?>"
     data-outcome="<?= $h['_outcome'] ?>"
     <?= $hidden ? 'hidden' : '' ?>>

<div class="history-thumb">
<?php if (!empty($h['event_image'])): ?>
<img src="uploads/events/<?= escape($h['event_image']) ?>"
     alt="<?= escape($h['title']) ?>">
<?php else: ?>
🎉
<?php endif; ?>
</div>

<div class="history-info">
<h4>
<a href="event-details.php?id=<?= (int) $h['event_id'] ?>"
   style="color: inherit; text-decoration: none;">
<?= escape($h['title']) ?>
</a>
</h4>

<div class="history-meta">
<span>▸ <strong><?= escape($h['venue']) ?></strong></span>
<?php if (!empty($h['attended_at'])): ?>
<span>• Checked in <?= formatDateTime($h['attended_at']) ?></span>
<?php else: ?>
<span>◷ <?= formatTime($h['start_time']) ?><?= !empty($h['end_time']) ? ' – ' . formatTime($h['end_time']) : '' ?></span>
<?php endif; ?>
<?php if (!empty($h['attended_at']) && !empty($h['end_time'])): ?>
<span>⌚ <?= formatTime($h['start_time']) ?> – <?= formatTime($h['end_time']) ?></span>
<?php endif; ?>
</div>

<div class="history-tags">
<span class="history-tag tag-category"><?= escape($h['category']) ?></span>
<span class="history-tag tag-<?= $h['_outcome'] ?>"><?= $h['_outcomeLabel'] ?></span>
<?php if ($h['_outcome'] === 'missed' && !empty($h['registered_at'])): ?>
<span class="history-tag tag-registered-only">Registered · Did not attend</span>
<?php endif; ?>
</div>
</div>

<div class="history-date-col">
<div class="hd-day"><?= date('j', strtotime($h['event_date'])) ?></div>
<div class="hd-month"><?= date('M', strtotime($h['event_date'])) ?></div>
<div class="hd-year"><?= date('Y', strtotime($h['event_date'])) ?></div>
</div>
</div>
<?php endforeach; ?>
                    </div>

                <?php else: ?>

                    <div class="history-empty">
<p class="empty-icon">📜</p>
<h3>No event history yet</h3>
<p>
Once you join your first campus event, your participation
history will appear here.
</p>
<a href="index.php#events" class="button button-secondary">
Explore Upcoming Events
</a>
                    </div>

                <?php endif; ?>
            </section>

            <div class="event-grid registrations-grid">

<?php foreach ($registrations as $registration): ?>

<?php
$taken = (int) $registration['taken_slots'];
$capacity = (int) $registration['capacity'];
$slotsLeft = max(0, $capacity - $taken);
$isPending =
$registration['registration_status'] === 'pending';
?>

<?php
$evStartTs = strtotime($registration['event_date'] . ' ' . $registration['start_time']);
$evEndTs = !empty($registration['end_time'])
? strtotime($registration['event_date'] . ' ' . $registration['end_time'])
: strtotime($registration['event_date'] . ' 23:59:59');
$nowTs = time();

$liveStatus = 'upcoming';
if ($nowTs >= $evStartTs && $nowTs <= $evEndTs) {
$liveStatus = 'ongoing';
} elseif ($nowTs > $evEndTs) {
$liveStatus = 'finished';
}
?>

<article id="reg-<?= (int) $registration['registration_id'] ?>" class="event-card<?= !empty($registration['event_image']) ? ' has-image' : '' ?><?= $isPending ? ' is-pending' : '' ?>"
<?php if (!empty($registration['event_image'])): ?>
style="--card-image: url('<?= escape(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>/uploads/events/<?= escape($registration['event_image']) ?>')"
<?php endif; ?>>

<div class="card-top-row">
<span class="category">
<?= escape($registration['category']) ?>
</span>

<span
class="event-status-badge status-<?= $liveStatus ?>"
data-start="<?= $evStartTs ?>"
data-end="<?= $evEndTs ?>"
>
<?= $liveStatus === 'ongoing'
? '● Ongoing'
: ($liveStatus === 'finished'
? '✓ Finished'
: '⏱ Upcoming') ?>
</span>
</div>

<h2>
<?= escape(
$registration['title']
) ?>
</h2>

<p>
<?= nl2br(
escape(
$registration['description'] ?? ''
)
) ?>
</p>

<div class="details">

<div class="detail-row">
<span class="detail-icon">📅</span>
<strong>Date:</strong>
<?= formatDate($registration['event_date']) ?>
</div>

<div class="detail-row">
<span class="detail-icon">⏰</span>
<strong>Start:</strong>
<?= formatTime($registration['start_time']) ?>
</div>

<?php if (!empty($registration['end_time'])): ?>

<div class="detail-row">
<span class="detail-icon">🏁</span>
<strong>End:</strong>
<?= formatTime($registration['end_time']) ?>
</div>

<?php endif; ?>

<div class="detail-row">
<span class="detail-icon">📍</span>
<strong>Venue:</strong>
<?= escape($registration['venue']) ?>
</div>

<div class="detail-row">
<span class="detail-icon">🕒</span>
<strong>Registered On:</strong>
<?= formatDateTime(
$registration['registered_at']
) ?>
</div>

<div class="detail-row">
<span class="detail-icon">
<?= $isPending ? '⏳' : '✅' ?>
</span>
<strong>Status:</strong>
<span class="status-badge status-<?= escape($registration['registration_status']) ?>">
<?= $isPending
? '⏳ Pending'
: '✅ Confirmed' ?>
</span>
<?php if ($isPending): ?>
<small class="muted">Awaiting admin approval</small>
<?php endif; ?>
</div>

<?php if (!$isPending && !empty($registration['attendance_token'])): ?>

<div class="detail-row">
<span class="detail-icon">👨‍🏫</span>
<strong>Confirmed By Admin On:</strong>
<?= formatDateTime(
$registration['confirmed_at']
) ?>
</div>

<?php endif; ?>

<div class="detail-row">
<span class="detail-icon">🎟️</span>
<strong>Event Slots:</strong>
<span><?= $slotsLeft ?> of <?= $capacity ?> remaining</span>
</div>

</div>

<?php if (!$isPending && !empty($registration['attendance_token'])): ?>

<div class="qr-pass-link-row">
    <?php if (!empty($registration['attended_at'])): ?>
        <div class="qr-checked-chip">✅ Checked in <?= formatDateTime($registration['attended_at']) ?></div>
    <?php endif; ?>
    <a class="qr-pass-button"
       href="qr_pass.php?token=<?= escape($registration['attendance_token']) ?>">
        View QR Pass
    </a>
</div>

<?php endif; ?>

<form
action="cancel-registration.php"
method="POST"
onsubmit="return confirm('Cancel your registration for this event?');"
>
<input
type="hidden"
name="registration_id"
value="<?= (int) $registration['registration_id'] ?>"
>

<input
type="hidden"
name="csrf_token"
value="<?= htmlspecialchars(
$_SESSION['csrf_token'] ?? '',
ENT_QUOTES,
'UTF-8'
) ?>"
>

<button type="submit" class="button-cancel">
Cancel Registration
</button>
</form>

</article>

<?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="message empty">
<p class="empty-icon">🎟️</p>
<h3>No registrations yet</h3>
<p>
You have not registered for any events yet.
Browse the upcoming campus events and join one
in a single click!
</p>
<a href="index.php#events" class="button button-secondary">
Browse Upcoming Events
</a>
            </div>

        <?php endif; ?>

    </main>

    <footer id="about">

        <p class="footer-school">
            Pateros Technological College — Campus Event Hub<br>
            &copy; <?= date('Y') ?> Pateros Technological College
        </p>

        <p>
            Discover, explore, and participate in campus events.
        </p>

    </footer>

        <script src="assets/js/main.js"></script>

        <script>
        (function () {
            var list = document.getElementById('history-list');
            if (!list) return;

            function setFilter(filter, pushUrl) {
                document.querySelectorAll('.history-filter a')
                    .forEach(function (a) {
                        a.classList.toggle('is-active', a.getAttribute('data-filter') === filter);
                    });

                var shown = 0;
                list.querySelectorAll('.history-item').forEach(function (item) {
                    var match = (filter === 'all' || item.getAttribute('data-outcome') === filter);
                    item.hidden = !match;
                    if (match) shown++;
                });


                var countBadge = document.getElementById('hist-live-count');
                if (countBadge) {
                    if (filter === 'all') {
                        countBadge.classList.remove('show');
                    } else {
                        countBadge.textContent = shown + ' event' + (shown === 1 ? '' : 's') + ' shown';
                        countBadge.classList.add('show');
                    }
                }

                if (pushUrl) history.replaceState(null, '', pushUrl);
            }

            document.querySelectorAll('.history-filter a').forEach(function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    setFilter(link.getAttribute('data-filter'), link.getAttribute('href'));
                });
            });


            var m = (location.search || '').match(/[?&]history=(\w+)/);
            if (m && m[1] !== 'all') setFilter(m[1], null);
        })();
        </script>
</body>
</html>
