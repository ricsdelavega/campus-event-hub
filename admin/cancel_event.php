
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$token = $_POST['csrf_token'] ?? '';

if (
    !is_string($token) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    exit('Invalid form submission. Please return and try again.');
}

$id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid event ID.');
}

$stmt = $pdo->prepare(
    "UPDATE events
     SET status = 'cancelled'
     WHERE id = :id"
);

$stmt->execute(['id' => $id]);

if ($stmt->rowCount() === 0) {
$check = $pdo->prepare(
        "SELECT id FROM events WHERE id = :id"
    );
    $check->execute(['id' => $id]);

    if (!$check->fetch()) {
        http_response_code(404);
        exit('Event not found.');
    }
}

header('Location: events.php?cancelled=1');
exit;
