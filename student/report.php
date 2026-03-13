<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

requireRole([3]); // Student only

$student_id = $_SESSION["user_id"];

// Log view
logAction($conn, $student_id, "Viewed Student Report Card");

$query = "
SELECT 
    s.subject_code,
    gp.period_name,
    g.percentage,
    g.numeric_grade,
    g.remarks
FROM grades g
JOIN enrollments e ON g.enrollment_id = e.enrollment_id
JOIN subjects s ON e.subject_id = s.subject_id
JOIN grading_periods gp ON g.period_id = gp.period_id
WHERE e.student_id = ?
  AND g.status = 'Approved'
ORDER BY s.subject_code, gp.period_id
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Grades &amp; Assessment Management Subsystem</title>
    <style>
        body { font-family: Arial; background:#f4f6f8; padding:20px; }
        table { border-collapse: collapse; width: 100%; background:#fff; }
        th, td { border:1px solid #ccc; padding:10px; text-align:center; }
        th { background:#2c3e50; color:#fff; }
    </style>
 </head>
<body>
<div style="display:flex; justify-content:space-between; align-items:center;">
    <h2>Official Report Card</h2>
    <div>
        <?php $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); ?>
        <a href="<?= htmlspecialchars($base_path) ?>/logout.php" style="background:#c0392b; color:#fff; padding:8px 12px; text-decoration:none; border-radius:4px;">Logout</a>
        <a href="<?= htmlspecialchars($base_path) ?>/export_pdf.php?type=student" style="background:#2980b9; color:#fff; padding:8px 12px; text-decoration:none; border-radius:4px; margin-left:8px;">Export PDF</a>
    </div>
</div>

<table>
<tr>
    <th>Subject</th>
    <th>Period</th>
    <th>Percentage</th>
    <th>Numeric Grade</th>
    <th>Remarks</th>
</tr>

<?php while ($row = $result->fetch_assoc()): ?>
<tr>
    <td><?= htmlspecialchars($row['subject_code'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($row['period_name'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($row['percentage'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($row['numeric_grade'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars($row['remarks'], ENT_QUOTES) ?></td>
</tr>
<?php endwhile; ?>
</table>

</body>
</html>
