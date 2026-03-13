<?php
/* 
 * STUDENT DASHBOARD CONTENT
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';

// Student access control
requireRole([3]);

// Query student profile from users table
$stmt = $conn->prepare("SELECT full_name, program, section, year_level FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

// Convert year_level to label
$year_labels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
$year_label = $year_labels[$student['year_level']] ?? 'Unknown';
?>

<style>
    :root {
        --navy: #0f246c;
        --blue-500: #3B82F6;
        --blue-600: #2563EB;
        --blue-700: #1E40AF;
        --blue-light: #93C5FD;
        --bg: #F0F4FF;
        --surface: #FFFFFF;
        --border: rgba(59, 130, 246, 0.14);
        --text-900: #0F1E4A;
        --text-600: #4B5E8A;
        --text-400: #8EA0C4;
        --shadow-sm: 0 2px 8px rgba(15, 36, 108, 0.08);
        --shadow-md: 0 6px 20px rgba(15, 36, 108, 0.12);
        --r-md: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .page-header {
        margin-bottom: 2rem;
    }

    .page-header h2 {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-900);
        margin-bottom: 0.5rem;
    }

    .page-header p {
        color: var(--text-600);
        font-size: 0.9rem;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-md);
        padding: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--blue-600), var(--blue-500));
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
        border-color: var(--blue-600);
    }

    .stat-icon {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
        background: linear-gradient(135deg, #EEF2FF, #F0F4FF);
        color: var(--blue-600);
    }

    .stat-info .label {
        font-size: 0.78rem;
        color: var(--text-400);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-info .value {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-900);
        line-height: 1.2;
        margin-top: 0.25rem;
    }

    .info-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-md);
        box-shadow: var(--shadow-sm);
        padding: 1.75rem;
        transition: var(--transition);
    }

    .info-card:hover {
        box-shadow: var(--shadow-md);
        border-color: var(--blue-600);
    }

    .info-card .card-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid var(--border);
    }

    .info-card .card-header i {
        font-size: 1.5rem;
        color: var(--blue-600);
    }

    .info-card .card-header h3 {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-900);
        margin: 0;
    }

    .detail-row {
        display: flex;
        justify-content: flex-start;
        align-items: center;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border);
        font-size: 0.95rem;
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        font-weight: 600;
        color: var(--text-600);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-right: 1rem;
    }

    .detail-value {
        font-weight: 700;
        color: var(--text-900);
        background: linear-gradient(135deg, #EEF2FF, #F0F4FF);
        padding: 0.5rem 1rem;
        border-radius: 8px;
        font-size: 0.95rem;
    }

    .two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    @media (max-width: 1024px) {
        .two-col {
            grid-template-columns: 1fr;
        }
    }

    .badge-status {
        display: inline-block;
        padding: 0.4rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: linear-gradient(135deg, #EEF2FF, #F0F4FF);
        color: var(--blue-600);
        border: 1px solid var(--border);
    }
</style>

<!-- Page Header -->
<div class="page-header">
    <h2>Dashboard</h2>
    <p>Here's your academic profile and enrollment information</p>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class='bx bx-book-open'></i>
        </div>
        <div class="stat-info">
            <div class="label">Academic Status</div>
            <div class="value">Active</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class='bx bx-layer'></i>
        </div>
        <div class="stat-info">
            <div class="label">Year Level</div>
            <div class="value"><?= htmlspecialchars($year_label, ENT_QUOTES) ?></div>
        </div>
    </div>

</div>

<!-- Two Column Layout -->
<div class="two-col">
    <!-- Student Information Card -->
    <div class="info-card">
        <div class="card-header">
            <i class='bx bx-user-circle'></i>
            <h3>Student Information</h3>
        </div>

        <div class="detail-row">
            <span class="detail-label">
                <i class='bx bx-user'></i>
                Full Name
            </span>
            <span class="detail-value"><?= htmlspecialchars($student['full_name'], ENT_QUOTES) ?></span>
        </div>

        <div class="detail-row">
            <span class="detail-label">
                <i class='bx bx-book'></i>
                Program
            </span>
            <span class="detail-value"><?= htmlspecialchars($student['program'], ENT_QUOTES) ?></span>
        </div>

        <div class="detail-row">
            <span class="detail-label">
                <i class='bx bx-map'></i>
                Section
            </span>
            <span class="detail-value"><?= htmlspecialchars($student['section'], ENT_QUOTES) ?></span>
        </div>

        <div class="detail-row">
            <span class="detail-label">
                <i class='bx bx-layer'></i>
                Year Level
            </span>
            <span class="detail-value"><?= htmlspecialchars($year_label, ENT_QUOTES) ?></span>
        </div>
    </div>

    <!-- Quick Actions Card -->
    <div class="info-card">
        <div class="card-header">
            <i class='bx bx-lightning-bolt'></i>
            <h3>Quick Actions</h3>
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <a href="?page=view_grades" style="padding: 0.875rem; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; color: var(--text-900); font-weight: 600; text-align: center; transition: var(--transition); background: #F0F4FF; cursor: pointer;" onmouseover="this.style.background='#EEF2FF'; this.style.borderColor='var(--blue-600)'" onmouseout="this.style.background='#F0F4FF'; this.style.borderColor='var(--border)'">
                <i class='bx bx-book-open'></i> View Grades
            </a>
            <a href="?page=request_enrollment" style="padding: 0.875rem; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; color: var(--text-900); font-weight: 600; text-align: center; transition: var(--transition); background: #F0F4FF; cursor: pointer;" onmouseover="this.style.background='#EEF2FF'; this.style.borderColor='var(--blue-600)'" onmouseout="this.style.background='#F0F4FF'; this.style.borderColor='var(--border)'">
                <i class='bx bx-user-plus'></i> Request Enrollment
            </a>
            <a href="?page=enrollment_status" style="padding: 0.875rem; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; color: var(--text-900); font-weight: 600; text-align: center; transition: var(--transition); background: #F0F4FF; cursor: pointer;" onmouseover="this.style.background='#EEF2FF'; this.style.borderColor='var(--blue-600)'" onmouseout="this.style.background='#F0F4FF'; this.style.borderColor='var(--border)'">
                <i class='bx bx-check-circle'></i> Enrollment Status
            </a>
        </div>
    </div>
</div>
