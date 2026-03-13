<?php
require_once "../includes/auth_check.php";
require_once "../includes/rbac.php";
require_once "../config/db.php";
require_once "../includes/audit.php";
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/notifications.php';

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

requireRole([1]); // Faculty
$faculty_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Corrections</title>
    <style>
        body  { font-family: Arial, sans-serif; background: #f4f6f8; padding: 20px; color: #222; }
        h2    { margin-bottom: 6px; }
        table { border-collapse: collapse; width: 100%; background: #fff; border-radius: 4px; overflow: hidden; }
        th, td{ border: 1px solid #e1e4e8; padding: 8px; text-align: center; vertical-align: middle; }
        th    { background: #34495e; color: #fff; }
        tr:nth-child(even) { background: #f9f9f9; }
        input[type=text], input[type=number] { padding:6px; }
        button { padding:6px 10px; border-radius:4px; cursor:pointer; }
        .logout { background: #c0392b; color: #fff; padding: 8px 12px; text-decoration: none; border-radius: 4px; }
        /* Keep action buttons inline and prevent stacking */
        table td form { display: flex; gap: 0.5rem; align-items: center; justify-content: center; flex-wrap: nowrap; }
        table td form button { white-space: nowrap; }
    </style>
</head>
<body>
<div style="display:flex; justify-content:space-between; align-items:center;">
    <h2>My Approved & Locked Grades</h2>
    <?php $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); ?>
    <a href="<?= htmlspecialchars($base_path) ?>/logout.php">Logout</a>
</div>

<p>Only your approved and locked grades appear here. You may request a correction once per grade.</p>

<table>
<tr>
    <th>Student</th>
    <th>Subject</th>
    <th>Period</th>
    <th>Percentage</th>
    <th>Final Grade</th>
    <th>Correction Status</th>
    <th>Action</th>
</tr>

<?php
$stmt = $conn->prepare(
    "SELECT g.grade_id, u.full_name AS student, s.subject_code, gp.period_name, g.percentage, g.numeric_grade
     FROM grades g
     JOIN enrollments e ON g.enrollment_id = e.enrollment_id
     JOIN users u ON e.student_id = u.user_id
     JOIN subjects s ON e.subject_id = s.subject_id
     JOIN grading_periods gp ON g.period_id = gp.period_id
     WHERE s.faculty_id = ? AND g.status = 'Approved' AND g.is_locked = 1"
);
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()):
    // check existing correction request
    $chk = $conn->prepare("SELECT status FROM grade_corrections WHERE grade_id = ? ORDER BY request_id DESC LIMIT 1");
    $chk->bind_param('i', $row['grade_id']);
    $chk->execute();
    $cres = $chk->get_result();
    $corr_status = ($cres && $crow = $cres->fetch_assoc()) ? $crow['status'] : 'None';
?>
<tr>
    <td><?= htmlspecialchars($row['student'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($row['subject_code'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($row['period_name'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($row['percentage'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($row['numeric_grade'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($corr_status, ENT_QUOTES) ?></td>
    <td>
        <?php if ($corr_status === 'Pending'): ?>
            Pending
        <?php else: ?>
            <form method="post" action="request_correction.php">
                <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">'; ?>
                <input type="hidden" name="grade_id" value="<?= $row['grade_id'] ?>">
                <input type="text" name="reason" placeholder="Brief reason" required>
                <button type="submit">Request Correction</button>
            </form>
        <?php endif; ?>
    </td>
</tr>
<?php endwhile; ?>
</table>

</body>
</html>