
<?php
session_start();

require __DIR__ . '/config/database.php';

function escape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function showResult($title, $message, $eventTitle = null)
{
$t = (string) $title;

    $isSuccess = ($t === 'Registration submitted');
    $isWarning = in_array(
        $t,
        ['Already registered', 'Event is full', 'Event unavailable', 'Event is ongoing', 'Event has finished'],
        true
    );

    $isError = !$isSuccess && !$isWarning;

    if ($isSuccess) {
        $icon = '🎉';
        $heading = 'Registration Submitted!';
        $typeLabel = 'PENDING APPROVAL';
        $theme = 'success';
    } elseif ($isWarning) {
        $icon = '⚠️';
        $heading = $t;
        $typeLabel = 'PLEASE TAKE NOTE';
        $theme = 'warning';
    } else {
        $icon = '❌';
        $heading = $t;
        $typeLabel = 'SOMETHING WENT WRONG';
        $theme = 'error';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport"
              content="width=device-width, initial-scale=1.0">
        <title><?= escape($title) ?> - Pateros Technological College</title>
        <link rel="stylesheet"
              href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    </head>
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
            </div>
        </nav>

        <main class="result-main" style="max-width:1400px">
            <section class="result-card result-<?= $theme ?>">

<div class="result-icon"><?= $icon ?></div>

<span class="result-type"><?= $typeLabel ?></span>

<h1 class="result-title"><?= escape($heading) ?></h1>

<p class="result-message"><?= escape($message) ?></p>

<?php if ($isSuccess && $eventTitle): ?>

<div class="result-event-chip">
<span class="result-event-chip-label">REGISTERED EVENT</span>
<strong class="result-event-chip-title"><?= escape($eventTitle) ?></strong>
</div>

<?php endif; ?>

<div class="result-actions">

<?php if ($isSuccess): ?>

<a class="result-btn result-btn-primary" href="my_registrations.php">
View My Registrations
</a>

<a class="result-btn result-btn-secondary" href="index.php#events">
Browse More Events
</a>

<?php else: ?>

<a class="result-btn result-btn-primary" href="index.php#events">
Back to Events
</a>

<?php if ($isWarning): ?>
<a class="result-btn result-btn-secondary" href="my_registrations.php">
My Registrations
</a>
<?php endif; ?>

<?php endif; ?>

</div>

            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['event_csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    showResult(
        'Request rejected',
        'Your request could not be verified. Return to the events page and try again.'
    );
}

$eventId = filter_var(
    $_POST['event_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($eventId === false || $eventId === null) {
    showResult(
        'Invalid event',
        'Please select a valid event and try again.'
    );
}

$liveStmt = $pdo->prepare(
    "SELECT event_date, start_time, end_time
     FROM events
     WHERE id = :event_id"
);

$liveStmt->execute(['event_id' => $eventId]);
$liveEvent = $liveStmt->fetch();

if ($liveEvent) {
    $liveStartTs = strtotime($liveEvent['event_date'] . ' ' . $liveEvent['start_time']);
    $liveEndTs = !empty($liveEvent['end_time'])
        ? strtotime($liveEvent['event_date'] . ' ' . $liveEvent['end_time'])
        : strtotime($liveEvent['event_date'] . ' 23:59:59');
    $nowTs = time();

    if ($nowTs >= $liveStartTs && $nowTs <= $liveEndTs) {
        showResult(
            'Event is ongoing',
            'This event has already started, so registration is no longer allowed.'
        );
    } elseif ($nowTs > $liveEndTs) {
        showResult(
            'Event has finished',
            'This event is already over, so registration is no longer allowed.'
        );
    }
}

$userId = (int) $_SESSION['user_id'];

try {
$pdo->beginTransaction();

$eventStmt = $pdo->prepare(
        "SELECT id, title, capacity
         FROM events
         WHERE id = :event_id
           AND status IN ('active', 'closed')
         FOR UPDATE"
    );

    $eventStmt->execute([
        'event_id' => $eventId
    ]);

    $event = $eventStmt->fetch();

    if (!$event) {
        $pdo->rollBack();

        showResult(
            'Event unavailable',
            'This event does not exist, is closed, or has been cancelled.'
        );
    }

$statusStmt = $pdo->prepare(
        "SELECT status FROM events WHERE id = :event_id"
    );
    $statusStmt->execute(['event_id' => $eventId]);
    $eventStatus = $statusStmt->fetchColumn();

    if ($eventStatus !== 'active') {
        $pdo->rollBack();

        showResult(
            'Registration closed',
            'This event is no longer accepting new registrations.'
        );
    }

$duplicateStmt = $pdo->prepare(
        "SELECT id
         FROM registrations
         WHERE user_id = :user_id
           AND event_id = :event_id
           AND status IN ('registered', 'pending')
         LIMIT 1"
    );

    $duplicateStmt->execute([
        'user_id' => $userId,
        'event_id' => $eventId
    ]);

    if ($duplicateStmt->fetch()) {
        $pdo->rollBack();

        showResult(
            'Already registered',
            'You are already registered for this event. You cannot register for it again.'
        );
    }

$countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM registrations
         WHERE event_id = :event_id
           AND status IN ('registered', 'pending')"
    );

    $countStmt->execute([
        'event_id' => $eventId
    ]);

    $registeredCount = (int) $countStmt->fetchColumn();

if (
        $event['capacity'] !== null &&
        $registeredCount >= (int) $event['capacity']
    ) {
        $pdo->rollBack();

        showResult(
            'Event is full',
            'Sorry, this event has reached its registration capacity.'
        );
    }

$insertStmt = $pdo->prepare(
        "INSERT INTO registrations
            (user_id, event_id, status)
         VALUES
            (:user_id, :event_id, 'pending')
         ON DUPLICATE KEY UPDATE
            status = 'pending',
            confirmed_at = NULL,
            registered_at = CURRENT_TIMESTAMP"
    );

    $insertStmt->execute([
        'user_id' => $userId,
        'event_id' => $eventId
    ]);

    $pdo->commit();

    showResult(
        'Registration submitted',
        'Your registration for ' . $event['title'] .
        ' is now PENDING for admin approval. ' .
        'Please check My Registrations for its status.',
        $event['title']
    );

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

error_log($e->getMessage());

if ($e->getCode() === '23000') {
        showResult(
            'Already registered',
            'You may already be registered for this event. Please check before trying again.'
        );
    }

    showResult(
        'Registration failed',
        'We could not complete your registration. Please try again later.'
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    showResult(
        'Registration failed',
        'Something went wrong. Please try again later.'
    );
}
