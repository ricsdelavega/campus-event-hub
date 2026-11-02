
<?php
session_start();

require __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?expired=1');
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
header('Location: events.php?delete_stale=1');
    exit;
}

$id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid event ID.');
}

try {

$imgStmt = $pdo->prepare(
        "SELECT event_image FROM events WHERE id = :id"
    );

    $imgStmt->execute(['id' => $id]);
    $imageName = $imgStmt->fetchColumn();

    if ($imageName) {
        $imagePath = __DIR__ . '/../uploads/events/' . basename((string) $imageName);

        if (is_file($imagePath)) {
            @unlink($imagePath);
        }
    }

    $deleteStmt = $pdo->prepare(
        "DELETE FROM events WHERE id = :id"
    );

    $deleteStmt->execute(['id' => $id]);

    if ($deleteStmt->rowCount() > 0) {
        header('Location: events.php?deleted=1');
        exit;
    }

header('Location: events.php?delete_missing=1');
    exit;

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('A database error occurred. Please try again later.');
}
