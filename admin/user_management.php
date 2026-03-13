<?php
/*
 * ADMIN USER MANAGEMENT
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

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

// Admin access control
requireRole([4]);

$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (empty($_POST['csrf_token']) || !csrf_check($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'create') {
            // Create new user
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role_id = (int)($_POST['role_id'] ?? 0);
            $program = trim($_POST['program'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $year_level = isset($_POST['year_level']) ? (int)$_POST['year_level'] : null;

            if (empty($full_name) || empty($email) || empty($password) || $role_id < 1 || $role_id > 4) {
                $error = 'Please fill all required fields correctly.';
            } else {
                // Check if email exists
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $error = 'Email already exists.';
                    $stmt->close();
                } else {
                    $stmt->close();
                    // Hash password
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    // Insert user
                    $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role_id, program, section, year_level) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssisss", $full_name, $email, $password_hash, $role_id, $program, $section, $year_level);
                    if ($stmt->execute()) {
                        $message = 'User created successfully.';
                        logAction($conn, $_SESSION['user_id'], "Created user: $full_name ($email)");
                    } else {
                        $error = 'Failed to create user: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'edit') {
            // Edit user
            $user_id = (int)($_POST['user_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role_id = (int)($_POST['role_id'] ?? 0);
            $program = trim($_POST['program'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $year_level = isset($_POST['year_level']) ? (int)$_POST['year_level'] : null;

            if ($user_id <= 0 || empty($full_name) || empty($email) || $role_id < 1 || $role_id > 4) {
                $error = 'Please fill all required fields correctly.';
            } else {
                // Check if email exists for other users
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $stmt->bind_param("si", $email, $user_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $error = 'Email already exists for another user.';
                    $stmt->close();
                } else {
                    $stmt->close();
                    // Update user (optionally update password if provided)
                    if (!empty($password)) {
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, password_hash = ?, role_id = ?, program = ?, section = ?, year_level = ? WHERE user_id = ?");
                        $stmt->bind_param("sssisssi", $full_name, $email, $password_hash, $role_id, $program, $section, $year_level, $user_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role_id = ?, program = ?, section = ?, year_level = ? WHERE user_id = ?");
                        $stmt->bind_param("ssisssi", $full_name, $email, $role_id, $program, $section, $year_level, $user_id);
                    }
                    if ($stmt->execute()) {
                        $message = 'User updated successfully.';
                        logAction($conn, $_SESSION['user_id'], "Updated user ID $user_id: $full_name");
                    } else {
                        $error = 'Failed to update user: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'toggle_status') {
            // Toggle active status
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id > 0) {
                // Get current status
                $stmt = $conn->prepare("SELECT is_active, full_name FROM users WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $new_status = $row['is_active'] ? 0 : 1;
                    $stmt->close();
                    $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
                    $stmt->bind_param("ii", $new_status, $user_id);
                    if ($stmt->execute()) {
                        $status_text = $new_status ? 'activated' : 'deactivated';
                        $message = 'User ' . $status_text . ' successfully.';
                        logAction($conn, $_SESSION['user_id'], "User ID $user_id " . $status_text . ": " . $row['full_name']);
                    } else {
                        $error = 'Failed to update user status: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = 'User not found.';
                    $stmt->close();
                }
            } else {
                $error = 'Invalid user ID.';
            }
        }
    }
}

// Get filter
$role_filter = isset($_GET['role']) ? (int)$_GET['role'] : 0;

// Query users
$query = "SELECT user_id, full_name, email, role_id, program, section, year_level, is_active, created_at FROM users";
$params = [];
$types = "";

if ($role_filter > 0) {
    $query .= " WHERE role_id = ?";
    $params[] = $role_filter;
    $types .= "i";
}

$query .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

$csrf_token = csrf_token();

// Role labels
$role_labels = [1 => 'Faculty', 2 => 'Registrar', 3 => 'Student', 4 => 'Admin'];
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
    }

    .page-header p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        margin: 0.5rem 0 0 0;
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

    .filters-section {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: var(--shadow);
    }

    .filters-row {
        display: flex;
        gap: 1rem;
        align-items: center;
        margin-bottom: 1rem;
    }

    .filter-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .filter-label {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.9rem;
    }

    .filter-select {
        padding: 0.5rem 1rem;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--surface);
        color: var(--text-primary);
        font-size: 0.9rem;
        transition: var(--transition);
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
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

    .users-table {
        width: 100%;
        border-collapse: collapse;
    }

    .users-table thead {
        background: var(--bg);
        border-bottom: 1px solid var(--border);
    }

    .users-table th {
        padding: 1rem 1.25rem;
        text-align: center;
        font-weight: 700;
        color: var(--text-400);
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .users-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: var(--transition);
    }

    .users-table tbody tr:hover {
        background: #f8f9ff;
    }

    .users-table td {
        padding: 1rem 1.25rem;
        vertical-align: middle;
        color: var(--text-primary);
        text-align: center;
    }

    .user-name {
        font-weight: 600;
        color: var(--text-primary);
    }

    .user-email {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .role-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .role-faculty { background: rgba(59, 130, 246, 0.1); color: var(--primary); }
    .role-registrar { background: rgba(16, 185, 129, 0.1); color: var(--secondary); }
    .role-student { background: rgba(245, 158, 11, 0.1); color: var(--accent); }
    .role-admin { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: rgba(34, 197, 94, 0.1);
        color: var(--success);
    }

    .status-inactive {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .action-btn {
        padding: 0.5rem 1rem;
        border: none;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.25rem;
        text-decoration: none;
    }

    .btn-edit {
        background: rgba(59, 130, 246, 0.1);
        color: var(--primary);
    }

    .btn-edit:hover {
        background: var(--primary);
        color: white;
    }

    .btn-activate {
        background: rgba(34, 197, 94, 0.1);
        color: var(--success);
    }

    .btn-activate:hover {
        background: var(--success);
        color: white;
    }

    .btn-deactivate {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
    }

    .btn-deactivate:hover {
        background: var(--danger);
        color: white;
    }

    .modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: var(--transition);
    }

    .modal.show {
        opacity: 1;
        visibility: visible;
    }

    .modal-content {
        background: var(--surface);
        border-radius: var(--radius);
        box-shadow: var(--shadow-lg);
        width: 90%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
        transform: scale(0.9);
        transition: var(--transition);
    }

    .modal.show .modal-content {
        transform: scale(1);
    }

    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: var(--text-secondary);
        cursor: pointer;
        padding: 0.25rem;
        border-radius: 4px;
        transition: var(--transition);
    }

    .modal-close:hover {
        background: var(--border);
        color: var(--text-primary);
    }

    .modal-body {
        padding: 1.5rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-label {
        display: block;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .form-input {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--surface);
        color: var(--text-primary);
        font-size: 0.9rem;
        transition: var(--transition);
    }

    .form-input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .password-group {
        position: relative;
    }

    .password-toggle {
        position: absolute;
        right: 0.75rem;
        top: 2.05rem;
        background: none;
        border: none;
        cursor: pointer;
        color: var(--text-secondary);
        font-size: 1.1rem;
        padding: 0.5rem;
        border-radius: 4px;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .password-toggle:hover {
        background: var(--border);
        color: var(--text-primary);
    }

    .modal-footer {
        padding: 1.5rem;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
    }

    .btn-secondary {
        background: var(--border);
        color: var(--text-primary);
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
    }

    .btn-secondary:hover {
        background: #e2e8f0;
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
        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
        }

        .filters-row {
            flex-direction: column;
            align-items: stretch;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .users-table {
            font-size: 0.85rem;
        }

        .users-table th,
        .users-table td {
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

        // Modal functionality
        const modal = document.getElementById('userModal');
        const createBtn = document.getElementById('createUserBtn');
        const editBtns = document.querySelectorAll('.edit-btn');
        const closeBtn = document.querySelector('.modal-close');
        const cancelBtn = document.getElementById('cancelBtn');

        function openModal() {
            modal.classList.add('show');
        }

        function closeModal() {
            modal.classList.remove('show');
            document.getElementById('userForm').reset();
            document.getElementById('user_id').value = '';
            document.getElementById('action').value = 'create';
            document.getElementById('modalTitle').textContent = 'Create New User';
        }

        createBtn.addEventListener('click', function() {
            document.getElementById('action').value = 'create';
            document.getElementById('modalTitle').textContent = 'Create New User';
            // make password required for creation
            const pwd = document.getElementById('password');
            pwd.required = true;
            document.querySelector('label[for="password"]').textContent = 'Password *';
            pwd.value = '';
            openModal();
        });

        editBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const userData = JSON.parse(this.dataset.user);
                document.getElementById('user_id').value = userData.user_id;
                document.getElementById('full_name').value = userData.full_name;
                document.getElementById('email').value = userData.email;
                document.getElementById('role_id').value = userData.role_id;
                document.getElementById('program').value = userData.program || '';
                document.getElementById('section').value = userData.section || '';
                document.getElementById('year_level').value = userData.year_level || '';
                document.getElementById('action').value = 'edit';
                document.getElementById('modalTitle').textContent = 'Edit User';
                // make password optional during edit
                const pwd = document.getElementById('password');
                pwd.required = false;
                pwd.value = '';
                document.querySelector('label[for="password"]').textContent = 'Password (leave blank to keep current)';
                openModal();
            });
        });

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);

        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        // Role-based field visibility
        const roleSelect = document.getElementById('role_id');
        const studentFields = document.getElementById('studentFields');

        function toggleStudentFields() {
            if (roleSelect.value == '3') {
                studentFields.style.display = 'block';
            } else {
                studentFields.style.display = 'none';
            }
        }

        roleSelect.addEventListener('change', toggleStudentFields);
        toggleStudentFields(); // Initial check

        // Password visibility toggle
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('passwordToggle');

        passwordToggle.addEventListener('click', function(e) {
            e.preventDefault();
            const type = passwordInput.getAttribute('type');
            if (type === 'password') {
                passwordInput.setAttribute('type', 'text');
                passwordToggle.innerHTML = '<i class="bx bx-show"></i>';
            } else {
                passwordInput.setAttribute('type', 'password');
                passwordToggle.innerHTML = '<i class="bx bx-hide"></i>';
            }
        });
    });
</script>

<div>
    <?php if (!empty($message)): ?>
        <div class="alert-card alert-success">
            <i class='bx bx-check-circle'></i>
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
        <div>
            <h2>User Management</h2>
            <p>Manage system users, roles, and access permissions.</p>
        </div>
        <button class="btn-primary" id="createUserBtn">
            <i class='bx bx-plus'></i>
            Create User
        </button>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <div class="filters-row">
            <div class="filter-group">
                <label class="filter-label">Filter by Role:</label>
                <select class="filter-select" onchange="window.location.href='?page=user_management&role=' + this.value">
                    <option value="0">All Roles</option>
                    <option value="1" <?= $role_filter == 1 ? 'selected' : '' ?>>Faculty</option>
                    <option value="2" <?= $role_filter == 2 ? 'selected' : '' ?>>Registrar</option>
                    <option value="3" <?= $role_filter == 3 ? 'selected' : '' ?>>Student</option>
                    <option value="4" <?= $role_filter == 4 ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="table-card">
        <div class="table-wrap">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($users) > 0): ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div class="user-name"><?= htmlspecialchars($user['full_name'], ENT_QUOTES) ?></div>
                                </td>
                                <td class="user-email"><?= htmlspecialchars($user['email'], ENT_QUOTES) ?></td>
                                <td>
                                    <span class="role-badge role-<?= strtolower($role_labels[$user['role_id']] ?? 'unknown') ?>">
                                        <?= htmlspecialchars($role_labels[$user['role_id']] ?? 'Unknown', ENT_QUOTES) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?= $user['is_active'] ? '' : 'status-inactive' ?>">
                                        <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="user-email"><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn btn-edit edit-btn" data-user='<?= json_encode($user) ?>'>
                                            <i class='bx bx-edit'></i>
                                            Edit
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                            <button type="submit" class="action-btn <?= $user['is_active'] ? 'btn-deactivate' : 'btn-activate' ?>">
                                                <i class='bx bx-<?= $user['is_active'] ? 'x' : 'check' ?>'></i>
                                                <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-message">
                                <i class='bx bx-group'></i>
                                <p>No users found.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- User Modal -->
<div class="modal" id="userModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Create New User</h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" id="userForm">
            <div class="modal-body">
                <input type="hidden" name="action" id="action" value="create">
                <input type="hidden" name="user_id" id="user_id" value="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="full_name">Full Name *</label>
                        <input type="text" class="form-input" id="full_name" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="email">Email *</label>
                        <input type="email" class="form-input" id="email" name="email" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="role_id">Role *</label>
                        <select class="form-input" id="role_id" name="role_id" required>
                            <option value="">Select Role</option>
                            <option value="1">Faculty</option>
                            <option value="2">Registrar</option>
                            <option value="3">Student</option>
                            <option value="4">Admin</option>
                        </select>
                    </div>
                    <div class="form-group password-group" id="password_group">
                        <label class="form-label" for="password">Password *</label>
                        <input type="password" class="form-input" id="password" name="password" required>
                        <button type="button" class="password-toggle" id="passwordToggle">
                            <i class='bx bx-hide'></i>
                        </button>
                    </div>
                </div>

                <div id="studentFields" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="program">Program</label>
                            <input type="text" class="form-input" id="program" name="program">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="section">Section</label>
                            <input type="text" class="form-input" id="section" name="section">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="year_level">Year Level</label>
                        <select class="form-input" id="year_level" name="year_level">
                            <option value="">Select Year</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelBtn">Cancel</button>
                <button type="submit" class="btn-primary">
                    <i class='bx bx-save'></i>
                    Save User
                </button>
            </div>
        </form>
    </div>
</div>