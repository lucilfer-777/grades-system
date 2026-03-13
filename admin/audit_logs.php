<?php
/*
 * ADMIN AUDIT LOGS
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';

// Admin access control
requireRole([4]);

// Get filter parameters
$selected_user = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$selected_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$selected_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search_action = isset($_GET['action_search']) ? trim($_GET['action_search']) : '';

// Get all users for filter dropdown
$users_stmt = $conn->prepare("SELECT user_id, full_name, email FROM users ORDER BY full_name");
$users_stmt->execute();
$users_result = $users_stmt->get_result();
$users = [];
while ($row = $users_result->fetch_assoc()) {
    $users[] = $row;
}
$users_stmt->close();

// Build query for audit logs
$query = "
    SELECT
        al.log_id,
        al.user_id,
        al.action,
        al.action_time,
        u.full_name,
        u.email,
        r.role_name
    FROM audit_logs al
    JOIN users u ON al.user_id = u.user_id
    JOIN roles r ON u.role_id = r.role_id
";

$params = [];
$types = '';

$where_clauses = [];

if ($selected_user > 0) {
    $where_clauses[] = "al.user_id = ?";
    $params[] = $selected_user;
    $types .= 'i';
}

if (!empty($selected_date_from)) {
    $where_clauses[] = "DATE(al.action_time) >= ?";
    $params[] = $selected_date_from;
    $types .= 's';
}

if (!empty($selected_date_to)) {
    $where_clauses[] = "DATE(al.action_time) <= ?";
    $params[] = $selected_date_to;
    $types .= 's';
}

if (!empty($search_action)) {
    $where_clauses[] = "al.action LIKE ?";
    $params[] = '%' . $search_action . '%';
    $types .= 's';
}

if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}

$query .= " ORDER BY al.action_time DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$audit_logs = [];
while ($row = $result->fetch_assoc()) {
    $audit_logs[] = $row;
}
$stmt->close();

// Calculate summary statistics
$total_logs = count($audit_logs);
$unique_users = count(array_unique(array_column($audit_logs, 'user_id')));
$today_logs = count(array_filter($audit_logs, function($log) {
    return date('Y-m-d', strtotime($log['action_time'])) === date('Y-m-d');
}));

?>

<style>
    :root {
        --primary: #3B82F6;
        --primary-dark: #2563EB;
        --success: #10B981;
        --danger: #EF4444;
        --warning: #F59E0B;
        --surface: #FFFFFF;
        --background: #F8FAFC;
        --text-primary: #1E293B;
        --text-secondary: #64748B;
        --text-900: #0F172A;
        --text-600: #475569;
        --border: #E2E8F0;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        --radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .audit-logs-container {
        padding: 0;
    }

    .page-header {
        margin-bottom: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .page-header h2 {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .page-header p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        margin: 0.5rem 0 0 0;
    }

    .filters-section {
        background: var(--surface);
        padding: 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
        border: 1px solid var(--border);
    }

    .filters-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        align-items: end;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
    }

    .filter-group label {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .filter-group input,
    .filter-group select {
        padding: 0.75rem;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 0.875rem;
        background: var(--surface);
        color: var(--text-primary);
        transition: var(--transition);
    }

    .filter-group input:focus,
    .filter-group select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .filter-btn {
        padding: 0.75rem 1.5rem;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .filter-btn:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
    }

    .summary-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .summary-card {
        background: var(--surface);
        padding: 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
    }

    .summary-card h3 {
        color: var(--text-primary);
        margin-bottom: 1rem;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
    }

    .summary-stats {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }

    .stat-item {
        text-align: center;
        padding: 1rem;
        background: var(--background);
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary);
        display: block;
        margin-bottom: 0.25rem;
    }

    .stat-label {
        font-size: 0.875rem;
        color: var(--text-secondary);
        font-weight: 500;
    }

    .content-section {
        background: var(--surface);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .table-card {
        padding: 0;
    }

    .subject-title {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        flex-direction: column;
    }

    .title {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .subtitle {
        font-size: 0.9rem;
        color: var(--text-secondary);
        margin-top: 0.25rem;
    }

    .table-wrap {
        overflow-x: auto;
    }

    .audit-table {
        width: 100%;
        border-collapse: collapse;
    }

    .audit-table th,
    .audit-table td {
        padding: 1rem 1.25rem;
        text-align: center;
        border-bottom: 1px solid var(--border);
    }

    .audit-table th {
        background: var(--background);
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .audit-table tbody tr:hover {
        background: var(--background);
    }

    .user-info {
        display: flex;
        flex-direction: column;
    }

    .user-name {
        font-weight: 600;
        color: var(--text-primary);
    }

    .user-email {
        font-size: 0.875rem;
        color: var(--text-secondary);
    }

    .role-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .role-admin {
        background: #FEF3C7;
        color: #92400E;
    }

    .role-registrar {
        background: #DBEAFE;
        color: #1E40AF;
    }

    .role-faculty {
        background: #D1FAE5;
        color: #065F46;
    }

    .role-student {
        background: #F3E8FF;
        color: #6B21A8;
    }

    .action-text {
        font-weight: 500;
        color: var(--text-primary);
    }

    .timestamp {
        color: var(--text-secondary);
        font-size: 0.875rem;
        font-family: 'DM Mono', monospace;
    }

    .no-data {
        text-align: center;
        padding: 3rem;
        background: var(--surface);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
    }

    .no-data h3 {
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        font-size: 1.25rem;
    }

    .no-data p {
        color: var(--text-secondary);
    }

    @media (max-width: 768px) {
        .filters-form {
            grid-template-columns: 1fr;
        }

        .summary-section {
            grid-template-columns: 1fr;
        }

        .audit-table th,
        .audit-table td {
            padding: 0.75rem;
        }

        .user-info {
            min-width: 150px;
        }
    }
</style>

<div class="audit-logs-container">
    <div class="page-header">
        <div>
            <h2>Audit Logs</h2>
            <p>Monitor system activities and user actions for security and compliance</p>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="filters-section">
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <label for="user">User</label>
                <select name="user" id="user">
                    <option value="0">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['user_id'] ?>"
                                <?= $selected_user == $user['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['full_name'] . ' (' . $user['email'] . ')', ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="date_from">Date From</label>
                <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($selected_date_from, ENT_QUOTES) ?>">
            </div>

            <div class="filter-group">
                <label for="date_to">Date To</label>
                <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($selected_date_to, ENT_QUOTES) ?>">
            </div>

            <div class="filter-group">
                <label for="action_search">Action Search</label>
                <input type="text" name="action_search" id="action_search" placeholder="Search actions..." value="<?= htmlspecialchars($search_action, ENT_QUOTES) ?>">
            </div>

            <button type="submit" class="filter-btn">
                <i class='bx bx-filter-alt'></i>
                Apply Filters
            </button>
        </form>
    </div>

    <!-- Summary Statistics -->
    <div class="summary-section">
        <div class="summary-card">
            <h3><i class='bx bx-bar-chart'></i> Activity Summary</h3>
            <div class="summary-stats">
                <div class="stat-item">
                    <span class="stat-number"><?= $total_logs ?></span>
                    <span class="stat-label">Total Logs</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?= $unique_users ?></span>
                    <span class="stat-label">Active Users</span>
                </div>
            </div>
        </div>

        <div class="summary-card">
            <h3><i class='bx bx-time-five'></i> Recent Activity</h3>
            <div class="summary-stats">
                <div class="stat-item">
                    <span class="stat-number"><?= $today_logs ?></span>
                    <span class="stat-label">Today's Logs</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">
                        <?php
                        $last_activity = !empty($audit_logs) ? date('H:i', strtotime($audit_logs[0]['action_time'])) : '--:--';
                        echo $last_activity;
                        ?>
                    </span>
                    <span class="stat-label">Last Activity</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Audit Logs Table -->
    <?php if (!empty($audit_logs)): ?>
        <div class="content-section">
            <div class="table-card">
                <div class="subject-title">
                    <div class="title">System Audit Logs</div>
                    <div class="subtitle">Showing <?= count($audit_logs) ?> log entries</div>
                </div>
                <div class="table-wrap">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit_logs as $log): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <span class="user-name"><?= htmlspecialchars($log['full_name'], ENT_QUOTES) ?></span>
                                            <span class="user-email"><?= htmlspecialchars($log['email'], ENT_QUOTES) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge role-<?= strtolower($log['role_name']) ?>">
                                            <?php
                                            $icon = 'user';
                                            if ($log['role_name'] === 'Admin') $icon = 'crown';
                                            elseif ($log['role_name'] === 'Registrar') $icon = 'shield';
                                            elseif ($log['role_name'] === 'Faculty') $icon = 'chalkboard';
                                            ?>
                                            <i class='bx bx-<?= $icon ?>'></i>
                                            <?= htmlspecialchars($log['role_name'], ENT_QUOTES) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="action-text"><?= htmlspecialchars($log['action'], ENT_QUOTES) ?></span>
                                    </td>
                                    <td>
                                        <div class="timestamp">
                                            <?= date('M d, Y', strtotime($log['action_time'])) ?><br>
                                            <?= date('H:i:s', strtotime($log['action_time'])) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="no-data">
            <h3><i class='bx bx-search-alt'></i> No Audit Logs Found</h3>
            <p>No audit logs match the selected filters. Try adjusting your search criteria.</p>
        </div>
    <?php endif; ?>
</div>