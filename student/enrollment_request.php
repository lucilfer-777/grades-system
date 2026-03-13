<?php
/* 
 * STUDENT ENROLLMENT REQUEST PAGE
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';

// Student access control
requireRole([3]);

$student_id = $_SESSION["user_id"];

// Handle enrollment request POST
$request_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_enrollment') {
    if (empty($_POST['csrf_token'])) { 
        $request_msg = 'Missing CSRF token';
    } else {
        csrf_validate_or_die($_POST['csrf_token']);

        // Ensure role is student (defense-in-depth)
        requireRole([3]);

        $subject_id = intval($_POST['subject_id'] ?? 0);
        if ($subject_id <= 0) {
            $request_msg = 'Invalid subject selected.';
        } else {
            // Atomic check + insert to prevent duplicates
            $conn->begin_transaction();
            try {
                // Check existing official enrollment
                $chkEn = $conn->prepare("SELECT enrollment_id FROM enrollments WHERE student_id = ? AND subject_id = ? LIMIT 1");
                $chkEn->bind_param('ii', $student_id, $subject_id);
                $chkEn->execute();
                $rEn = $chkEn->get_result();
                if ($rEn && $rEn->num_rows > 0) {
                    $conn->rollback();
                    $request_msg = 'You are already enrolled in this subject.';
                    logAction($conn, $student_id, "Attempted duplicate enrollment request for subject $subject_id");
                } else {
                    // Check existing pending request (row lock)
                    $chkReq = $conn->prepare("SELECT request_id FROM enrollment_requests WHERE student_id = ? AND subject_id = ? AND status = 'Pending' LIMIT 1 FOR UPDATE");
                    $chkReq->bind_param('ii', $student_id, $subject_id);
                    $chkReq->execute();
                    $rReq = $chkReq->get_result();
                    if ($rReq && $rReq->num_rows > 0) {
                        $conn->rollback();
                        $request_msg = 'You already have a pending request for this subject.';
                        logAction($conn, $student_id, "Duplicate enrollment request blocked for subject $subject_id");
                    } else {
                        $ins = $conn->prepare("INSERT INTO enrollment_requests (student_id, subject_id, status, request_date) VALUES (?, ?, 'Pending', NOW())");
                        $ins->bind_param('ii', $student_id, $subject_id);
                        $ins->execute();
                        $conn->commit();
                        $request_msg = 'Enrollment request submitted successfully.';
                        logAction($conn, $student_id, "Submitted enrollment request for subject $subject_id");
                    }
                }
            } catch (Exception $e) {
                $conn->rollback();
                $request_msg = 'Server error while submitting request.';
            }
        }
    }
}

// Fetch available subjects (not enrolled, no pending request)
$avail_q = "
SELECT s.subject_id, s.subject_code, s.subject_name, u.full_name AS faculty_name
FROM subjects s
LEFT JOIN enrollments e ON s.subject_id = e.subject_id AND e.student_id = ?
LEFT JOIN enrollment_requests er ON s.subject_id = er.subject_id AND er.student_id = ? AND er.status = 'Pending'
LEFT JOIN users u ON s.faculty_id = u.user_id
WHERE e.enrollment_id IS NULL AND er.request_id IS NULL
ORDER BY s.subject_code
";
$avail = $conn->prepare($avail_q);
$avail->bind_param('ii', $student_id, $student_id);
$avail->execute();
$avail_res = $avail->get_result();
?>

<style>
    .alert-card {
        padding: 1rem 1.5rem;
        border-radius: var(--r-md);
        margin-bottom: 2rem;
        border: 1px solid;
        position: relative;
        animation: slideIn 0.3s ease-out;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    @keyframes slideIn {
        from { transform: translateY(-10px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .alert-success {
        background: rgba(34, 197, 94, 0.1);
        border-color: #22c55e;
        color: #166534;
    }
    .alert-error {
        background: #fff5f5;
        border-color: #ef4444;
        color: #991b1b;
    }
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

@keyframes fadeOut {
    0% { opacity: 1; }
    91.67% { opacity: 1; }
    100% { opacity: 0; }
}

.message-box {
    padding: 1rem;
    margin: 1rem 0;
    border-radius: var(--r-md);
    font-size: 0.9rem;
    animation: fadeOut 3.6s ease-in-out forwards;
    border-left: 4px solid;
}

.message-box.success {
    background: #f0fdf4;
    color: #166534;
    border-color: #22c55e;
}

.message-box.error {
    background: #fff5f5;
    color: #991b1b;
    border-color: #ef4444;
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

.btn-request {
    padding: 0.5rem 1rem;
    background: linear-gradient(135deg, var(--blue-600), var(--blue-500));
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.85rem;
    transition: var(--transition);
    box-shadow: var(--shadow-sm);
    white-space: nowrap;
}

.btn-request:hover {
    background: linear-gradient(135deg, var(--blue-700), var(--blue-600));
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.btn-request:active {
    transform: translateY(0);
}

form {
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    justify-content: center;
    flex-wrap: nowrap;
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

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const alertCards = document.querySelectorAll('.alert-card');
        alertCards.forEach(card => {
            setTimeout(() => {
                card.style.opacity = '0';
                card.style.transition = 'opacity 0.3s ease-out';
                setTimeout(() => card.remove(), 300);
            }, 3600);
        });
    });
</script>

<div>
    <?php if (!empty($request_msg)): ?>
        <?php $is_error = (strpos($request_msg, 'Invalid') !== false || strpos($request_msg, 'already') !== false || strpos($request_msg, 'Server') !== false); ?>
        <div class="alert-card alert-<?= $is_error ? 'error' : 'success' ?>">
            <i class='bx <?= $is_error ? 'bx-error-circle' : 'bx-check-circle' ?>'></i>
            <?= htmlspecialchars($request_msg, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h2>Request Subject Enrollment</h2>
        <p>Select an available subject below to submit an enrollment request.</p>
    </div>

    <div class="content-section">
        <div class="table-card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Assigned Faculty</th>
                            <th style="width: 120px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($avail_res && $avail_res->num_rows > 0): ?>
                            <?php while ($s = $avail_res->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['subject_code'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($s['subject_name'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($s['faculty_name'] ?? '-', ENT_QUOTES) ?></td>
                                <td>
                                    <form method="post">
                                        <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">'; ?>
                                        <input type="hidden" name="action" value="request_enrollment">
                                        <input type="hidden" name="subject_id" value="<?= htmlspecialchars($s['subject_id'], ENT_QUOTES) ?>">
                                        <button type="submit" class="btn-request">Request</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 2rem 1rem;">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">📚</div>
                                        <p style="color: var(--text-600);">No available subjects to enroll in at this moment.</p>
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
