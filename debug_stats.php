<?php
require 'config/db.php';

try {
    // Total users
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users");
    $stmt->execute();
    $result = $stmt->get_result();
    $total_users = $result->fetch_assoc()['count'];
    $stmt->close();

    // Active users
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $active_users = $result->fetch_assoc()['count'];
    $stmt->close();

    // Total grades
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM grades WHERE status = 'Approved'");
    $stmt->execute();
    $result = $stmt->get_result();
    $total_grades = $result->fetch_assoc()['count'];
    $stmt->close();

    // Recent logs
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM audit_logs WHERE action_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_logs = $result->fetch_assoc()['count'];
    $stmt->close();

    echo "Total Users: $total_users\n";
    echo "Active Users: $active_users\n";
    echo "Approved Grades: $total_grades\n";
    echo "Recent Logs (24h): $recent_logs\n";

} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>