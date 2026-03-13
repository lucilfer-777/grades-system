<?php
ob_start();
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/notifications.php';

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}

requireRole([2]); // Registrar

$action_msg = '';
$msg_type = 'success';

// Handle approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_enrollment'])) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if (empty($_POST['csrf_token'])) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Missing CSRF token']);
            exit;
        }
        http_response_code(400);
        die('Missing CSRF token');
    }
    csrf_validate_or_die($_POST['csrf_token']);

    $request_id = intval($_POST['request_id'] ?? 0);
    $decision_notes = trim($_POST['decision_notes'] ?? '');

    // Validate and approve enrollment
    $chk = $conn->prepare("SELECT student_id, subject_id, status FROM enrollment_requests WHERE request_id = ?");
    $chk->bind_param('i', $request_id);
    $chk->execute();
    $cres = $chk->get_result();
    if ($cres && $crow = $cres->fetch_assoc()) {
        if ($crow['status'] === 'Pending') {
            $student_id = intval($crow['student_id']);
            $subject_id = intval($crow['subject_id']);

            // Get semester_id
            $sem = $conn->prepare("SELECT semester_id FROM semesters ORDER BY semester_id DESC LIMIT 1");
            $sem->execute();
            $sr = $sem->get_result();
            if ($sr && $srow = $sr->fetch_assoc()) {
                $semester_id = intval($srow['semester_id']);

                // Create enrollment
                $ins = $conn->prepare("INSERT INTO enrollments (student_id, subject_id, semester_id) VALUES (?, ?, ?)");
                $ins->bind_param('iii', $student_id, $subject_id, $semester_id);
                $ins->execute();

                // Update request
                $upd = $conn->prepare("UPDATE enrollment_requests SET status = 'Approved', registrar_id = ?, decision_notes = ?, decision_date = NOW() WHERE request_id = ?");
                $upd->bind_param('isi', $_SESSION['user_id'], $decision_notes, $request_id);
                $upd->execute();

                logAction($conn, $_SESSION['user_id'], "Approved enrollment request $request_id");
                addNotification($conn, $student_id, "Your enrollment request #$request_id was approved.");

                $action_msg = 'Enrollment approved.';

                // If AJAX request, return JSON
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => $action_msg]);
                    exit;
                }
            } else {
                // No semester found
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'No active semester found.']);
                    exit;
                }
                $action_msg = 'No active semester found.';
                $msg_type = 'error';
            }
        } else {
            // Status is not pending
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Request is no longer pending.']);
                exit;
            }
            $action_msg = 'Request is no longer pending.';
            $msg_type = 'error';
        }
    } else {
        // Invalid request
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid enrollment request.']);
            exit;
        }
        $action_msg = 'Invalid enrollment request.';
        $msg_type = 'error';
    }
}

// Handle rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_enrollment'])) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if (empty($_POST['csrf_token'])) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Missing CSRF token']);
            exit;
        }
        http_response_code(400);
        die('Missing CSRF token');
    }
    csrf_validate_or_die($_POST['csrf_token']);

    $request_id = intval($_POST['request_id'] ?? 0);
    $decision_notes = trim($_POST['decision_notes'] ?? '');

    $upd = $conn->prepare("UPDATE enrollment_requests SET status = 'Rejected', registrar_id = ?, decision_notes = ?, decision_date = NOW() WHERE request_id = ?");
    $upd->bind_param('isi', $_SESSION['user_id'], $decision_notes, $request_id);
    $upd->execute();

    logAction($conn, $_SESSION['user_id'], "Rejected enrollment request $request_id");

    $action_msg = 'Enrollment rejected.';
    $msg_type = 'warning';

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $action_msg, 'type' => 'warning']);
        exit;
    }
}

// Fetch pending enrollments
$er_q = "
SELECT er.request_id, er.student_id, er.subject_id, u.full_name AS student_name, s.subject_code, s.subject_name, uf.full_name AS teacher_name, er.request_date, er.status
FROM enrollment_requests er
JOIN users u ON er.student_id = u.user_id
JOIN subjects s ON er.subject_id = s.subject_id
LEFT JOIN users uf ON s.faculty_id = uf.user_id
WHERE er.status = 'Pending'
ORDER BY er.request_date DESC
";
$er_result = $conn->query($er_q);

