
<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_registrations.php');
    exit;
}

$token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($token) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    exit('Invalid request token. Please go back and try again.');
}

$registration_id = filter_input(
    INPUT_POST,
    'registration_id',
    FILTER_VALIDATE_INT
);

if (!$registration_id || $registration_id < 1) {
    $_SESSION['registration_message'] = 'Invalid registration.';
    header('Location: my_registrations.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
$stmt = $pdo->prepare("
        UPDATE registrations
        SET status = 'cancelled'
        WHERE id = :registration_id
          AND user_id = :user_id
          AND status IN ('registered', 'pending')
    ");

    $stmt->execute([
        'registration_id' => $registration_id,
        'user_id' => $user_id
    ]);

    if ($stmt->rowCount() === 1) {
        $_SESSION['registration_message'] =
            'Your event registration has been cancelled.';
    } else {
        $_SESSION['registration_message'] =
            'Registration not found, already cancelled, or not eligible for cancellation.';
    }

header('Location: my_registrations.php#reg-' . $registration_id);
    exit;

} catch (PDOException $e) {
    error_log($e->getMessage());

    http_response_code(500);
    exit('A database error occurred. Please try again later.');
}
