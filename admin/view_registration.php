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

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatDateTime($value)
{
    if (empty($value)) {
        return '—';
    }

    return date('M j, Y — g:i A', strtotime($value));
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$registration_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$registration_id || $registration_id < 1) {
    header('Location: registrations.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        r.id AS registration_id,
        r.status AS registration_status,
        r.registered_at,
        r.confirmed_at,
        u.id AS user_id,
        u.full_name,
        u.email,
        u.created_at AS member_since,
        e.id AS event_id,
        e.title AS event_title,
        e.category,
        e.organizer,
        e.event_date,
        e.start_time,
        e.end_time,
        e.venue,
        e.capacity,
        e.status AS event_status,
        (
            SELECT COUNT(*)
            FROM registrations r2
            WHERE r2.event_id = e.id
              AND r2.status IN ('registered', 'pending')
        ) AS taken_slots
    FROM registrations r
    INNER JOIN users u ON u.id = r.user_id
    INNER JOIN events e ON e.id = r.event_id
    WHERE r.id = :registration_id
    LIMIT 1
");

$stmt->execute(['registration_id' => $registration_id]);
$reg = $stmt->fetch();

if (!$reg) {
    http_response_code(404);
    $page_error = 'Registration not found.';
} else {
    $slotsLeft = max(
        0,
        (int) $reg['capacity'] - (int) $reg['taken_slots']
    );

    $status = $reg['registration_status'];

    $statusLabels = [
        'pending'    => ['⏳ Pending — awaiting admin approval', 'status-pending'],
        'registered' => ['✅ Confirmed', 'status-registered'],
        'declined'   => ['❌ Declined', 'status-declined'],
        'cancelled'  => ['Cancelled', 'status-cancelled'],
    ];

    [$statusLabel, $statusClass] =
        $statusLabels[$status] ?? [$status, 'status-pending'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Registration Confirmation | Admin</title>

    <link rel="stylesheet"
        href="../assets/css/style.css">
</head>

<body>

    <main class="container" style="max-width:1400px">

        <p class="admin-breadcrumbs">
            <a href="index.php">Admin Dashboard</a>
            ›
            <a href="registrations.php">Registration Management</a>
            ›
            <span>Registration #<?= (int) ($reg['registration_id'] ?? 0) ?></span>
        </p>

        <?php if (isset($page_error)): ?>

            <h1><?= e($page_error) ?></h1>

        <?php else: ?>

            <section class="confirmation-card">
<div class="confirmation-head">
<div>
<p class="confirmation-kicker">
🎟️ Registration Confirmation
</p>
<h1>
<?= e($reg['event_title']) ?>
</h1>
<p class="confirmation-sub">
Registration
<strong>#<?= (int) $reg['registration_id'] ?></strong>
</p>
</div>

<span class="status-badge <?= e($statusClass) ?> status-badge-lg">
<?= e($statusLabel) ?>
</span>
</div>

<?php if ($status === 'pending'): ?>
<div class="confirmation-actions">
<form action="confirm_registration.php"
method="POST"
class="inline-form">
<input type="hidden"
name="registration_id"
value="<?= (int) $reg['registration_id'] ?>">
<input type="hidden"
name="action"
value="approve">
<input type="hidden"
name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">
<button type="submit"
class="btn-approve">
Approve Registration
</button>
</form>

<form action="confirm_registration.php"
method="POST"
class="inline-form"
onsubmit="return confirm('Decline this registration? The slot will be freed for other students.');">
<input type="hidden"
name="registration_id"
value="<?= (int) $reg['registration_id'] ?>">
<input type="hidden"
name="action"
value="decline">
<input type="hidden"
name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">
<button type="submit"
class="btn-decline">
Decline Registration
</button>
</form>
</div>
<?php endif; ?>
            </section>

            <div class="confirmation-grid">

<section class="confirmation-panel">
<h2>👤 Student Information</h2>

<dl class="detail-list">
<div class="detail-item">
<dt>Full Name</dt>
<dd><?= e($reg['full_name']) ?></dd>
</div>

<div class="detail-item">
<dt>Email</dt>
<dd><?= e($reg['email']) ?></dd>
</div>

<div class="detail-item">
<dt>Member Since</dt>
<dd><?= formatDateTime($reg['member_since']) ?></dd>
</div>
</dl>
</section>

<section class="confirmation-panel">
<h2>📅 Event Information</h2>

<dl class="detail-list">
<div class="detail-item">
<dt>Category</dt>
<dd><?= e($reg['category']) ?></dd>
</div>

<div class="detail-item">
<dt>Organizer</dt>
<dd><?= e($reg['organizer'] ?? '—') ?></dd>
</div>

<div class="detail-item">
<dt>Date</dt>
<dd><?= formatDateTime($reg['event_date']) ?></dd>
</div>

<div class="detail-item">
<dt>Time</dt>
<dd>
<?= formatDateTime($reg['start_time']) ?>
<?php if (!empty($reg['end_time'])): ?>
– <?= formatDateTime($reg['end_time']) ?>
<?php endif; ?>
</dd>
</div>

<div class="detail-item">
<dt>Venue</dt>
<dd><?= e($reg['venue']) ?></dd>
</div>

<div class="detail-item">
<dt>Slots Remaining</dt>
<dd>
<?= $slotsLeft ?> of
<?= (int) $reg['capacity'] ?>
</dd>
</div>
</dl>
</section>

            </div>

            <section class="confirmation-panel">
<h2>🕒 Registration Timeline</h2>

<ul class="timeline">
<li class="timeline-item">
<span class="timeline-dot"></span>
<div>
<strong>Student registered</strong>
<p><?= formatDateTime($reg['registered_at']) ?></p>
</div>
</li>

<li class="timeline-item
<?= in_array($status, ['registered', 'declined'], true)
? 'timeline-active' : 'timeline-muted' ?>">
<span class="timeline-dot"></span>
<div>
<strong>
<?= $status === 'declined'
? 'Admin decision — declined'
: 'Admin confirmation' ?>
</strong>
<p>
<?= in_array($status, ['registered', 'declined'], true)
? formatDateTime($reg['confirmed_at'])
: 'Awaiting admin approval' ?>
</p>
</div>
</li>
</ul>

<p class="back-link">
<a href="registrations.php<?= $reg['event_id'] !== null
? '?event_id=' . (int) $reg['event_id'] : '' ?>">
Back to Attendee List
</a>
</p>
            </section>

        <?php endif; ?>

    </main>

</body>

</html>