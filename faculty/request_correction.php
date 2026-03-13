<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';

// cache-control headers with check
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}


requireRole([1]); // Faculty

// Only accept POST - prevent direct URL access
require_once __DIR__ . '/../includes/flash.php';
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    // GET: Show locked/approved grades that can be corrected
    $faculty_id = $_SESSION['user_id'];
    $action_msg = '';
    $msg_type = 'success';
    if ($flash = getFlash()) {
        $action_msg = $flash['msg'];
        $msg_type = $flash['type'];
    }
    
    // Fetch all locked/approved grades for this faculty's subjects
    $grades_query = "
        SELECT 
            g.grade_id,
            u.full_name AS student_name,
            s.subject_code,
            s.subject_name,
            g.academic_period,
            g.percentage,
            g.numeric_grade,
            g.status,
            CASE WHEN gc.request_id IS NOT NULL THEN 1 ELSE 0 END AS has_pending_request
        FROM grades g
        JOIN users u ON g.student_id = u.user_id
        JOIN subjects s ON g.subject_id = s.subject_id
        LEFT JOIN grade_corrections gc ON g.grade_id = gc.grade_id AND gc.status IN ('Pending', 'Approved')
        WHERE s.faculty_id = ? AND g.is_locked = 1
        ORDER BY s.subject_code, u.full_name, g.academic_period
    ";
    
    $stmt = $conn->prepare($grades_query);
    $stmt->bind_param("i", $faculty_id);
    $stmt->execute();
    $grades_result = $stmt->get_result();
    
    $locked_grades = [];
    if ($grades_result) {
        while ($row = $grades_result->fetch_assoc()) {
            $locked_grades[] = $row;
        }
    }
    
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
    --accent: #F59E0B;
    --danger: #EF4444;
    --success: #22C55E;
    --radius: 12px;
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
    border-radius: var(--r-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: 2rem;
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

.grades-table {
    width: 100%;
    border-collapse: collapse;
}

.grades-table thead {
    background: var(--bg);
    border-bottom: 1px solid var(--border);
}

.grades-table th {
    padding: 1rem 1.25rem;
    text-align: center;
    font-weight: 700;
    color: var(--text-400);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.grades-table tbody tr {
    border-bottom: 1px solid var(--border);
    transition: var(--transition);
}

.grades-table tbody tr:hover {
    background: #f8f9ff;
}

.grades-table td {
    padding: 1rem 1.25rem;
    vertical-align: middle;
    color: black;
    text-align: center;
}

.email-message {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--text-600);
}

.email-message-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

textarea {
    padding: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    font-size: 0.9rem;
    background: var(--surface);
    color: var(--text-900);
    font-family: 'DM Sans', sans-serif;
    min-height: 80px;
    width: 100%;
}

textarea:focus {
    outline: none;
    border-color: var(--blue-600);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.action-btn {
    padding: 0.75rem 1.5rem;
    background: linear-gradient(135deg, var(--blue-600), var(--blue-700));
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    white-space: nowrap;
}

.action-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.action-btn:active:not(:disabled) {
    transform: translateY(0);
}

.action-btn:disabled {
    background: rgba(100, 116, 139, 0.2);
    color: var(--text-600);
    cursor: not-allowed;
}

form {
    margin: 0;
}

.badge-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-pending {
    background: #fef3c7;
    color: #92400e;
}

.badge-approved {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #22c55e;
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
            <h2>Grade Correction Requests</h2>
            <p>Submit correction requests for grades that need to be updated.</p>
        </div>

            <div class="content-section">
            <?php if (count($locked_grades) > 0): ?>
            <div class="table-card">
                <div class="table-wrap">
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Subject</th>
                                <th>Period</th>
                                <th>Grade</th>
                                <th>Status</th>
                                <th>Reason for Correction</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locked_grades as $grade): ?>
                            <tr>
                                <td style="text-align: left;"><?= htmlspecialchars($grade['student_name'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($grade['subject_code'] . ' - ' . $grade['subject_name'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($grade['academic_period'], ENT_QUOTES) ?></td>
                                <td><?= htmlspecialchars($grade['numeric_grade'], ENT_QUOTES) ?></td>
                                <td>
                                    <?php
                                    $status = strtolower($grade['status']);
                                    $badge_class = ($status === 'approved') ? 'badge-approved' : 'badge-pending';
                                    $icon = ($status === 'approved') ? '<i class="bx bx-check-circle"></i>' : '<i class="bx bx-time"></i>';
                                    ?>
                                    <span class="badge-status <?= $badge_class ?>">
                                        <?= $icon ?> <?= htmlspecialchars($grade['status'], ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post">
                                        <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">'; ?>
                                        <input type="hidden" name="grade_id" value="<?= htmlspecialchars($grade['grade_id'], ENT_QUOTES) ?>">
                                        <textarea name="reason" placeholder="Explain why this grade needs correction..." required></textarea>
                                </td>
                                <td>
                                        <button type="submit" class="action-btn" <?php echo $grade['has_pending_request'] ? 'disabled title="Request already pending"' : ''; ?>>
                                            <?php echo $grade['has_pending_request'] ? 'Pending' : 'Request'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="table-card">
                <div class="table-wrap">
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Subject</th>
                                <th>Period</th>
                                <th>Grade</th>
                                <th>Status</th>
                                <th>Reason for Correction</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 2rem 1rem;">
                                    <div class="email-message">
                                        <div class="email-message-icon">📋</div>
                                        <p>No locked/approved grades available for correction.</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    exit;
}

if (empty($_POST['csrf_token'])) { http_response_code(400); die('Missing CSRF token'); }
csrf_validate_or_die($_POST['csrf_token']);

$grade_id = intval($_POST["grade_id"] ?? 0);
$reason = trim($_POST["reason"] ?? '');

// Validate that the grade belongs to this faculty and is locked (only locked/approved grades may be requested)
// Verify ownership and locked state
$check = $conn->prepare(
    "SELECT g.grade_id
     FROM grades g
     JOIN subjects s ON g.subject_id = s.subject_id
     WHERE g.grade_id = ? AND s.faculty_id = ? AND g.is_locked = 1"
);
$check->bind_param("ii", $grade_id, $_SESSION["user_id"]);
$check->execute();
$res = $check->get_result();
if ($res->num_rows === 0) {
    // Invalid request or not allowed
    http_response_code(403);
    logAction($conn, $_SESSION["user_id"], "Unauthorized correction request attempt for grade ID $grade_id");
    setFlash('You are not authorized to request this correction.','error');
    if (!headers_sent()) {
        header("Location: ?page=request_correction");
        exit;
    } else {
        echo "<script>window.location.href='?page=request_correction';</script>";
        exit;
    }
}

$conn->begin_transaction();
try {
    // Check for existing active request for this grade
    $dup = $conn->prepare(
        "SELECT request_id FROM grade_corrections WHERE grade_id = ? AND status IN ('Pending','Approved') LIMIT 1 FOR UPDATE"
    );
    $dup->bind_param("i", $grade_id);
    $dup->execute();
    $dupRes = $dup->get_result();
    if ($dupRes && $dupRes->num_rows > 0) {
        // Duplicate exists: rollback and stop
        $conn->rollback();
        http_response_code(409);
        logAction($conn, $_SESSION["user_id"], "Duplicate correction request blocked for grade ID $grade_id");
        echo "A correction request already exists for this grade.";
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO grade_corrections (grade_id, faculty_id, reason, status)
         VALUES (?, ?, ?, ?)"
    );
    $status = 'Pending';
    $stmt->bind_param("iiss", $grade_id, $_SESSION["user_id"], $reason, $status);
    $stmt->execute();

    logAction($conn, $_SESSION["user_id"], "Requested correction for grade ID $grade_id");

    $conn->commit();

    setFlash('Correction request submitted.','success');
    if (!headers_sent()) {
        header("Location: ?page=request_correction");
        exit;
    } else {
        echo "<script>window.location.href='?page=request_correction';</script>";
        exit;
    }

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo "Server error";
    exit;
}

?>