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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: registrations.php#pending-approvals');
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

$action = $_POST['action'] ?? '';

if (!$registration_id || $registration_id < 1) {
    $_SESSION['admin_message'] = 'Invalid registration.';
    header('Location: registrations.php#pending-approvals');
    exit;
}

if ($action !== 'approve' && $action !== 'decline') {
    $_SESSION['admin_message'] = 'Invalid action.';
    header('Location: registrations.php#pending-approvals');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $pdo->beginTransaction();

$stmt = $pdo->prepare("
        SELECT r.id, r.event_id, r.status
        FROM registrations r
        WHERE r.id = :registration_id
        FOR UPDATE
    ");

    $stmt->execute(['registration_id' => $registration_id]);
    $registration = $stmt->fetch();

    if (!$registration || $registration['status'] !== 'pending') {
        $pdo->rollBack();

        $_SESSION['admin_message'] =
            'Registration not found or is not pending for approval.';
        header('Location: registrations.php#pending-approvals');
        exit;
    }

    if ($action === 'approve') {
        $capacityStmt = $pdo->prepare("
            SELECT capacity
            FROM events
            WHERE id = :event_id
        ");

        $capacityStmt->execute([
            'event_id' => $registration['event_id']
        ]);

        $capacity = (int) $capacityStmt->fetchColumn();

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM registrations
            WHERE event_id = :event_id
              AND status IN ('registered', 'pending')
              AND id <> :registration_id
        ");

        $countStmt->execute([
            'event_id' => $registration['event_id'],
            'registration_id' => $registration_id
        ]);

        $taken = (int) $countStmt->fetchColumn();

        if ($taken >= $capacity) {
            $pdo->rollBack();

            $_SESSION['admin_message'] =
'Cannot approve: this event has reached its capacity.';
            header('Location: registrations.php#pending-approvals');
            exit;
        }
    }

    if ($action === 'approve') {

        
        do {
            $newToken = bin2hex(random_bytes(16));
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE attendance_token = :t");
            $dupStmt->execute(['t' => $newToken]);
        } while ((int) $dupStmt->fetchColumn() > 0);

        $updateStmt = $pdo->prepare("
            UPDATE registrations
            SET status = 'registered',
                confirmed_at = CURRENT_TIMESTAMP,
                attendance_token = COALESCE(attendance_token, :token)
            WHERE id = :registration_id
              AND status = 'pending'
        ");

        $updateStmt->execute([
            'registration_id' => $registration_id,
            'token'           => $newToken,
        ]);

        $pdo->commit();

        $_SESSION['admin_message'] =
            'Registration approved successfully.';
    } else {

        $updateStmt = $pdo->prepare("
            UPDATE registrations
            SET status = 'declined',
confirmed_at = CURRENT_TIMESTAMP
            WHERE id = :registration_id
              AND status = 'pending'
        ");

        $updateStmt->execute([
            'registration_id' => $registration_id
        ]);

        $pdo->commit();

        $_SESSION['admin_message'] =
            'Registration declined.';
    }

    header('Location: registrations.php#pending-approvals');
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    http_response_code(500);
    exit('A database error occurred. Please try again later.');
}

