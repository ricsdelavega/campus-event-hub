
<?php
session_start();

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/event_image_helper.php';

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

$message = '';
$error = '';

$title = '';
$description = '';
$category = '';
$organizer = '';
$eventDate = '';
$startTime = '';
$endTime = '';
$venue = '';
$capacity = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Invalid form submission. Please refresh the page.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $organizer = trim($_POST['organizer'] ?? '');
        $eventDate = trim($_POST['event_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $capacityInput = $_POST['capacity'] ?? '';

        if (
            !is_string($title) ||
            !is_string($description) ||
            !is_string($category) ||
            !is_string($organizer) ||
            !is_string($eventDate) ||
            !is_string($startTime) ||
            !is_string($endTime) ||
            !is_string($venue) ||
            !is_scalar($capacityInput)
        ) {
            $error = 'Invalid form data.';
        } else {
            $capacity = (string) $capacityInput;

            $dateObject = DateTime::createFromFormat(
'!Y-m-d',
$eventDate
            );

            $validDate = $dateObject
&& $dateObject->format('Y-m-d') === $eventDate;

            $validStart = preg_match(
'/^(?:[01]\d|2[0-3]):[0-5]\d$/',
$startTime
            );

            $validEnd = $endTime === '' ||
preg_match(
'/^(?:[01]\d|2[0-3]):[0-5]\d$/',
$endTime
);

            if (
$title === '' ||
$category === '' ||
$organizer === '' ||
$venue === '' ||
!$validDate ||
!$validStart ||
!$validEnd ||
!ctype_digit($capacity) ||
(int) $capacity < 1 ||
(int) $capacity > 100000
            ) {
$error = 'Please complete all required fields correctly.';
            } elseif (
$endTime !== '' &&
$endTime <= $startTime
            ) {
$error = 'The end time must be later than the start time.';
            } else {
try {
$sql = "
INSERT INTO events
(title, description, category, organizer,
event_date, start_time, end_time, venue, capacity)
VALUES
(:title, :description, :category, :organizer,
:event_date, :start_time, :end_time, :venue, :capacity)
";

$insertStmt = $pdo->prepare($sql);

$insertStmt->execute([
'title' => $title,
'description' => $description,
'category' => $category,
'organizer' => $organizer,
'event_date' => $eventDate,
'start_time' => $startTime,
'end_time' => $endTime === ''
? null
: $endTime,
'venue' => $venue,
'capacity' => (int) $capacity
]);

$newId = (int) $pdo->lastInsertId();

$file = (isset($_FILES['event_image'])
&& is_array($_FILES['event_image']))
? $_FILES['event_image']
: null;

list($uploadedImage, $imageError) = handle_event_image_upload(
$pdo,
$newId,
$file,
false,
null
);

if ($imageError !== null) {
$error = $imageError;
} else {
header('Location: events.php?created=1');
exit;
}
} catch (PDOException $e) {
error_log($e->getMessage());
$error = 'Could not save the event. Please check your database columns.';
}
            }
        }
    }
}

if (isset($_GET['created']) && $_GET['created'] === '1') {
    $message = 'Event created successfully!';
} elseif (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $message = 'Event updated successfully!';
} elseif (isset($_GET['cancelled']) && $_GET['cancelled'] === '1') {
    $message = 'Event cancelled successfully!';
} elseif (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $message = 'Event deleted permanently, together with its registrations.';
} elseif (isset($_GET['delete_missing']) && $_GET['delete_missing'] === '1') {
    $error = 'The event you tried to delete was not found (it may already be deleted).';
} elseif (isset($_GET['delete_stale']) && $_GET['delete_stale'] === '1') {
    $error = 'Your session expired. Refresh this page (F5) and click Delete again.';
} elseif (isset($_GET['delete_blocked']) && $_GET['delete_blocked'] === '1') {
    $error = 'This event still has registered attendees. Tick the Delete checkbox to also remove their registrations.';
}

$stmt = $pdo->query(
    "SELECT id, title, event_date, venue, capacity, status
     FROM events
     ORDER BY event_date DESC, id DESC"
);

