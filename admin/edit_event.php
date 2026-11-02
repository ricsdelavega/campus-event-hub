
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

require_once __DIR__ . '/event_image_helper.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid event ID.');
}

$stmt = $pdo->prepare(
    "SELECT id, title, description, category, organizer,
            event_date, start_time, end_time, venue,
            capacity, status, event_image
     FROM events
     WHERE id = :id"
);

$stmt->execute(['id' => $id]);
$event = $stmt->fetch();

if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Invalid form submission. Refresh the page and try again.';
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
        $status = $_POST['status'] ?? '';

        if (
            !is_string($title) ||
            !is_string($description) ||
            !is_string($category) ||
            !is_string($organizer) ||
            !is_string($eventDate) ||
            !is_string($startTime) ||
            !is_string($endTime) ||
            !is_string($venue) ||
            !is_string($status) ||
            !is_scalar($capacityInput)
        ) {
            $error = 'Invalid form data.';
        } else {
            $capacity = (string) $capacityInput;

            $dateObject = DateTime::createFromFormat(
'!Y-m-d',
$eventDate
            );

            $validDate = $dateObject &&
$dateObject->format('Y-m-d') === $eventDate;

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
(int) $capacity > 100000 ||
!in_array($status, ['active', 'cancelled', 'closed'], true)
            ) {
$error = 'Please check all required fields.';
            } elseif (
$endTime !== '' &&
$endTime <= $startTime
            ) {
$error = 'End time must be later than start time.';
            } else {
try {
$uploadError = null;

$removeRequested =
isset($_POST['remove_image'])
&& $_POST['remove_image'] === '1';

$file = (isset($_FILES['event_image'])
&& is_array($_FILES['event_image']))
? $_FILES['event_image']
: null;

list($uploadedImage, $imageError) = handle_event_image_upload(
$pdo,
(int) $event['id'],
$file,
$removeRequested,
$event['event_image'] ?? null
);

if ($imageError !== null) {
$error = $imageError;
} else {
$sql = "UPDATE events
SET title = :title,
description = :description,
category = :category,
organizer = :organizer,
event_date = :event_date,
start_time = :start_time,
end_time = :end_time,
venue = :venue,
capacity = :capacity,
status = :status
WHERE id = :id";

$stmt = $pdo->prepare($sql);

$stmt->execute([
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
'capacity' => (int) $capacity,
'status' => $status,
'id' => $id
]);

header('Location: events.php?updated=1');
exit;
}
} catch (PDOException $e) {
error_log($e->getMessage());
$error = 'Unable to update the event. Check your database columns.';
}
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') {
    $event['title'] = is_string($_POST['title'] ?? null)
        ? $_POST['title'] : $event['title'];

    $event['description'] = is_string($_POST['description'] ?? null)
        ? $_POST['description'] : $event['description'];

    $event['category'] = is_string($_POST['category'] ?? null)
        ? $_POST['category'] : $event['category'];

    $event['organizer'] = is_string($_POST['organizer'] ?? null)
        ? $_POST['organizer'] : $event['organizer'];

    $event['event_date'] = is_string($_POST['event_date'] ?? null)
        ? $_POST['event_date'] : $event['event_date'];

    $event['start_time'] = is_string($_POST['start_time'] ?? null)
        ? $_POST['start_time'] : $event['start_time'];

    $event['end_time'] = is_string($_POST['end_time'] ?? null)
        ? $_POST['end_time'] : $event['end_time'];

    $event['venue'] = is_string($_POST['venue'] ?? null)
        ? $_POST['venue'] : $event['venue'];

    $event['capacity'] = is_scalar($_POST['capacity'] ?? null)
        ? $_POST['capacity'] : $event['capacity'];

    $event['status'] = is_string($_POST['status'] ?? null)
        ? $_POST['status'] : $event['status'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Event - Pateros Technological College</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .edit-container {
            max-width: 750px;
            margin: 30px auto;
            padding: 20px;
        }

        .edit-form {
            background: white;
            padding: 24px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .edit-form label {
            display: block;
            margin: 14px 0 6px;
            font-weight: bold;
        }

        .edit-form input,
        .edit-form textarea,
        .edit-form select {
            width: 100%;
            padding: 10px;
            box-sizing: border-box;
        }

        .edit-form textarea {
            min-height: 100px;
        }

        .error-message {
            background: #fde8e8;
            color: #8a1c1c;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 16px;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .actions a {
            padding: 12px 18px;
            border: 1px solid #aaa;
            border-radius: 5px;
            text-decoration: none;
        }

        .image-fieldset {
            margin-top: 18px;
            border: 1.5px dashed #bfe0cd;
            border-radius: 12px;
            padding: 14px 16px 16px;
            background: #f6fbf8;
        }

        .image-fieldset legend {
            font-weight: bold;
            color: var(--green-800);
            padding: 0 8px;
        }

        .image-fieldset input[type="file"] {
            padding: 8px 0;
        }

        .current-image img {
            display: block;
            max-width: 100%;
            max-height: 220px;
            border-radius: 10px;
            border: 1px solid #d7e9dd;
            box-shadow: 0 4px 14px rgba(20, 83, 45, 0.10);
            margin-bottom: 8px;
        }

        .remove-image-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #8b1e1e;
            margin-top: 6px;
            cursor: pointer;
        }

        .remove-image-label input {
            width: auto;
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
        <a href="../logout.php">Logout</a>
    </div>
</nav>

<main class="edit-container">

    <h1>Edit Event #<?= (int) $event['id'] ?></h1>

    <p>Change the event information below, then save your changes.</p>

    <?php if ($error !== ''): ?>
        <div class="error-message">
            <?= escape($error) ?>
        </div>
    <?php endif; ?>

    <form class="edit-form" method="POST"
          enctype="multipart/form-data"
          action="edit_event.php?id=<?= (int) $event['id'] ?>">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= escape($_SESSION['csrf_token']) ?>"
        >

        <label for="title">Event Title *</label>
        <input
            id="title"
            name="title"
            maxlength="200"
            value="<?= escape($event['title']) ?>"
            required
        >

        <label for="description">Description</label>
        <textarea
            id="description"
            name="description"
        ><?= escape($event['description'] ?? '') ?></textarea>

        <label for="category">Category *</label>
        <input
            id="category"
            name="category"
            maxlength="100"
            value="<?= escape($event['category']) ?>"
            required
        >

        <label for="organizer">Organizer *</label>
        <input
            id="organizer"
            name="organizer"
            maxlength="150"
            value="<?= escape($event['organizer']) ?>"
            required
        >

        <label for="event_date">Event Date *</label>
        <input
            type="date"
            id="event_date"
            name="event_date"
            value="<?= escape($event['event_date']) ?>"
            required
        >

        <label for="start_time">Start Time *</label>
        <input
            type="time"
            id="start_time"
            name="start_time"
            value="<?= escape(substr($event['start_time'], 0, 5)) ?>"
            required
        >

        <label for="end_time">End Time</label>
        <input
            type="time"
            id="end_time"
            name="end_time"
            value="<?= escape(
$event['end_time']
? substr($event['end_time'], 0, 5)
: ''
            ) ?>"
        >

        <label for="venue">Venue *</label>
        <input
            id="venue"
            name="venue"
            maxlength="200"
            value="<?= escape($event['venue']) ?>"
            required
        >

        <label for="capacity">Capacity *</label>
        <input
            type="number"
            id="capacity"
            name="capacity"
            min="1"
            max="100000"
            value="<?= escape($event['capacity']) ?>"
            required
        >

        <label for="status">Registration Status *</label>
        <select id="status" name="status" required>
            <option value="active"
<?= $event['status'] === 'active' ? 'selected' : '' ?>>
Open — accepting registrations
            </option>

            <option value="closed"
<?= $event['status'] === 'closed' ? 'selected' : '' ?>>
Closed — not accepting registrations
            </option>

            <option value="cancelled"
<?= $event['status'] === 'cancelled' ? 'selected' : '' ?>>
Cancelled — event will not happen
            </option>
        </select>

        <small class="field-hint">
            💡 Tip: "Closed" only stops new registrations — the event
            still pushes through. Registration also closes automatically
            when all slots are full.
        </small>

        <fieldset class="image-fieldset">
            <legend>Event Background Image (optional)</legend>

            <?php if (!empty($event['event_image'])): ?>

<div class="current-image">
<img
src="../uploads/events/<?= escape($event['event_image']) ?>"
alt="Current event image"
>
<p class="muted">This image currently shows on the event page and cards.</p>

<label class="remove-image-label">
<input
type="checkbox"
id="remove_image"
name="remove_image"
value="1"
>
Remove this image (revert to plain event)
</label>
</div>

            <?php else: ?>

<p class="muted">No image yet — this event currently uses the plain green style. Choose a file below to give it a background.</p>

            <?php endif; ?>

            <label for="event_image" style="margin-top:10px;">
<?= !empty($event['event_image']) ? 'Replace with a new image' : 'Upload an image' ?>
            </label>
            <input
type="file"
id="event_image"
name="event_image"
accept="image/jpeg,image/png,image/gif,image/webp"
            >
            <p class="muted">JPG, PNG, GIF or WebP. Up to 4&nbsp;MB. Leave empty to keep the current image.</p>
        </fieldset>

        <div class="actions">
            <button type="submit" class="submit-button">
Save Changes
            </button>

            <a href="events.php">Cancel</a>
        </div>

    </form>

</main>

<footer>
    <p>Pateros Technological College &copy; <?= date('Y') ?></p>
</footer>

</body>
</html>
