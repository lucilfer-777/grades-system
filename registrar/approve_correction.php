<?php
ob_start();
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/flash.php';

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}

requireRole([2]); // Registrar

$action_msg = '';
$msg_type = 'success';
// pull flash if exists
if ($flash = getFlash()) {
    $action_msg = $flash['msg'];
    $msg_type = $flash['type'];
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token'])) { http_response_code(400); die('Missing CSRF token'); }
    csrf_validate_or_die($_POST['csrf_token']);

    // Approve correction
    if (isset($_POST['approve'])) {
        $request_id = intval($_POST['request_id'] ?? 0);
        $grade_id = intval($_POST['grade_id'] ?? 0);
        $faculty_id = intval($_POST['faculty_id'] ?? 0);
        $decision_notes = trim($_POST['decision_notes'] ?? '');

        $conn->begin_transaction();
        try {
            // Verify request is still pending (row-level lock)
            $chk = $conn->prepare("SELECT status FROM grade_corrections WHERE request_id = ? FOR UPDATE");
            $chk->bind_param('i', $request_id);
            $chk->execute();
            $cres = $chk->get_result();
            if (!($cres && $crow = $cres->fetch_assoc()) || $crow['status'] !== 'Pending') {
                $conn->rollback();
                http_response_code(409);
                setFlash("This request has already been processed.",'error');
                if (!headers_sent()) {
                    header('Location: ?page=pending_corrections');
                    exit;
                } else {
                    echo "<script>window.location.href='?page=pending_corrections';</script>";
                    exit;
                }
            } else {
                $can_proceed = true;

                // Re-check grade is still locked
                $gchk = $conn->prepare("SELECT is_locked FROM grades WHERE grade_id = ? FOR UPDATE");
                $gchk->bind_param('i', $grade_id);
                $gchk->execute();
                $gr = $gchk->get_result();
                if (!($gr && $grow = $gr->fetch_assoc()) || intval($grow['is_locked']) !== 1) {
                    $conn->rollback();
                    setFlash('Cannot approve: grade is not locked.','error');
                    if (!headers_sent()) {
                        header('Location: ?page=pending_corrections');
                        exit;
                    } else {
                        echo "<script>window.location.href='?page=pending_corrections';</script>";
                        exit;
                    }

                }

                // Ensure no other active request exists for this grade
                if ($can_proceed) {
                    $dup = $conn->prepare(
                        "SELECT COUNT(*) AS c FROM grade_corrections
                         WHERE grade_id = ? AND status IN ('Pending','Approved') AND request_id != ?
                         FOR UPDATE"
                    );
                    $dup->bind_param('ii', $grade_id, $request_id);
                    $dup->execute();
                    $dres  = $dup->get_result();
                    $count = ($dres && $drow = $dres->fetch_assoc()) ? intval($drow['c']) : 0;
                    if ($count > 0) {
                        $conn->rollback();
                        setFlash("Cannot approve: another active correction request exists for this grade.",'error');
                        if (!headers_sent()) {
                            header('Location: ?page=pending_corrections');
                            exit;
                        } else {
                            echo "<script>window.location.href='?page=pending_corrections';</script>";
                            exit;
                        }
                    }
                }

                if ($can_proceed) {
                    // Unlock grade for resubmission
                    $u1 = $conn->prepare("UPDATE grades SET is_locked = 0, status = 'Returned' WHERE grade_id = ?");
                    $u1->bind_param('i', $grade_id);
                    $u1->execute();

                    // Mark correction Approved
                    $u2 = $conn->prepare(
                        "UPDATE grade_corrections
                         SET status = 'Approved', registrar_id = ?, decision_notes = ?, decision_date = NOW()
                         WHERE request_id = ?"
                    );
                    $u2->bind_param('isi', $_SESSION['user_id'], $decision_notes, $request_id);
                    $u2->execute();

                    logAction($conn, $_SESSION['user_id'], "Approved correction request ID $request_id for grade ID $grade_id");
                    addNotification($conn, $faculty_id, "Your correction request #$request_id was approved; grade unlocked for resubmission.");

                    $conn->commit();

                    setFlash('Correction request approved — grade unlocked and returned to faculty.','success');
                    if (!headers_sent()) {
                        header('Location: ?page=pending_corrections');
                        exit;
                    } else {
                        echo "<script>window.location.href='?page=pending_corrections';</script>";
                        exit;
                    }
                }
            }
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            $action_msg = 'Server error processing request.';
            $msg_type = 'error';
        }
    }

    // Reject correction
    if (isset($_POST['reject'])) {
        $request_id = intval($_POST['request_id'] ?? 0);
        $faculty_id = intval($_POST['faculty_id'] ?? 0);
        $decision_notes = trim($_POST['decision_notes'] ?? '');

        $conn->begin_transaction();
        try {
            // Verify request is still pending
            $chk = $conn->prepare("SELECT status FROM grade_corrections WHERE request_id = ? FOR UPDATE");
            $chk->bind_param('i', $request_id);
            $chk->execute();
            $cres = $chk->get_result();
            if (!($cres && $crow = $cres->fetch_assoc()) || $crow['status'] !== 'Pending') {
                $conn->rollback();
                http_response_code(409);
                setFlash('This request has already been processed.','error');
                if (!headers_sent()) {
                    header('Location: ?page=pending_corrections');
                    exit;
                } else {
                    echo "<script>window.location.href='?page=pending_corrections';</script>";
                    exit;
                }
            }

            // Mark correction Rejected
            $u = $conn->prepare("UPDATE grade_corrections SET status = 'Rejected', registrar_id = ?, decision_notes = ?, decision_date = NOW() WHERE request_id = ?");
            $u->bind_param('isi', $_SESSION['user_id'], $decision_notes, $request_id);
            $u->execute();

            logAction($conn, $_SESSION['user_id'], "Rejected correction request ID $request_id");
            addNotification($conn, $faculty_id, "Your correction request #$request_id was rejected. Notes: $decision_notes");

            $conn->commit();

            setFlash('Correction request rejected.','warning');
            if (!headers_sent()) {
                header('Location: ?page=pending_corrections');
                exit;
            } else {
                echo "<script>window.location.href='?page=pending_corrections';</script>";
                exit;
            }
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            setFlash('Server error processing request.','error');
            if (!headers_sent()) {
                header('Location: ?page=pending_corrections');
                exit;
            } else {
                echo "<script>window.location.href='?page=pending_corrections';</script>";
                exit;
            }
        }
    }
}

