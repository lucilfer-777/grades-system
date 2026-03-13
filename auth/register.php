<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require "../config/db.php";

$errors = [];
$old = ['full_name' => '', 'email' => '', 'role' => 3, 'program' => '', 'section' => '', 'year_level' => ''];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (empty($_POST['csrf_token'])) {
        http_response_code(400);
        die('Missing CSRF token');
    }
    csrf_validate_or_die($_POST['csrf_token']);

    $name  = trim($_POST["full_name"] ?? '');
    $email = trim($_POST["email"] ?? '');
    $pass  = $_POST["password"] ?? '';
    $pass2 = $_POST["confirm_password"] ?? '';
    $role  = intval($_POST["role"] ?? 3);
    $program = trim($_POST["program"] ?? '');
    $section = trim($_POST["section"] ?? '');
    $year_level = intval($_POST["year_level"] ?? 0);

    $old['full_name'] = $name;
    $old['email'] = $email;
    $old['role'] = $role;
    $old['program'] = $program;
    $old['section'] = $section;
    $old['year_level'] = $year_level;

    if ($name === '') $errors[] = 'Full Name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid Email is required.';
    
    // If registering as student, require program, section, and year_level
    if ($role === 3) {
        if ($program === '') $errors[] = 'Program is required for students.';
        if ($section === '') $errors[] = 'Section is required for students.';
        if ($year_level < 1 || $year_level > 3) $errors[] = 'Year Level is required for students.';
    }

    // Password policy: min 8 chars, upper, lower, number, special char
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $pass)) $errors[] = 'Password must include at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $pass)) $errors[] = 'Password must include at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $pass)) $errors[] = 'Password must include at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $pass)) $errors[] = 'Password must include at least one special character.';

    if ($pass !== $pass2) $errors[] = 'Confirm Password does not match.';

    // Check email uniqueness
    if (empty($errors)) {
        $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE email = ?");
        $check->bind_param('s', $email);
        $check->execute();
        $res = $check->get_result();
        $row = $res->fetch_assoc();
        if ($row && intval($row['cnt']) > 0) {
            $errors[] = 'Email is already registered.';
        }
    }

    if (empty($errors)) {
        $passwordHash = password_hash($pass, PASSWORD_BCRYPT);

        if ($role === 3) {
            // Student registration with program, section, and year_level
            $stmt = $conn->prepare(
                "INSERT INTO users (full_name, email, password_hash, role_id, program, section, year_level)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("sssissi", $name, $email, $passwordHash, $role, $program, $section, $year_level);
        } else {
            // Faculty or Registrar registration
            $stmt = $conn->prepare(
                "INSERT INTO users (full_name, email, password_hash, role_id)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("sssi", $name, $email, $passwordHash, $role);
        }
        
        if ($stmt->execute()) {
            header("Location: login.php");
            exit;
        } else {
            $errors[] = 'Database error while creating account.';
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .register-box { width: 100%; max-width: 500px; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2); }
        .register-box h3 { color: #0f246c; font-size: 1.75rem; margin-bottom: 0.5rem; font-weight: 600; }
        .register-box p { color: #64748B; font-size: 0.95rem; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: 500; color: #334155; margin-bottom: 0.5rem; }
        input, select { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; transition: all 0.2s; }
        input:focus, select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .student-fields { border: 1px solid #e2e8f0; border-radius: 6px; padding: 1.5rem; margin-bottom: 1rem; background: #f8fafc; display: none; }
        .student-fields.show { display: block; }
        .student-fields h4 { color: #0f246c; font-size: 1rem; margin-bottom: 1rem; }
        button { width: 100%; padding: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.95rem; transition: background 0.2s; }
        button:hover { background: #2563eb; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem; }
        .error ul { margin-left: 1.5rem; }
        .error li { margin-bottom: 0.5rem; }
        .small-link { display: block; margin-top: 1rem; text-align: center; color: #64748B; font-size: 0.9rem; }
        .small-link a { color: #3b82f6; text-decoration: none; }
        .small-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="register-box">
    <h3>Create Account</h3>
    <p>Join our grades management system</p>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <ul>
            <?php foreach ($errors as $e): ?>
                <li><?php echo htmlspecialchars($e, ENT_QUOTES); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post">
        <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">'; ?>
        
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?php echo htmlspecialchars($old['full_name'], ENT_QUOTES); ?>" required>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars($old['email'], ENT_QUOTES); ?>" required>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
            <small style="color: #64748B; display: block; margin-top: 0.25rem;">Min 8 chars, uppercase, lowercase, number, special char</small>
        </div>

        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>
        </div>

        <div class="form-group">
            <label>Role</label>
            <select name="role" id="role-select" onchange="toggleStudentFields()">
                <option value="3" <?php echo ($old['role']==3)?'selected':''; ?>>Student</option>
                <option value="1" <?php echo ($old['role']==1)?'selected':''; ?>>Faculty</option>
                <option value="2" <?php echo ($old['role']==2)?'selected':''; ?>>Registrar</option>
            </select>
        </div>

        <!-- Student-only fields -->
        <div class="student-fields" id="student-fields" <?php echo ($old['role']==3)?'style="display: block;"':''; ?>>
            <h4>Student Information</h4>
            
            <div class="form-group">
                <label>Program</label>
                <select name="program" id="program-select">
                    <option value="">-- Select Program --</option>
                    <option value="Bachelor of Science in Information Technology" <?php echo ($old['program']=='Bachelor of Science in Information Technology')?'selected':''; ?>>Bachelor of Science in Information Technology</option>
                </select>
            </div>

            <div class="form-group">
                <label>Section</label>
                <select name="section" id="section-select">
                    <option value="">-- Select Section --</option>
                    <option value="BSIT-32011-IM" <?php echo ($old['section']=='BSIT-32011-IM')?'selected':''; ?>>BSIT-32011-IM</option>
                </select>
            </div>

            <div class="form-group">
                <label>Year Level</label>
                <select name="year_level" id="year-select">
                    <option value="">-- Select Year Level --</option>
                    <option value="1" <?php echo ($old['year_level']==1)?'selected':''; ?>>1st Year</option>
                    <option value="2" <?php echo ($old['year_level']==2)?'selected':''; ?>>2nd Year</option>
                    <option value="3" <?php echo ($old['year_level']==3)?'selected':''; ?>>3rd Year</option>
                </select>
            </div>
        </div>

        <button type="submit">Register</button>
    </form>

    <div class="small-link">
        Already have an account? <a href="login.php">Back to Login</a>
    </div>
</div>

<script>
    function toggleStudentFields() {
        const roleSelect = document.getElementById('role-select');
        const studentFields = document.getElementById('student-fields');
        const programSelect = document.getElementById('program-select');
        const sectionSelect = document.getElementById('section-select');
        const yearSelect = document.getElementById('year-select');
        
        if (roleSelect.value === '3') {
            studentFields.classList.add('show');
            programSelect.required = true;
            sectionSelect.required = true;
            yearSelect.required = true;
        } else {
            studentFields.classList.remove('show');
            programSelect.required = false;
            sectionSelect.required = false;
            yearSelect.required = false;
        }
    }
</script>

</body>
</html>