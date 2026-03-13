<?php
/* 
 * STUDENT VIEW GRADES
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';

// Student access control
requireRole([3]);

$student_id = $_SESSION['user_id'];

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

// Query grades
$stmt = $conn->prepare("
    SELECT
        s.subject_code,
        s.subject_name,
        u.full_name AS teacher_name,
        g.numeric_grade,
        g.remarks,
        g.status
    FROM grades g
    JOIN subjects s ON g.subject_id = s.subject_id
    JOIN users u ON s.faculty_id = u.user_id
    WHERE g.student_id = ?
    AND g.academic_period = ?
");
$stmt->bind_param("is", $student_id, $selected_period);
$stmt->execute();
$result = $stmt->get_result();
$grades = [];
while ($row = $result->fetch_assoc()) {
    $grades[] = $row;
}
$stmt->close();
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

.content-section {
    margin-bottom: 3rem;
}

.table-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    box-shadow: var(--shadow-sm);
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

table {
    border-collapse: collapse;
    width: 100%;
}

th {
    padding: 1rem 1.25rem;
    text-align: center; /* header names centered now */
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-400);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    background: var(--bg);
    border-bottom: 1px solid var(--border);
}

td {
    padding: 1rem 1.25rem;
    font-size: 0.875rem;
    color: black; /* values should be black */
    text-align: center; /* center the cell contents */
    border-bottom: 1px solid var(--border);
}

tbody tr:last-child td {
    border-bottom: none;
}

tbody tr:hover {
    background: #f8f9ff;
}

.badge-status {
    display: inline-block;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-approved {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #22c55e;
}

.badge-passed {
    background: #ecfdf5;
    color: #065f46;
}

.badge-failed {
    background: #fef2f2;
    color: #991b1b;
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
</style>

<div>
    <div class="page-header">
        <h2>View Grades</h2>
        <p>Review your grades across all academic periods.</p>
    </div>

    <div class="period-selector">
        <form method="GET">
            <input type="hidden" name="page" value="view_grades">
            <label for="period">Academic Period:</label>
            <select id="period" name="period" onchange="this.form.submit()">
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
        <div class="table-card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Instructor</th>
                            <th>Grade</th>
                            <th>Remarks</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($grades) > 0): ?>
                            <?php foreach ($grades as $grade): ?>
                                <tr>
                                    <td><?= htmlspecialchars($grade['subject_code'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($grade['subject_name'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($grade['teacher_name'], ENT_QUOTES) ?></td>
                                    <td>
                                        <?php if ($grade['status'] === 'Approved'): ?>
                                            <?= number_format($grade['numeric_grade'], 2) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($grade['status'] === 'Approved'): ?>
                                            <?php 
                                                $remarks = (strpos($grade['remarks'], 'Failed') !== false || $grade['numeric_grade'] == 5.00) 
                                                    ? 'Failed' 
                                                    : 'Passed';
                                                echo htmlspecialchars($remarks, ENT_QUOTES);
                                            ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($grade['status'] === 'Approved'): ?>
                                            <span class="badge-status badge-approved">Approved</span>
                                        <?php else: ?>
                                            <span class="badge-status" style="background: #fef3c7; color: #92400e;">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 2rem 1rem;">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">📊</div>
                                        <p>No grades found for this period.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
