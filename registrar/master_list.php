<?php
/* 
 * REGISTRAR MASTER GRADE LIST
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';

// Registrar access control
requireRole([4]);

// Query all grades
$stmt = $conn->prepare("
    SELECT
        u.full_name AS student_name,
        u.section,
        s.subject_code,
        s.subject_name,
        g.academic_period,
        g.numeric_grade,
        g.remarks,
        g.status
    FROM grades g
    JOIN subjects s ON g.subject_id = s.subject_id
    JOIN users u ON g.student_id = u.user_id
    ORDER BY u.full_name, g.academic_period
");
$stmt->execute();
$result = $stmt->get_result();
$all_grades = [];
while ($row = $result->fetch_assoc()) {
    $all_grades[] = $row;
}
$stmt->close();
?>

<style>
    .master-list-container {
        padding: 0;
    }
    .master-list-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }
    .master-list-table thead {
        background-color: #f1f5f9;
        border-bottom: 2px solid #cbd5e1;
    }
    .master-list-table th {
        padding: 1rem;
        text-align: center;
        font-weight: 600;
        color: #1e293b;
        border: 1px solid #e2e8f0;
    }
    .master-list-table td {
        padding: 1rem;
        text-align: center;
        border: 1px solid #e2e8f0;
        color: #475569;
    }
    .master-list-table tbody tr:hover {
        background-color: #f8fafc;
    }
    .no-records {
        padding: 2rem;
        text-align: center;
        color: #64748b;
        font-size: 1rem;
    }
</style>

<div class="master-list-container">
    <?php if (count($all_grades) > 0): ?>
        <table class="master-list-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Section</th>
                    <th>Subject Code</th>
                    <th>Subject</th>
                    <th>Period</th>
                    <th>Numeric Grade</th>
                    <th>Remarks</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_grades as $grade): ?>
                    <tr>
                        <td><?= htmlspecialchars($grade['student_name'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($grade['section'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($grade['subject_code'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($grade['subject_name'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($grade['academic_period'], ENT_QUOTES) ?></td>
                        <td><?= number_format($grade['numeric_grade'], 2) ?></td>
                        <td><?= htmlspecialchars($grade['remarks'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($grade['status'], ENT_QUOTES) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-records">
            No grade records found.
        </div>
    <?php endif; ?>
</div>