// Fetch pending correction requests
$stmt = $conn->prepare(
    "SELECT
        gc.request_id,
        gc.grade_id,
        gc.faculty_id,
        gc.reason,
        u.full_name AS student,
        s.subject_code,
        s.subject_name,
        g.academic_period,
        g.percentage,
        r.full_name AS requester
     FROM grade_corrections gc
     JOIN grades g ON gc.grade_id = g.grade_id
     JOIN users u ON g.student_id = u.user_id
     JOIN subjects s ON g.subject_id = s.subject_id
     JOIN users r ON gc.faculty_id = r.user_id
     WHERE gc.status = 'Pending'
     ORDER BY gc.request_id ASC"
);
$stmt->execute();
$res = $stmt->get_result();

// Build verified pending rows to avoid race conditions/stale data
$pending_rows = [];
while ($row = $res->fetch_assoc()) {
    $statusChk = $conn->prepare("SELECT status FROM grade_corrections WHERE request_id = ?");
    $statusChk->bind_param('i', $row['request_id']);
    $statusChk->execute();
    $sr = $statusChk->get_result();
    if (!($sr && $srow = $sr->fetch_assoc())) continue;
    if (trim($srow['status']) !== 'Pending') continue;
    $pending_rows[] = $row;
}

logAction($conn, $_SESSION['user_id'], "Viewed pending correction requests");
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

    body {
        background-color: #F0F4FF;
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

    .corrections-table {
        width: 100%;
        border-collapse: collapse;
    }

    .corrections-table thead {
        background: var(--bg);
        border-bottom: 1px solid var(--border);
    }

    .corrections-table th {
        padding: 1rem 1.25rem;
        text-align: center;
        font-weight: 700;
        color: var(--text-400);
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .corrections-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: var(--transition);
    }

    .corrections-table tbody tr:hover {
        background: #f8f9ff;
    }

    .corrections-table td {
        padding: 1rem 1.25rem;
        vertical-align: middle;
        color: black;
        text-align: center;
    }

    .reason-cell {
        text-align: justify;
        text-align-last: left;
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
        white-space: nowrap;
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

    input[type="text"] {
        padding: 0.5rem 0.75rem;
        border: 1px solid var(--border);
        border-radius: 6px;
        font-size: 0.9rem;
        transition: var(--transition);
    }

    input[type="text"]:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    @media (max-width: 768px) {
        .corrections-table {
            font-size: 0.85rem;
        }

        .corrections-table th,
        .corrections-table td {
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
            <i class='bx <?= $msg_type !== 'success' ? ($msg_type === 'warning' ? 'bx-error-circle' : 'bx-error-circle') : 'bx-check-circle' ?>'></i>
            <?= htmlspecialchars($action_msg, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h2>Correction Requests</h2>
        <p>Review faculty requests to correct locked or approved grades.</p>
    </div>

    <div class="content-section">
        <div class="table-card">
            <div class="table-wrap">
                <table class="corrections-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Subject Code</th>
                            <th>Subject</th>
                            <th>Percentage</th>
                            <th>Requested by:</th>
                            <th>Reason</th>
                            <th>Decision Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($pending_rows)): ?>
                            <?php foreach ($pending_rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['student'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($row['subject_code'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($row['subject_name'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($row['percentage'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($row['requester'], ENT_QUOTES) ?></td>
                                <td class="reason-cell"><?= htmlspecialchars($row['reason'], ENT_QUOTES) ?></td>
                                <td>
                                    <form id="corrForm<?= htmlspecialchars($row['request_id'], ENT_QUOTES) ?>" method="post">
                                        <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">'; ?>
                                        <input type="hidden" name="request_id" value="<?= htmlspecialchars($row['request_id'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="grade_id" value="<?= htmlspecialchars($row['grade_id'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="faculty_id" value="<?= htmlspecialchars($row['faculty_id'], ENT_QUOTES) ?>">
                                        <input type="text" name="decision_notes" placeholder="Notes (required)" required>
                                    </form>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="submit" form="corrForm<?= htmlspecialchars($row['request_id'], ENT_QUOTES) ?>" name="approve" class="action-btn btn-approve">
                                            <i class='bx bx-check'></i>
                                            Approve
                                        </button>
                                        <button type="submit" form="corrForm<?= htmlspecialchars($row['request_id'], ENT_QUOTES) ?>" name="reject" class="action-btn btn-reject">
                                            <i class='bx bx-x'></i>
                                            Reject
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty-table-cell">
                                    <div class="empty-message">
                                        <i class='bx bx-inbox'></i>
                                        <p>No pending correction requests at this time.</p>
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