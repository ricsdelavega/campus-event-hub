
<?php
session_start();

require __DIR__ . '/config/database.php';

if (empty($_SESSION['event_csrf_token'])) {
    $_SESSION['event_csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$sort = $_GET['sort'] ?? 'date_asc';

$allowedSorts = [
    'date_asc' => 'event_date ASC, start_time ASC',
    'date_desc' => 'event_date DESC, start_time DESC',
    'title_asc' => 'title ASC'
];

if (!isset($allowedSorts[$sort])) {
    $sort = 'date_asc';
}

$sql = "
    SELECT id,
           title,
           description,
           category,
           organizer,
           event_date,
           start_time,
           end_time,
           venue,
           capacity,
           event_image,
           status,
           (
               SELECT COUNT(*)
               FROM registrations r
               WHERE r.event_id = events.id
AND r.status IN ('registered', 'pending')
           ) AS registered_count
    FROM events
    WHERE status = :status
";

$params = ['status' => 'active'];

if ($search !== '') {
    $sql .= "
        AND (
            title LIKE :search_title
            OR description LIKE :search_description
            OR category LIKE :search_category
        )
    ";

    $keyword = '%' . $search . '%';

    $params['search_title'] = $keyword;
    $params['search_description'] = $keyword;
    $params['search_category'] = $keyword;
}

if ($category !== '' && $category !== 'all') {
    $sql .= " AND category = :category";
    $params['category'] = $category;
}

$sql .= " ORDER BY " . $allowedSorts[$sort];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryStmt = $pdo->query("
    SELECT DISTINCT category
    FROM events
    WHERE status = 'active'
      AND category IS NOT NULL
      AND category <> ''
    ORDER BY category ASC
");

$categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Campus Event Hub | Pateros Technological College</title>

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

            <a href="index.php#events">Events</a>

            <?php if (isset($_SESSION['user_id'])): ?>

<a href="my_registrations.php">
My Registrations
</a>

<span class="user-chip">
<span class="user-avatar"><?= escape(strtoupper(mb_substr($_SESSION['user_name'] ?? 'S', 0, 1))) ?></span>
Hello,
<?= escape($_SESSION['user_name'] ?? 'Student') ?>
</span>

<a href="logout.php">Logout</a>

            <?php else: ?>

<a href="register.php">Register</a>

<a href="login.php">Login</a>

            <?php endif; ?>

        </div>

    </nav>

    <header class="hero">

        <span class="hero-badge">Pateros Technological College — Campus Event Hub</span>

        <h1>
            Discover Events
            <span class="hero-highlight">Happening on Campus</span>
        </h1>

        <p>
            Explore seminars, sports, and workshops.
            Join in one click and never miss out!
        </p>

        <div class="hero-actions">

            <?php if (isset($_SESSION['user_id'])): ?>

<a class="hero-button" href="#events">Browse Events</a>

<a class="hero-button hero-button-outline" href="my_registrations.php">
My Registrations
</a>

            <?php else: ?>

<a class="hero-button" href="register.php">Get Started — It's Free</a>

<a class="hero-button hero-button-outline" href="login.php">
I Already Have an Account
</a>

            <?php endif; ?>

        </div>

        <div class="hero-stats">

            <div class="hero-stat">
<strong><?= count($events) ?></strong>
<span>Upcoming Events</span>
            </div>

            <div class="hero-stat">
<strong><?= count(array_unique(array_column($events, 'category'))) ?></strong>
<span>Categories</span>
            </div>

            <div class="hero-stat">
<strong><?= array_sum(array_column($events, 'capacity')) ?></strong>
<span>Total Slots</span>
            </div>

        </div>

    </header>

    <main style="max-width:1400px">

        <div class="section-head">
            <h2 id="events">Upcoming Campus Events</h2>
            <p class="section-sub">Stay updated — new events are added regularly.</p>
        </div>

        <section class="event-search">
            <div class="search-head">
<h2>🔎 Find a Campus Event</h2>
<p class="search-sub">Search by keyword, filter by category, and sort the results.</p>
            </div>

            <form action="index.php#events" method="GET">
<div class="search-field">
<div class="input-wrap">
<span class="input-icon">🔍</span>
<input
type="search"
id="search"
name="search"
placeholder="Search by title or keyword..."
value="<?= escape($_GET['search'] ?? '') ?>"
>
</div>
</div>

<div class="search-field">
<div class="input-wrap">
<span class="input-icon">🏷️</span>
<select id="category" name="category">
<option value="all">All categories</option>

<?php foreach ($categories as $cat): ?>
<option
value="<?= escape($cat) ?>"
<?= (($_GET['category'] ?? 'all') === $cat)
? 'selected'
: '' ?>
>
<?= escape($cat) ?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>

<div class="search-field">
<div class="input-wrap">
<span class="input-icon">↕️</span>
<select id="sort" name="sort">
<option value="date_asc"
<?= $sort === 'date_asc' ? 'selected' : '' ?>>
Earliest date
</option>

<option value="date_desc"
<?= $sort === 'date_desc' ? 'selected' : '' ?>>
Latest date
</option>

<option value="title_asc"
<?= $sort === 'title_asc' ? 'selected' : '' ?>>
Event title A–Z
</option>
</select>
</div>
</div>

<div class="search-actions">
<button type="submit">Search Events</button>
<a href="index.php#events" class="button button-secondary">
Clear Filters
</a>
</div>
            </form>
        </section>

        <?php if (count($events) > 0): ?>

            <div class="event-grid">

<?php foreach ($events as $event): ?>

<?php
$eventStartTs = strtotime($event['event_date'] . ' ' . $event['start_time']);
$eventEndTs = !empty($event['end_time'])
? strtotime($event['event_date'] . ' ' . $event['end_time'])
: strtotime($event['event_date'] . ' 23:59:59');
$nowTs = time();

$liveStatus = 'upcoming';
if ($nowTs >= $eventStartTs && $nowTs <= $eventEndTs) {
$liveStatus = 'ongoing';
} elseif ($nowTs > $eventEndTs) {
$liveStatus = 'finished';
}
?>

<article class="event-card<?= !empty($event['event_image']) ? ' has-image' : '' ?>"
<?php if (!empty($event['event_image'])): ?>
style="--card-image: url('<?= escape(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>/uploads/events/<?= escape($event['event_image']) ?>')"
<?php endif; ?>>

<div class="card-top-row">
<span class="category">
<?= escape($event['category']) ?>
</span>

<span
class="event-status-badge status-<?= $liveStatus ?>"
data-start="<?= $eventStartTs ?>"
data-end="<?= $eventEndTs ?>"
>
<?= $liveStatus === 'ongoing'
? '● Ongoing'
: ($liveStatus === 'finished'
? '✓ Finished'
: '⏱ Upcoming') ?>
</span>
</div>

<h2>
<?= escape($event['title']) ?>
</h2>

<p>
<?= nl2br(
escape(
$event['description'] ?? ''
)
) ?>
</p>

<div class="details">

<div class="detail-row">
<span class="detail-icon">📅</span>
<strong>Date:</strong>
<?= formatDate($event['event_date']) ?>
</div>

<div class="detail-row">
<span class="detail-icon">⏰</span>
<strong>Start:</strong>
<?= formatTime($event['start_time']) ?>
</div>

<?php if (!empty($event['end_time'])): ?>

<div class="detail-row">
<span class="detail-icon">🏁</span>
<strong>End:</strong>
<?= formatTime($event['end_time']) ?>
</div>

<?php endif; ?>

<div class="detail-row">
<span class="detail-icon">📍</span>
<strong>Venue:</strong>
<?= escape($event['venue']) ?>
</div>

<div class="detail-row">
<span class="detail-icon">🙋</span>
<strong>Organizer:</strong>
<?= escape($event['organizer'] ?? '') ?>
</div>

<div class="detail-row">
<span class="detail-icon">🎟️</span>
<strong>Capacity:</strong>
<?= escape($event['capacity']) ?> slots
</div>

<div class="detail-row">
<span class="detail-icon">🪑</span>
<strong>Slots Left:</strong>
<?= max(0, (int) $event['capacity'] - (int) ($event['registered_count'] ?? 0)) ?>
</div>

<a class="details-link" href="event-details.php?id=<?= (int) $event['id'] ?>">
View Details
</a>

</div>

<?php $slotsLeft = max(0, (int) $event['capacity'] - (int) ($event['registered_count'] ?? 0)); ?>

<?php if ($event['status'] === 'cancelled'): ?>
<div class="event-full-note event-cancelled-note">Event cancelled</div>
<?php elseif ($liveStatus === 'finished'): ?>
<div class="event-full-note event-live-note event-live-finished">
✓ Event finished — registration closed
</div>
<?php elseif ($liveStatus === 'ongoing'): ?>
<div class="event-full-note event-live-note event-live-ongoing">
● Event is ongoing — registration closed
</div>
<?php elseif ($event['status'] === 'closed' || $slotsLeft <= 0): ?>
<div class="event-full-note">
<?= $event['status'] === 'closed'
? 'Registration closed'
: 'No more slots available' ?>
</div>
<?php elseif (isset($_SESSION['user_id'])): ?>

<form
action="event_register.php"
method="POST"
>

<input
type="hidden"
name="event_id"
value="<?= (int) $event['id'] ?>"
>

<input
type="hidden"
name="csrf_token"
value="<?= escape(
$_SESSION['event_csrf_token']
) ?>"
>

<button
type="submit"
class="register-button"
>
Register for Event
</button>

</form>

<?php elseif ($event['status'] === 'active' && $slotsLeft > 0): ?>

<div class="login-register-cta">
<a class="login-cta-button" href="login.php?redirect_to=<?= rawurlencode('index.php#events') ?>">
Log In to Register
</a>
</div>

<?php endif; ?>

</article>

<?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="message empty">
<p class="empty-icon">🔍</p>
<h3>No events match your search</h3>
<p>
No events match your search.
Try another keyword or category.
</p>
<a href="index.php#events" class="button button-secondary">
Clear Filters
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

</body>
</html>
