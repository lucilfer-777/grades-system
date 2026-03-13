<?php
/* 
 * FACULTY GRADE SHEET
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';

// Faculty access control
requireRole([1]);

$faculty_id = $_SESSION['user_id'];

// Period options
$periods = [
    '1st Year - 1st Semester',
    '1st Year - 2nd Semester',
    '2nd Year - 1st Semester',
    '2nd Year - 2nd Semester',
    '3rd Year - 1st Semester',
    '3rd Year - 2nd Semester'
];

// Get selected period from GET, default to '3rd Year - 2nd Semester'
$selected_period = $_GET['period'] ?? '3rd Year - 2nd Semester';
if (!in_array($selected_period, $periods)) {
    $selected_period = '3rd Year - 2nd Semester';
}

// Query grades for this faculty's subjects filtered by period, grouped by subject
$stmt = $conn->prepare("
    SELECT
        s.subject_id,
        s.subject_code,
        s.subject_name,
        u.full_name AS student_name,
        g.academic_period,
        g.percentage,
        g.numeric_grade,
        g.remarks,
        g.status
    FROM grades g
    JOIN subjects s ON g.subject_id = s.subject_id
    JOIN users u ON g.student_id = u.user_id
    WHERE s.faculty_id = ? AND g.academic_period = ?
    ORDER BY s.subject_code, u.full_name
");
$stmt->bind_param("is", $faculty_id, $selected_period);
$stmt->execute();
$result = $stmt->get_result();
$grades_all = [];
while ($row = $result->fetch_assoc()) {
    $grades_all[] = $row;
}
$stmt->close();

// Group grades by subject
$grades_by_subject = [];
foreach ($grades_all as $grade) {
    $subject_id = $grade['subject_id'];
    if (!isset($grades_by_subject[$subject_id])) {
        $grades_by_subject[$subject_id] = [
            'subject_code' => $grade['subject_code'],
            'subject_name' => $grade['subject_name'],
            'grades' => []
        ];
    }
    $grades_by_subject[$subject_id]['grades'][] = $grade;
}
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

body {
    background: var(--bg);
}

.grade-sheet-container {
    padding: 2rem;
    max-width: 1400px;
    margin: 0 auto;
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

.period-selector {
    margin-bottom: 2rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.period-selector label {
    font-weight: 600;
    color: var(--text-900);
    font-size: 0.95rem;
}

.period-selector form {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.period-selector select {
    padding: 0.65rem 1rem;
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    font-size: 0.9rem;
    color: var(--text-900);
    background: var(--surface);
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: var(--transition);
}

.period-selector select:hover {
    border-color: var(--blue-600);
}

.period-selector select:focus {
    outline: none;
    border-color: var(--blue-600);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.table-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: 2rem;
    overflow: hidden;
    transition: var(--transition);
}

.table-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--blue-600);
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

.grades-table-container {
    padding: 0;
}

.grades-table {
    width: 100%;
    border-collapse: collapse;
}

.grades-table thead {
    background: var(--bg);
    border-bottom: 1px solid var(--border);
}

.grades-table th {
    padding: 1rem 1.25rem;
    text-align: center; /* header names centered */
    font-weight: 700;
    color: var(--text-400);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    background: var(--bg);
    border-bottom: 1px solid var(--border);
}

.grades-table tbody tr {
    border-bottom: 1px solid var(--border);
    transition: var(--transition);
}

.grades-table tbody tr:hover {
    background: #f8f9ff;
}

.grades-table td {
    padding: 1rem 1.25rem;
    vertical-align: middle;
    color: black;
    text-align: center;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-pending {
    background: #fef3c7;
    color: #92400e;
}

.badge-approved {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #22c55e;
}

.badge-submitted {
    background: #ecfdf5;
    color: #065f46;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--text-600);
}

.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

@media (max-width: 768px) {
    .grade-sheet-container {
        padding: 1rem;
    }

    .page-header h2 {
        font-size: 1.4rem;
    }

    .period-selector {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .period-selector select {
        width: 100%;
    }

    table {
        font-size: 0.8rem;
    }

    th, td {
        padding: 0.75rem;
    }
}
</style>

    <div class="page-header">
        <h2>Submitted Grades</h2>
        <p>Review all grades submitted for your subjects by academic period.</p>
    </div>

    <div class="period-selector">
        <form method="GET" action="" id="periodForm">
            <input type="hidden" name="page" value="grade_sheet">
            <label for="period">Academic Period:</label>
            <select id="period" name="period" onchange="document.getElementById('periodForm').submit()">
                <?php foreach ($periods as $period): ?>
                    <option value="<?= htmlspecialchars($period, ENT_QUOTES) ?>" 
                        <?= $period === $selected_period ? 'selected' : '' ?>>
                        <?= htmlspecialchars($period, ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="content-section">
        <?php if (count($grades_by_subject) > 0): ?>
            <?php foreach ($grades_by_subject as $subject): ?>
                <div class="table-card">
                    <div class="subject-title">
                        <div class="title"><?= htmlspecialchars($subject['subject_code'], ENT_QUOTES) ?></div>
                        <div class="subtitle"><?= htmlspecialchars($subject['subject_name'], ENT_QUOTES) ?></div>
                    </div>

                    <div class="table-wrap">
                        <table class="grades-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Percentage</th>
                                        <th>Numeric Grade</th>
                                        <th>Remarks</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subject['grades'] as $grade): ?>
                                        <tr>
                                            <td style="text-align: left;"><?= htmlspecialchars($grade['student_name'], ENT_QUOTES) ?></td>
                                            <td><?= number_format($grade['percentage'], 2) ?>%</td>
                                            <td><?= number_format($grade['numeric_grade'], 2) ?></td>
                                            <td><?= htmlspecialchars($grade['remarks'], ENT_QUOTES) ?></td>
                                            <td>
                                                <?php
                                                $status = strtolower($grade['status']);
                                                $icon = '';
                                                if ($status === 'approved') $icon = '<i class="bx bx-check-circle"></i>';
                                                elseif ($status === 'pending') $icon = '<i class="bx bx-time"></i>';
                                                elseif ($status === 'submitted') $icon = '<i class="bx bx-send"></i>';
                                                ?>
                                                <span class="badge badge-<?= $status ?>">
                                                    <?= $icon ?> <?= htmlspecialchars($grade['status'], ENT_QUOTES) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="table-card">
                <div class="subject-title">
                    <div class="title">Grades</div>
                    <div class="subtitle">Submitted grades for the selected period</div>
                </div>
                <div class="table-wrap">
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Percentage</th>
                                <th>Numeric Grade</th>
                                <th>Remarks</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 2rem 1rem;">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">📊</div>
                                        <p>No grades found for this period.</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
