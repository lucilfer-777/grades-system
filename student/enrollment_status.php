<?php
/* 
 * STUDENT ENROLLMENT STATUS
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

// Query enrollments
$stmt = $conn->prepare("
    SELECT
        u.section,
        s.subject_code,
        s.subject_name,
        uf.full_name AS teacher_name,
        e.status
    FROM enrollments e
    JOIN subjects s ON e.subject_id = s.subject_id
    JOIN users u ON e.student_id = u.user_id
    JOIN users uf ON s.faculty_id = uf.user_id
    WHERE e.student_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$enrollments = [];
while ($row = $result->fetch_assoc()) {
    $enrollments[] = $row;
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

.badge-active {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #22c55e;
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
        <h2>Enrollment Status</h2>
        <p>View your current course enrollments and their approval status.</p>
    </div>

    <div class="content-section">
        <div class="table-card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Instructor</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($enrollments) > 0): ?>
                            <?php foreach ($enrollments as $enrollment): ?>
                                <tr>
                                    <td><?= htmlspecialchars($enrollment['section'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($enrollment['subject_code'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($enrollment['subject_name'], ENT_QUOTES) ?></td>
                                    <td><?= htmlspecialchars($enrollment['teacher_name'], ENT_QUOTES) ?></td>
                                    <td>
                                        <?php if ($enrollment['status'] === 'Active'): ?>
                                            <span class="badge-status badge-active">Active</span>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars($enrollment['status'], ENT_QUOTES) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 2rem 1rem;">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">📋</div>
                                        <p>No enrollment records found.</p>
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
