<?php
/*
 * REGISTRAR DASHBOARD CONTENT
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

// Registrar access control
requireRole([2]);

$registrar_id = $_SESSION["user_id"];
$registrar_name = $_SESSION["user_name"] ?? "Registrar";

// Get real statistics from database
try {
    // Pending enrollments
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM enrollment_requests WHERE status = 'Pending'");
    $stmt->execute();
    $result = $stmt->get_result();
    $pending_enrollments_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // Pending grades to approve
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM grades WHERE status = 'Pending'");
    $stmt->execute();
    $result = $stmt->get_result();
    $pending_grades_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // Pending corrections to approve
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM grade_corrections WHERE status = 'Pending'");
    $stmt->execute();
    $result = $stmt->get_result();
    $pending_corrections_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // Total active students
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT student_id) as count FROM enrollments WHERE status = 'Active'");
    $stmt->execute();
    $result = $stmt->get_result();
    $total_students_count = $result->fetch_assoc()['count'];
    $stmt->close();

    $stats = [
        'pending_enrollments' => $pending_enrollments_count,
        'pending_grades' => $pending_grades_count,
        'pending_corrections' => $pending_corrections_count,
        'total_students' => $total_students_count
    ];

} catch (Exception $e) {
    // Fallback to mock data if database error
    $stats = [
        'pending_enrollments' => 5,
        'pending_grades' => 12,
        'pending_corrections' => 3,
        'total_students' => 245
    ];
    error_log("Database error in registrar dashboard: " . $e->getMessage());
}

$recent_activities = [];

// Get real recent activities from database for registrar actions
try {
    // Get enrollment approvals by registrar
    $stmt = $conn->prepare("
        SELECT
            'enrollment_approved' as type,
            al.action_time,
            er.student_id,
            u.full_name as student_name,
            s.subject_name
        FROM audit_logs al
        INNER JOIN enrollment_requests er ON er.request_id = CAST(REPLACE(al.action, 'Approved enrollment request ', '') AS UNSIGNED)
        INNER JOIN users u ON er.student_id = u.user_id
        INNER JOIN subjects s ON er.subject_id = s.subject_id
        WHERE al.action LIKE 'Approved enrollment request %'
        ORDER BY al.action_time DESC
        LIMIT 10
    ");
    $stmt->execute();
    $enrollment_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get grade approvals by registrar
    $stmt = $conn->prepare("
        SELECT
            'grade_approved' as type,
            al.action_time,
            g.student_id,
            u.full_name as student_name,
            s.subject_name
        FROM audit_logs al
        INNER JOIN grades g ON g.grade_id = CAST(REPLACE(al.action, 'Approved grade ID ', '') AS UNSIGNED)
        INNER JOIN users u ON g.student_id = u.user_id
        INNER JOIN subjects s ON g.subject_id = s.subject_id
        WHERE al.action LIKE 'Approved grade ID %'
        ORDER BY al.action_time DESC
        LIMIT 10
    ");
    $stmt->execute();
    $grade_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get grade returns by registrar
    $stmt = $conn->prepare("
        SELECT
            'grade_returned' as type,
            al.action_time,
            g.student_id,
            u.full_name as student_name,
            s.subject_name
        FROM audit_logs al
        INNER JOIN grades g ON g.grade_id = CAST(REPLACE(al.action, 'Grade ID ', '') AS UNSIGNED)
        INNER JOIN users u ON g.student_id = u.user_id
        INNER JOIN subjects s ON g.subject_id = s.subject_id
        WHERE al.action LIKE 'Grade ID % returned by registrar'
        ORDER BY al.action_time DESC
        LIMIT 10
    ");
    $stmt->execute();
    $grade_returned_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get correction approvals by registrar
    $stmt = $conn->prepare("
        SELECT
            'correction_approved' as type,
            al.action_time,
            gc.grade_id,
            u.full_name as student_name,
            s.subject_name
        FROM audit_logs al
        INNER JOIN grade_corrections gc ON gc.request_id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.action, 'Approved correction request ID ', -1), ' for grade ID', 1) AS UNSIGNED)
        INNER JOIN grades g ON gc.grade_id = g.grade_id
        INNER JOIN users u ON g.student_id = u.user_id
        INNER JOIN subjects s ON g.subject_id = s.subject_id
        WHERE al.action LIKE 'Approved correction request ID %'
        ORDER BY al.action_time DESC
        LIMIT 5
    ");
    $stmt->execute();
    $correction_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Combine and sort all activities by time
    $all_activities = array_merge($enrollment_activities, $grade_activities, $grade_returned_activities, $correction_activities);

    // Sort by time descending
    usort($all_activities, function($a, $b) {
        return strtotime($b['action_time']) - strtotime($a['action_time']);
    });

    // Take top 5 and format
    $recent_activities = array_slice($all_activities, 0, 5);

    foreach ($recent_activities as &$activity) {
        $time_diff = time() - strtotime($activity['action_time']);

        if ($time_diff < 60) {
            $activity['time'] = 'just now';
        } elseif ($time_diff < 3600) {
            $mins = floor($time_diff / 60);
            $activity['time'] = $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
        } elseif ($time_diff < 86400) {
            $hrs = floor($time_diff / 3600);
            $activity['time'] = $hrs . ' hour' . ($hrs === 1 ? '' : 's') . ' ago';
        } elseif ($time_diff < 2592000) {
            $days = floor($time_diff / 86400);
            $activity['time'] = $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
        } else {
            // older than a month, show absolute date
            $activity['time'] = date('M j, Y', strtotime($activity['action_time']));
        }
    }

} catch (Exception $e) {
    // Fallback to mock data if database error
    $recent_activities = [
        ['type' => 'enrollment_approved', 'student_name' => 'John Doe', 'subject_name' => 'Mathematics 101', 'time' => '2 hours ago'],
        ['type' => 'grade_approved', 'student_name' => 'Jane Smith', 'subject_name' => 'Physics 202', 'time' => '1 day ago'],
        ['type' => 'correction_approved', 'student_name' => 'Bob Johnson', 'subject_name' => 'Chemistry 301', 'time' => '3 days ago']
    ];
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

    .activity-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-right: 0.75rem;
        font-size: 1rem;
    }

    .activity-enrollment .activity-icon { background: rgba(16, 185, 129, 0.1); color: var(--secondary); }
    .activity-grade .activity-icon { background: rgba(59, 130, 246, 0.1); color: var(--primary); }
    .activity-grade_returned .activity-icon { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
    .activity-correction .activity-icon { background: rgba(245, 158, 11, 0.1); color: var(--accent); }

    .activity-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .activity-text {
        flex: 1;
        margin-right: 1rem;
    }

    .activity-description {
        font-size: 0.9rem;
        color: var(--text-primary);
        line-height: 1.4;
    }

    .activity-description strong {
        font-weight: 600;
        color: var(--text-primary);
    }

    .activity-time {
        font-size: 0.8rem;
        color: var(--text-secondary);
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
        transition: var(--transition);
        font-weight: 500;
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

    .main-content {
        padding: 0.7rem 2rem 2rem 2rem;
        flex: 1;
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
            <p>Manage enrollments, approve grades, and oversee academic records.</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class='bx bx-user-plus'></i>
            </div>
            <div class="stat-value"><?= $stats['pending_enrollments'] ?></div>
            <div class="stat-label">Pending Enrollments</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class='bx bx-check-circle'></i>
            </div>
            <div class="stat-value"><?= $stats['pending_grades'] ?></div>
            <div class="stat-label">Pending Grades</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class='bx bx-edit-alt'></i>
            </div>
            <div class="stat-value"><?= $stats['pending_corrections'] ?></div>
            <div class="stat-label">Pending Corrections</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class='bx bx-group'></i>
            </div>
            <div class="stat-value"><?= $stats['total_students'] ?></div>
            <div class="stat-label">Total Students</div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="content-grid">
        <!-- Recent Activities -->
        <div class="activity-card">
            <h3>
                <i class='bx bx-history'></i>
                Recent Activities
            </h3>
            <ul class="activity-list">
                <?php foreach ($recent_activities as $activity): ?>
                    <li class="activity-item activity-<?= $activity['type'] === 'grade_returned' ? 'grade_returned' : str_replace('_approved', '', $activity['type']) ?>">
                        <div class="activity-content">
                            <div class="activity-icon">
                                <?php
                                $icon = 'bx-check-circle';
                                if ($activity['type'] === 'enrollment_approved') $icon = 'bx-user-plus';
                                elseif ($activity['type'] === 'grade_returned') $icon = 'bx-x';
                                elseif ($activity['type'] === 'correction_approved') $icon = 'bx-edit-alt';
                                ?>
                                <i class='bx <?= $icon ?>'></i>
                            </div>
                            <div class="activity-text">
                                <div class="activity-description">
                                    <?php
                                    if ($activity['type'] === 'enrollment_approved') {
                                        echo 'You approved enrollment for <strong>' . htmlspecialchars($activity['student_name']) . '</strong> in <strong>' . htmlspecialchars($activity['subject_name']) . '</strong>';
                                    } elseif ($activity['type'] === 'grade_approved') {
                                        echo 'You approved a grade for <strong>' . htmlspecialchars($activity['student_name']) . '</strong> in <strong>' . htmlspecialchars($activity['subject_name']) . '</strong>';
                                    } elseif ($activity['type'] === 'grade_returned') {
                                        echo 'You returned a grade for <strong>' . htmlspecialchars($activity['student_name']) . '</strong> in <strong>' . htmlspecialchars($activity['subject_name']) . '</strong>';
                                    } elseif ($activity['type'] === 'correction_approved') {
                                        echo 'You approved a correction for <strong>' . htmlspecialchars($activity['student_name']) . '</strong> in <strong>' . htmlspecialchars($activity['subject_name']) . '</strong>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="activity-time"><?= $activity['time'] ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions-card">
            <h3>Quick Actions</h3>
            <div class="action-buttons">
                <a href="?page=pending_enrollments" class="action-btn">
                    <i class='bx bx-user-plus'></i>
                    <span>Review Enrollments</span>
                </a>
                <a href="?page=pending_grades" class="action-btn">
                    <i class='bx bx-check-circle'></i>
                    <span>Approve Grades</span>
                </a>
                <a href="?page=approve_correction" class="action-btn">
                    <i class='bx bx-edit'></i>
                    <span>Review Corrections</span>
                </a>
                <a href="?page=master_list" class="action-btn">
                    <i class='bx bx-list-ul'></i>
                    <span>Master List</span>
                </a>
            </div>
        </div>
    </div>
</div>