$events = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Events - Pateros Technological College</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .admin-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 20px;
        }

        .event-form {
            background: white;
            padding: 24px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            margin-bottom: 35px;
        }

        .event-form label {
            display: block;
            margin-top: 14px;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .event-form input,
        .event-form textarea,
        .event-form select {
            width: 100%;
            padding: 10px;
            box-sizing: border-box;
        }

        .event-form textarea {
            min-height: 100px;
        }

        .notice {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
        }

        .success {
            background: #e3f7e8;
            color: #155724;
        }

        .error {
            background: #fde8e8;
            color: #8a1c1c;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .events-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .events-table th,
        .events-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }

        .events-table th {
            background: linear-gradient(90deg, var(--green-800), var(--green-600));
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-size: 12.5px;
            padding: 14px;
        }

        .events-table td {
            vertical-align: middle;
            padding: 14px;
        }

        .cell-title {
            font-weight: 700;
            color: var(--green-900);
        }

        .cell-date {
            white-space: nowrap;
            color: #55636d;
        }

        .cell-status .status-badge {
            white-space: nowrap;
        }

        .cell-actions {
            min-width: 250px;
        }

        .table-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: nowrap;
        }

        .table-actions form {
            display: inline-flex;
            margin: 0;
        }

        .table-actions button.btn-table,
        .table-actions a.btn-table {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            font-family: inherit;
            border: none;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            flex: 0 0 auto;
            line-height: 1.2;
            transition: transform 0.2s ease, box-shadow 0.2s ease,
filter 0.2s ease;
        }

        .table-actions .btn-table:hover {
            transform: translateY(-2px);
            filter: brightness(1.07);
            text-decoration: none;
        }

        .table-actions .btn-table:active {
            transform: translateY(0);
        }

        .table-actions .btn-edit {
            background: #ffffff;
            color: var(--green-800);
            border: 1.5px solid var(--green-400);
            box-shadow: none;
        }

        .table-actions .btn-edit:hover {
            background: var(--green-100);
            color: var(--green-900);
            box-shadow: 0 4px 10px rgba(20, 83, 45, 0.14);
        }

        .table-actions .btn-cancel {
            background: linear-gradient(135deg, #e05252, #c13a3a) !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(214, 69, 69, 0.30);
            border: none !important;
        }

        .table-actions .btn-cancel:hover {
            box-shadow: 0 8px 18px rgba(214, 69, 69, 0.45);
        }

        .table-actions .btn-delete {
            background: linear-gradient(135deg, #f9b000, #e09400) !important;
            color: #3a2a00 !important;
            box-shadow: 0 4px 12px rgba(249, 176, 0, 0.30);
            border: none !important;
        }

        .table-actions .btn-delete:hover {
            box-shadow: 0 8px 18px rgba(249, 176, 0, 0.45);
        }

        .status-active {
            background: var(--green-100);
            color: var(--green-800);
            border: 1.5px solid var(--green-400);
        }

        .status-cancelled {
            background: #fdeaea;
            color: #8b1e1e;
            border: 1.5px solid #f3b3b3;
        }

        .status-archived,
        .status-unknown {
            background: #eef1f4;
            color: #55636d;
            border: 1.5px solid #d5dde3;
        }

        .events-table tbody tr {
            transition: background 0.2s ease;
        }

        .events-table tbody tr:hover {
            background: var(--green-100);
        }

        .row-muted {
            opacity: 0.62;
        }

        .row-muted .cell-title {
            text-decoration: line-through;
            text-decoration-color: rgba(139, 30, 30, 0.5);
            text-decoration-thickness: 2px;
        }

        .notice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 18px;
            border-radius: 12px;
            font-weight: 600;
            border-left: 5px solid transparent;
        }

        .notice.success {
            background: var(--green-100);
            color: var(--green-900);
            border-left-color: var(--green-500);
        }

        .notice.error {
            background: #fdeaea;
            color: #8b1e1e;
            border-left-color: var(--danger);
        }

        .event-form .submit-button {
            width: 100%;
            font-size: 16px;
            padding: 14px;
            margin-top: 10px;
        }

        .event-form input:focus,
        .event-form textarea:focus {
            outline: none;
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(39, 163, 119, 0.18);
        }

        .event-form h2,
        main h2 {
            color: var(--green-900);
        }

        main > section > h2 {
            position: relative;
            padding-bottom: 8px;
        }

        main > section > h2::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: 0;
            width: 48px;
            height: 4px;
            border-radius: 4px;
            background: linear-gradient(90deg, var(--green-400), var(--green-600));
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
        <a href="../index.php">View Website</a>
        <a href="../logout.php">Logout</a>
    </div>
</nav>

<main class="admin-container">

    <h1>Manage Campus Events</h1>

    <p>Create new events and view existing events below.</p>

    <?php if ($message !== ''): ?>
        <div class="notice success">
            <?= escape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error">
            <?= escape($error) ?>
        </div>
    <?php endif; ?>

    <section class="event-form">

        <h2>Add a New Event</h2>

        <form action="events.php" method="POST" enctype="multipart/form-data">

            <input
type="hidden"
name="csrf_token"
value="<?= escape($_SESSION['csrf_token']) ?>"
            >

            <label for="title">Event Title *</label>
            <input
type="text"
id="title"
name="title"
maxlength="200"
value="<?= escape($title) ?>"
required
            >

            <label for="description">Description</label>
            <textarea
id="description"
name="description"
            ><?= escape($description) ?></textarea>

            <label for="category">Category *</label>
            <input
type="text"
id="category"
name="category"
maxlength="100"
value="<?= escape($category) ?>"
placeholder="e.g. Academic, Sports, Cultural"
required
            >

            <label for="organizer">Organizer *</label>
            <input
type="text"
id="organizer"
name="organizer"
maxlength="150"
value="<?= escape($organizer) ?>"
required
            >

            <label for="event_date">Event Date *</label>
            <input
type="date"
id="event_date"
name="event_date"
value="<?= escape($eventDate) ?>"
required
            >

            <label for="start_time">Start Time *</label>
            <input
type="time"
id="start_time"
name="start_time"
value="<?= escape($startTime) ?>"
required
            >

            <label for="end_time">End Time (optional)</label>
            <input
type="time"
id="end_time"
name="end_time"
value="<?= escape($endTime) ?>"
            >

            <label for="venue">Venue *</label>
            <input
type="text"
id="venue"
name="venue"
maxlength="200"
value="<?= escape($venue) ?>"
required
            >

            <label for="capacity">Capacity *</label>
            <input
type="number"
id="capacity"
name="capacity"
min="1"
max="100000"
value="<?= escape($capacity) ?>"
required
            >

            <fieldset class="image-fieldset">
<legend>Event Background Image (optional)</legend>

<label for="event_image">Upload an image</label>
<input
type="file"
id="event_image"
name="event_image"
accept="image/jpeg,image/png,image/gif,image/webp"
>
<p class="muted">JPG, PNG, GIF or WebP. Up to 4&nbsp;MB. Optional — leave empty for the plain green style.</p>
            </fieldset>

            <br><br>

            <button type="submit" class="submit-button">
Create Event
            </button>

        </form>

    </section>

    <section>
        <h2>Existing Events</h2>

        <?php if (count($events) > 0): ?>

            <div class="table-wrapper">
<table class="events-table">

<thead>
<tr>
<th>ID</th>
<th>Event Title</th>
<th>Date</th>
<th>Venue</th>
<th>Capacity</th>
<th>Status</th>
<th>Actions</th>
</tr>
</thead>

<tbody>
<?php foreach ($events as $event): ?>
<tr class="<?= in_array($event['status'], ['cancelled', 'archived'], true) ? 'row-muted' : '' ?>">
<td><?= (int) $event['id'] ?></td>
<td class="cell-title"><?= escape($event['title']) ?></td>
<td class="cell-date"><?= date('M j, Y', strtotime($event['event_date'])) ?></td>
<td><?= escape($event['venue']) ?></td>
<td><?= (int) $event['capacity'] ?></td>
<td class="cell-status">
<span class="status-badge status-<?= strtolower(trim((string) $event['status']) ?: 'unknown') ?>">
<?= escape(ucfirst((string) $event['status'])) ?>
</span>
</td>

<td class="cell-actions">
<div class="table-actions">
<a class="btn-table btn-edit" href="edit_event.php?id=<?= (int) $event['id'] ?>">
Edit
</a>

<?php if ($event['status'] === 'active'): ?>
<form
action="cancel_event.php"
method="POST"
onsubmit="return confirm('Are you sure you want to cancel this event?');"
>
<input
type="hidden"
name="csrf_token"
value="<?= escape($_SESSION['csrf_token']) ?>"
>

<input
type="hidden"
name="event_id"
value="<?= (int) $event['id'] ?>"
>

<button type="submit" class="btn-table btn-cancel">
⛔ Cancel
</button>
</form>
<?php endif; ?>

<form
action="delete_event.php"
method="POST"
onsubmit="return confirm('Permanently DELETE this event and ALL of its registrations? This cannot be undone!');"
>
<input
type="hidden"
name="csrf_token"
value="<?= escape($_SESSION['csrf_token']) ?>"
>

<input
type="hidden"
name="event_id"
value="<?= (int) $event['id'] ?>"
>

<button type="submit" class="btn-table btn-delete">
Delete
</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>

</table>
            </div>

        <?php else: ?>

            <p>No events have been created yet.</p>

        <?php endif; ?>
    </section>

</main>

<footer>
    <p>Pateros Technological College &copy; <?= date('Y') ?></p>
</footer>

</body>
</html>
