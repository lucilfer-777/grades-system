<?php
require "includes/auth_check.php";
require "includes/rbac.php";
require "config/db.php";
require "includes/audit.php";
require_once __DIR__ . '/includes/simple_pdf.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Supported types: student (student exports own), registrar_student (registrar exports any student's record)
$type = $_GET['type'] ?? '';

if ($type === 'student') {
	requireRole([3]);
	$student_id = $_SESSION['user_id'];

	// Fetch approved enrollments + approved grades for this student
	$stmt = $conn->prepare(
		"SELECT s.subject_code, gp.period_name, g.percentage, g.numeric_grade, g.remarks
		 FROM grades g
		 JOIN enrollments e ON g.enrollment_id = e.enrollment_id
		 JOIN subjects s ON e.subject_id = s.subject_id
		 JOIN grading_periods gp ON g.period_id = gp.period_id
		 WHERE e.student_id = ? AND g.status = 'Approved'
		 ORDER BY s.subject_code, gp.period_id"
	);
	$stmt->bind_param('i', $student_id);
	$stmt->execute();
	$res = $stmt->get_result();

	$lines = [];
	$lines[] = 'Official Report Card';
	$lines[] = 'Student ID: ' . $student_id;
	$lines[] = '-----------------------------';
	while ($r = $res->fetch_assoc()) {
		$lines[] = $r['subject_code'] . ' | ' . $r['period_name'] . ' | ' . $r['percentage'] . '% | ' . $r['numeric_grade'] . ' | ' . $r['remarks'];
	}

	logAction($conn, $_SESSION['user_id'], "Exported student report PDF for student $student_id");

	pdf_send_simple('report_card_' . $student_id . '.pdf', $lines);

} elseif ($type === 'registrar_student') {
	requireRole([2]); // Registrar only
	$student_id = intval($_GET['student_id'] ?? 0);
	if ($student_id <= 0) { http_response_code(400); die('Invalid student id'); }

	// Fetch approved enrollments + approved grades
	$stmt = $conn->prepare(
		"SELECT s.subject_code, gp.period_name, g.percentage, g.numeric_grade, g.remarks
		 FROM grades g
		 JOIN enrollments e ON g.enrollment_id = e.enrollment_id
		 JOIN subjects s ON e.subject_id = s.subject_id
		 JOIN grading_periods gp ON g.period_id = gp.period_id
		 WHERE e.student_id = ? AND g.status = 'Approved'
		 ORDER BY s.subject_code, gp.period_id"
	);
	$stmt->bind_param('i', $student_id);
	$stmt->execute();
	$res = $stmt->get_result();

	$lines = [];
	$lines[] = 'Official Student Academic Record';
	$lines[] = 'Student ID: ' . $student_id;
	$lines[] = '-----------------------------';
	while ($r = $res->fetch_assoc()) {
		$lines[] = $r['subject_code'] . ' | ' . $r['period_name'] . ' | ' . $r['percentage'] . '% | ' . $r['numeric_grade'] . ' | ' . $r['remarks'];
	}

	logAction($conn, $_SESSION['user_id'], "Registrar exported student record PDF for student $student_id");

	pdf_send_simple('student_record_' . $student_id . '.pdf', $lines);

} elseif ($type === 'registrar_master') {
	requireRole([2]);
	$subject_id = intval($_GET['subject_id'] ?? 0);
	$period_id = intval($_GET['period_id'] ?? 0);
	$term = trim($_GET['term'] ?? '');

	$base = "
	SELECT u.user_id AS student_id, u.full_name, s.subject_code, gp.period_name, g.percentage, g.numeric_grade
	FROM grades g
	JOIN enrollments e ON g.enrollment_id = e.enrollment_id
	JOIN users u ON e.student_id = u.user_id
	JOIN subjects s ON e.subject_id = s.subject_id
	JOIN grading_periods gp ON g.period_id = gp.period_id
	WHERE g.status = 'Approved'
	";

	$params = [];
	$types = '';
	if ($subject_id > 0) { $base .= " AND s.subject_id = ?"; $types .= 'i'; $params[] = $subject_id; }
	if ($period_id > 0) { $base .= " AND gp.period_id = ?"; $types .= 'i'; $params[] = $period_id; }
	if ($term !== '') { $base .= " AND e.term = ?"; $types .= 's'; $params[] = $term; }

	$base .= " ORDER BY s.subject_code, u.full_name, gp.period_id";

	$stmt = $conn->prepare($base);
	if ($types !== '') { $stmt->bind_param($types, ...$params); }
	$stmt->execute();
	$res = $stmt->get_result();

	$lines = [];
	$lines[] = 'Master Enrollment & Grade List';
	$lines[] = '-----------------------------';
	while ($r = $res->fetch_assoc()) {
		$lines[] = $r['student_id'] . ' | ' . $r['full_name'] . ' | ' . $r['subject_code'] . ' | ' . $r['period_name'] . ' | ' . $r['percentage'] . '% | ' . $r['numeric_grade'];
	}

	logAction($conn, $_SESSION['user_id'], "Registrar exported master enrollment & grade list PDF");

	pdf_send_simple('master_list.pdf', $lines);

} else {
	http_response_code(400);
	die('Invalid export type');
}

?>