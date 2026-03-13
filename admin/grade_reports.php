<?php
/*
 * ADMIN GRADE REPORTS & SUMMARY
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';

// Admin access control
requireRole([4]);

// Get filter parameters
$selected_period = isset($_GET['period']) ? $_GET['period'] : '';
$selected_subject = isset($_GET['subject']) ? (int)$_GET['subject'] : 0;

// Period options
$periods = [
    '1st Year - 1st Semester',
    '1st Year - 2nd Semester',
    '2nd Year - 1st Semester',
    '2nd Year - 2nd Semester',
    '3rd Year - 1st Semester',
    '3rd Year - 2nd Semester'
];

// Get all subjects for filter dropdown
$subjects_stmt = $conn->prepare("SELECT subject_id, subject_code, subject_name FROM subjects ORDER BY subject_code");
$subjects_stmt->execute();
$subjects_result = $subjects_stmt->get_result();
$subjects = [];
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[] = $row;
}
$subjects_stmt->close();

// Build query for grade reports
$query = "
    SELECT
        u.user_id,
        u.full_name AS student_name,
        u.section,
        s.subject_id,
        s.subject_code,
        s.subject_name,
        g.academic_period,
        g.percentage,
        g.numeric_grade,
        g.remarks,
        g.status
    FROM grades g
    JOIN subjects s ON g.subject_id = s.subject_id
    JOIN users u ON g.student_id = u.user_id
    WHERE g.status = 'Approved'
";

$params = [];
$types = '';

if (!empty($selected_period)) {
    $query .= " AND g.academic_period = ?";
    $params[] = $selected_period;
    $types .= 's';
}

if ($selected_subject > 0) {
    $query .= " AND g.subject_id = ?";
    $params[] = $selected_subject;
    $types .= 'i';
}

$query .= " ORDER BY u.full_name, s.subject_code, g.academic_period";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$grade_reports = [];
while ($row = $result->fetch_assoc()) {
    $grade_reports[] = $row;
}
$stmt->close();

// Calculate summary statistics
$subject_summary = [];
$student_gpas = [];

foreach ($grade_reports as $grade) {
    $subject_key = $grade['subject_id'];
    $student_key = $grade['user_id'];

    // Subject pass/fail counts
    if (!isset($subject_summary[$subject_key])) {
        $subject_summary[$subject_key] = [
            'subject_code' => $grade['subject_code'],
            'subject_name' => $grade['subject_name'],
            'total' => 0,
            'passed' => 0,
            'failed' => 0
        ];
    }

    $subject_summary[$subject_key]['total']++;
    if ($grade['numeric_grade'] <= 3.00) {
        $subject_summary[$subject_key]['passed']++;
    } else {
        $subject_summary[$subject_key]['failed']++;
    }

    // Student GPA calculation
    if (!isset($student_gpas[$student_key])) {
        $student_gpas[$student_key] = [
            'student_name' => $grade['student_name'],
            'section' => $grade['section'],
            'grades' => [],
            'total_subjects' => 0
        ];
    }

    $student_gpas[$student_key]['grades'][] = $grade['numeric_grade'];
    $student_gpas[$student_key]['total_subjects']++;
}

// Calculate average GPA for each student
foreach ($student_gpas as $student_id => &$student_data) {
    if (!empty($student_data['grades'])) {
        $student_data['gpa'] = array_sum($student_data['grades']) / count($student_data['grades']);
    } else {
        $student_data['gpa'] = 0;
    }
}
unset($student_data);
?>

<style>
    :root {
        --primary: #3B82F6;
        --primary-dark: #1E40AF;
        --secondary: #10B981;
        --accent: #F59E0B;
        --danger: #EF4444;
        --success: #22C55E;
        --surface: #FFFFFF;
        --background: #F8FAFC;
        --text-primary: #1E293B;
        --text-secondary: #64748B;
        --text-900: #0F172A;
        --text-600: #475569;
        --border: #E2E8F0;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        --radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .grade-reports-container {
        padding: 0;
    }

    .page-header {
        margin-bottom: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .page-header h2 {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .page-header p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        margin: 0.5rem 0 0 0;
    }

    .filters-section {
        background: var(--surface);
        padding: 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
        border: 1px solid var(--border);
    }

    .filters-form {
        display: flex;
        gap: 1rem;
        align-items: end;
        flex-wrap: wrap;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        min-width: 200px;
        flex: 1;
    }

    .filter-group label {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .filter-group select {
        padding: 0.75rem;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: white;
        font-size: 0.9rem;
        transition: var(--transition);
        width: 100%;
    }

    .filter-group select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .filter-btn {
        padding: 0.75rem 1.5rem;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: var(--radius);
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .filter-btn:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
    }

    .summary-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .summary-card {
        background: var(--surface);
        padding: 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
    }

    .summary-card h3 {
        color: var(--text-primary);
        margin-bottom: 1rem;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
    }

    .summary-stats {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }

    .stat-item {
        text-align: center;
        padding: 1rem;
        background: var(--background);
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary);
        display: block;
        margin-bottom: 0.25rem;
    }

    .stat-label {
        font-size: 0.9rem;
        color: var(--text-secondary);
        font-weight: 500;
    }

    .table-container {
        background: var(--surface);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        overflow: hidden;
        margin-bottom: 1rem;
    }

    .reports-table {
        width: 100%;
        border-collapse: collapse;
        background: transparent;
    }

    .reports-table thead {
        background: var(--bg);
        border-bottom: 1px solid var(--border);
        color: var(--text-400);
    }

    .reports-table th,
    .reports-table td {
        padding: 1rem 1.25rem;
        text-align: center;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }

    .reports-table th {
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        white-space: nowrap;
        color: var(--text-400);
    }

    .reports-table tbody tr:hover {
        background: #f8f9ff;
    }

    .reports-table tbody tr:last-child td {
        border-bottom: none;
    }

    .grade-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-align: center;
        display: inline-block;
        min-width: 60px;
    }

    .grade-passed {
        background: rgba(34, 197, 94, 0.1);
        color: var(--success);
        border: 1px solid rgba(34, 197, 94, 0.2);
    }

    .grade-failed {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
        border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .badge-status {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }

    .badge-approved {
        background: rgba(34, 197, 94, 0.1);
        color: var(--success);
        border: 1px solid rgba(34, 197, 94, 0.2);
    }

    .gpa-display {
        font-weight: 700;
        color: var(--primary);
        font-size: 1.1rem;
    }

    .section-header {
        margin: 2rem 0 1rem 0;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid var(--border);
    }

    .section-header h3 {
        color: var(--text-primary);
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .no-data {
        text-align: center;
        padding: 3rem;
        color: var(--text-secondary);
        background: var(--surface);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
    }

    .no-data i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .no-data h3 {
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    /* Responsive Design */
    @media (max-width: 1024px) {
        .summary-section {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .filters-form {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-group {
            min-width: auto;
            width: 100%;
        }

        .filter-btn {
            width: 100%;
            justify-content: center;
        }

        .summary-section {
            grid-template-columns: 1fr;
        }

        .reports-table {
            font-size: 0.85rem;
        }

        .reports-table th,
        .reports-table td {
            padding: 0.75rem 0.5rem;
        }

        .reports-table th {
            font-size: 0.8rem;
        }
    }

    @media (max-width: 480px) {
        .reports-table th,
        .reports-table td {
            padding: 0.5rem 0.25rem;
        }

        .reports-table th {
            font-size: 0.75rem;
        }

        .reports-table td {
            font-size: 0.8rem;
        }

        .grade-badge,
        .badge-status {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            min-width: 50px;
        }

        .stat-number {
            font-size: 1.5rem;
        }

        .stat-label {
            font-size: 0.8rem;
        }
    }

    /* Table horizontal scroll for very small screens */
    @media (max-width: 640px) {
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .reports-table {
            min-width: 600px;
        }
    }

    /* Shared card/table styles used in faculty pages */
    .content-section {
        margin-bottom: 2rem;
    }

    .table-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
        overflow: hidden;
        transition: var(--transition);
    }

    .table-card:hover {
        box-shadow: var(--shadow-lg);
        border-color: var(--primary);
    }

    .table-wrap {
        overflow-x: auto;
    }

    .subject-title {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        flex-direction: column;
    }

    .title {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .subtitle {
        font-size: 0.9rem;
        color: var(--text-secondary);
        margin-top: 0.25rem;
    }
</style>

<div class="grade-reports-container">
    <div class="page-header">
        <div>
            <h2>Grade Reports & Summary</h2>
            <p>Comprehensive overview of all approved grades with filtering and statistical analysis</p>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="filters-section">
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <label for="period">Academic Period</label>
                <select name="period" id="period">
                    <option value="">All Periods</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= htmlspecialchars($period, ENT_QUOTES) ?>"
                                <?= $selected_period === $period ? 'selected' : '' ?>>
                            <?= htmlspecialchars($period, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="subject">Subject</label>
                <select name="subject" id="subject">
                    <option value="0">All Subjects</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= $subject['subject_id'] ?>"
                                <?= $selected_subject == $subject['subject_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name'], ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="filter-btn">
                Apply Filters
            </button>
        </form>
    </div>

    <!-- Summary Statistics -->
    <div class="summary-section">
        <div class="summary-card">
            <h3><i class='bx bx-book'></i> Subject Performance Summary</h3>
            <div class="summary-stats">
                <?php
                $total_subjects = count($subject_summary);
                $total_passed = array_sum(array_column($subject_summary, 'passed'));
                $total_failed = array_sum(array_column($subject_summary, 'failed'));
                $pass_rate = $total_subjects > 0 ? round(($total_passed / ($total_passed + $total_failed)) * 100, 1) : 0;
                ?>
                <div class="stat-item">
                    <span class="stat-number"><?= $total_subjects ?></span>
                    <span class="stat-label">Total Subjects</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?= $pass_rate ?>%</span>
                    <span class="stat-label">Pass Rate</span>
                </div>
            </div>
        </div>

        <div class="summary-card">
            <h3><i class='bx bx-user'></i> Student Performance Summary</h3>
            <div class="summary-stats">
                <?php
                $total_students = count($student_gpas);
                $avg_gpa = $total_students > 0 ? array_sum(array_column($student_gpas, 'gpa')) / $total_students : 0;
                ?>
                <div class="stat-item">
                    <span class="stat-number"><?= $total_students ?></span>
                    <span class="stat-label">Total Students</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?= number_format($avg_gpa, 2) ?></span>
                    <span class="stat-label">Average GPA</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Grade Reports Table -->
    <?php if (!empty($grade_reports)): ?>
        <div class="content-section">
            <div class="table-card">
                <div class="subject-title">
                    <div class="title">Grade Reports</div>
                </div>
                <div class="table-wrap">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Section</th>
                                <th>Subject</th>
                                <th>Academic Period</th>
                                <th>Percentage</th>
                                <th>Grade</th>
                                <th>Remarks</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grade_reports as $grade): ?>
                                <tr>
                                    <td><?= htmlspecialchars($grade['student_name'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($grade['section'], ENT_QUOTES) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($grade['subject_code'], ENT_QUOTES) ?></strong><br>
                                        <small style="color: var(--text-secondary);"><?= htmlspecialchars($grade['subject_name'], ENT_QUOTES) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($grade['academic_period'], ENT_QUOTES) ?></td>
                                    <td><strong><?= number_format($grade['percentage'], 2) ?>%</strong></td>
                                    <td>
                                        <span class="grade-badge <?= $grade['numeric_grade'] <= 3.00 ? 'grade-passed' : 'grade-failed' ?>">
                                            <?= number_format($grade['numeric_grade'], 2) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($grade['remarks'], ENT_QUOTES) ?></td>
                                    <td>
                                        <span class="badge-status badge-approved">
                                            <i class='bx bx-check'></i> <?= htmlspecialchars($grade['status'], ENT_QUOTES) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="no-data">
            <h3>No Grade Reports Found</h3>
            <p>No approved grades match the selected filters.</p>
        </div>
    <?php endif; ?>

    <!-- Subject-wise Summary Table -->
    <?php if (!empty($subject_summary)): ?>
        <div class="content-section">
            <div class="table-card">
                <div class="subject-title">
                    <div class="title">Pass/Fail Summary</div>
                </div>
                <div class="table-wrap">
                    <table class="reports-table">
                <thead>
                    <tr>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Total Students</th>
                        <th>Passed</th>
                        <th>Failed</th>
                        <th>Pass Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subject_summary as $summary): ?>
                        <?php
                        $pass_rate = $summary['total'] > 0 ? round(($summary['passed'] / $summary['total']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($summary['subject_code'], ENT_QUOTES) ?></strong></td>
                            <td><?= htmlspecialchars($summary['subject_name'], ENT_QUOTES) ?></td>
                            <td><strong><?= $summary['total'] ?></strong></td>
                            <td style="color: var(--success);"><strong><?= $summary['passed'] ?></strong></td>
                            <td style="color: var(--danger);"><strong><?= $summary['failed'] ?></strong></td>
                            <td>
                                <span style="color: <?= $pass_rate >= 75 ? 'var(--success)' : 'var(--danger)' ?>; font-weight: 700;">
                                    <?= $pass_rate ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Student GPA Summary -->
    <?php if (!empty($student_gpas)): ?>
        <div class="content-section">
            <div class="table-card">
                <div class="subject-title">
                    <div class="title">Student GPA Summary</div>
                </div>
                <div class="table-wrap">
                    <table class="reports-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Section</th>
                        <th>Subjects Taken</th>
                        <th>Average GPA</th>
                        <th>Performance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Sort students by GPA descending
                    usort($student_gpas, function($a, $b) {
                        return $b['gpa'] <=> $a['gpa'];
                    });
                    ?>
                    <?php foreach ($student_gpas as $student): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($student['student_name'], ENT_QUOTES) ?></strong></td>
                            <td><?= htmlspecialchars($student['section'], ENT_QUOTES) ?></td>
                            <td><strong><?= $student['total_subjects'] ?></strong></td>
                            <td><span class="gpa-display"><?= number_format($student['gpa'], 2) ?></span></td>
                            <td>
                                <?php if ($student['gpa'] <= 1.75): ?>
                                    <span class="grade-badge grade-passed">Excellent</span>
                                <?php elseif ($student['gpa'] <= 2.25): ?>
                                    <span class="grade-badge grade-passed">Very Good</span>
                                <?php elseif ($student['gpa'] <= 2.75): ?>
                                    <span class="grade-badge grade-passed">Good</span>
                                <?php elseif ($student['gpa'] <= 3.00): ?>
                                    <span class="grade-badge grade-passed">Passed</span>
                                <?php else: ?>
                                    <span class="grade-badge grade-failed">Failed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>