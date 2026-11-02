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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

function formatDateTime($value)
{
    if (empty($value)) {
        return '—';
    }
    return date('M j, Y g:i A', strtotime($value));
}

$event_id = filter_input(
    INPUT_GET,
    'event_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($event_id === false || $event_id === 0) {
    $event_id = null;
}

$statusFilter = $_GET['status'] ?? ($_POST['status'] ?? 'all');
$validStatuses = ['all', 'pending', 'registered', 'declined', 'cancelled', 'attended', 'not_attended'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['bulk_action'] ?? '') === 'delete'
    && !empty($_POST['reg_ids'])) {

    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], is_string($posted) ? $posted : '')) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    $ids = array_filter(array_map('intval', (array) $_POST['reg_ids']));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $del = $pdo->prepare("DELETE FROM registrations WHERE id IN ($ph)");
        $del->execute($ids);
        $_SESSION['flash_attendees'] = 'Deleted ' . $del->rowCount() . ' registration'
            . ($del->rowCount() === 1 ? '' : 's') . ' successfully.';
    }

    $back = 'attendees.php?';
    if ($event_id !== null) $back .= 'event_id=' . (int) $event_id . '&';
    if ($statusFilter !== 'all') $back .= 'status=' . urlencode($statusFilter);
    header('Location: ' . rtrim($back, '&'));
    exit;
}

$flash = $_SESSION['flash_attendees'] ?? '';
unset($_SESSION['flash_attendees']);

$eventOptions = $pdo->query(
    "SELECT id, title FROM events ORDER BY event_date ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$currentEventTitle = null;

if ($event_id !== null) {
    foreach ($eventOptions as $opt) {
        if ((int) $opt['id'] === $event_id) {
            $currentEventTitle = $opt['title'];
            break;
        }
    }
}

$sql = "
    SELECT
        r.id AS registration_id,
        r.status AS registration_status,
        r.registered_at,
        r.confirmed_at,
        u.full_name,
        u.email,
        r.attended_at,
        e.id AS event_id,
        e.title AS event_title,
        e.event_date,
        e.venue,
        e.capacity,
        e.status AS event_status
    FROM registrations r
    INNER JOIN users u ON u.id = r.user_id
    INNER JOIN events e ON e.id = r.event_id
";

$params = [];
$conditions = [];

if ($event_id !== null) {
    $conditions[] = "e.id = :event_id";
    $params['event_id'] = $event_id;
}

if (in_array($statusFilter, ['pending', 'registered', 'declined', 'cancelled'], true)) {
    $conditions[] = "r.status = :status";
    $params['status'] = $statusFilter;
} elseif ($statusFilter === 'attended') {
    $conditions[] = "r.attended_at IS NOT NULL";
} elseif ($statusFilter === 'not_attended') {
    $conditions[] = "r.attended_at IS NULL";
}

if ($conditions) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
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
$attendees = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        'Venue',
        'Student Name',
        'Email',
        'Registration Status',
        'Attendance',
        'Checked In At',
        'Registered At',
        'Confirmed At'
    ]);

    foreach ($attendees as $row) {
        fputcsv($output, [
            $safe_csv($row['event_title']),
            $safe_csv($row['event_date']),
            $safe_csv($row['venue']),
            $safe_csv($row['full_name']),
            $safe_csv($row['email']),
            $safe_csv($row['registration_status']),
            !empty($row['attended_at']) ? 'Present' : 'Absent',
            $safe_csv($row['attended_at'] ?? ''),
            $safe_csv($row['registered_at']),
            $safe_csv($row['confirmed_at'] ?? '')
        ]);
    }

    fclose($output);
    exit;
}

$totalAttendees   = count($attendees);
$totalPending     = 0;
$totalConfirmed   = 0;
$totalDeclined    = 0;
$totalAttended    = 0;

foreach ($attendees as $row) {
    if (!empty($row['attended_at'])) {
        $totalAttended++;
    }
    switch ($row['registration_status']) {
        case 'pending':
            $totalPending++;
            break;
        case 'registered':
            $totalConfirmed++;
            break;
        case 'declined':
            $totalDeclined++;
            break;
    }
}

