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

$eventOptions = $pdo->query(
    "SELECT id, title, event_date, venue FROM events WHERE status = 'active' ORDER BY event_date ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$selectedEventId = filter_input(
    INPUT_GET,
    'event_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$stmt = $pdo->query("
    SELECT
        e.id AS event_id,
        e.title AS event_title,
        e.event_date,
        e.venue,
        COUNT(r.id) AS total_registered,
        SUM(CASE WHEN r.status IN ('registered', 'pending') THEN 1 ELSE 0 END) AS total_active,
        SUM(CASE WHEN r.attended_at IS NOT NULL THEN 1 ELSE 0 END) AS total_attended
    FROM events e
    LEFT JOIN registrations r ON r.event_id = e.id
        AND r.status IN ('registered', 'pending', 'declined')
    WHERE e.status IN ('active', 'archived')
    GROUP BY e.id, e.title, e.event_date, e.venue
    HAVING total_registered > 0
    ORDER BY e.event_date DESC, e.title ASC
");
$eventAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rowsStmt = $pdo->query("
    SELECT
        r.id AS reg_id,
        r.event_id,
        r.status AS reg_status,
        r.attended_at,
        r.registered_at,
        r.confirmed_at,
        u.full_name,
        u.email,
        e.title AS event_title
    FROM registrations r
    INNER JOIN users u ON u.id = r.user_id
    INNER JOIN events e ON e.id = r.event_id
    WHERE r.status IN ('registered', 'pending', 'declined')
    ORDER BY e.event_date DESC, e.start_time DESC, r.attended_at DESC, u.full_name ASC
");
$allRegRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>QR Attendance Scanner - Pateros Technological College</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .scan-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 24px 32px;
        }

        .scan-header {
            background-image:
                linear-gradient(rgba(9, 46, 34, 0.84), rgba(13, 71, 53, 0.90)),
                url('../assets/images/PTC_background.png');
            background-size: cover;
            background-position: center;
            border-radius: 18px;
            padding: 38px 36px;
            color: white;
            margin-bottom: 26px;
            box-shadow: 0 10px 32px rgba(11, 61, 46, 0.22);
        }

        .scan-header h1 {
            margin: 0 0 6px;
            font-size: 28px;
            text-shadow: 0 3px 12px rgba(0, 0, 0, 0.35);
        }

        .scan-header p {
            margin: 0;
            color: #d8f3e6;
            font-size: 14.5px;
        }

        .scan-card {
            background: white;
            border: 1px solid #e3efe8;
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(20, 83, 45, 0.09);
            padding: 24px;
            margin-bottom: 22px;
        }

        #qr-reader {
            position: relative;
            border: 2px dashed #bfe0cd;
            border-radius: 14px;
            overflow: hidden;
            max-width: 860px;
            margin: 0 auto;
            background: #0b3d2e;
        }

        #qr-reader video {
            border-radius: 12px;
            width: 100% !important;
            object-fit: cover;
        }

        #native-video {
            display: block;
            width: 100%;
            max-height: 560px;
            object-fit: cover;
            border-radius: 12px;
            background: #06231a;
        }


        .scan-overlay {
            position: absolute;
            inset: 0;
            pointer-events: none;
            display: none;
        }

        .scan-overlay.active { display: block; }

        .scan-overlay .scan-frame {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 66%;
            max-width: 460px;
            aspect-ratio: 1;
            transform: translate(-50%, -50%);
            border-radius: 22px;
            box-shadow: 0 0 0 100vmax rgba(6, 34, 25, 0.55);
        }

        .scan-overlay .scan-frame::before,
        .scan-overlay .scan-frame::after {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: 18px;
            border: 4px solid transparent;
        }

        .scan-overlay .scan-frame::before {
            border-top-color: #2ee6a8;
            border-bottom-color: #2ee6a8;
            clip-path: inset(0 0 0 0);
            -webkit-mask:
                linear-gradient(#000 0 0) top/left no-repeat 0 0/100% 12%,
                linear-gradient(#000 0 0) bottom/left no-repeat 0 100%/100% 12%;
            mask:
                linear-gradient(#000 0 0) top/left no-repeat 0 0/100% 12%,
                linear-gradient(#000 0 0) bottom/left no-repeat 0 100%/100% 12%;
        }

        .scan-overlay .scan-frame::after {
            border-left-color: #2ee6a8;
            border-right-color: #2ee6a8;
            -webkit-mask:
                linear-gradient(#000 0 0) left no-repeat 0 0/12% 100%,
                linear-gradient(#000 0 0) right no-repeat 100% 0/12% 100%;
            mask:
                linear-gradient(#000 0 0) left no-repeat 0 0/12% 100%,
                linear-gradient(#000 0 0) right no-repeat 100% 0/12% 100%;
        }

        .scan-overlay .scan-beam {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 66%;
            max-width: 460px;
            height: 5px;
            transform: translate(-50%, -50%);
            background: linear-gradient(90deg, transparent, #2ee6a8, transparent);
            border-radius: 999px;
            animation: scan-sweep 2.2s ease-in-out infinite;
            box-shadow: 0 0 14px rgba(46, 230, 168, 0.9);
        }

        @keyframes scan-sweep {
            0%, 100% { top: 32%; }
            50%      { top: 68%; }
        }

        .flash-ok { animation: flash-ok 0.5s ease; }

        @keyframes flash-ok {
            0%   { box-shadow: 0 0 0 0 rgba(21, 128, 61, 0.55); }
            100% { box-shadow: 0 0 0 24px rgba(21, 128, 61, 0); }
        }

        .scan-actions {
            text-align: center;
            margin-top: 16px;
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-scan {
            padding: 12px 26px;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 15px;
            background: linear-gradient(135deg, #27a377, #1b6f52);
            color: white;
            box-shadow: 0 4px 14px rgba(30, 133, 99, 0.35);
        }

        .btn-scan.stop {
            background: #b91c1c;
            box-shadow: 0 4px 14px rgba(185, 28, 28, 0.35);
        }

        .scan-result {
            max-width: 720px;
            margin: 18px auto 0;
            padding: 16px 20px;
            border-radius: 12px;
            font-weight: 600;
            display: none;
        }

        .scan-result.success { background: #e8f8ef; color: #15803d; border: 1.5px solid #9de0bd; }
        .scan-result.warn    { background: #fef6e0; color: #b45309; border: 1.5px solid #f5d78e; }
        .scan-result.error   { background: #fdeaea; color: #b91c1c; border: 1.5px solid #f3b3b3; }
        .scan-result.wrong   { background: linear-gradient(90deg, #fdeaea, #fff5f5); color: #b91c1c; border: 1.5px solid #dc2626; font-weight: 800; box-shadow: 0 6px 18px rgba(220, 38, 38, 0.25); }


        .confirm-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(4, 26, 19, 0.72);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
        }

        .confirm-backdrop.show { display: flex; animation: fade-in 0.22s ease; }

        @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }

        .confirm-card {
            background: white;
            border-radius: 24px;
            padding: 40px 44px 34px;
            max-width: 440px;
            width: 100%;
            text-align: center;
            box-shadow:
                0 24px 70px rgba(0, 0, 0, 0.45),
                0 0 0 1px rgba(255, 255, 255, 0.08) inset;
            animation: pop-in 0.32s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
        }


        .confirm-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #27a377, #4ee39c, #27a377);
        }

        .confirm-card.err::before,
        .confirm-card.wrong::before {
            background: linear-gradient(90deg, #dc2626, #f87171, #dc2626);
        }

        .confirm-card.dup::before {
            background: linear-gradient(90deg, #d97706, #fbbf24, #d97706);
        }

        @keyframes pop-in {
            from { transform: scale(0.82) translateY(18px); opacity: 0; }
            to   { transform: scale(1) translateY(0); opacity: 1; }
        }

        .confirm-card.ok    { border-top: 6px solid #16a34a; }
        .confirm-card.dup   { border-top: 6px solid #d97706; }
        .confirm-card.err   { border-top: 6px solid #dc2626; }
        .confirm-card.wrong { border-top: 6px solid #dc2626; background: linear-gradient(180deg, #fff, #fff5f5); }

        .confirm-icon {
            width: 86px;
            height: 86px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            animation: pop-in 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .confirm-card.ok  .confirm-icon { background: #e8f8ef; box-shadow: 0 0 0 10px rgba(22, 163, 74, 0.12); }
        .confirm-card.dup .confirm-icon { background: #fef6e0; box-shadow: 0 0 0 10px rgba(217, 119, 6, 0.12); }
        .confirm-card.err .confirm-icon { background: #fdeaea; box-shadow: 0 0 0 10px rgba(220, 38, 38, 0.12); }
        .confirm-card.wrong .confirm-icon { background: #fdeaea; box-shadow: 0 0 0 10px rgba(220, 38, 38, 0.12); }

        .confirm-title {
            margin: 0 0 4px;
            font-size: 24px;
            font-weight: 800;
        }

        .confirm-card.ok  .confirm-title { color: #15803d; }
        .confirm-card.dup .confirm-title { color: #b45309; }
        .confirm-card.err .confirm-title { color: #b91c1c; }
        .confirm-card.wrong .confirm-title { color: #b91c1c; }

        .confirm-name {
            font-size: 21px;
            font-weight: 800;
            color: #0b3d2e;
            margin: 10px 0 2px;
            word-break: break-word;
        }

        .confirm-event {
            font-size: 15px;
            color: #40614f;
            margin: 0 0 6px;
        }

        .confirm-time {
            display: inline-block;
            margin-top: 8px;
            padding: 6px 16px;
            border-radius: 999px;
            background: #f0f7f3;
            border: 1px solid #d5e8de;
            color: #14532d;
            font-size: 13.5px;
            font-weight: 700;
        }

        .confirm-msg {
            margin: 12px 0 0;
            font-size: 14.5px;
            color: #556b60;
        }

        .confirm-btn {
            margin-top: 22px;
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 999px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            letter-spacing: 0.3px;
            color: white;
            background: linear-gradient(135deg, #27a377, #1b6f52);
            box-shadow: 0 6px 18px rgba(30, 133, 99, 0.4);
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }

        .confirm-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(30, 133, 99, 0.5);
            filter: brightness(1.07);
        }

        .confirm-btn:active {
            transform: translateY(0);
            box-shadow: 0 3px 8px rgba(30, 133, 99, 0.35);
        }

        .confirm-card.err .confirm-btn,
        .confirm-card.wrong .confirm-btn {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            box-shadow: 0 6px 18px rgba(220, 38, 38, 0.4);
        }

        .confirm-card.dup .confirm-btn {
            background: linear-gradient(135deg, #d97706, #b45309);
            box-shadow: 0 6px 18px rgba(217, 119, 6, 0.4);
        }

        .confirm-resume-hint {
            margin: 10px 0 0;
            font-size: 12.5px;
            color: #8aa598;
        }


        .event-lock-picker {
            margin-top: 18px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .event-lock-picker label {
            font-weight: 800;
            font-size: 14.5px;
            color: #d8f3e6;
            white-space: nowrap;
        }

        .event-lock-picker select {
            flex: 1 1 240px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 2px solid rgba(255, 255, 255, 0.35);
            background: #ffffff;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 700;
            color: #0b3d2e;
            cursor: pointer;
        }

        .event-lock-picker select:focus {
            outline: none;
            border-color: #2ee6a8;
            box-shadow: 0 0 0 3px rgba(46, 230, 168, 0.35);
        }

        .event-lock-picker select:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .scan-stat-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            padding: 8px 18px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.25);
            font-weight: 700;
            font-size: 14px;
        }

        .scan-stat-chip strong { font-size: 17px; color: #a7f3d0; }

        .scan-stat-chip.bump {
            animation: chip-bump 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes chip-bump {
            0%   { transform: scale(1); }
            40%  { transform: scale(1.12); background: rgba(46, 230, 168, 0.35); }
            100% { transform: scale(1); }
        }


        .scan-status-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            padding: 8px 18px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.25);
            font-weight: 600;
            font-size: 13.5px;
            color: #d8f3e6;
        }

        .scan-status-chip .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #9aa8a1;
            transition: background 0.25s ease;
        }

        .scan-status-chip.live .dot {
            background: #2ee6a8;
            animation: pulse-dot 1.6s infinite;
        }

        .scan-status-chip.live {
            border-color: rgba(46, 230, 168, 0.4);
        }

        @keyframes pulse-dot {
            0%, 100% { box-shadow: 0 0 0 0 rgba(46, 230, 168, 0.7); }
            50%      { box-shadow: 0 0 0 8px rgba(46, 230, 168, 0); }
        }

        .scan-log-card {
            background: white;
            border: 1px solid #e3efe8;
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(20, 83, 45, 0.09);
            overflow: hidden;
        }

        .scan-log-head {
            padding: 18px 24px;
            border-bottom: 2px solid #eef6f1;
            color: #0b3d2e;
            font-size: 18px;
            font-weight: 700;
        }

        table.scan-log {
            width: 100%;
            border-collapse: collapse;
        }

        table.scan-log th {
            background: linear-gradient(90deg, var(--green-800), var(--green-600));
            color: white;
            padding: 11px 16px;
            font-size: 12.5px;
            text-transform: uppercase;
            text-align: left;
        }

        table.scan-log td {
            padding: 11px 16px;
            border-bottom: 1px solid #eef6f1;
            font-size: 14px;
        }

        .badge-ok   { color: #15803d; font-weight: 700; }
        .badge-dup  { color: #b45309; font-weight: 700; }
        .badge-err  { color: #b91c1c; font-weight: 700; }
        .badge-wrong { color: white; font-weight: 800; background: #b91c1c; padding: 4px 12px; border-radius: 999px; font-size: 12px; }


        .scan-log-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .scan-log-count {
            font-size: 13px;
            font-weight: 700;
            color: #40614f;
            background: #eef7f2;
            border: 1px solid #d5e8de;
            padding: 4px 14px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .scan-log-toolbar {
            display: flex;
            gap: 10px;
            padding: 14px 24px;
            border-bottom: 1px solid #eef6f1;
            flex-wrap: wrap;
            align-items: center;
        }

        .scan-log-toolbar input {
            flex: 1 1 220px;
            padding: 9px 14px;
            border: 1.5px solid #c8ddd3;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
        }

        .scan-log-toolbar input:focus {
            outline: none;
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(39, 163, 119, 0.15);
        }

        .btn-print {
            padding: 9px 18px;
            border: 1.5px solid var(--green-600);
            background: white;
            color: var(--green-700);
            font-weight: 700;
            font-size: 13.5px;
            border-radius: 10px;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
            white-space: nowrap;
        }

        .btn-print:hover {
            background: var(--green-600);
            color: white;
            transform: translateY(-1px);
        }

        td.attend-num { color: #7a8d80; font-weight: 600; }
        td.attend-name { font-weight: 700; color: #0b3d2e; }
        td.attend-empty {
            text-align: center;
            color: #7a8d80;
            padding: 26px;
            font-size: 14px;
        }


        .attendance-layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 26px;
            align-items: start;
        }

        .event-side-panel {
            background: white;
            border: 1px solid #e3efe8;
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(20, 83, 45, 0.09);
            overflow: hidden;
            position: sticky;
            top: 20px;
        }

        .event-side-head {
            padding: 16px 20px;
            background: linear-gradient(120deg, #0b3d2e, #1b6f52);
            color: white;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .event-list-btns {
            display: flex;
            flex-direction: column;
            padding: 10px;
            gap: 6px;
            max-height: 480px;
            overflow-y: auto;
        }

        .event-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            background: transparent;
            border: 1.5px solid transparent;
            cursor: pointer;
            text-align: left;
            font-family: inherit;
            font-size: 13.5px;
            color: #2c4a3b;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .event-btn:hover {
            background: #f0f7f3;
        }

        .event-btn.active {
            background: linear-gradient(90deg, #e6f5ec, #f2fbf6);
            border-color: #27a377;
            box-shadow: inset 3px 0 0 0 #27a377;
        }

        .event-btn .ev-title {
            font-weight: 800;
            color: #0b3d2e;
            line-height: 1.3;
            flex: 1;
        }

        .event-btn .ev-meta {
            display: block;
            font-size: 11.5px;
            color: #7a8d80;
            font-weight: 500;
            margin-top: 2px;
        }

        .event-btn .ev-count {
            flex-shrink: 0;
            background: linear-gradient(135deg, #27a377, #1b6f52);
            color: white;
            border-radius: 999px;
            padding: 4px 11px;
            font-size: 11.5px;
            font-weight: 800;
            min-width: 38px;
            text-align: center;
        }

        .attendance-table-card {
            background: white;
            border: 1px solid #e3efe8;
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(20, 83, 45, 0.09);
            overflow: hidden;
        }

        .attendance-table-head {
            padding: 20px 26px;
            border-bottom: 2px solid #eef6f1;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            flex-wrap: wrap;
        }

        .attendance-table-head h2 {
            margin: 0 0 4px;
            font-size: 20px;
            color: #0b3d2e;
        }

        .attendance-table-head small {
            color: #5b7268;
            font-weight: 600;
            font-size: 13px;
            display: block;
        }

        .attendance-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .attend-rate-bar {
            padding: 14px 26px;
            background: linear-gradient(90deg, #f0faf5, #e8f6ef);
            border-bottom: 1px solid #d5ecdf;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .attend-rate-bar .arb-label {
            font-size: 13px;
            font-weight: 800;
            color: #0b3d2e;
            white-space: nowrap;
        }

        .attend-rate-bar .arb-track {
            flex: 1;
            height: 10px;
            border-radius: 999px;
            background: #d5ecdf;
            overflow: hidden;
            position: relative;
        }

        .attend-rate-bar .arb-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #27a377, #1b6f52);
            transition: width 0.4s ease;
        }

        .attend-rate-bar .arb-num {
            font-size: 13px;
            font-weight: 800;
            color: #14532d;
            white-space: nowrap;
        }

        table.attend-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.attend-table th {
            background: linear-gradient(90deg, var(--green-800), var(--green-600));
            color: white;
            padding: 11px 16px;
            font-size: 12.5px;
            text-transform: uppercase;
            text-align: left;
        }

        table.attend-table td {
            padding: 11px 16px;
            border-bottom: 1px solid #eef6f1;
            font-size: 14px;
        }

        table.attend-table tbody tr:hover {
            background: #f4fbf7;
        }

        table.attend-table tbody tr.row-attended {
            background: linear-gradient(90deg, #f0fdf4, #ffffff);
        }

        table.attend-table tbody tr.row-absent td.col-status .badge-no {
            background: #fef3c7;
            color: #b45309;
        }

        .badge-present,
        .badge-no {
            display: inline-block;
            padding: 4px 13px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 0.3px;
        }

        .badge-present {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-no {
            background: #e5e7eb;
            color: #4b5563;
        }

        .badge-absent,
        .badge-decl { padding: 4px 13px; border-radius: 999px; font-size: 11.5px; font-weight: 800; }
        .badge-absent { background: #fef3c7; color: #b45309; }
        .badge-decl { background: #fee2e2; color: #b91c1c; }

        .attend-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: white;
            background: linear-gradient(135deg, #27a377, #1b6f52);
            flex-shrink: 0;
            margin-right: 10px;
            font-size: 14px;
            text-transform: uppercase;
            vertical-align: middle;
        }

        .attend-student-cell {
            display: flex;
            align-items: center;
            gap: 0;
        }

        .attend-student-cell .nm {
            font-weight: 700;
            color: #0b3d2e;
            line-height: 1.3;
        }

        .attend-student-cell .em {
            font-size: 11.5px;
            color: #7a8d80;
            word-break: break-all;
        }


        @media print {
            body * { visibility: hidden; }
            .attendance-table-card,
            .attendance-table-card * { visibility: visible; }
            .attendance-table-card {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                box-shadow: none;
            }
            .attendance-actions,
            .attend-rate-bar { display: none; }
        }

        @media (max-width: 900px) {
            .attendance-layout {
                grid-template-columns: 1fr;
            }
            .event-side-panel { position: static; }
            .event-list-btns {
                flex-direction: row;
                flex-wrap: wrap;
                max-height: none;
            }
            .event-btn { flex: 1 1 200px; }
        }

        .manual-row {
            display: flex;
            gap: 10px;
            max-width: 720px;
            margin: 14px auto 0;
        }

        .manual-row input {
            flex: 1;
            padding: 10px 14px;
            border: 1.5px solid #c8ddd3;
            border-radius: 10px;
            font-family: monospace;
        }

        .manual-row input:focus {
            outline: none;
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(39, 163, 119, 0.18);
        }


        .scan-tips {
            background: linear-gradient(160deg, #f6fbf8 0%, #eef7f2 100%);
            border: 1.5px dashed #bfe0cd;
            border-radius: 16px;
            padding: 18px 24px;
            margin-top: 22px;
            max-width: 860px;
            margin-left: auto;
            margin-right: auto;
        }

        .scan-tips h3 {
            margin: 0 0 10px;
            font-size: 15.5px;
            color: #0b3d2e;
            font-weight: 800;
        }

        .scan-tips ul {
            margin: 0;
            padding-left: 20px;
            color: #40614f;
            font-size: 14px;
            line-height: 1.7;
        }

        .scan-tips li::marker { color: #27a377; font-weight: 700; }
    </style>
</head>

<body>

    <nav class="navbar">
        <a class="brand" href="../index.php">
            <img class="brand-logo" src="../assets/images/logo-ptc.png" alt="PTC Logo">
            <span class="brand-text">
                Pateros Technological College
                <small>Campus Event Hub</small>
            </span>
        </a>

        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="events.php">Manage Events</a>
            <a href="registrations.php">Registrations</a>
            <a href="attendees.php">Attendees</a>
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

    <main class="scan-container">

        <header class="scan-header">
            <h1>QR Attendance Scanner</h1>
            <p>
                Pick ONE event below, then point the camera at each student's QR.
                Only QR codes for the selected event will be accepted.
            </p>

            <div class="event-lock-picker">
                <label for="scan-event">Scanning for event:</label>
                <select id="scan-event">
                    <option value="0">— Select an event to start —</option>
                    <?php foreach ($eventOptions as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>"
                            <?= $selectedEventId !== null && (int) $opt['id'] === $selectedEventId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt['title'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="scan-stat-chip">Checked in this session: <strong id="checked-count">0</strong></div>
            <div class="scan-status-chip" id="scan-status-chip">
                <span class="dot"></span> <span id="scan-status-text">Camera off</span>
            </div>
        </header>

        <section class="scan-card">
            <div id="qr-reader">
                <div class="scan-overlay" id="scan-overlay">
                    <div class="scan-frame"></div>
                    <div class="scan-beam"></div>
                </div>
            </div>

            <div class="manual-row">
                <input type="text" id="manual-token" placeholder="Or paste a 32-character token…" maxlength="64" disabled>
                <button class="btn-scan" id="btn-manual" type="button" disabled>Check In</button>
            </div>

            <div class="scan-actions">
                <button class="btn-scan" id="btn-start" type="button" disabled>Start Camera</button>
                <button class="btn-scan stop" id="btn-stop" type="button" style="display:none">Stop Camera</button>
                <button class="btn-scan" id="btn-switch" type="button" style="display:none">Switch Camera</button>
                <button class="btn-scan" id="btn-torch" type="button" style="display:none">Torch</button>
            </div>

            <div class="scan-result" id="scan-result"></div>
        </section>

        
        <div class="confirm-backdrop" id="confirm-backdrop">
            <div class="confirm-card" id="confirm-card">
                <div class="confirm-icon" id="confirm-icon">✓</div>
                <h2 class="confirm-title" id="confirm-title">Scan Successful!</h2>
                <div class="confirm-name" id="confirm-name" style="display:none"></div>
                <div class="confirm-event" id="confirm-event" style="display:none"></div>
                <div class="confirm-time" id="confirm-time" style="display:none"></div>
                <p class="confirm-msg" id="confirm-msg"></p>
                <button class="confirm-btn" id="btn-resume" type="button">Scan Next Person</button>
                <p class="confirm-resume-hint">Camera stays on — scanning resumes when you continue.</p>
            </div>
        </div>

        
        <section class="attendance-layout" id="attendance-section">

            
            <aside class="event-side-panel">
                <div class="event-side-head">📋 Attendance by Event</div>
                <div class="event-list-btns" id="event-list">
<?php if (!$eventAttendance): ?>
<div style="padding:16px; color:#7a8d80; font-size:13.5px;">No events with registrations yet.</div>
<?php else: ?>
<?php foreach ($eventAttendance as $i => $ev): ?>
<button type="button"
class="event-btn<?= $i === 0 ? ' active' : '' ?>"
data-event-index="<?= $i ?>">
<span class="ev-title">
<?= htmlspecialchars($ev['event_title'], ENT_QUOTES, 'UTF-8') ?>
<span class="ev-meta">📅 <?= htmlspecialchars(date('M j, Y', strtotime($ev['event_date'])), ENT_QUOTES, 'UTF-8') ?> · 📍 <?= htmlspecialchars($ev['venue'], ENT_QUOTES, 'UTF-8') ?></span>
</span>
<span class="ev-count"><?= (int) $ev['total_attended'] ?>/<?= (int) $ev['total_active'] ?></span>
</button>
<?php endforeach; ?>
<?php endif; ?>
                </div>
            </aside>

            
            <div class="attendance-table-card" id="attendance-print-area">
                <div class="attendance-table-head">
                    <div>
<h2 id="sel-event-title">Select an event</h2>
<small id="sel-event-sub">Pick an event on the left to view its attendance record.</small>
                    </div>
                    <div class="attendance-actions">
<input type="text" id="attend-search" placeholder="Search student or email…" style="padding:9px 14px;border:1.5px solid #c8ddd3;border-radius:10px;font-family:inherit;font-size:14px;min-width:220px">
<button class="btn-print" id="btn-attend-csv" type="button">Download CSV</button>
<button class="btn-print" id="btn-attend-print" type="button">Print / PDF</button>
                    </div>
                </div>

                <div class="attend-rate-bar" id="attend-rate-bar" style="display:none">
<span class="arb-label">📊 Attendance Rate:</span>
<div class="arb-track"><div class="arb-fill" id="arb-fill" style="width:0%"></div></div>
<span class="arb-num" id="arb-num">0%</span>
                </div>

                <table class="attend-table">
                    <thead>
                        <tr>
<th>#</th>
<th>Student</th>
<th>Status</th>
<th>Time Checked In</th>
<th>Attendance</th>
                        </tr>
                    </thead>
                    <tbody id="attend-rows">
<tr><td colspan="5" class="attend-empty">Select an event to see its attendance record.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <script id="attend-data" type="application/json">
<?= json_encode(
    array_map(function ($ev) use ($allRegRows) {
        $eid = (int) $ev['event_id'];
        $rows = array_values(array_filter($allRegRows, function ($r) use ($eid) {
            return (int) $r['event_id'] === $eid;
        }));
        return [
            'id'        => $eid,
            'title'     => $ev['event_title'],
            'date'      => date('M j, Y', strtotime($ev['event_date'])),
            'venue'     => $ev['venue'],
            'attended'  => (int) $ev['total_attended'],
            'active'    => (int) $ev['total_active'],
            'rows'      => array_map(function ($r) {
                return [
                    'name'      => $r['full_name'],
                    'email'     => $r['email'],
                    'status'    => $r['reg_status'],
                    'attended'  => $r['attended_at'] !== null,
                    'time'      => $r['attended_at'] ? date('M j, Y g:i A', strtotime($r['attended_at'])) : '',
                ];
            }, $rows),
        ];
    }, $eventAttendance),
    JSON_UNESCAPED_UNICODE
) ?>
        </script>

        
        <section class="scan-tips">
            <h3>Tips for faster scanning</h3>
            <ul>
                <li>Hold the phone <strong>10–20 cm</strong> away from the QR — not too close, not too far.</li>
                <li>Make sure the QR is <strong>well-lit</strong> — use the Torch button in dim venues.</li>
                <li>Keep the QR <strong>inside the green frame</strong> and hold steady for ~1 second.</li>
                <li>If a QR won't scan, ask the student to <strong>brighten their screen</strong> to max.</li>
                <li>Still failing? Type the 32-character token in the manual box below the camera.</li>
            </ul>
        </section>

    </main>

    <footer>
        <p>Pateros Technological College &copy; <?= date('Y') ?></p>
    </footer>

    <script src="../assets/libs/html5-qrcode.min.js"></script>
    <script src="../assets/libs/jsqr.min.js"></script>

    <script>
        let scanner = null;
        let paused = false;
        let scannedTokens = new Set();
        let checkedCount = 0;
        let cameras = [];
        let cameraIdx = 0;
        let torchOn = false;
        let lockedEventId = 0;
        let cameraActive = false;
        let lastDecodeError = '';
        let nativeStream = null;
        let nativeDetector = null;
        let nativeTimer = null;

        const selEvent = document.getElementById('scan-event');
        const btnStart = document.getElementById('btn-start');
        const btnManual = document.getElementById('btn-manual');
        const manualInput = document.getElementById('manual-token');


        selEvent.addEventListener('change', () => {
            lockedEventId = parseInt(selEvent.value, 10) || 0;
            const ready = lockedEventId > 0;
            btnStart.disabled = !ready;
            btnManual.disabled = !ready;
            manualInput.disabled = !ready;
            if (!ready && scanner) {

                document.getElementById('btn-stop').click();
            }
        });


        const preselect = selEvent.options[selEvent.selectedIndex];
        if (preselect && parseInt(preselect.value, 10) > 0) {
            lockedEventId = parseInt(preselect.value, 10);
            btnStart.disabled = false;
            btnManual.disabled = false;
            manualInput.disabled = false;
        }

        function pauseScanning() {
            paused = true;
            try { scanner && scanner.pause(true); } catch (e) {}
        }

        function resumeScanning() {
            document.getElementById('confirm-backdrop').classList.remove('show');
            paused = false;
            try { scanner && scanner.resume(); } catch (e) {}
        }

        function extractToken(text) {

            text = String(text).trim();
            const m = text.match(/[a-f0-9]{32}/i);
            if (m) return m[0];
            try {
                const obj = JSON.parse(text);
                if (obj && typeof obj.token === 'string') return obj.token;
            } catch (e) {  }
            return text;
        }

        function feedback(kind) {

            try {
                const ctx = feedback._ctx || (feedback._ctx = new (window.AudioContext || window.webkitAudioContext)());
                if (ctx.state === 'suspended') ctx.resume();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g); g.connect(ctx.destination);
                o.type = kind === 'err' ? 'sawtooth' : 'sine';
                o.frequency.value = kind === 'ok' ? 880 : (kind === 'dup' ? 440 : 220);
                g.gain.setValueAtTime(0.08, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);
                o.start(); o.stop(ctx.currentTime + 0.2);
            } catch (e) {}
            if (navigator.vibrate) navigator.vibrate(kind === 'ok' ? [60, 40, 60] : (kind === 'dup' ? 80 : 180));
        }

        async function submitToken(token) {
            const res = await fetch('attendance_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: token, event_id: lockedEventId })
            });
            return res.json();
        }

        function showResult(data) {
            const box = document.getElementById('scan-result');
            box.className = 'scan-result';

            if (!data.ok) {
                box.classList.add(data.wrong_event ? 'wrong' : 'error');
                box.textContent = (data.wrong_event ? 'Wrong event: ' : 'Failed: ') + (data.msg || 'Scan failed.');
                feedback('err');
            } else if (data.duplicate) {
                box.classList.add('warn');
                box.textContent = 'Duplicate: ' + data.name + ' — ' + data.msg;
                feedback('dup');
            } else {
                box.classList.add('success');
                box.textContent = 'Checked in: ' + data.name + ' — ' + data.msg;
                feedback('ok');
                box.classList.add('flash-ok');
                setTimeout(() => box.classList.remove('flash-ok'), 600);
            }

            box.style.display = 'block';

            if (data.ok && !data.duplicate) {
                bumpCount();
                refreshAttendance();
            }
            clearTimeout(box._t);
            box._t = setTimeout(() => { box.style.display = 'none'; }, 4000);
        }


        function escHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c =>
                ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
            );
        }

        async function refreshAttendance() {


            if (typeof selectEvent === 'function' && selectedIdx >= 0) {
                selectEvent(selectedIdx, true);
            }
        }


        const ATTEND_DATA = JSON.parse(
            document.getElementById('attend-data').textContent
        );
        let selectedIdx = -1;

        function statusBadge(row) {
            if (row.attended) return '<span class="badge-present">Present</span>';
            if (row.status === 'declined') return '<span class="badge-decl">Declined</span>';
            if (row.status === 'pending') return '<span class="badge-absent">⏳ Pending reg.</span>';
            return '<span class="badge-no">○ Not checked in</span>';
        }

        function selectEvent(idx, keepActive) {
            const ev = ATTEND_DATA[idx];
            if (!ev) return;
            selectedIdx = idx;

            if (!keepActive) {
                document.querySelectorAll('.event-btn').forEach(b =>
                    b.classList.toggle('active', +b.dataset.eventIndex === idx));
            }

            document.getElementById('sel-event-title').textContent = ev.title;
            document.getElementById('sel-event-sub').textContent =
                '📅 ' + ev.date + ' · 📍 ' + ev.venue + ' · ' +
                ev.attended + ' of ' + ev.active + ' checked in';

            const pct = ev.active > 0 ? Math.round(ev.attended / ev.active * 100) : 0;
            document.getElementById('attend-rate-bar').style.display = 'flex';
            document.getElementById('arb-fill').style.width = pct + '%';
            document.getElementById('arb-num').textContent = pct + '% (' + ev.attended + '/' + ev.active + ')';

            const body = document.getElementById('attend-rows');
            if (!ev.rows.length) {
                body.innerHTML = '<tr><td colspan="5" class="attend-empty">No registrations for this event yet.</td></tr>';
                updateSideCount(ev);
                return;
            }


            const sorted = [...ev.rows].sort((a, b) =>
                (b.attended - a.attended) || a.name.localeCompare(b.name));

            const q = (document.getElementById('attend-search')?.value || '').trim().toLowerCase();
            const filtered = q
                ? sorted.filter(r => (r.name || '').toLowerCase().includes(q)
                    || (r.email || '').toLowerCase().includes(q))
                : sorted;

            if (!filtered.length) {
                body.innerHTML = '<tr><td colspan="5" class="attend-empty">No student matches "' + escHtml(q) + '".</td></tr>';
                updateSideCount(ev);
                return;
            }

            body.innerHTML = filtered.map((r, i) =>
                '<tr class="' + (r.attended ? 'row-attended' : '') + '">' +
                '<td class="attend-num">' + (i + 1) + '</td>' +
                '<td><div class="attend-student-cell">' +
                '<span class="attend-avatar">' + escHtml((r.name || '?').charAt(0)) + '</span>' +
                '<div><div class="nm">' + escHtml(r.name) + '</div>' +
                '<div class="em">' + escHtml(r.email) + '</div></div>' +
                '</div></td>' +
                '<td>' + escHtml(r.status === 'registered' ? 'Confirmed' :
                    (r.status === 'pending' ? 'Pending' : 'Declined')) + '</td>' +
                '<td>' + (r.attended ? escHtml(r.time) : '—') + '</td>' +
                '<td class="col-status">' + statusBadge(r) + '</td>' +
                '</tr>'
            ).join('');
            updateSideCount(ev);
        }

        function updateSideCount(ev) {
            const badge = document.querySelector('.event-btn[data-event-index="' + selectedIdx + '"] .ev-count');
            if (badge) badge.textContent = ev.attended + '/' + ev.active;
        }

        document.getElementById('attend-search')?.addEventListener('input', () => {
            if (selectedIdx >= 0) selectEvent(selectedIdx, true);
        });

        document.querySelectorAll('.event-btn').forEach(btn => {
            btn.addEventListener('click', () => selectEvent(+btn.dataset.eventIndex));
        });


        document.getElementById('btn-attend-csv').addEventListener('click', () => {
            if (selectedIdx < 0) { alert('Select an event first.'); return; }
            const ev = ATTEND_DATA[selectedIdx];
            const esc = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
            const lines = [['#', 'Student', 'Email', 'Status', 'Checked In']
                .map(esc).join(',')];
            const sorted = [...ev.rows].sort((a, b) =>
                (b.attended - a.attended) || a.name.localeCompare(b.name));
            sorted.forEach((r, i) => {
                lines.push([
                    i + 1,
                    r.name, r.email,
                    r.attended ? 'Present' : (r.status === 'declined' ? 'Declined' :
                        (r.status === 'pending' ? 'Pending' : 'Absent')),
                    r.time || ''
                ].map(esc).join(','));
            });
            const blob = new Blob(['\ufeff' + lines.join('\r\n')],
                { type: 'text/csv;charset=utf-8;' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'attendance_' + ev.title.replace(/[^a-z0-9]+/gi, '_')
                .toLowerCase() + '.csv';
            a.click();
            URL.revokeObjectURL(a.href);
        });

        document.getElementById('btn-attend-print').addEventListener('click', () => {
            if (selectedIdx < 0) { alert('Select an event first.'); return; }
            window.print();
        });


        if (ATTEND_DATA.length) selectEvent(0);

        function showConfirm(data) {
            const backdrop = document.getElementById('confirm-backdrop');
            const card = document.getElementById('confirm-card');
            const icon = document.getElementById('confirm-icon');
            const title = document.getElementById('confirm-title');
            const name = document.getElementById('confirm-name');
            const event = document.getElementById('confirm-event');
            const time = document.getElementById('confirm-time');
            const msg = document.getElementById('confirm-msg');
            const btn = document.getElementById('btn-resume');

            card.className = 'confirm-card';

            if (!data.ok) {
                card.classList.add(data.wrong_event ? 'wrong' : 'err');
                icon.textContent = data.wrong_event ? '✕' : '✕';
                title.textContent = data.wrong_event ? 'Wrong Event' : 'Scan Failed';
                msg.textContent = data.msg || 'The QR could not be verified. Try scanning again.';
                btn.textContent = 'Try Again';
            } else if (data.duplicate) {
                card.classList.add('dup');
                icon.textContent = '!';
                title.textContent = 'Already Checked In';
                msg.textContent = 'This student\u2019s attendance was already recorded earlier — no action needed.';
                btn.textContent = 'Scan Next Person';
            } else {
                card.classList.add('ok');
                icon.textContent = '✓';
                title.textContent = 'Scan Successful';
                msg.textContent = 'Attendance recorded. The student may now proceed to the venue.';
                btn.textContent = 'Scan Next Person';
                bumpCount();
            }

            name.style.display = data.name ? 'block' : 'none';
            name.textContent = data.name || '';
            event.style.display = data.event ? 'block' : 'none';
            event.textContent = data.event || '';
            time.style.display = data.at ? 'inline-block' : 'none';
            time.textContent = data.at || '';

            backdrop.classList.add('show');
            btn.focus();
        }

        document.getElementById('btn-resume').addEventListener('click', resumeScanning);

        function bumpCount() {
            checkedCount++;
            const el = document.getElementById('checked-count');
            if (el) {
                el.textContent = checkedCount;
                el.parentElement.classList.remove('bump');
                void el.parentElement.offsetWidth;
                el.parentElement.classList.add('bump');
            }
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' || e.key === 'Enter' || e.key === ' ') {
                if (document.getElementById('confirm-backdrop').classList.contains('show')) {
                    e.preventDefault();
                    resumeScanning();
                }
            }
        });

        async function handleScan(text) {
            if (paused) return;


            if (!lockedEventId) {
                showResult({ ok: false, msg: 'Select an event first before scanning.' });
                showConfirm({ ok: false, msg: 'Pick which event you are scanning for at the top, then try again.' });
                return;
            }

            const token = extractToken(text);



            if (scannedTokens.has(token.toLowerCase())) {
                pauseScanning();
                feedback('dup');
                showConfirm({
                    ok: true,
                    duplicate: true,
                    msg: 'This QR was already scanned in this session. '
                       + 'Press "Scan Next Person" to continue — no need to refresh the page.'
                });
                setTimeout(resumeScanning, 1800);
                return;
            }

            if (!/^[a-f0-9]{32}$/i.test(token)) {
                showResult({ ok: false, msg: 'Unrecognized QR content: "' + String(text).slice(0, 40) + '"' });
                return;
            }

            scannedTokens.add(token.toLowerCase());
            pauseScanning();

            try {
                const data = await submitWithRetry(token);



                if (!data.ok) scannedTokens.delete(token.toLowerCase());

                showResult(data);
                showConfirm(data);
            } catch (e) {
                scannedTokens.delete(token.toLowerCase());
                showResult({ ok: false, msg: 'Network error: ' + e.message });
                showConfirm({ ok: false, msg: 'Network error: ' + e.message });
            }
        }


        async function submitWithRetry(token, tries = 2) {
            for (let i = 0; i < tries; i++) {
                try {
                    return await submitToken(token);
                } catch (e) {
                    if (i === tries - 1) throw e;
                    await new Promise(r => setTimeout(r, 400));
                }
            }
        }

        function recreateQrReader() {


            const old = document.getElementById('qr-reader');
            const fresh = document.createElement('div');
            fresh.id = 'qr-reader';
            fresh.innerHTML =
                '<div class="scan-overlay" id="scan-overlay">' +
                '<div class="scan-frame"></div>' +
                '<div class="scan-beam"></div>' +
                '</div>';
            old.replaceWith(fresh);
            scanner = null;
            nativeStream = null;
            nativeDetector = null;
            nativeTimer = null;
        }


        const jsqrRaw = window.jsQR;
        const jsqrFn = typeof jsqrRaw === 'function'
            ? jsqrRaw
            : (jsqrRaw && typeof jsqrRaw.default === 'function' ? jsqrRaw.default : null);

        if (jsqrFn) window.jsQR = jsqrFn;

        if (!('BarcodeDetector' in window) && jsqrFn) {
            window.BarcodeDetector = class {
                constructor(opts) { this.formats = opts?.formats || ['qr_code']; }
                static async getSupportedFormats() { return ['qr_code']; }
                async detect(source) {
                    const w = source.videoWidth || source.width;
                    const h = source.videoHeight || source.height;
                    if (!w || !h) return [];
                    const c = BarcodeDetector._canvas || (BarcodeDetector._canvas = document.createElement('canvas'));
                    const scale = Math.min(1, 480 / Math.max(w, h));
                    c.width = Math.floor(w * scale);
                    c.height = Math.floor(h * scale);
                    const ctx = c.getContext('2d', { willReadFrequently: true });
                    ctx.drawImage(source, 0, 0, c.width, c.height);
                    const img = ctx.getImageData(0, 0, c.width, c.height);
                    const code = jsqrFn(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
                    return code
                        ? [{ rawValue: code.data, format: 'qr_code',
                             boundingBox: code.location }] : [];
                }
            };
        }


        async function startNativeScanner() {
            if (!('BarcodeDetector' in window)) return false;



            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            } catch (err1) {
                if (err1.name === 'NotFoundError' || err1.name === 'DevicesNotFoundError') {

                    stream = await navigator.mediaDevices.getUserMedia({
                        video: { facingMode: 'environment' }, audio: false
                    });
                } else {
                    throw err1;
                }
            }
            nativeStream = stream;

            const reader = document.getElementById('qr-reader');
            const video = document.createElement('video');
            video.id = 'native-video';
            video.autoplay = true;
            video.playsInline = true;
            video.muted = true;
            video.setAttribute('playsinline', '');



            reader.innerHTML =
                '<div class="scan-overlay" id="scan-overlay">' +
                '<div class="scan-frame"></div>' +
                '<div class="scan-beam"></div>' +
                '</div>';
            reader.appendChild(video);

            video.srcObject = stream;

            await new Promise((resolve, reject) => {
                video.onloadeddata = () => resolve();
                video.onerror = () => reject(new Error('Video stream failed to load.'));
                setTimeout(() => reject(new Error('Camera stream timed out.')), 8000);
            });



            try { await video.play(); } catch (e) {  }

            const detector = new window.BarcodeDetector({
                formats: ['qr_code', 'aztec', 'data_matrix']
            });
            nativeDetector = detector;

            let scanning = true;
            const tick = async () => {
                if (!scanning || !nativeDetector) return;
                try {
                    const codes = await detector.detect(video);
                    if (codes.length && !paused) {
                        scanning = false;
                        handleScan(codes[0].rawValue);

                        const wait = setInterval(() => {
                            if (!paused) {
                                clearInterval(wait);
                                scanning = true;
                                tick();
                            }
                        }, 250);
                        return;
                    }
                } catch (e) {  }
                if (scanning) nativeTimer = requestAnimationFrame(tick);
            };
            nativeTimer = requestAnimationFrame(tick);

            const overlay = document.getElementById('scan-overlay');
            if (overlay) overlay.classList.add('active');
            document.getElementById('btn-start').style.display = 'none';
            document.getElementById('btn-stop').style.display = 'inline-block';
            cameraActive = true;
            updateStatusChip('Camera live — scanning…', true);
            return true;
 }

        function stopNativeScanner() {
            if (nativeTimer) { cancelAnimationFrame(nativeTimer); nativeTimer = null; }
            if (nativeStream) {
                nativeStream.getTracks().forEach(t => t.stop());
                nativeStream = null;
            }
            nativeDetector = null;
        }

        async function startCamera(constraintsOverride) {
            cameraActive = false;


            try {
                const okNative = await startNativeScanner();
                if (okNative) return;
            } catch (e) {
                if (nativeStream) stopNativeScanner();
                const name = String(e && e.name || '');
                if (name === 'NotAllowedError' || name === 'SecurityError') {
                    updateStatusChip('Camera blocked', false);
                    showResult({ ok: false, msg: 'Camera permission was denied. Allow camera access in your browser (lock icon in the address bar), then press Start Camera again.' });
                    return;
                }
                if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                    updateStatusChip('No camera found', false);
                    showResult({ ok: false, msg: 'No usable camera was found on this device. Connect a camera and try again.' });
                    return;
                }

            }


            recreateQrReader();
            updateStatusChip('Starting camera (fallback mode)…', false);

            try {
                if (!cameras.length) {
                    cameras = await Html5Qrcode.getCameras();
                }
            } catch (e) {
                updateStatusChip('No camera found', false);
                showResult({ ok: false, msg: 'No camera found on this device. Check browser camera permission, then try again. You can still use the manual token box.' });
                return;
            }

            scanner = new Html5Qrcode('qr-reader', {
                formatsToSupport: [
                    Html5QrcodeSupportedFormats.QR_CODE
                ],
                experimentalFeatures: { useBarCodeDetectorIfSupported: true },
                verbose: false
            });

            const qrboxFn = (vw, vh) => {
                const side = Math.floor(Math.min(vw, vh) * 0.85);
                return { width: side, height: side };
            };

            scanner.start(
                { facingMode: 'environment' },
                {
                    fps: 10,
                    qrbox: qrboxFn,
                    disableFlip: false


                },
                (decodedText) => handleScan(decodedText),
                (msg) => {
                    lastDecodeError = String(msg || '').slice(0, 120);
                }
            ).then(() => {
                document.getElementById('btn-start').style.display = 'none';
                document.getElementById('btn-stop').style.display = 'inline-block';
                document.getElementById('scan-overlay').classList.add('active');
                cameraActive = true;
                updateStatusChip('Camera live (fallback) — scanning…', true);
            }).catch(err => {
                const errMsg = String(err && err.message ? err.message : err || '');
                cameraActive = false;
                try { scanner.stop().catch(() => {}); } catch (e) {}
                try { scanner.clear(); } catch (e) {}
                scanner = null;
                recreateQrReader();
                updateStatusChip('Camera failed', false);
                document.getElementById('btn-start').style.display = 'inline-block';
                document.getElementById('btn-stop').style.display = 'none';
                showResult({
                    ok: false,
                    msg: 'Camera failed to start (' + errMsg + '). '
                       + 'Allow camera access in your browser, close other apps using the camera, then try again. '
                       + 'You can still use the manual token box below.'
                });
            });
        }

        function updateStatusChip(text, live) {
            const chip = document.getElementById('scan-status-chip');
            const label = document.getElementById('scan-status-text');
            if (!chip || !label) return;
            label.textContent = text;
            chip.classList.toggle('live', !!live);
        }

        document.getElementById('btn-start').addEventListener('click', () => {
            updateStatusChip('Starting camera…', false);
            startCamera();
        });

        document.getElementById('btn-switch').addEventListener('click', async () => {
            if (nativeStream) stopNativeScanner();
            if (scanner) {
                try { await scanner.stop(); scanner.clear(); } catch (e) {}
                scanner = null;
            }
            torchOn = false;
            cameraActive = false;
            cameraIdx = (cameraIdx + 1) % Math.max(cameras.length, 1);
            recreateQrReader();
            startCamera();
        });

        document.getElementById('btn-torch').addEventListener('click', () => {
            if (!scanner) return;
            torchOn = !torchOn;
            scanner.applyVideoConstraints({ advanced: [{ torch: torchOn }] })
                .then(() => {
                    document.getElementById('btn-torch').textContent = torchOn ? 'Torch Off' : 'Torch';
                })
                .catch(() => { torchOn = false; });
        });

        document.getElementById('btn-stop').addEventListener('click', () => {

            stopNativeScanner();
            const stopLib = scanner
                ? scanner.stop().then(() => { try { scanner.clear(); } catch (e) {} }).catch(() => {})
                : Promise.resolve();
            stopLib.then(() => {
                scanner = null;
                torchOn = false;
                cameraActive = false;
                updateStatusChip('Camera off', false);
                document.getElementById('btn-start').style.display = 'inline-block';
                document.getElementById('btn-stop').style.display = 'none';
                document.getElementById('btn-switch').style.display = 'none';
                document.getElementById('btn-torch').style.display = 'none';
                document.getElementById('btn-torch').textContent = 'Torch';
                recreateQrReader();
            });
        });

        document.getElementById('btn-manual').addEventListener('click', () => {
            const val = document.getElementById('manual-token').value;
            if (val.trim()) {
                handleScan(val);
                document.getElementById('manual-token').value = '';
            }
        });

        document.getElementById('manual-token').addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('btn-manual').click();
            }
        });
    </script>

</body>

</html>
