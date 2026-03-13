<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    die("Unauthorized access");
}

// Check if user is still active in database
$stmt = $conn->prepare("SELECT is_active FROM users WHERE user_id = ? AND is_active = 1");
if (!$stmt) {
    http_response_code(500);
    die("Database error");
}
$stmt->bind_param("i", $_SESSION["user_id"]);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // User is deactivated or doesn't exist
    session_destroy();
    http_response_code(401);
    header("Location: ../auth/login.php?error=Account has been deactivated");
    exit;
}
$stmt->close();
?>