
<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['event_csrf_token'])) {
    $_SESSION['event_csrf_token'] = bin2hex(random_bytes(32));
}

$event_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$event_id || $event_id < 1) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        e.id,
        e.title,
        e.description,
        e.category,
        e.event_date,
        e.start_time,
        e.end_time,
        e.venue,
        e.capacity,
        e.status,
        e.event_image,
        (
            SELECT COUNT(*)
            FROM registrations r
            WHERE r.event_id = e.id
              AND r.status IN ('registered', 'pending')
        ) AS registered_count
    FROM events e
    WHERE e.id = :id
");

$stmt->execute(['id' => $event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    $page_error = 'Event not found.';
} else {
    $remaining = max(
        0,
        (int) $event['capacity'] -
        (int) $event['registered_count']
    );

$liveStartTs = strtotime($event['event_date'] . ' ' . $event['start_time']);
    $liveEndTs = !empty($event['end_time'])
        ? strtotime($event['event_date'] . ' ' . $event['end_time'])
        : strtotime($event['event_date'] . ' 23:59:59');
    $nowTs = time();

    $liveStatus = 'upcoming';
    if ($nowTs >= $liveStartTs && $nowTs <= $liveEndTs) {
        $liveStatus = 'ongoing';
    } elseif ($nowTs > $liveEndTs) {
        $liveStatus = 'finished';
    }
}

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Event Details | Pateros Technological College</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<main class="container event-details-page" style="max-width:1400px">

    <?php if (isset($page_error)): ?>

        <div class="message error ed-error">
            <h1><?= e($page_error) ?></h1>
            <p>The event you are looking for does not exist or was removed.</p>
            <a class="button-secondary" href="index.php">Back to Events</a>
        </div>

    <?php else:

$capacity = max(1, (int) $event['capacity']);
        $fillPercent = min(100, round(((int) $event['registered_count'] / $capacity) * 100));
    ?>

    <a class="ed-back" href="index.php">Back to Events</a>

    <article class="event-details">

        <header class="ed-hero<?= !empty($event['event_image']) ? ' ed-hero-image' : '' ?>"
            <?php if (!empty($event['event_image'])): ?>
style="background-image:
linear-gradient(
rgba(9, 46, 34, 0.86),
rgba(13, 71, 53, 0.90)
),
url('<?= e(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>/uploads/events/<?= e($event['event_image']) ?>');"
            <?php endif; ?>>
            <span class="ed-category"><?= e($event['category']) ?></span>

            <h1><?= e($event['title']) ?></h1>

            <div class="ed-meta">
<span class="ed-meta-item">📅 <?= date('M j, Y', strtotime($event['event_date'])) ?></span>
<span class="ed-meta-item">⏰ <?= date('g:i A', strtotime($event['start_time'])) ?></span>
<span class="ed-meta-item">📍 <?= e($event['venue']) ?></span>
<span class="ed-meta-item ed-status
<?= $event['status'] === 'active'
? ($remaining <= 0 ? 'ed-status-full' : 'ed-status-active')
: ($event['status'] === 'closed' ? 'ed-status-full' : 'ed-status-inactive') ?>">
<?= $event['status'] === 'active'
? ($remaining <= 0 ? 'Full' : 'Open for registration')
: ($event['status'] === 'closed'
? 'Registration closed'
: e($event['status'])) ?>
</span>
            </div>
        </header>

        <div class="ed-body">

            <div class="ed-main">

<section class="ed-panel">
<h2>About this Event</h2>
<p class="ed-description"><?= nl2br(e($event['description'])) ?></p>
</section>

<section class="ed-panel">
<h2>Event Details</h2>

<dl class="ed-info-grid">

<div class="ed-info-item">
<dt>📅 Date</dt>
<dd><?= date('l, M j, Y', strtotime($event['event_date'])) ?></dd>
</div>

<div class="ed-info-item">
<dt>⏰ Time</dt>
<dd><?= date('g:i A', strtotime($event['start_time'])) ?>
<?php if (!empty($event['end_time'])): ?>
– <?= date('g:i A', strtotime($event['end_time'])) ?>
<?php endif; ?>
</dd>
</div>

<div class="ed-info-item">
<dt>📍 Venue</dt>
<dd><?= e($event['venue']) ?></dd>
</div>

<div class="ed-info-item">
<dt>🏷️ Category</dt>
<dd><?= e($event['category']) ?></dd>
</div>

<div class="ed-info-item">
<dt>🎟️ Capacity</dt>
<dd><?= (int) $event['capacity'] ?> slots</dd>
</div>

<div class="ed-info-item">
<dt>🪑 Available Slots</dt>
<dd><?= $remaining ?> of <?= (int) $event['capacity'] ?></dd>
</div>

</dl>

<div class="ed-capacity">
<div class="ed-capacity-label">
<span>Registration Fill</span>
<strong><?= (int) $event['registered_count'] ?> / <?= (int) $event['capacity'] ?> registered
(<?= $fillPercent ?>%)</strong>
</div>

<div class="ed-capacity-bar">
<div class="ed-capacity-fill
<?= $fillPercent >= 100 ? 'ed-capacity-full' : '' ?>"
style="width: <?= $fillPercent ?>%">
</div>
</div>
</div>
</section>

            </div>

            <aside class="ed-side">

<div class="ed-register-card">
<h2>Join this Event</h2>

<div class="ed-slots"><?= $remaining ?></div>
<p class="ed-slots-label">slots remaining</p>

<?php if (!empty($_SESSION['user_id']) &&
($_SESSION['user_role'] ?? '') !== 'admin'): ?>

<?php
$myStmt = $pdo->prepare("
SELECT status
FROM registrations
WHERE user_id = :user_id
AND event_id = :event_id
LIMIT 1
");

$myStmt->execute([
'user_id' => (int) $_SESSION['user_id'],
'event_id' => $event_id
]);

$myStatus = $myStmt->fetchColumn();
?>

<?php if ($myStatus === 'pending'): ?>

<div class="ed-my-status ed-my-pending">
<span class="ed-my-icon">⏳</span>
<strong>Pending approval</strong>
<p>Your registration is waiting for admin approval.
It will show as <strong>Confirmed</strong> in
<a href="my_registrations.php">My Registrations</a>
once approved.</p>
</div>

<?php elseif ($myStatus === 'registered'): ?>

<div class="ed-my-status ed-my-confirmed">
<span class="ed-my-icon">✅</span>
<strong>You're confirmed!</strong>
<p>You are registered for this event. See you there!
Manage it in <a href="my_registrations.php">My Registrations</a>.</p>
</div>

<?php endif; ?>

<?php endif; ?>

<?php if ($event['status'] === 'cancelled'): ?>

<div class="ed-note ed-note-inactive">
🚫 This event has been cancelled.
</div>

<?php elseif ($event['status'] === 'closed'): ?>

<div class="ed-note ed-note-inactive">
Registration for this event is closed.
</div>

<?php elseif ($liveStatus === 'finished'): ?>

<div class="ed-note ed-note-live ed-note-finished">
✓ This event has finished — registration is closed.
</div>

<?php elseif ($liveStatus === 'ongoing'): ?>

<div class="ed-note ed-note-live ed-note-ongoing">
● This event is ongoing — registration is closed.
</div>

<?php elseif ($remaining <= 0): ?>

<div class="ed-note ed-note-full">
This event is already full — all slots are taken.
</div>

<?php elseif (empty($_SESSION['user_id'])): ?>

<div class="login-register-cta ed-login-cta">
<a class="login-cta-button" href="login.php?redirect_to=<?= rawurlencode('event-details.php?id=' . (int) $event['id']) ?>">
Log In to Register
</a>
</div>

<?php elseif (($_SESSION['user_role'] ?? '') !== 'admin'): ?>

<form action="event_register.php" method="POST">
<input type="hidden"
name="event_id"
value="<?= (int) $event['id'] ?>">

<input type="hidden"
name="csrf_token"
value="<?= e($_SESSION['event_csrf_token'] ?? '') ?>">

<button type="submit" class="ed-register-btn">
Register for Event
</button>
</form>

<?php endif; ?>
</div>

            </aside>

        </div>

    </article>

    <?php endif; ?>
</main>

</body>
</html>
