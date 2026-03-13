<?php
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

requireRole([2]); // Registrar only

$student_id = intval($_GET['student_id'] ?? 0);
$student = null;
$grades = [];
if ($student_id > 0) {
    // Verify student exists
    $u = $conn->prepare("SELECT user_id, full_name FROM users WHERE user_id = ?");
    $u->bind_param('i', $student_id);
    $u->execute();
    $ur = $u->get_result();
    if ($ur && $ur->num_rows > 0) {
        $student = $ur->fetch_assoc();

        // Approved enrollments + approved grades
        $g = $conn->prepare(
            "SELECT s.subject_code, gp.period_name, g.percentage, g.numeric_grade, g.remarks
             FROM grades g
             JOIN enrollments e ON g.enrollment_id = e.enrollment_id
             JOIN subjects s ON e.subject_id = s.subject_id
             JOIN grading_periods gp ON g.period_id = gp.period_id
             WHERE e.student_id = ? AND g.status = 'Approved'
             ORDER BY s.subject_code, gp.period_id"
        );
        $g->bind_param('i', $student_id);
        $g->execute();
        $gr = $g->get_result();
        while ($row = $gr->fetch_assoc()) $grades[] = $row;

        logAction($conn, $_SESSION['user_id'], "Viewed Registrar Student Record for $student_id");
    } else {
        $student = null;
    }
}

?>

<style>
    .registrar-student-record { 
        padding: 2rem; 
    }
    .page-header { margin-bottom: 2rem; }
    .page-header h1 { color: #0f246c; font-size: 1.5rem; font-weight: 600; margin-bottom: 0.25rem; }
    .page-header p { color: #64748B; font-size: 0.9375rem; }
    .content-section { margin-bottom: 3rem; }
    .content-title {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: #0f246c;
        border-bottom: 2px solid #3B82F6;
        padding-bottom: 0.5rem;
    }
    .search-form { margin-bottom: 1.5rem; }
    .search-form input { padding: 6px; border: 1px solid #ddd; border-radius: 4px; margin: 0 0.5rem 0.5rem 0; }
    .search-form button { padding: 6px 12px; background: #3B82F6; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .search-form button:hover { background: #1E40AF; }
    .search-form a { display: inline-block; padding: 6px 12px; background: #10b981; color: white; text-decoration: none; border-radius: 4px; margin-left: 0.5rem; }
    .search-form a:hover { background: #059669; }
    .table-wrap { overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; background: #fff; border: 1px solid #e1e4e8; border-radius: 4px; overflow: hidden; }
    th, td { border: 1px solid #e1e4e8; padding: 12px; text-align: left; vertical-align: middle; }
    th { background: #f6f8fa; font-weight: 600; color: #0f246c; }
    tr:nth-child(even) { background: #f9f9f9; }
    tr:hover { background: #f0f7ff; }
    .student-name { font-size: 1.1rem; font-weight: 600; color: #0f246c; margin-bottom: 1rem; }
    .no-record { color: #64748B; padding: 1rem; }
</style>

<div class="registrar-student-record">
    <div class="page-header">
        <h1>Student Academic Record</h1>
        <p>View approved grades and enrollment history.</p>
    </div>

    <div class="content-section">
        <div class="content-title" aria-hidden="true"></div>
        
        <form method="get" class="search-form">
            <label>Student ID: <input name="student_id" type="number" value="<?= htmlspecialchars($student_id) ?>"></label>
            <button type="submit">Search</button>
            <?php if ($student): ?>
                <a href="../export_pdf.php?type=registrar_student&student_id=<?= htmlspecialchars($student['user_id'], ENT_QUOTES) ?>">Export PDF</a>
            <?php endif; ?>
        </form>

        <?php if ($student): ?>
            <div class="student-name">
                <?= htmlspecialchars($student['full_name'], ENT_QUOTES) ?> (ID: <?= htmlspecialchars($student['user_id'], ENT_QUOTES) ?>)
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Period</th>
                            <th>Percentage</th>
                            <th>Numeric Grade</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grades as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['subject_code'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($r['period_name'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($r['percentage'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($r['numeric_grade'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($r['remarks'], ENT_QUOTES) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($student_id > 0): ?>
            <div class="no-record">
                <p>No student found with that ID.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
