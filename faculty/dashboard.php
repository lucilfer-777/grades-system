<?php
/*
 * FACULTY DASHBOARD CONTENT
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';

// Faculty access control
requireRole([1]);

$faculty_id = $_SESSION["user_id"];
$faculty_name = $_SESSION["user_name"] ?? "Faculty";

// Get real statistics from database
try {
    // Subjects teaching
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM subjects WHERE faculty_id = ?");
    $stmt->bind_param("i", $faculty_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $subjects_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // Total students (distinct students enrolled in faculty's subjects)
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.student_id) as count 
        FROM enrollments e 
        INNER JOIN subjects s ON e.subject_id = s.subject_id 
        WHERE s.faculty_id = ? AND e.status = 'Active'
    ");
    $stmt->bind_param("i", $faculty_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $students_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // Pending grades (grades not yet approved for faculty's subjects)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM grades g 
        INNER JOIN subjects s ON g.subject_id = s.subject_id 
        WHERE s.faculty_id = ? AND g.status = 'Pending'
    ");
    $stmt->bind_param("i", $faculty_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pending_grades_count = $result->fetch_assoc()['count'];
    $stmt->close();

    // Correction requests (pending corrections requested by faculty)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM grade_corrections 
        WHERE faculty_id = ? AND status = 'Pending'
    ");
    $stmt->bind_param("i", $faculty_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $corrections_count = $result->fetch_assoc()['count'];
    $stmt->close();

    $stats = [
        'subjects' => $subjects_count,
        'students' => $students_count,
        'pending_grades' => $pending_grades_count,
        'corrections' => $corrections_count
    ];

} catch (Exception $e) {
    // Fallback to mock data if database error
    $stats = [
        'subjects' => 5,
        'students' => 120,
        'pending_grades' => 3,
        'corrections' => 2
    ];
    error_log("Database error in faculty dashboard: " . $e->getMessage());
}

$recent_activities = [];

// Get real recent activities from database for this faculty
try {
    // Get faculty's own grade encoding activities
    $stmt = $conn->prepare("
        SELECT
            'grade_encoded' as type,
            al.action_time,
            s.subject_name,
            g.student_id,
            u.full_name as student_name
        FROM audit_logs al
        INNER JOIN grades g ON g.student_id = al.user_id
        INNER JOIN subjects s ON g.subject_id = s.subject_id
        INNER JOIN users u ON g.student_id = u.user_id
        WHERE al.user_id = ?
        AND al.action = 'Encoded grade'
        AND s.faculty_id = ?
        ORDER BY al.action_time DESC
        LIMIT 10
    ");
    $stmt->bind_param("ii", $faculty_id, $faculty_id);
    $stmt->execute();
    $encoded_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get grade approvals for this faculty's subjects
    $stmt = $conn->prepare("
        SELECT
            'grade_approved' as type,
            al.action_time,
            s.subject_name,
            g.student_id,
            u.full_name as student_name
        FROM audit_logs al
        INNER JOIN grades g ON g.grade_id = CAST(REPLACE(al.action, 'Approved grade ID ', '') AS UNSIGNED)
        INNER JOIN subjects s ON g.subject_id = s.subject_id
        INNER JOIN users u ON g.student_id = u.user_id
        WHERE al.action LIKE 'Approved grade ID %'
        AND s.faculty_id = ?
        ORDER BY al.action_time DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $faculty_id);
    $stmt->execute();
    $approved_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get correction requests made by this faculty
    $stmt = $conn->prepare("
        SELECT
            'correction_requested' as type,
            gc.request_date as action_time,
            s.subject_name,
            g.student_id,
            u.full_name as student_name
        FROM grade_corrections gc
        INNER JOIN grades g ON gc.grade_id = g.grade_id
        INNER JOIN subjects s ON g.subject_id = s.subject_id
        INNER JOIN users u ON g.student_id = u.user_id
        WHERE gc.faculty_id = ?
        ORDER BY gc.request_date DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $faculty_id);
    $stmt->execute();
    $correction_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Combine and sort all activities by time
    $all_activities = array_merge($encoded_activities, $approved_activities, $correction_activities);

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
        ['type' => 'grade_submitted', 'subject' => 'Mathematics 101', 'time' => '2 hours ago'],
        ['type' => 'correction_requested', 'subject' => 'Physics 202', 'time' => '1 day ago'],
        ['type' => 'grade_approved', 'subject' => 'Chemistry 301', 'time' => '3 days ago']
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

    .activity-grade .activity-icon { background: rgba(16, 185, 129, 0.1); color: var(--secondary); }
    .activity-correction .activity-icon { background: rgba(245, 158, 11, 0.1); color: var(--accent); }
    .activity-approved .activity-icon { background: rgba(59, 130, 246, 0.1); color: var(--primary); }

    .activity-content {
        display: flex;
        align-items: center;
    }

    .activity-text {
        flex: 1;
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

    @media (max-width: 768px) {
        .faculty-dashboard {
            padding: 1rem;
        }

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

<div class="faculty-dashboard">
    <!-- Header -->
    <div class="dashboard-header">
            <h2>Dashboard</h2>
            <p>Manage your grades, track submissions, and stay updated with your teaching activities.</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class='bx bx-book'></i>
            </div>
            <div class="stat-value"><?= $stats['subjects'] ?></div>
            <div class="stat-label">Subjects Teaching</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class='bx bx-group'></i>
            </div>
            <div class="stat-value"><?= $stats['students'] ?></div>
            <div class="stat-label">Total Students</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class='bx bx-time-five'></i>
            </div>
            <div class="stat-value"><?= $stats['pending_grades'] ?></div>
            <div class="stat-label">Pending Grades</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class='bx bx-edit-alt'></i>
            </div>
            <div class="stat-value"><?= $stats['corrections'] ?></div>
            <div class="stat-label">Correction Requests</div>
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
                    <li class="activity-item activity-<?= $activity['type'] ?>">
                        <div class="activity-content">
                            <div class="activity-icon">
                                <?php
                                $icon = 'bx-check-circle';
                                if ($activity['type'] === 'correction_requested') $icon = 'bx-edit-alt';
                                elseif ($activity['type'] === 'grade_approved') $icon = 'bx-check-double';
                                ?>
                                <i class='bx <?= $icon ?>'></i>
                            </div>
                            <div class="activity-text">
                                <div class="activity-description">
                                    <?php
                                    if ($activity['type'] === 'grade_encoded') {
                                        echo 'You encoded a grade for <strong>' . htmlspecialchars($activity['student_name']) . '</strong> in <strong>' . htmlspecialchars($activity['subject_name']) . '</strong>';
                                    } elseif ($activity['type'] === 'correction_requested') {
                                        echo 'You requested a correction for <strong>' . htmlspecialchars($activity['student_name']) . '</strong> in <strong>' . htmlspecialchars($activity['subject_name']) . '</strong>';
                                    } elseif ($activity['type'] === 'grade_approved') {
                                        echo 'A grade was approved for <strong>' . htmlspecialchars($activity['student_name']) . '</strong> in <strong>' . htmlspecialchars($activity['subject_name']) . '</strong>';
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
                <a href="?page=submit_grades" class="action-btn">
                    <i class='bx bx-plus-circle'></i>
                    <span>Submit Grades</span>
                </a>
                <a href="?page=view_grades" class="action-btn">
                    <i class='bx bx-show'></i>
                    <span>View Submissions</span>
                </a>
                <a href="?page=request_correction" class="action-btn">
                    <i class='bx bx-edit'></i>
                    <span>Request Correction</span>
                </a>
            </div>
        </div>
    </div>
</div>