$snapAttended = $totalAttended;
$snapTotal    = $totalAttendees;
$snapNoShow   = $snapTotal - $snapAttended;
$snapRate     = $snapTotal > 0 ? round($snapAttended / $snapTotal * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Attendee List - Pateros Technological College</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .attendees-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 20px;
        }

        .attendees-header {
            background-image:
                linear-gradient(rgba(9, 46, 34, 0.84),
                    rgba(13, 71, 53, 0.90)),
                url('../assets/images/PTC_background.png');
            background-size: cover;
            background-position: center;
            border-radius: 18px;
            padding: 42px 40px;
            color: white;
            margin-bottom: 26px;
            box-shadow: 0 10px 32px rgba(11, 61, 46, 0.22);
        }

        .attendees-header .breadcrumb {
            font-size: 13px;
            color: #a8dcc3;
            margin-bottom: 10px;
        }

        .attendees-header .breadcrumb a {
            color: #9df3cd;
            text-decoration: none;
            font-weight: 600;
        }

        .attendees-header .breadcrumb a:hover {
            text-decoration: underline;
        }

        .attendees-header h1 {
            margin: 0 0 6px;
            font-size: 30px;
            text-shadow: 0 3px 12px rgba(0, 0, 0, 0.35);
        }

        .attendees-header p {
            margin: 0;
            color: #d8f3e6;
            font-size: 15px;
        }


        .attendee-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px;
            margin-bottom: 26px;
        }

        a.attendee-stat,
        .attendee-stat {
            background: white;
            border: 1px solid #e3efe8;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(20, 83, 45, 0.07);
            padding: 20px;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none;
        }

        a.attendee-stat:hover,
        .attendee-stat:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(20, 83, 45, 0.12);
        }

        a.attendee-stat .num,
        .attendee-stat .num {
            display: block;
            font-size: 32px;
            font-weight: 800;
            line-height: 1.15;
        }

        a.attendee-stat .lbl,
        .attendee-stat .lbl {
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #5b7268;
        }

        a.attendee-stat.active-stat {
            outline: 3px solid #27a377;
            outline-offset: -1px;
            box-shadow: 0 8px 22px rgba(30, 133, 99, 0.25);
        }

        a.attendee-stat .lbl::after {
            content: " →";
            opacity: 0;
            transition: opacity 0.15s ease;
        }

        a.attendee-stat:hover .lbl::after {
            opacity: 1;
        }

        a.attendee-stat.total .num,
        .attendee-stat.total .num {
            color: #0b3d2e;
        }

        a.attendee-stat.pending .num,
        .attendee-stat.pending .num {
            color: #b45309;
        }

        a.attendee-stat.confirmed .num,
        .attendee-stat.confirmed .num {
            color: #15803d;
        }

        a.attendee-stat.declined .num,
        .attendee-stat.declined .num {
            color: #b91c1c;
        }


        .attendee-filters {
            background: white;
            border: 1px solid #e3efe8;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(20, 83, 45, 0.07);
            padding: 18px 22px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .attendee-filters label {
            font-weight: 700;
            color: #0b3d2e;
            font-size: 14px;
            white-space: nowrap;
        }

        .attendee-filters select {
            flex: 1 1 260px;
            max-width: 420px;
            padding: 10px 14px;
            border: 1.5px solid #c8ddd3;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14.5px;
            background: #f8fdfa;
            cursor: pointer;
        }

        .attendee-filters select:focus {
            outline: none;
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(39, 163, 119, 0.18);
        }

        .attendee-filters .btn-go,
        .attendee-filters .btn-clear {
            padding: 10px 22px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }

        .attendee-filters .btn-go {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            color: white;
            box-shadow: 0 4px 12px rgba(30, 133, 99, 0.35);
        }

        .attendee-filters .btn-go:hover {
            filter: brightness(1.08);
        }

        .attendee-filters .btn-clear {
            background: white;
            color: var(--green-800);
            border: 1.5px solid #bfe0cd;
        }

        .attendee-filters .btn-clear:hover {
            background: var(--green-100);
        }


        .attendee-table-card {
            background: white;
            border: 1px solid #e3efe8;
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(20, 83, 45, 0.09);
            overflow: hidden;
        }

        .attendee-table-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            padding: 20px 26px;
            border-bottom: 2px solid #eef6f1;
        }

        .attendee-table-head h2 {
            margin: 0;
            font-size: 20px;
            color: #0b3d2e;
        }

        .attendee-table-head small {
            color: #5b7268;
            font-weight: 600;
        }

        .btn-export {
            display: inline-block;
            background: linear-gradient(135deg, #f9b000, #e09400);
            color: #3a2a00;
            font-weight: 700;
            font-size: 13.5px;
            padding: 10px 20px;
            border-radius: 999px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(249, 176, 0, 0.30);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(249, 176, 0, 0.45);
        }


        .attendee-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
            padding: 22px 26px 4px;
        }

        .sum-chip {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 18px 20px;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 700;
            color: white;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .sum-chip:hover {
            transform: translateY(-4px) scale(1.02);
        }

        .sum-chip .chip-ico {
            position: absolute;
            right: 14px;
            top: 12px;
            font-size: 30px;
            opacity: 0.30;
        }

        .sum-chip b {
            font-size: 34px;
            font-weight: 900;
            line-height: 1.1;
            letter-spacing: -1px;
        }

        .sum-chip .chip-lbl {
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            opacity: 0.92;
        }

        .sum-chip.c-present {
            background: linear-gradient(135deg, #16a34a, #15803d);
            box-shadow: 0 8px 22px rgba(21, 128, 61, 0.35);
        }

        .sum-chip.c-absent {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            box-shadow: 0 8px 22px rgba(217, 119, 6, 0.35);
        }

        .sum-chip.c-total {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            box-shadow: 0 8px 22px rgba(29, 78, 216, 0.35);
        }

        .sum-chip.c-rate {
            background: linear-gradient(135deg, var(--green-500), var(--green-600), #1b6f52);
            box-shadow: 0 8px 22px rgba(30, 133, 99, 0.40);
        }

        .rate-bar {
            margin-top: 6px;
            height: 9px;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 999px;
            overflow: hidden;
        }

        .rate-fill {
            display: block;
            height: 100%;
            background: linear-gradient(90deg, #fde68a, #fbbf24);
            border-radius: 999px;
            transition: width 0.8s cubic-bezier(0.22, 1, 0.36, 1);
        }


        .table-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 14px 26px;
            border-bottom: 1px solid #eef6f1;
            background: #fbfefc;
        }

        .search-wrap {
            position: relative;
            flex: 1 1 240px;
            max-width: 360px;
        }

        .search-wrap input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1.5px solid #c8ddd3;
            border-radius: 999px;
            font: inherit;
            font-size: 14px;
            background: white;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .search-wrap input:focus {
            outline: none;
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(39, 163, 119, 0.18);
        }

        .search-wrap::before {
            content: "🔍";
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            opacity: 0.55;
            pointer-events: none;
        }

        .search-live-count {
            font-size: 13px;
            font-weight: 700;
            color: #15803d;
            background: #e8f8ef;
            border: 1.5px solid #9de0bd;
            padding: 6px 14px;
            border-radius: 999px;
            display: none;
        }

        .search-live-count.show {
            display: inline-block;
        }


        th.sortable {
            cursor: pointer;
            user-select: none;
        }

        th.sortable:hover {
            background: rgba(255, 255, 255, 0.14);
        }

        th.sortable .arrow {
            opacity: 0.45;
            font-size: 10px;
            margin-left: 5px;
        }

        th.sortable.asc .arrow,
        th.sortable.desc .arrow {
            opacity: 1;
            color: #fbbf24;
        }


        .table-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 16px 26px;
            border-top: 2px solid #eef6f1;
        }

        .page-info {
            font-size: 13.5px;
            font-weight: 700;
            color: #5b7268;
        }

        .page-btns {
            display: flex;
            gap: 6px;
        }

        .page-btn {
            min-width: 38px;
            padding: 8px 12px;
            border: 1.5px solid #c8ddd3;
            background: white;
            border-radius: 10px;
            font-weight: 800;
            font-size: 13.5px;
            font-family: inherit;
            color: #0b3d2e;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .page-btn:hover:not(:disabled) {
            border-color: #27a377;
            background: #f0faf5;
        }

        .page-btn.active {
            background: linear-gradient(135deg, #27a377, #1b6f52);
            color: white;
            border-color: #1b6f52;
            box-shadow: 0 4px 10px rgba(30, 133, 99, 0.30);
        }

        .page-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .attendee-table-wrap {
            overflow-x: auto;
        }

        table.attendee-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.attendee-table th {
            background: linear-gradient(90deg, var(--green-800), var(--green-600));
            color: white;
            padding: 13px 16px;
            font-size: 13px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            white-space: nowrap;
            text-align: left;
        }

        table.attendee-table td {
            padding: 13px 16px;
            border-bottom: 1px solid #eef6f1;
            font-size: 14.5px;
            vertical-align: middle;
        }

        table.attendee-table tbody tr {
            transition: background 0.15s ease;
        }

        table.attendee-table tbody tr:hover {
            background: #f4fbf7;
        }

        table.attendee-table td.col-check {
            width: 52px;
            text-align: center;
        }

        table.attendee-table tbody tr.row-selected {
            background: linear-gradient(90deg, #eafaf1, #f2fcf6) !important;
            box-shadow: inset 4px 0 0 0 #27a377;
        }

        table.attendee-table tbody tr.row-selected:hover {
            background: linear-gradient(90deg, #ddf5e8, #eafaf1) !important;
        }

        .student-name {
            font-weight: 700;
            color: #0b3d2e;
        }

        .student-email {
            display: block;
            font-size: 12.5px;
            color: #7a8d80;
            word-break: break-all;
        }

        .event-cell strong {
            color: #14532d;
        }

        .event-cell small {
            display: block;
            color: #7a8d80;
            font-size: 12px;
        }


        .attendee-flash {
            background: linear-gradient(90deg, #e8f8ef, #f0fdf4);
            border: 1.5px solid #9de0bd;
            color: #15803d;
            font-weight: 700;
            font-size: 14.5px;
            padding: 14px 22px;
            margin: 18px 26px 0;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(21, 128, 61, 0.10);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .attendee-flash::before {
            content: "✔";
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #16a34a;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 900;
            flex-shrink: 0;
        }


        .bulk-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            padding: 16px 26px;
            background: linear-gradient(90deg, #f0faf5 0%, #e8f6ef 100%);
            border-bottom: 2px solid #d5ecdf;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 3px 12px rgba(11, 61, 46, 0.07);
        }

        .bulk-bar-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .bulk-bar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bulk-check-all {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
            padding: 8px 16px 8px 12px;
            background: white;
            border: 2px solid #c8ddd3;
            border-radius: 999px;
            transition: border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
        }

        .bulk-check-all:hover {
            border-color: #27a377;
            box-shadow: 0 3px 10px rgba(30, 133, 99, 0.15);
        }

        .bulk-check-all input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .check-all-box {
            width: 22px;
            height: 22px;
            border: 2px solid #9cc5b2;
            border-radius: 7px;
            background: white;
            position: relative;
            display: inline-block;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .bulk-check-all input:checked ~ .check-all-box {
            background: linear-gradient(135deg, #27a377, #1b6f52);
            border-color: #1b6f52;
        }

        .bulk-check-all input:checked ~ .check-all-box::after {
            content: "✔";
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 13px;
            font-weight: 900;
            line-height: 1;
        }

        .check-all-text {
            font-weight: 800;
            font-size: 14px;
            color: #0b3d2e;
        }

        .bulk-count {
            font-size: 13.5px;
            font-weight: 800;
            color: #5b7268;
            background: white;
            border: 1.5px solid #dfeee5;
            padding: 8px 18px;
            border-radius: 999px;
            min-width: 110px;
            text-align: center;
            transition: all 0.18s ease;
        }

        .bulk-count.hot {
            background: linear-gradient(135deg, #0b3d2e, #14532d);
            color: #a7f3d0;
            border-color: #0b3d2e;
            box-shadow: 0 4px 14px rgba(11, 61, 46, 0.28);
            transform: scale(1.04);
        }

        .btn-bulk-delete {
            padding: 12px 26px;
            border: none;
            border-radius: 999px;
            font-weight: 800;
            font-size: 14.5px;
            cursor: pointer;
            color: white;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            box-shadow: 0 6px 18px rgba(185, 28, 28, 0.35);
            transition: transform 0.18s ease, filter 0.18s ease, box-shadow 0.18s ease;
        }

        .btn-bulk-delete:hover:not(:disabled) {
            transform: translateY(-2px) scale(1.02);
            filter: brightness(1.1);
            box-shadow: 0 10px 26px rgba(185, 28, 28, 0.45);
        }

        .btn-bulk-delete:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-bulk-delete:disabled {
            opacity: 0.35;
            cursor: not-allowed;
            box-shadow: none;
            filter: grayscale(0.5);
        }

        .btn-bulk-clear {
            padding: 10px 18px;
            border: 1.5px solid #c8ddd3;
            border-radius: 999px;
            background: white;
            color: #40614f;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            transition: border-color 0.18s ease, background 0.18s ease;
        }

        .btn-bulk-clear:hover {
            border-color: #7a8d80;
            background: #f4fbf7;
        }


        .row-check {
            appearance: none;
            -webkit-appearance: none;
            width: 22px;
            height: 22px;
            border: 2px solid #9cc5b2;
            border-radius: 7px;
            background: white;
            cursor: pointer;
            position: relative;
            vertical-align: middle;
            transition: background 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
        }

        .row-check:hover {
            border-color: #27a377;
            transform: scale(1.1);
            box-shadow: 0 0 0 4px rgba(39, 163, 119, 0.12);
        }

        .row-check:checked {
            background: linear-gradient(135deg, #27a377, #1b6f52);
            border-color: #1b6f52;
            transform: scale(1);
        }

        .row-check:checked::after {
            content: "✔";
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            font-weight: 900;
            line-height: 1;
        }


        .student-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #27a377, #1b6f52);
            color: white;
            font-weight: 800;
            font-size: 17px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(30, 133, 99, 0.28);
            text-transform: uppercase;
        }

        .student-meta {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        .student-meta .student-name {
            font-weight: 700;
            color: #0b3d2e;
            line-height: 1.3;
        }

        .student-meta .student-email {
            display: block;
            font-size: 12.5px;
            color: #7a8d80;
            word-break: break-all;
            line-height: 1.3;
        }


        .attend-pill {
            display: inline-block;
            padding: 4px 13px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .attend-pill.present {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            color: #15803d;
            box-shadow: 0 2px 8px rgba(21, 128, 61, 0.18);
        }

        .attend-pill.absent {
            background: #f3f4f6;
            color: #6b7280;
        }


        .attendee-empty {
            text-align: center;
            padding: 60px 20px;
            color: #5b7268;
        }

        .attendee-empty .icon {
            font-size: 48px;
            display: block;
            margin-bottom: 10px;
        }

        .no-results-row td {
            text-align: center;
            color: #7a8d80;
            font-weight: 700;
            padding: 40px 20px !important;
        }

        @media (max-width: 700px) {
            .attendees-header {
                padding: 30px 22px;
            }

            .attendees-header h1 {
                font-size: 23px;
            }

            .attendee-filters {
                padding: 14px;
            }

            .bulk-bar {
                padding: 14px 18px;
            }
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
            <a href="events.php">Manage Events</a>
            <a href="registrations.php">Registrations</a>
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

    <main class="attendees-container">

        
        <header class="attendees-header">
            <div class="breadcrumb">
                <a href="index.php">Dashboard</a> ›
                <a href="registrations.php">Registrations</a> ›
                Attendee List
            </div>

            <h1>👥 Attendee List</h1>

            <p>
                <?php if ($currentEventTitle): ?>
                    Showing attendees for
                    <strong style="color:#9df3cd"><?= escape($currentEventTitle) ?></strong>
                    — <?= $totalAttendees ?> registration<?= $totalAttendees === 1 ? '' : 's' ?> found.
                <?php else: ?>
                    All campus events — <?= $totalAttendees ?> registration<?= $totalAttendees === 1 ? '' : 's' ?> found.
                <?php endif; ?>
            </p>
        </header>

        
        <section class="attendee-stats">
            <?php
            $baseQ = 'attendees.php?';
            if ($event_id !== null) $baseQ .= 'event_id=' . (int) $event_id . '&';
            $statLinks = [
                ['all',        $totalAttendees, 'Total Records', 'total'],
                ['registered', $totalConfirmed, 'Confirmed',     'confirmed'],
                ['attended',   $totalAttended,  '✅ Attended',    ''],
                ['pending',    $totalPending,   'Pending',       'pending'],
                ['declined',   $totalDeclined,  'Declined',      'declined'],
            ];
            ?>
            <?php foreach ($statLinks as [$st, $num, $lbl, $cls]): ?>
                <a class="attendee-stat <?= $cls ?> <?= $statusFilter === $st ? 'active-stat' : '' ?>"
                   href="<?= $baseQ . 'status=' . $st ?>">
                    <span class="num" <?= $st === 'attended' ? 'style="color:#15803d"' : '' ?>><?= $num ?></span>
                    <span class="lbl"><?= $lbl ?></span>
                </a>
            <?php endforeach; ?>
        </section>

        
        <section class="attendee-filters">
            <form method="GET" action="attendees.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center; width:100%;">

                <label for="f_event">Event:</label>

                <select id="f_event" name="event_id" onchange="this.form.submit()">
                    <option value="0">All events</option>
                    <?php foreach ($eventOptions as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>"
                            <?= $event_id !== null && (int) $opt['id'] === $event_id ? 'selected' : '' ?>>
                            <?= escape($opt['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="f_status">Status:</label>

                <select id="f_status" name="status" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <option value="attended" <?= $statusFilter === 'attended' ? 'selected' : '' ?>>✔ Attended</option>
                    <option value="not_attended" <?= $statusFilter === 'not_attended' ? 'selected' : '' ?>>✕ Did not attend</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="registered" <?= $statusFilter === 'registered' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="declined" <?= $statusFilter === 'declined' ? 'selected' : '' ?>>Declined</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>

                <a class="btn-clear" href="attendees.php">Reset</a>
            </form>
        </section>

        
        <section class="attendee-table-card">

            <div class="attendee-summary">
                <div class="sum-chip c-present">
                    <span class="chip-ico">✔</span>
                    <b><?= $snapAttended ?></b>
                    <span class="chip-lbl">Attended</span>
                </div>
                <div class="sum-chip c-absent">
                    <span class="chip-ico">✕</span>
                    <b><?= $snapNoShow ?></b>
                    <span class="chip-lbl">Did not attend</span>
                </div>
                <div class="sum-chip c-total">
                    <span class="chip-ico">👥</span>
                    <b><?= $snapTotal ?></b>
                    <span class="chip-lbl">Total records</span>
                </div>
                <div class="sum-chip c-rate">
                    <span class="chip-ico">📈</span>
                    <b><?= $snapRate ?>%</b>
                    <span class="chip-lbl">Attendance rate</span>
                    <div class="rate-bar"><span class="rate-fill" style="width: <?= $snapRate ?>%"></span></div>
                </div>
            </div>

            <div class="attendee-table-head">
                <div>
                    <h2><?= $currentEventTitle
                            ? 'Attendees — ' . escape($currentEventTitle)
                            : 'All Attendees' ?></h2>
                    <small><?= $totalAttendees ?> record<?= $totalAttendees === 1 ? '' : 's' ?>
                        <?= $statusFilter !== 'all' ? '· filtered by ' . escape($statusFilter) : '' ?></small>
                </div>

                <a class="btn-export"
                    href="attendees.php?export=csv<?= $event_id !== null ? '&event_id=' . (int) $event_id : '' ?><?= $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '' ?>">
                    Export to CSV
                </a>
            </div>

            <?php if ($flash): ?>
                <div class="attendee-flash"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (count($attendees) > 0): ?>

                
                <form method="POST" action="attendees.php" id="bulk-form">
                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="bulk_action" value="delete">
                    <?php if ($event_id !== null): ?>
                        <input type="hidden" name="event_id" value="<?= (int) $event_id ?>">
                    <?php endif; ?>
                    <?php if ($statusFilter !== 'all'): ?>
                        <input type="hidden" name="status" value="<?= escape($statusFilter) ?>">
                    <?php endif; ?>

                    <div class="bulk-bar" id="bulk-bar">
                        <div class="bulk-bar-left">
                            <label class="bulk-check-all">
                                <input type="checkbox" id="check-all">
                                <span class="check-all-box"></span>
                                <span class="check-all-text">Select all on this page</span>
                            </label>
                            <span class="bulk-count" id="bulk-count">0 selected</span>
                        </div>
                        <div class="bulk-bar-right">
                            <button type="button" class="btn-bulk-clear" id="btn-bulk-clear" style="display:none">
                                Clear selection
                            </button>
                            <button type="submit" class="btn-bulk-delete" id="btn-bulk-delete" disabled>
                                Delete Selected
                            </button>
                        </div>
                    </div>

                    <div class="table-toolbar">
                        <div class="search-wrap">
                            <input type="text" id="table-search" placeholder="Search name, email, event…" autocomplete="off">
                        </div>
                        <span class="search-live-count" id="search-live-count"></span>
                    </div>

                    <div class="attendee-table-wrap">
                        <table class="attendee-table">
                            <thead>
                                <tr>
                                    <th class="col-check"></th>
                                    <th class="sortable" data-sort="0">Event <span class="arrow">▲▼</span></th>
                                    <th class="sortable" data-sort="1">Student <span class="arrow">▲▼</span></th>
                                    <th class="sortable" data-sort="2">Status <span class="arrow">▲▼</span></th>
                                    <th>Attendance</th>
                                    <th class="sortable" data-sort="5">Registered At <span class="arrow">▲▼</span></th>
                                    <th class="sortable" data-sort="6">Confirmed At <span class="arrow">▲▼</span></th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($attendees as $row): ?>
                                    <tr data-reg-id="<?= (int) $row['registration_id'] ?>">
                                        <td class="col-check">
                                            <input type="checkbox"
                                                   class="row-check"
                                                   name="reg_ids[]"
                                                   value="<?= (int) $row['registration_id'] ?>">
                                        </td>
                                        <td class="event-cell">
                                            <strong><?= escape($row['event_title']) ?></strong>
                                            <small>📅 <?= formatDate($row['event_date']) ?> · 📍 <?= escape($row['venue']) ?></small>
                                        </td>

                                        <td>
                                            <div class="student-cell">
                                                <span class="student-avatar"><?= escape(strtoupper(mb_substr($row['full_name'], 0, 1))) ?></span>
                                                <div class="student-meta">
                                                    <span class="student-name"><?= escape($row['full_name']) ?></span>
                                                    <span class="student-email"><?= escape($row['email']) ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="status-badge status-<?= escape($row['registration_status']) ?>">
                                                <?= escape(ucfirst($row['registration_status'])) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if (!empty($row['attended_at'])): ?>
                                                <span class="attend-pill present">✔ Present</span>
                                                <small style="display:block; color:#15803d; font-size:11.5px; margin-top:3px;">
                                                    Checked in <?= escape(date('M j, g:i A', strtotime($row['attended_at']))) ?></small>
                                            <?php elseif (in_array($row['registration_status'], ['cancelled', 'declined'], true)): ?>
                                                <span class="attend-pill not-attended">✕ Did not attend</span>
                                                <small style="display:block; color:#92400e; font-size:11.5px; margin-top:3px;">
                                                    Registration <?= escape($row['registration_status']) ?></small>
                                            <?php else: ?>
                                                <?php
                                                $evEnded = !empty($row['event_date'])
                                                    && strtotime($row['event_date'] . ' 23:59:59') < time();
                                                ?>
                                                <?php if ($evEnded): ?>
                                                    <span class="attend-pill registered-no-show">Registered — Did Not Attend</span>
                                                    <small style="display:block; color:#64748b; font-size:11.5px; margin-top:3px;">
                                                        No check-in recorded</small>
                                                <?php else: ?>
                                                    <span class="attend-pill absent">○ Not yet</span>
                                                    <small style="display:block; color:#64748b; font-size:11.5px; margin-top:3px;">
                                                        Event still upcoming</small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>

                                        <td><?= formatDateTime($row['registered_at']) ?></td>

                                        <td><?= formatDateTime($row['confirmed_at'] ?? null) ?></td>

                                        <td>
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

                    <div class="table-pagination">
                        <span class="page-info" id="page-info"></span>
                        <div class="page-btns" id="page-btns"></div>
                    </div>
                </form>

            <?php else: ?>

                <div class="attendee-empty">
                    <span class="icon">🗂️</span>
                    <h3>No attendees found</h3>
                    <p>No registration records match the current filters.</p>
                    <a class="btn-clear" href="attendees.php">Clear filters</a>
                </div>

            <?php endif; ?>

        </section>

    </main>

    <footer>
        <p>Pateros Technological College &copy; <?= date('Y') ?></p>
    </footer>

    <script src="../assets/js/main.js"></script>

    <script>
        (function () {
            const checkAll = document.getElementById('check-all');
            const rowChecks = Array.from(document.querySelectorAll('.row-check'));
            const bulkCount = document.getElementById('bulk-count');
            const bulkDelete = document.getElementById('btn-bulk-delete');
            const bulkForm = document.getElementById('bulk-form');

            function refresh() {
                const picked = rowChecks.filter(c => c.checked).length;
                bulkCount.textContent = picked + ' selected';
                bulkCount.classList.toggle('hot', picked > 0);
                bulkDelete.disabled = picked === 0;
                const clearBtn = document.getElementById('btn-bulk-clear');
                if (clearBtn) clearBtn.style.display = picked > 0 ? 'inline-block' : 'none';
                rowChecks.forEach(c => {
                    c.closest('tr').classList.toggle('row-selected', c.checked);
                });
                if (checkAll) {
                    checkAll.checked = rowChecks.length > 0 && picked === rowChecks.length;
                }
            }

            rowChecks.forEach(c => c.addEventListener('change', refresh));

            if (checkAll) {
                checkAll.addEventListener('change', () => {
                    rowChecks.forEach(c => { c.checked = checkAll.checked; });
                    refresh();
                });
            }

            bulkForm.addEventListener('submit', (e) => {
                const picked = rowChecks.filter(c => c.checked).length;
                if (picked === 0) {
                    e.preventDefault();
                    return;
                }
                const names = rowChecks.filter(c => c.checked)
                    .slice(0, 5)
                    .map(c => c.closest('tr').querySelector('.student-name')?.textContent.trim())
                    .filter(Boolean).join(', ');
                const more = picked > 5 ? ' and ' + (picked - 5) + ' more…' : '';
                const ok = confirm(
                    'Delete ' + picked + ' registration' + (picked === 1 ? '' : 's') + '?\n\n' +
                    names + more + '\n\n' +
                    'This permanently removes the record (including any attendance). This cannot be undone.'
                );
                if (!ok) e.preventDefault();
            });

            const btnClear = document.getElementById('btn-bulk-clear');
            if (btnClear) {
                btnClear.addEventListener('click', () => {
                    rowChecks.forEach(c => { c.checked = false; });
                    refresh();
                });
            }

            refresh();
        })();


        (function () {
            const tbody = document.querySelector('.attendee-table tbody');
            if (!tbody) return;

            const searchInput = document.getElementById('table-search');
            const pageInfo = document.getElementById('page-info');
            const pageBtns = document.getElementById('page-btns');
            const liveCount = document.getElementById('search-live-count');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const PER_PAGE = 10;

            let query = '';
            let sortCol = null;
            let sortDir = 1;
            let page = 1;

            const cellText = (row, i) => (row.cells[i] ? row.cells[i].textContent.trim().toLowerCase() : '');

            function filtered() {
                let list = rows;
                if (query) {
                    list = list.filter(r => r.textContent.toLowerCase().includes(query));
                }
                if (sortCol !== null) {
                    list = list.slice().sort((a, b) => {
                        const x = cellText(a, sortCol), y = cellText(b, sortCol);
                        const nx = parseFloat(x.replace(/[^\d.]/g, '')), ny = parseFloat(y.replace(/[^\d.]/g, ''));
                        const numeric = !isNaN(nx) && !isNaN(ny) && x.match(/[a-z]/i) === null;
                        const cmp = numeric ? nx - ny : x.localeCompare(y);
                        return cmp * sortDir;
                    });
                }
                return list;
            }

            function render() {
                const list = filtered();
                const totalPages = Math.max(1, Math.ceil(list.length / PER_PAGE));
                if (page > totalPages) page = totalPages;

                rows.forEach(r => { r.style.display = 'none'; });
                list.slice((page - 1) * PER_PAGE, page * PER_PAGE)
                    .forEach(r => { r.style.display = ''; });

                const start = list.length === 0 ? 0 : (page - 1) * PER_PAGE + 1;
                const end = Math.min(page * PER_PAGE, list.length);
                pageInfo.textContent = list.length === 0
                    ? 'No matching records'
                    : 'Showing ' + start + '–' + end + ' of ' + list.length + ' records';

                if (liveCount) {
                    if (query) {
                        liveCount.textContent = list.length + ' match' + (list.length === 1 ? '' : 'es');
                        liveCount.classList.add('show');
                    } else {
                        liveCount.classList.remove('show');
                    }
                }

                pageBtns.innerHTML = '';
                const mk = (label, target, disabled, active) => {
                    const b = document.createElement('button');
                    b.className = 'page-btn' + (active ? ' active' : '');
                    b.textContent = label;
                    b.disabled = disabled;
                    if (!disabled && !active) b.addEventListener('click', () => { page = target; render(); });
                    pageBtns.appendChild(b);
                };
                mk('‹', page - 1, page === 1, false);
                for (let p = 1; p <= totalPages; p++) {
                    if (totalPages > 7 && p !== 1 && p !== totalPages && Math.abs(p - page) > 1) {
                        if (p === 2 || p === totalPages - 1) mk('…', p, true, false);
                        continue;
                    }
                    mk(String(p), p, false, p === page);
                }
                mk('›', page + 1, page === totalPages, false);
            }

            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    query = searchInput.value.trim().toLowerCase();
                    page = 1;
                    render();
                });
            }

            document.querySelectorAll('th.sortable').forEach(th => {
                th.addEventListener('click', () => {
                    const col = parseInt(th.dataset.sort, 10);
                    if (sortCol === col) { sortDir *= -1; } else { sortCol = col; sortDir = 1; }
                    document.querySelectorAll('th.sortable').forEach(t => t.classList.remove('asc', 'desc'));
                    th.classList.add(sortDir === 1 ? 'asc' : 'desc');
                    page = 1;
                    render();
                });
            });

            render();
        })();
    </script>
</body>

</html>
