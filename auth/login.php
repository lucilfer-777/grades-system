<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require "../config/db.php";
require "../includes/audit.php";

$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (empty($_POST['csrf_token'])) {
        http_response_code(400);
        die('Missing CSRF token');
    }
    csrf_validate_or_die($_POST['csrf_token']);

    $email = $_POST["email"] ?? '';
    $pass  = $_POST["password"] ?? '';

    $stmt = $conn->prepare(
        "SELECT user_id, password_hash, role_id, is_active FROM users WHERE email = ?"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if ($row['is_active'] == 0) {
            $error = "Your account has been deactivated. Please contact the administrator.";
        } elseif (password_verify($pass, $row['password_hash'])) {
            secure_session_regenerate();
            $_SESSION["user_id"] = $row["user_id"];
            $_SESSION["role_id"] = $row["role_id"];

            logAction($conn, $row["user_id"], "User logged in");

            header("Location: ../index.php");
            exit;
        } else {
            $error = "Invalid credentials. Access denied.";
        }
    } else {
        $error = "Invalid credentials. Access denied.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Information & Enrollment Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=Fraunces:ital,wght@0,300;0,400;0,600;1,300;1,400&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy:         #0f246c;
            --navy-mid:     #1a3a8f;
            --navy-dark:    #091847;
            --blue:         #3B82F6;
            --blue-dark:    #1E40AF;
            --blue-light:   #60A5FA;
            --blue-pale:    #DBEAFE;
            --blue-faint:   #EFF6FF;
            --accent:       #2563EB;
            --white:        #ffffff;
            --bg:           #F0F5FF;
            --rule:         #E2E8F0;
            --ink:          #0F172A;
            --ink-soft:     #374151;
            --ink-muted:    #64748B;
            --ink-faint:    #94A3B8;
            --gold:         #c9a84c;
            --gold-lt:      #e8cc85;
            --error:        #dc2626;
            --shadow-blue:  0 6px 24px rgba(37, 99, 235, 0.22);
            --shadow-md:    0 8px 32px rgba(15, 36, 108, 0.10);
            --transition:   all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
            --radius-sm:    8px;
            --radius-md:    12px;
        }

        html, body { height: 100%; font-family: 'DM Sans', sans-serif; -webkit-font-smoothing: antialiased; }

        body {
            height: 100vh;
            background: linear-gradient(135deg, #c7d9ff 0%, var(--bg) 50%, #f0f5ff 100%);
            display: flex;
            align-items: stretch;
            justify-content: center;
            overflow: hidden;
        }

        .card {
            width: 100%;
            max-width: 100%;
            background: var(--white);
            display: flex;
            min-height: 100vh;
            overflow: hidden;
            animation: cardIn 0.7s cubic-bezier(0.16,1,0.3,1) both;
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(24px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── LEFT PANEL ───────────────────────────── */
        .left {
            position: relative;
            width: 47%;
            flex-shrink: 0;
            overflow: hidden;
            background: var(--bg);
        }

        .chev-1 {
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 100%;
            background: linear-gradient(150deg, var(--navy-dark) 0%, var(--navy-mid) 100%);
            clip-path: polygon(0 0, 82% 0, 100% 50%, 82% 100%, 0 100%);
        }
        .chev-2 {
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 108%;
            background: linear-gradient(140deg, #1a3a8f 0%, var(--accent) 100%);
            clip-path: polygon(0 0, 70% 0, 88% 50%, 70% 100%, 0 100%);
        }
        .chev-dots {
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle, rgba(255,255,255,0.08) 1px, transparent 1px);
            background-size: 20px 20px;
            pointer-events: none;
            z-index: 2;
            clip-path: polygon(0 0, 82% 0, 100% 50%, 82% 100%, 0 100%);
        }
        .chev-ring {
            position: absolute;
            top: 50%; left: 38%;
            transform: translate(-50%, -50%);
            width: 520px; height: 520px;
            border: 1px solid rgba(96,165,250,0.12);
            border-radius: 50%;
            pointer-events: none;
            z-index: 2;
            animation: spinSlow 60s linear infinite;
            clip-path: polygon(0 0, 82% 0, 100% 50%, 82% 100%, 0 100%);
        }
        .chev-ring2 {
            position: absolute;
            top: 50%; left: 38%;
            transform: translate(-50%, -50%);
            width: 340px; height: 340px;
            border: 1px solid rgba(96,165,250,0.08);
            border-radius: 50%;
            pointer-events: none;
            z-index: 2;
            animation: spinSlow 40s linear infinite reverse;
            clip-path: polygon(0 0, 82% 0, 100% 50%, 82% 100%, 0 100%);
        }
        @keyframes spinSlow { to { transform: translate(-50%,-50%) rotate(360deg); } }

        .logo-wrap {
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 74%;
            z-index: 5;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 2rem 1rem 2rem 1.5rem;
            animation: logoIn 0.9s 0.15s cubic-bezier(0.16,1,0.3,1) both;
        }
        @keyframes logoIn {
            from { opacity: 0; transform: scale(0.82); }
            to   { opacity: 1; transform: scale(1); }
        }

        .shield {
            position: relative;
            width: 154px;
            height: 175px;
            filter: drop-shadow(0 12px 32px rgba(0,0,0,0.45));
        }
        .shield svg { width: 100%; height: 100%; }
        .shield-body {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding-bottom: 12px;
            gap: 4px;
        }

        .brand { text-align: center; }
        .brand-name {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--white);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            line-height: 1.1;
            text-shadow: 0 2px 14px rgba(0,0,0,0.4);
        }
        .brand-name span { color: var(--gold-lt); display: block; font-size: 1.1rem; font-weight: 700; }
        .brand-sub {
            font-size: 10px;
            font-weight: 600;
            color: rgba(255,255,255,0.38);
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-top: 6px;
        }

        .system-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--blue-light);
            background: rgba(96,165,250,0.14);
            border: 1px solid rgba(96,165,250,0.28);
            padding: 5px 13px;
            border-radius: 20px;
            margin-top: 4px;
        }
        .pulse-dot {
            width: 6px; height: 6px;
            background: var(--blue-light);
            border-radius: 50%;
            animation: pulse 2.4s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.75); }
        }

        /* ── RIGHT PANEL ──────────────────────────── */
        .right {
            flex: 1;
            padding: 3rem 3.5rem 2.5rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
            position: relative;
            background: var(--bg);
        }

        .right-inner {
            width: 100%;
            max-width: 420px;
        }

        .right::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--gold), var(--gold-lt), var(--accent));
        }

        .page-hd {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--blue-pale);
        }
        .page-hd-top {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: var(--accent);
            letter-spacing: 0.12em;
            text-transform: uppercase;
            line-height: 1;
        }
        .page-hd h1 {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: clamp(1.8rem, 2.8vw, 2.5rem);
            font-weight: 800;
            color: var(--ink);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            line-height: 1.05;
        }
        .page-hd h1 span { color: var(--accent); }

        .error-alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-left: 3px solid var(--error);
            border-radius: 10px;
            padding: 0.875rem 1rem;
            margin-bottom: 1.25rem;
            animation: shakeIn 0.5s ease;
        }
        @keyframes shakeIn {
            0%,100% { transform: translateX(0); }
            20%,60% { transform: translateX(-5px); }
            40%,80% { transform: translateX(5px); }
        }
        .error-alert i { color: var(--error); font-size: 1rem; margin-top: 1px; flex-shrink: 0; }
        .error-alert span { font-size: 0.8125rem; font-weight: 500; color: #7f1d1d; line-height: 1.5; }

        .fgrid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem 1.25rem;
        }
        .s2 { grid-column: 1 / -1; }

        .fld { display: flex; flex-direction: column; gap: 4px; }
        .fld label {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.10em;
            text-transform: uppercase;
            color: var(--blue-dark);
        }
        .fld label i { font-size: 0.6rem; color: var(--accent); }

        .iw {
            position: relative;
            display: flex;
            align-items: center;
        }

        .fi {
            width: 100%;
            padding: 0.78rem 1rem 0.78rem 2.4rem;
            border: 1.5px solid rgba(59, 130, 246, 0.25);
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9375rem;
            font-weight: 450;
            color: var(--ink);
            background: var(--white);
            outline: none;
            transition: var(--transition);
            appearance: none;
            position: relative;
            z-index: 1;
        }
        .fi::placeholder { color: var(--ink-faint); font-weight: 400; }
        .fi:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }
        .fi.err { border-color: #fca5a5; background: #fff8f8; }

        .ii {
            position: absolute;
            left: 0.7rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1rem;
            color: var(--blue-light);
            pointer-events: none;
            transition: color 0.17s;
            z-index: 2;
        }
        .iw:focus-within .ii { color: var(--accent); }

        .peye {
            position: absolute;
            right: 0.7rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1rem;
            color: var(--ink-faint);
            cursor: pointer;
            z-index: 3;
            transition: color 0.17s;
            background: none;
            border: none;
            padding: 4px;
        }
        .peye:hover { color: var(--accent); }

        .ff {
            margin-top: 0.875rem;
            font-size: 0.8125rem;
            color: var(--ink-muted);
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .ff a { color: var(--accent); font-weight: 600; text-decoration: none; }
        .ff a:hover { text-decoration: underline; }

        .sbtn {
            margin-top: 1.1rem;
            width: 100%;
            padding: 0.9rem;
            background: linear-gradient(135deg, var(--navy-dark) 0%, var(--accent) 100%);
            color: var(--white);
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            box-shadow: var(--shadow-blue);
        }
        .sbtn::before {
            content:'';
            position:absolute;
            top:0; left:-100%;
            width:100%; height:100%;
            background:linear-gradient(90deg,transparent,rgba(255,255,255,0.14),transparent);
            transition: left 0.5s;
        }
        .sbtn:hover { transform:translateY(-2px); box-shadow: 0 10px 28px rgba(37,99,235,0.32); }
        .sbtn:hover::before { left:100%; }
        .sbtn:active { transform:translateY(0); }
        .sbi { display:flex; align-items:center; justify-content:center; gap:8px; }

        .security-notice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: var(--blue-faint);
            border: 1px solid var(--blue-pale);
            border-radius: 10px;
            padding: 0.875rem 1rem;
            margin-top: 1rem;
        }
        .security-icon {
            width: 28px; height: 28px;
            background: linear-gradient(135deg, var(--accent), var(--blue-dark));
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .security-icon i { font-size: 0.8rem; color: var(--white); }
        .security-text { font-size: 0.6875rem; color: var(--ink-soft); line-height: 1.55; }
        .security-text strong { display: block; font-weight: 700; color: var(--blue-dark); margin-bottom: 2px; font-size: 0.6875rem; }

        .page-footer {
            margin-top: 1.25rem;
            font-size: 0.65rem;
            color: var(--ink-faint);
            text-align: center;
        }
        .page-footer a { color: var(--accent); text-decoration: none; }
        .page-footer a:hover { text-decoration: underline; }

        @media (max-width: 700px) {
            body { overflow-y: auto; }
            .card { flex-direction: column; min-height: 100vh; }
            .left { width: 100%; height: 220px; flex-shrink: 0; }
            .chev-1 { clip-path: polygon(0 0, 100% 0, 100% 80%, 50% 100%, 0 80%); }
            .chev-2 { clip-path: polygon(0 0, 100% 0, 100% 68%, 50% 90%, 0 68%); }
            .chev-dots { clip-path: polygon(0 0, 100% 0, 100% 80%, 50% 100%, 0 80%); }
            .chev-ring, .chev-ring2 { clip-path: polygon(0 0, 100% 0, 100% 80%, 50% 100%, 0 80%); }
            .logo-wrap { width:100%; flex-direction:row; padding:1.5rem 2rem; gap:1.25rem; }
            .brand { text-align:left; }
            .shield { width:90px; height:102px; }
            .right { padding:1.75rem 1.5rem; align-items: stretch; }
            .right-inner { max-width: 100%; }
            .fgrid { grid-template-columns:1fr; }
            .s2 { grid-column:1; }
        }
    </style>
</head>
<body>

<div class="card">

    <!-- ── LEFT PANEL ─────────────────────────── -->
    <div class="left">
        <div class="chev-1"></div>
        <div class="chev-2"></div>
        <div class="chev-dots"></div>
        <div class="chev-ring"></div>
        <div class="chev-ring2"></div>

        <div class="logo-wrap">
            <div class="shield">
                <div class="shield-body">
                    <!-- place your logo image here -->
                    <img src="../images/siems.png" alt="SIEMS Logo" style="width:300px; height:auto; margin-bottom: -50px; object-fit:contain; filter:drop-shadow(0 2px 8px rgba(0,0,0,0.4));">
                </div>
            </div>

            <div class="brand">
                <div class="brand-name">
                    <span>SIEMS</span>
                </div>
                <div class="brand-sub">Enrollment Management System</div>
                <div class="system-label" style="margin-top:10px;">
                    <span class="pulse-dot"></span>
                    System Online
                </div>
            </div>
        </div>
    </div>

    <!-- ── RIGHT PANEL ────────────────────────── -->
    <div class="right">
    <div class="right-inner">

        <div class="page-hd">
            <div class="page-hd-top">SIEMS — Operations Portal</div>
            <h1>Portal <span>Sign In</span></h1>
        </div>

        <!-- Error alert (shown when there's an error) -->
        <?php if (!empty($error)): ?>
            <div class="error-alert">
                <i class='bx bx-error-circle'></i>
                <span><?php echo htmlspecialchars($error, ENT_QUOTES); ?></span>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <div class="fgrid">

                <div class="fld s2">
                    <label><i class='bx bx-circle'></i> Email Address <span style="color:var(--accent)">*</span></label>
                    <div class="iw">
                        <i class='bx bx-envelope ii'></i>
                        <input
                            type="email"
                            class="fi"
                            name="email"
                            id="email"
                            placeholder="Enter your email address"
                            autocomplete="email"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="fld s2">
                    <label><i class='bx bx-circle'></i> Password <span style="color:var(--accent)">*</span></label>
                    <div class="iw">
                        <i class='bx bx-lock ii'></i>
                        <input
                            type="password"
                            class="fi"
                            name="password"
                            id="pw1"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            style="padding-right:2.4rem;"
                            required
                        >
                        <button type="button" class="peye" id="tp1" aria-label="Toggle password">
                            <i class='bx bx-show' id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

            </div>

            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">

            <button type="submit" class="sbtn" id="submitBtn">
                <span class="sbi" id="btnInner">
                    <i class='bx bx-log-in'></i> Sign In to Portal
                </span>
            </button>
        </form>

        <div class="security-notice">
            <div class="security-icon"><i class='bx bxs-shield-alt-2'></i></div>
            <div class="security-text">
                <strong>Authorized Personnel Only</strong>
                This system is for SIEMS-registered users only. All access attempts are logged and monitored by the system administrator.
            </div>
        </div>

        <div class="page-footer">
            SIEMS &mdash; Student Information &amp; Enrollment Integrated Management System
        </div>

    </div>
    </div>
</div>

<script>
    // Password toggle
    const tp1 = document.getElementById('tp1');
    const pw1 = document.getElementById('pw1');
    const eye = document.getElementById('eyeIcon');

    if (tp1 && pw1) {
        tp1.addEventListener('click', function (e) {
            e.preventDefault();
            const isHidden = pw1.type === 'password';
            pw1.type = isHidden ? 'text' : 'password';
            eye.className = isHidden ? 'bx bx-hide' : 'bx bx-show';
        });
    }

    const emailField = document.getElementById('email');

    // Remove error styling on input
    document.querySelectorAll('.fi').forEach(inp => {
        inp.addEventListener('input', function () {
            this.classList.remove('err');
        });
    });

    // Focus on email field
    if (emailField) emailField.focus();
</script>
</body>
</html>