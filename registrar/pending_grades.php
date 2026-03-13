<?php
/* 
 * REGISTRAR PENDING GRADES APPROVAL
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';

// Registrar access control
requireRole([2]);

$registrar_id = $_SESSION['user_id'];
$message = '';
$msg_type = 'success';
if ($flash = getFlash()) {
    $message = $flash['msg'];
    $msg_type = $flash['type'];
}

// Handle approval/return actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (empty($_POST['csrf_token']) || !csrf_check($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $grade_id = isset($_POST['grade_id']) ? (int)$_POST['grade_id'] : 0;
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        if ($grade_id <= 0 || !in_array($action, ['approve', 'return'])) {
            $error = 'Invalid request.';
        } else {
            if ($action === 'approve') {
                $status = 'Approved';
                $is_locked = 1;
            } else {
                $status = 'Returned';
                $is_locked = 0;
            }

            $stmt = $conn->prepare("UPDATE grades SET status = ?, is_locked = ? WHERE grade_id = ?");
            $stmt->bind_param("sii", $status, $is_locked, $grade_id);

            if ($stmt->execute()) {
                setFlash('Grade ' . strtolower($action) . 'ed successfully.', 'success');
                logAction($conn, $registrar_id, "Grade $grade_id " . ($action === 'approve' ? 'approved' : 'returned') . " by registrar");
                $stmt->close();
                if (!headers_sent()) {
                    header('Location: ?page=pending_grades');
                    exit;
                } else {
                    echo "<script>window.location.href='?page=pending_grades';</script>";
                    exit;
                }
            } else {
                setFlash('Failed to update grade: ' . $stmt->error, 'error');
                $stmt->close();
                if (!headers_sent()) {
                    header('Location: ?page=pending_grades');
                    exit;
                } else {
                    echo "<script>window.location.href='?page=pending_grades';</script>";
                    exit;
                }
            }
        }
    }
}

// Query pending grades
$stmt = $conn->prepare("
    SELECT
        g.grade_id,
        u.full_name AS student_name,
        s.subject_code,
        g.academic_period,
        g.numeric_grade,
        g.status
    FROM grades g
    JOIN subjects s ON g.subject_id = s.subject_id
    JOIN users u ON g.student_id = u.user_id
    WHERE g.status = 'Pending'
    ORDER BY g.academic_period, u.full_name
");
$stmt->execute();
$result = $stmt->get_result();
$pending_grades = [];
while ($row = $result->fetch_assoc()) {
    $pending_grades[] = $row;
}
$stmt->close();

$csrf_token = csrf_token();
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

    .pending-grades-table {
        width: 100%;
        border-collapse: collapse;
    }

    .pending-grades-table thead {
        background: var(--bg);
        border-bottom: 1px solid var(--border);
    }

    .pending-grades-table th {
        padding: 1rem 1.25rem;
        text-align: center;
        font-weight: 700;
        color: var(--text-400);
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .pending-grades-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: var(--transition);
    }

    .pending-grades-table tbody tr:hover {
        background: #f8f9ff;
    }

    .pending-grades-table td {
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

    .btn-return {
        background: linear-gradient(135deg, var(--accent), #d97706);
        color: white;
    }

    .btn-return:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
        opacity: 0.9;
    }

    .btn-return:active {
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

    @media (max-width: 768px) {
        .pending-grades-table {
            font-size: 0.85rem;
        }

        .pending-grades-table th,
        .pending-grades-table td {
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
    <?php if (!empty($message)): ?>
        <div class="alert-card <?= $msg_type === 'error' ? 'alert-error' : 'alert-success' ?>">
            <i class='bx <?= $msg_type === 'error' ? 'bx-error-circle' : 'bx-check-circle' ?>'></i>
            <?= htmlspecialchars($message, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
        <div class="alert-card alert-error">
            <i class='bx bx-error-circle'></i>
            <?= htmlspecialchars($error, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h2>Grade Submissions</h2>
        <p>Review and approve grades submitted by faculty.</p>
    </div>

    <div class="content-section">
        <div class="table-card">
            <div class="table-wrap">
                <table class="pending-grades-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Subject Code</th>
                            <th>Period</th>
                            <th>Grade</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($pending_grades) > 0): ?>
                            <?php foreach ($pending_grades as $grade): ?>
                                <tr>
                                    <td class="student-cell"><?= htmlspecialchars($grade['student_name'], ENT_QUOTES) ?></td>
                                    <td class="subject-cell"><?= htmlspecialchars($grade['subject_code'], ENT_QUOTES) ?></td>
                                    <td class="subject-cell"><?= htmlspecialchars($grade['academic_period'], ENT_QUOTES) ?></td>
                                    <td><?= number_format($grade['numeric_grade'], 2) ?></td>
                                    <td>
                                        <span class="status-badge">
                                            <i class='bx bx-time-five'></i>
                                            <?= htmlspecialchars($grade['status'], ENT_QUOTES) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="grade_id" value="<?= $grade['grade_id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                                <button type="submit" class="action-btn btn-approve">
                                                    <i class='bx bx-check'></i>
                                                    Approve
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="grade_id" value="<?= $grade['grade_id'] ?>">
                                                <input type="hidden" name="action" value="return">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                                <button type="submit" class="action-btn btn-return">
                                                    <i class='bx bx-x'></i>
                                                    Return
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 2rem 1rem;">
                                    <div style="color: var(--text-600);">
                                        <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
                                        <p>No pending grade submissions.</p>
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
