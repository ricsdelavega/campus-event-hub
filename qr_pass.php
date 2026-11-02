<?php

session_start();

require __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$token  = $_GET['token'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
    http_response_code(400);
    exit('Invalid token.');
}

$stmt = $pdo->prepare("
    SELECT r.id, r.status, r.attended_at, r.confirmed_at,
           u.full_name, u.email,
           e.title, e.event_date, e.start_time, e.end_time, e.venue, e.category
    FROM registrations r
    INNER JOIN users u ON u.id = r.user_id
    INNER JOIN events e ON e.id = r.event_id
    WHERE r.attendance_token = :t
      AND r.user_id = :uid
      AND r.status = 'registered'
    LIMIT 1
");
$stmt->execute(['t' => $token, 'uid' => $userId]);
$reg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reg) {
    http_response_code(404);
    exit('QR pass not found, or this registration is no longer confirmed.');
}

$eventStamp = strtotime($reg['event_date'] . ' ' . $reg['start_time']);
$isPast = $eventStamp && time() > strtotime($reg['event_date'] . ' ' . ($reg['end_time'] ?: '23:59:59'));

function escape($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
function fdate($v)
{
    return date('D, M j, Y', strtotime($v));
}
function ftime($v)
{
    return date('g:i A', strtotime($v));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Pass — <?= escape($reg['title']) ?> · Pateros Technological College</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">

    <style>
        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            background:
                radial-gradient(1100px 500px at 15% -10%, rgba(46, 230, 168, 0.16), transparent 60%),
                radial-gradient(900px 480px at 90% 110%, rgba(27, 111, 82, 0.20), transparent 60%),
                #eef7f2;
            min-height: 100vh;
            color: #0b3d2e;
        }

        .pass-wrap {
            max-width: 1240px;
            margin: 0 auto;
            padding: 22px 20px 40px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-height: 100vh;
            justify-content: center;
        }

        .pass-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #1b6f52;
            font-weight: 700;
            font-size: 14.5px;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .pass-badge-top {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 16px;
            border-radius: 999px;
            background: white;
            border: 1px solid #bfe0cd;
            color: #1b6f52;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            box-shadow: 0 3px 10px rgba(20, 83, 45, 0.08);
        }


        .pass-ticket {
            background: white;
            border-radius: 22px;
            overflow: hidden;
            box-shadow:
                0 24px 60px rgba(11, 61, 46, 0.20),
                0 4px 14px rgba(11, 61, 46, 0.10);
            animation: pass-in 0.45s cubic-bezier(0.34, 1.4, 0.64, 1);
        }

        @keyframes pass-in {
            from {
                transform: translateY(22px) scale(0.98);
                opacity: 0;
            }

            to {
                transform: none;
                opacity: 1;
            }
        }


        .pass-head {
            position: relative;
            background: linear-gradient(120deg, #0b3d2e 0%, #14603f 55%, #1e8a5f 100%);
            color: white;
            padding: 22px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            overflow: hidden;
        }

        .pass-head::before {
            content: "";
            position: absolute;
            width: 340px;
            height: 340px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            bottom: -220px;
            left: -80px;
        }

        .pass-head::after {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.07);
            top: -120px;
            right: -60px;
        }

        .pass-head-left {
            position: relative;
            z-index: 1;
        }

        .pass-brand {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.7px;
            text-transform: uppercase;
            color: #a7f3d0;
        }

        .pass-brand img {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            background: white;
            padding: 2px;
        }

        .pass-head h1 {
            margin: 10px 0 0;
            font-size: 27px;
            line-height: 1.22;
            letter-spacing: -0.3px;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
            word-break: break-word;
        }

        .pass-cat {
            position: relative;
            z-index: 1;
            display: inline-block;
            padding: 5px 16px;
            border-radius: 999px;
            background: rgba(46, 230, 168, 0.18);
            border: 1px solid rgba(46, 230, 168, 0.45);
            color: #a7f3d0;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            white-space: nowrap;
        }


        .pass-cols {
            display: grid;
            grid-template-columns: minmax(480px, 560px) 1fr;
            align-items: stretch;
            min-height: 0;
        }


        .pass-cols::before {
            content: "";
            position: absolute;
            display: none;
        }

        .pass-qr-side {
            position: relative;
            background:
                radial-gradient(460px 340px at 50% 8%, rgba(46, 230, 168, 0.16), transparent 72%),
                radial-gradient(circle at 18% 82%, rgba(27, 111, 82, 0.10), transparent 55%),
                linear-gradient(170deg, #f4fbf7, #e5f3ec);
            border-right: 2.5px dashed #bcd9c9;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            padding: 30px 24px;
        }


        .pass-qr-side::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(27, 111, 82, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(27, 111, 82, 0.05) 1px, transparent 1px);
            background-size: 26px 26px;
            mask-image: radial-gradient(circle at 50% 40%, black, transparent 75%);
            -webkit-mask-image: radial-gradient(circle at 50% 40%, black, transparent 75%);
            pointer-events: none;
        }

        .pass-qr-label {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 18px;
            border-radius: 999px;
            background: linear-gradient(120deg, #0b3d2e, #1b6f52);
            color: #a7f3d0;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(11, 61, 46, 0.3);
        }

        .pass-qr-label .pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #2ee6a8;
            box-shadow: 0 0 0 0 rgba(46, 230, 168, 0.7);
            animation: qr-pulse 1.8s infinite;
        }

        @keyframes qr-pulse {
            0%   { box-shadow: 0 0 0 0 rgba(46, 230, 168, 0.7); }
            70%  { box-shadow: 0 0 0 9px rgba(46, 230, 168, 0); }
            100% { box-shadow: 0 0 0 0 rgba(46, 230, 168, 0); }
        }


        .pass-hole {
            position: absolute;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #eef7f2;
            border: 1px solid #d8e8df;
            right: -14px;
        }

        .pass-hole.top { top: -13px; }
        .pass-hole.bottom { bottom: -13px; }

        .pass-qr-frame {
            position: relative;
            z-index: 1;
            display: inline-flex;
            padding: 16px;
            background: white;
            border-radius: 18px;
            box-shadow:
                0 0 0 3px #0b3d2e,
                0 18px 40px rgba(11, 61, 46, 0.24),
                0 4px 12px rgba(11, 61, 46, 0.14);
            max-width: 100%;
        }


        .pass-qr-frame img {
            display: block;
            border-radius: 6px;
            width: min(100%, 460px);
            height: auto;
            transition: transform 0.3s ease;
        }

        .pass-qr-frame:hover img {
            transform: scale(1.02);
        }


        .pass-qr-scanline {
            position: absolute;
            left: 10px;
            right: 10px;
            top: 10px;
            height: 3px;
            border-radius: 3px;
            background: linear-gradient(90deg, transparent, #2ee6a8 30%, #34d399 70%, transparent);
            box-shadow: 0 0 14px rgba(46, 230, 168, 0.9);
            animation: qr-scan 2.6s ease-in-out infinite;
            pointer-events: none;
            opacity: 0.85;
        }

        @keyframes qr-scan {
            0%   { top: 12px; opacity: 0; }
            8%   { opacity: 0.9; }
            48%  { top: calc(100% - 15px); opacity: 0.9; }
            56%  { opacity: 0; }
            100% { top: 12px; opacity: 0; }
        }


        .pass-qr-corner {
            position: absolute;
            width: 34px;
            height: 34px;
            z-index: 2;
            pointer-events: none;
        }

        .pass-qr-corner::before {
            content: "";
            position: absolute;
            inset: 0;
            border: 4.5px solid #157347;
            border-radius: 10px;
        }

        .pass-qr-corner.tl { top: -13px; left: -13px; }
        .pass-qr-corner.tl::before { border-right: none; border-bottom: none; }

        .pass-qr-corner.tr { top: -13px; right: -13px; }
        .pass-qr-corner.tr::before { border-left: none; border-bottom: none; }

        .pass-qr-corner.bl { bottom: -13px; left: -13px; }
        .pass-qr-corner.bl::before { border-right: none; border-top: none; }

        .pass-qr-corner.br { bottom: -13px; right: -13px; }
        .pass-qr-corner.br::before { border-left: none; border-top: none; }

        @media (prefers-reduced-motion: no-preference) {
            .pass-qr-corner { animation: corner-breathe 2.2s ease-in-out infinite; }
            .pass-qr-corner.tr, .pass-qr-corner.bl { animation-delay: 0.4s; }
            .pass-qr-corner.br { animation-delay: 0.8s; }
        }

        @keyframes corner-breathe {
            0%, 100% { transform: scale(1); opacity: 1; }
            50%      { transform: scale(1.12); opacity: 0.65; }
        }

        .pass-qr-hint {
            position: relative;
            z-index: 1;
            margin: 0;
            text-align: center;
            font-size: 13.5px;
            font-weight: 700;
            color: #2c4a3b;
            max-width: 330px;
        }

        .pass-qr-hint small {
            display: block;
            margin-top: 3px;
            font-size: 11.5px;
            color: #7a8d80;
            font-weight: 500;
        }

        .token-chip {
            position: relative;
            z-index: 1;
            display: inline-block;
            max-width: 100%;
            padding: 6px 16px;
            border-radius: 999px;
            background: white;
            border: 1.5px dashed #9fceba;
            font-family: ui-monospace, 'Cascadia Mono', Consolas, monospace;
            font-size: 11px;
            letter-spacing: 0.8px;
            color: #2c4a3b;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(20, 83, 45, 0.08);
        }


        .pass-details {
            padding: 26px 30px 24px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .pass-details-title {
            margin: 0;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #7a8d80;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pass-details-title::after {
            content: "";
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, #d8e8df, transparent);
        }

        .pass-status {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 14px 18px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.4;
        }

        .pass-status.done {
            background: #e8f8ef;
            color: #15803d;
            border: 1.5px solid #9de0bd;
        }

        .pass-status.waiting {
            background: #fef6e0;
            color: #b45309;
            border: 1.5px solid #f5d78e;
        }

        .pass-status.past {
            background: #f4f5f6;
            color: #5b6b63;
            border: 1.5px solid #d8dee2;
        }

        .pass-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .pass-meta-item {
            background: #f6fbf8;
            border: 1px solid #e0efe7;
            border-radius: 14px;
            padding: 13px 16px;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .pass-meta-item .v {
            font-size: 15px;
            line-height: 1.45;
            margin-top: 3px;
        }

        .pass-meta-item:hover {
            transform: translateY(-2px);
            border-color: #9fceba;
            box-shadow: 0 8px 18px rgba(20, 83, 45, 0.12);
        }

        .pass-meta-item.wide {
            grid-column: 1 / -1;
        }

        .pass-meta-item .k {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #7a8d80;
        }

        .pass-meta-item .v {
            margin-top: 3px;
            font-size: 14.5px;
            font-weight: 700;
            color: #0b3d2e;
            word-break: break-word;
        }


        .pass-student {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 14px 17px;
            border-radius: 14px;
            background: linear-gradient(120deg, #0b3d2e, #1b6f52);
            color: white;
            min-width: 0;
        }

        .pass-student-info {
            min-width: 0;
        }

        .pass-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
            color: #0b3d2e;
            background: linear-gradient(135deg, #a7f3d0, #2ee6a8);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
        }

        .pass-student .n {
            font-weight: 800;
            font-size: 15.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .pass-student .e {
            font-size: 12.5px;
            color: #a7f3d0;
            word-break: break-all;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }


        .pass-actions {
            margin-top: auto;
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 10px;
        }

        .pass-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 15px 20px;
            border-radius: 13px;
            font-weight: 800;
            font-size: 14.5px;
            white-space: nowrap;
            text-decoration: none;
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }

        .pass-btn.primary {
            flex: 1.6;
            color: white;
            background: linear-gradient(135deg, #1e8a5f, #0b3d2e);
            box-shadow: 0 8px 20px rgba(11, 61, 46, 0.35);
        }

        .pass-btn.primary::after {
            content: "";
            position: absolute;
            top: 0;
            left: -80%;
            width: 50%;
            height: 100%;
            background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.35), transparent);
            transform: skewX(-20deg);
            transition: left 0.55s ease;
        }

        .pass-btn.primary:hover {
            transform: translateY(-2px);
            filter: brightness(1.1);
            box-shadow: 0 12px 26px rgba(11, 61, 46, 0.42);
        }

        .pass-btn.primary:hover::after {
            left: 130%;
        }

        .pass-btn.ghost {
            color: #1b6f52;
            background: white;
            border: 2px solid #bfe0cd;
        }

        .pass-btn.ghost:hover {
            background: #f0f7f3;
            transform: translateY(-1px);
        }

        .pass-foot {
            padding: 14px 30px 20px;
            text-align: center;
            font-size: 12px;
            color: #8aa598;
            background: #fafdfb;
            border-top: 1px solid #eef6f1;
        }


        @media (max-width: 820px) {
            .pass-cols {
                grid-template-columns: 1fr;
            }

            .pass-qr-side {
                border-right: none;
                border-bottom: 2.5px dashed #bcd9c9;
                padding: 26px 18px;
            }

            .pass-hole { display: none; }

            .pass-head h1 { font-size: 19px; }

            .pass-actions {
                grid-template-columns: 1fr;
            }

            .pass-details {
                padding: 20px 18px;
            }


            .pass-qr-frame img {
                width: min(76vw, 380px) !important;
                height: auto !important;
            }

            .pass-cols {
                grid-template-columns: 1fr !important;
            }
        }


        @media print {
            .pass-topbar,
            .pass-btn.ghost {
                display: none;
            }

            body { background: white; }
            .pass-ticket { box-shadow: none; }
        }
    </style>
</head>

<body>

    <div class="pass-wrap">

        <div class="pass-topbar">
            <a class="back-link" href="my_registrations.php"> Back to My Registrations</a>
            <span class="pass-badge-top">Official Attendance Pass</span>
        </div>

        <div class="pass-ticket">

            <header class="pass-head">
                <div class="pass-head-left">
                    <div class="pass-brand">
                        <img src="assets/images/logo-ptc.png" alt="PTC Logo">
                        Pateros Technological College
                    </div>
                    <h1><?= escape($reg['title']) ?></h1>
                </div>
                <span class="pass-cat"><?= escape($reg['category'] ?: 'Campus Event') ?></span>
            </header>

            <div class="pass-cols">

                
                <div class="pass-qr-side">
                    <span class="pass-hole top"></span>
                    <span class="pass-hole bottom"></span>

                    <div class="pass-qr-label">
<span class="pulse-dot"></span> Scan for Check-in
                    </div>

                    <div class="pass-qr-frame">
                        <img src="qr.php?token=<?= escape($token) ?>&size=480"
                            alt="Attendance QR for <?= escape($reg['title']) ?>"
                            width="480" height="480"
                            onerror="this.parentElement.style.display='none'; document.getElementById('qr-fallback').style.display='block';">
                        <span class="pass-qr-scanline"></span>
                        <span class="pass-qr-corner tl"></span>
                        <span class="pass-qr-corner tr"></span>
                        <span class="pass-qr-corner bl"></span>
                        <span class="pass-qr-corner br"></span>
                    </div>
                    <div id="qr-fallback" style="display:none; color:#b91c1c; font-weight:700;">
                        QR image failed to load — try the download button on the right.
                    </div>

                    <p class="pass-qr-hint">
                        Show this QR to the event admin for check-in
                        <small>Keep your screen brightness at maximum for faster scanning.</small>
                    </p>

                    <div class="token-chip" title="Reference code"><?= escape(strtoupper($token)) ?></div>
                </div>

                
                <div class="pass-details">

                    <div class="pass-details-title">Event Details</div>

                    <div class="pass-meta">
                        <div class="pass-meta-item">
                            <div class="k">📅 Date</div>
                            <div class="v"><?= escape(fdate($reg['event_date'])) ?></div>
                        </div>
                        <div class="pass-meta-item">
                            <div class="k">⏰ Time</div>
                            <div class="v"><?= escape(ftime($reg['start_time'])) ?>
                                <?php if (!empty($reg['end_time'])): ?>
                                    — <?= escape(ftime($reg['end_time'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="pass-meta-item wide">
                            <div class="k">📍 Venue</div>
                            <div class="v"><?= escape($reg['venue']) ?></div>
                        </div>
                    </div>

                    <div class="pass-status <?= !empty($reg['attended_at']) ? 'done' : ($isPast ? 'past' : 'waiting') ?>">
                            <?php if (!empty($reg['attended_at'])): ?>
                                ✅ Checked in on <?= escape(date('M j, Y g:i A', strtotime($reg['attended_at']))) ?>
                            <?php elseif ($isPast): ?>
                                🕐 Event ended — no check-in recorded.
                            <?php else: ?>
                                ⏳ Not yet checked in — present this pass at the venue.
                            <?php endif; ?>
                        </div>

                        <div class="pass-student">
                            <div class="pass-avatar"><?= escape(strtoupper(mb_substr($_SESSION['user_name'] ?? 'S', 0, 1))) ?></div>
                            <div class="pass-student-info">
                                <div class="n"><?= escape($reg['full_name']) ?></div>
                                <div class="e"><?= escape($reg['email']) ?></div>
                            </div>
                        </div>

                        <div class="pass-actions">
                        <a class="pass-btn primary"
                            href="qr_download.php?token=<?= escape($token) ?>"
                            download>
                            Download QR Pass (JPG)
                        </a>
                        <button class="pass-btn ghost" type="button" onclick="window.print();">
                            Print / PDF
                        </button>
                    </div>

                </div>

            </div>

            <div class="pass-foot">
                Do not share this pass — one scan per student. · Gented <?= date('M j, Y g:i A') ?> · Campus Event Hub
            </div>

        </div>

    </div>

</body>

</html>