logAction($conn, $_SESSION['user_id'], "Viewed pending enrollment requests");
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
        --border: #E2E8F0;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        --radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
        margin: 0;
        padding: 0;
    }

    .page-header {
        margin-bottom: 2rem;
    }

    .page-header h2 {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        margin-top: 0;
    }

    .page-header p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        margin: 0;
    }

    .alert-card {
        padding: 1rem 1.5rem;
        border-radius: var(--radius);
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
        border-color: var(--success);
        color: #166534;
    }

    .alert-error {
        background: rgba(239, 68, 68, 0.1);
        border-color: var(--danger);
        color: #991b1b;
    }

    .alert-warning {
        background: rgba(245, 158, 11, 0.1);
        border-color: var(--accent);
        color: #92400e;
    }

    .content-section {
        margin-bottom: 3rem;
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

    .enrollments-table {
        width: 100%;
        border-collapse: collapse;
    }

    .enrollments-table thead {
        background: var(--bg);
        border-bottom: 1px solid var(--border);
    }

    .enrollments-table th {
        padding: 1rem 1.25rem;
        text-align: center;
        font-weight: 700;
        color: var(--text-400);
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .enrollments-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: var(--transition);
    }

    .enrollments-table tbody tr:hover {
        background: #f8f9ff;
    }

    .enrollments-table td {
        padding: 1rem 1.25rem;
        vertical-align: middle;
        color: black;
        text-align: center;
    }

    .student-cell {
        color: var(--text-primary);
    }

    .subject-cell {
        color: var(--text-secondary);
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.4rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: rgba(245, 158, 11, 0.1);
        color: #92400e;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        justify-content: center;
    }

    .action-btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }

    .btn-approve {
        background: linear-gradient(135deg, var(--secondary), #059669);
        color: white;
    }

    .btn-approve:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
        opacity: 0.9;
    }

    .btn-approve:active {
        transform: translateY(0);
    }

    .btn-reject {
        background: linear-gradient(135deg, var(--danger), #dc2626);
        color: white;
    }

    .btn-reject:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
        opacity: 0.9;
    }

    .btn-reject:active {
        transform: translateY(0);
    }

    .empty-message {
        text-align: center;
        padding: 3rem 2rem;
        color: var(--text-secondary);
    }

    .empty-message i {
        font-size: 3rem;
        color: var(--border);
        margin-bottom: 1rem;
        display: block;
    }

    .empty-message p {
        font-size: 1rem;
        margin: 0;
    }

    .empty-table-cell {
        padding: 0;
    }

    @media (max-width: 768px) {
        .enrollments-table {
            font-size: 0.85rem;
        }

        .enrollments-table th,
        .enrollments-table td {
            padding: 0.75rem 0.5rem;
        }

        .action-buttons {
            flex-direction: column;
            width: 100%;
        }

        .action-btn {
            width: 100%;
            justify-content: center;
        }
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
    <?php if (!empty($action_msg)): ?>
        <div class="alert-card alert-<?= $msg_type !== 'success' ? $msg_type : 'success' ?>">
            <i class='bx <?= $msg_type !== 'success' ? 'bx-error-circle' : 'bx-check-circle' ?>'></i>
            <?= htmlspecialchars($action_msg, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h2>Enrollment Requests</h2>
        <p>Review and approve student enrollment requests.</p>
    </div>

    <div class="content-section">
        <div class="table-card">
            <div class="table-wrap">
                <table class="enrollments-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Subject Teacher</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($er_result && $er_result->num_rows > 0): ?>
                            <?php while ($row = $er_result->fetch_assoc()): ?>
                                <tr>
                                    <td class="student-cell"><?= htmlspecialchars($row['student_name'], ENT_QUOTES) ?></td>
                                    <td class="subject-cell"><?= htmlspecialchars($row['subject_code'], ENT_QUOTES) ?></td>
                                    <td class="subject-cell"><?= htmlspecialchars($row['subject_name'], ENT_QUOTES) ?></td>
                                    <td class="subject-cell"><?= htmlspecialchars($row['teacher_name'] ?? '-', ENT_QUOTES) ?></td>
                                    <td>
                                        <span class="status-badge">
                                            <i class='bx bx-time-five'></i>
                                            <?= htmlspecialchars($row['status'], ENT_QUOTES) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <form method="post" style="display: inline;">
                                                <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">'; ?>
                                                <input type="hidden" name="request_id" value="<?= htmlspecialchars($row['request_id'], ENT_QUOTES) ?>">
                                                <input type="hidden" name="decision_notes" value="">
                                                <button type="submit" name="approve_enrollment" value="1" class="action-btn btn-approve">
                                                    <i class='bx bx-check'></i>
                                                    Approve
                                                </button>
                                            </form>
                                            <form method="post" style="display: inline;">
                                                <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">'; ?>
                                                <input type="hidden" name="request_id" value="<?= htmlspecialchars($row['request_id'], ENT_QUOTES) ?>">
                                                <input type="hidden" name="decision_notes" value="">
                                                <button type="submit" name="reject_enrollment" value="1" class="action-btn btn-reject">
                                                    <i class='bx bx-x'></i>
                                                    Reject
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-table-cell">
                                    <div class="empty-message">
                                        <i class='bx bx-inbox'></i>
                                        <p>No pending enrollment requests.</p>
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
