<?php





session_start();
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Admin access only.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$token = trim((string) ($data['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid QR code format.']);
    exit;
}


$eventId = filter_var($data['event_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($eventId === false || $eventId === null) {
    echo json_encode(['ok' => false, 'msg' => 'No event selected. Pick an event first.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.id, r.status, r.attended_at, u.full_name, u.email,
           e.id AS event_id, e.title, e.event_date
    FROM registrations r
    INNER JOIN users u ON u.id = r.user_id
    INNER JOIN events e ON e.id = r.event_id
    WHERE r.attendance_token = :t
    LIMIT 1
");
$stmt->execute(['t' => $token]);
$reg = $stmt->fetch();

if (!$reg) {
    echo json_encode(['ok' => false, 'msg' => 'QR code not recognized.']);
    exit;
}


if ((int) $reg['event_id'] !== (int) $eventId) {
    echo json_encode([
        'ok' => false,
        'wrong_event' => true,
        'name' => $reg['full_name'],
        'event' => $reg['title'],
        'msg' => 'Wrong event! This QR is for "' . $reg['title'] . '", not the event you are scanning for.',
    ]);
    exit;
}

if ($reg['status'] !== 'registered') {
    echo json_encode(['ok' => false, 'msg' => 'Registration is not confirmed (status: ' . $reg['status'] . ').']);
    exit;
}

if (!empty($reg['attended_at'])) {
    echo json_encode([
        'ok' => true,
        'duplicate' => true,
        'name' => $reg['full_name'],
        'event' => $reg['title'],
        'at' => date('g:i A', strtotime($reg['attended_at'])),
        'msg' => 'Already checked in at ' . date('g:i A', strtotime($reg['attended_at'])) . '.',
    ]);
    exit;
}

$upd = $pdo->prepare("UPDATE registrations SET attended_at = NOW() WHERE id = :id");
$upd->execute(['id' => $reg['id']]);

echo json_encode([
    'ok' => true,
    'duplicate' => false,
    'name' => $reg['full_name'],
    'event' => $reg['title'],
    'at' => date('g:i A'),
    'msg' => 'Attendance recorded!',
]);
