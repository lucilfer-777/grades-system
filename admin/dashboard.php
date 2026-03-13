<?php
/*
 * ADMIN DASHBOARD CONTENT
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

ob_start();
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}

// Admin access control
requireRole([4]);

$admin_id = $_SESSION["user_id"];
$admin_name = $_SESSION["user_name"] ?? "Admin";

// Get real statistics from database
try {
    // Total users
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users");
    $stmt->execute();
    $result = $stmt->get_result();
    $total_users_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // Active users
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $active_users_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // Total grades
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM grades WHERE status = 'Approved'");
    $stmt->execute();
    $result = $stmt->get_result();
    $total_grades_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // Recent audit logs
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM audit_logs WHERE action_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_logs_count = $result->fetch_assoc()['count'];
    $stmt->close();

    $stats = [
        'total_users' => $total_users_count,
        'active_users' => $active_users_count,
        'total_grades' => $total_grades_count,
        'recent_logs' => $recent_logs_count
    ];

} catch (Exception $e) {
    // Database error - use zero values
    $stats = [
        'total_users' => 0,
        'active_users' => 0,
        'total_grades' => 0,
        'recent_logs' => 0
    ];
    error_log("Database error in admin dashboard: " . $e->getMessage());
}

$recent_activities = [];

// Get recent audit activities
try {
    $stmt = $conn->prepare("
        SELECT action_time, user_id, action
        FROM audit_logs
        ORDER BY action_time DESC
        LIMIT 5
    ");
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($activities as $activity) {
        $time_diff = time() - strtotime($activity['action_time']);
        if ($time_diff < 60) {
            $time = 'just now';
        } elseif ($time_diff < 3600) {
            $mins = floor($time_diff / 60);
            $time = $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
        } elseif ($time_diff < 86400) {
            $hrs = floor($time_diff / 3600);
            $time = $hrs . ' hour' . ($hrs === 1 ? '' : 's') . ' ago';
        } else {
            $days = floor($time_diff / 86400);
            $time = $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
        }
        $recent_activities[] = [
            'action' => $activity['action'],
            'time' => $time
        ];
    }

} catch (Exception $e) {
    $recent_activities = [];
    error_log("Database error getting recent activities: " . $e->getMessage());
}
?>

<style>
    :root {
        --primary: #3B82F6;
        --primary-dark: #1E40AF;
        --secondary: #10B981;
        --accent: #F59E0B;
        --danger: #EF4444;
        --surface: #FFFFFF;
        --background: #F8FAFC;
        --text-primary: #1E293B;
        --text-secondary: #64748B;
        --border: #E2E8F0;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        --radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .dashboard-header {
        margin-bottom: 2rem;
    }

    .dashboard-header h2 {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-900);
        margin-bottom: 0.5rem;
    }

    .dashboard-header p {
        color: var(--text-600);
        font-size: 0.9rem;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--surface);
        padding: 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--primary);
    }

    .stat-card:nth-child(2)::before { background: var(--secondary); }
    .stat-card:nth-child(3)::before { background: var(--accent); }
    .stat-card:nth-child(4)::before { background: var(--danger); }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1rem;
        font-size: 1.5rem;
    }

    .stat-card:nth-child(1) .stat-icon { background: rgba(59, 130, 246, 0.1); color: var(--primary); }
    .stat-card:nth-child(2) .stat-icon { background: rgba(16, 185, 129, 0.1); color: var(--secondary); }
    .stat-card:nth-child(3) .stat-icon { background: rgba(245, 158, 11, 0.1); color: var(--accent); }
    .stat-card:nth-child(4) .stat-icon { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .stat-label {
        font-size: 0.9rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }

    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 2rem;
    }

    .activity-card, .quick-actions-card {
        background: var(--surface);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
    }

    .activity-card {
        padding: 1.5rem;
    }

    .activity-card h3 {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .activity-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .activity-item {
        padding: 1rem;
        border-bottom: 1px solid var(--border);
        transition: var(--transition);
    }

    .activity-item:last-child {
        border-bottom: none;
    }

    .activity-item:hover {
        background: rgba(59, 130, 246, 0.05);
        border-radius: 8px;
    }

    .activity-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .activity-description {
        font-size: 0.9rem;
        color: var(--text-primary);
        line-height: 1.4;
        flex: 1;
        margin-right: 1rem;
    }

    .activity-description strong {
        font-weight: 600;
        color: var(--text-primary);
    }

    .activity-time {
        font-size: 0.8rem;
        color: var(--text-secondary);
        flex-shrink: 0;
    }

    .quick-actions-card {
        padding: 1.5rem;
    }

    .quick-actions-card h3 {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }

    .action-buttons {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .action-btn {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 8px;
        text-decoration: none;
        color: var(--text-primary);
        font-weight: 500;
        transition: var(--transition);
    }

    .action-btn:hover {
        background: rgba(59, 130, 246, 0.05);
        border-color: var(--primary);
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .action-btn i {
        font-size: 1.25rem;
        color: var(--primary);
    }

    @media (max-width: 768px) {
        .welcome-card {
            padding: 2rem;
        }

        .welcome-card h1 {
            font-size: 2rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .content-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Header -->
<div class="dashboard-header">
        <h2>Dashboard</h2>
        <p>Manage users, view reports, and monitor system activities.</p>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class='bx bx-group'></i>
        </div>
        <div class="stat-value"><?= $stats['total_users'] ?></div>
        <div class="stat-label">Total Users</div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class='bx bx-user-check'></i>
        </div>
        <div class="stat-value"><?= $stats['active_users'] ?></div>
        <div class="stat-label">Active Users</div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class='bx bx-book'></i>
        </div>
        <div class="stat-value"><?= $stats['total_grades'] ?></div>
        <div class="stat-label">Approved Grades</div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class='bx bx-time-five'></i>
        </div>
        <div class="stat-value"><?= $stats['recent_logs'] ?></div>
        <div class="stat-label">Recent Logs (24h)</div>
    </div>
</div>

<!-- Content Grid -->
<div class="content-grid">
    <!-- Recent Activities -->
    <div class="activity-card">
        <h3>
            <i class='bx bx-activity'></i>
            Recent Activities
        </h3>
        <ul class="activity-list">
            <?php foreach ($recent_activities as $activity): ?>
                <li class="activity-item">
                    <div class="activity-content">
                        <div class="activity-description">
                            <strong>System:</strong> <?= htmlspecialchars($activity['action'], ENT_QUOTES) ?>
                        </div>
                        <div class="activity-time"><?= htmlspecialchars($activity['time'], ENT_QUOTES) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions-card">
        <h3>Quick Actions</h3>
        <div class="action-buttons">
            <a href="?page=user_management" class="action-btn">
                <i class='bx bx-group'></i>
                <span>User Management</span>
            </a>
            <a href="?page=grade_reports" class="action-btn">
                <i class='bx bx-bar-chart'></i>
                <span>Grade Reports</span>
            </a>
            <a href="?page=audit_logs" class="action-btn">
                <i class='bx bx-file-find'></i>
                <span>Audit Logs</span>
            </a>
        </div>
    </div>
</div>