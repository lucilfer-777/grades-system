<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/rbac.php';

// Check if user is authenticated
if (!isset($_SESSION["user_id"], $_SESSION["role_id"])) {
    http_response_code(401);
    die("Unauthorized access");
}

$role_id = (int)$_SESSION["role_id"];
$user_id = (int)$_SESSION["user_id"];

// Fetch user's full name from database
$user_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$user_stmt->bind_param('i', $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_name = "User";
if ($user_result && $user_row = $user_result->fetch_assoc()) {
    $user_name = $user_row['full_name'] ?? "User";
}

// Generate user initials from first and last name
$name_parts = array_filter(explode(' ', trim($user_name)));
$initials = '';
if (count($name_parts) >= 2) {
    $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[1], 0, 1));
} else {
    $initials = strtoupper(substr($name_parts[0] ?? 'U', 0, 1));
}

// Define menu structure per role
$menus = [
    // Faculty (role_id = 1)
    1 => [
        ['is_section' => true, 'label' => 'FACULTY DASHBOARD'],
        ['icon' => 'bx-tachometer', 'label' => 'Dashboard', 'sublabel' => 'Overview', 'link' => '?page=dashboard'],
        ['is_section' => true, 'label' => 'MODULES'],
        ['icon' => 'bx-pencil', 'label' => 'Encode Grades', 'sublabel' => 'Encode Grades', 'link' => '?page=submit_grades'],
        ['icon' => 'bx-show', 'label' => 'Submitted Grades', 'sublabel' => 'Review Encoded Grades', 'link' => '?page=view_grades'],
        ['icon' => 'bx-edit', 'label' => 'Grade Correction', 'sublabel' => 'File Corrections', 'link' => '?page=request_correction'],
    ],
    // Registrar (role_id = 2)
    2 => [
        ['is_section' => true, 'label' => 'REGISTRAR DASHBOARD'],
        ['icon' => 'bx-tachometer', 'label' => 'Dashboard', 'sublabel' => 'Overview', 'link' => '?page=dashboard'],
        ['is_section' => true, 'label' => 'MODULES'],
        ['icon' => 'bx-check-shield', 'label' => 'Enrollment Requests', 'sublabel' => 'Approve or Reject Requests', 'link' => '?page=pending_enrollments'],
        ['icon' => 'bx-list-check', 'label' => 'Grade Submissions', 'sublabel' => 'Verify Grades', 'link' => '?page=pending_grades'],
        ['icon' => 'bx-comment-add', 'label' => 'Correction Requests', 'sublabel' => 'Review Corrections', 'link' => '?page=pending_corrections'],
    ],
    // Student (role_id = 3)
    3 => [
        ['is_section' => true, 'label' => 'STUDENT DASHBOARD'],
        ['icon' => 'bx-tachometer', 'label' => 'Dashboard', 'sublabel' => 'Overview', 'link' => '?page=dashboard'],
        ['is_section' => true, 'label' => 'ENROLLMENT'],
        ['icon' => 'bx-user-plus', 'label' => 'Request Enrollment', 'sublabel' => 'Enroll Subjects', 'link' => '?page=request_enrollment'],
        ['icon' => 'bx-check-circle', 'label' => 'Enrollment Status', 'sublabel' => 'View Requests', 'link' => '?page=enrollment_status'],
        ['is_section' => true, 'label' => 'MODULES'],
        ['icon' => 'bx-book-open', 'label' => 'View Grades', 'sublabel' => 'Your Grades', 'link' => '?page=view_grades'],
    ],
    // Admin (role_id = 4)
    4 => [
        ['is_section' => true, 'label' => 'ADMIN DASHBOARD'],
        ['icon' => 'bx-tachometer', 'label' => 'Dashboard', 'sublabel' => 'Overview', 'link' => '?page=dashboard'],
        ['is_section' => true, 'label' => 'MODULES'],
        ['icon' => 'bx-group', 'label' => 'User Management', 'sublabel' => 'Manage Users', 'link' => '?page=user_management'],
        ['icon' => 'bx-bar-chart', 'label' => 'Grade Reports', 'sublabel' => 'View Reports', 'link' => '?page=grade_reports'],
        ['icon' => 'bx-file-find', 'label' => 'Audit Logs', 'sublabel' => 'View Logs', 'link' => '?page=audit_logs'],
    ],
];

// Get role label
$role_labels = [1 => 'Faculty', 2 => 'Registrar', 3 => 'Student', 4 => 'Admin'];
$role_label = $role_labels[$role_id] ?? 'User';
$role_icon = [1 => 'bx-chalkboard', 2 => 'bx-shield', 3 => 'bx-user', 4 => 'bx-crown'][($role_id)] ?? 'bx-user';

// Get current page
$current_page = $_GET['page'] ?? 'dashboard';

// Determine project base path for absolute links
$base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grades &amp; Assessment Management Subsystem</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
:root {
    /* Primary Colors */
    --navy:             #0f246c;
    --navy-dark:        #0a1a50;
    --blue-500:         #3B82F6;
    --blue-600:         #2563EB;
    --blue-700:         #1E40AF;
    --blue-light:       #93C5FD;
    
    /* Semantic Colors */
    --success:          #22c55e;
    --success-light:    #86efac;
    --error:            #ef4444;
    
    /* Background & Surface */
    --bg:               #F0F4FF;
    --surface:          #FFFFFF;
    --surface-dark:     #f8fafc;
    
    /* Text & Border */
    --border:           rgba(59, 130, 246, 0.14);
    --text-900:         #0F1E4A;
    --text-600:         #4B5E8A;
    --text-400:         #8EA0C4;
    --text-light:       #CBD5E1;
    --text-primary-light: #EFF6FF;
    --text-secondary-light: #CBD5E1;

    /* Shadows */
    --shadow-sm:        0 2px 8px rgba(15, 36, 108, 0.08);
    --shadow-md:        0 6px 20px rgba(15, 36, 108, 0.12);

    /* Border */
    --sidebar-border:   rgba(59, 130, 246, 0.25);

    /* Radii */
    --r-sm:             8px;
    --r-md:             12px;
    --r-lg:             16px;

    /* Dimensions */
    --sidebar-w:        300px;
    --topbar-h:         64px;

    /* Transitions */
    --transition:       all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ============================================================
   2. RESET & BASE
============================================================ */
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html { scroll-behavior: smooth; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text-900);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
}

/* ============================================================
   3. LAYOUT
============================================================ */
.page-wrapper {
    margin-left: var(--sidebar-w);
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

.page-wrapper.expanded {
    margin-left: 0;
}

/* ============================================================
   4. SIDEBAR
============================================================ */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    width: var(--sidebar-w);
    background: var(--navy);
    border-right: 1px solid rgba(59, 130, 246, 0.25);
    overflow-y: auto;
    overflow-x: hidden;
    z-index: 1000;
    transition: var(--transition);
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.4);
}

.sidebar.collapsed {
    transform: translateX(-100%);
}

.sidebar::-webkit-scrollbar       { width: 4px; }
.sidebar::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.15); border-radius: 10px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 10px; }

/* ============================================================
   5. SIDEBAR HEADER
============================================================ */
.sidebar-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--sidebar-border);
    position: relative;
    flex-shrink: 0;
}

.sidebar-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.08) 0%, transparent 70%);
    animation: rotate 20s linear infinite;
}

.brand {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    position: relative;
    z-index: 1;
}

.logo {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--blue-600), var(--blue-500));
    border-radius: 12px;
    border: 2px solid rgba(59, 130, 246, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    flex-shrink: 0;
    box-shadow: 0 2px 12px rgba(59, 130, 246, 0.3);
    animation: logoFloat 3s ease-in-out infinite;
}

.brand-info {
    flex: 1;
    min-width: 0;
}

.brand-info h1 {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-primary-light);
    margin: 0;
    letter-spacing: 0.5px;
    line-height: 1.2;
}

.brand-info p {
    font-size: 0.65rem;
    color: var(--text-secondary-light);
    margin: 2px 0 0 0;
    font-weight: 500;
}

.system-status {
    background: rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 0.75rem 1rem;
    border: 1px solid rgba(96, 165, 250, 0.5);
    position: relative;
    z-index: 1;
    animation: statusGlow 2s ease-in-out infinite;
}

.status-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.status-left {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.status-indicator {
    width: 8px;
    height: 8px;
    background: var(--success);
    border-radius: 50%;
    animation: pulse 2s infinite;
    box-shadow: 0 0 8px rgba(34, 197, 94, 0.5);
}

.status-text {
    font-size: 0.75rem;
    color: var(--text-primary-light);
    font-weight: 500;
}

/* ============================================================
   6. NAVIGATION
============================================================ */
.nav {
    flex: 1;
    padding: 1.5rem 1rem;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.section-title {
    font-size: 0.65rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.55);
    text-transform: uppercase;
    letter-spacing: 1.2px;
    padding: 1.5rem 1rem 0.75rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    position: relative;
}

.nav > .section-title:first-of-type {
    margin-top: 0;
}

.nav > .section-title:not(:first-of-type) {
    margin-top: 0.5rem;
}

.section-title::before {
    content: '';
    width: 8px;
    height: 8px;
    background: var(--blue-600);
    border-radius: 50%;
    box-shadow: 0 0 12px var(--blue-600);
    animation: pulse 2s infinite;
    flex-shrink: 0;
}

.section-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.3), transparent);
    margin-left: 0.5rem;
}

.nav ul {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.nav ul li {
    position: relative;
}

.nav ul li a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    color: var(--text-primary-light);
    text-decoration: none;
    border-radius: 12px;
    transition: var(--transition);
    font-size: 0.9rem;
    font-weight: 500;
    position: relative;
    overflow: hidden;
    cursor: pointer;
    border: 1px solid transparent;
}

.nav ul li a::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    height: 100%;
    width: 4px;
    background: linear-gradient(180deg, var(--blue-600), var(--blue-light));
    transform: scaleY(0);
    transition: var(--transition);
    box-shadow: 0 0 15px var(--blue-600);
}
.nav ul li a:hover {
    background: rgba(59, 130, 246, 0.15);
    color: white;
    transform: translateX(8px);
    border-color: rgba(59, 130, 246, 0.25);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1);
}

.nav ul li a:hover::before {
    transform: scaleY(1);
}

.nav ul li a:hover .icon-wrapper {
    background: rgba(59, 130, 246, 0.25);
    transform: scale(1.15) rotate(8deg);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

.nav ul li a.active {
    background: rgba(59, 130, 246, 0.25);
    color: white;
    font-weight: 600;
    border: 1px solid rgba(59, 130, 246, 0.25);
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

.nav ul li a.active::before {
    transform: scaleY(1);
}

.nav ul li a.active .icon-wrapper {
    background: rgba(59, 130, 246, 0.3);
    box-shadow: 0 0 15px rgba(59, 130, 246, 0.5);
}

.left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex: 1;
    min-width: 0;
}

.icon-wrapper {
    width: 36px;
    height: 36px;
    background: rgba(59, 130, 246, 0.15);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--transition);
    flex-shrink: 0;
}

.icon {
    font-size: 1.2rem;
    transition: var(--transition);
    color: var(--blue-light);
}

.nav-label {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
    flex: 1;
}

.nav-label .main-text {
    font-size: 0.9rem;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.nav-label .sub-text {
    font-size: 0.7rem;
    opacity: 0.7;
    font-weight: 400;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ============================================================
   7. SUBMENU
============================================================ */
.sub-menu {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 0 0 12px 12px;
    position: relative;
    opacity: 0;
    margin-top: 0.25rem;
}

.nav ul li.open .sub-menu {
    max-height: 600px;
    padding: 0.5rem 0 0.5rem 0.5rem;
    opacity: 1;
}

.sub-menu::before {
    content: '';
    position: absolute;
    left: 1.3rem;
    top: 0.5rem;
    bottom: 0.5rem;
    width: 2px;
    background: linear-gradient(180deg, var(--blue-600), rgba(59, 130, 246, 0.1));
    border-radius: 2px;
}

.sub-menu li {
    position: relative;
    animation: slideIn 0.3s ease forwards;
    opacity: 0;
}

.nav ul li.open .sub-menu li:nth-child(1) {
    animation-delay: 0.05s;
}

.nav ul li.open .sub-menu li:nth-child(2) {
    animation-delay: 0.1s;
}

.nav ul li.open .sub-menu li:nth-child(3) {
    animation-delay: 0.15s;
}

.nav ul li.open .sub-menu li:nth-child(4) {
    animation-delay: 0.2s;
}

.nav ul li.open .sub-menu li:nth-child(5) {
    animation-delay: 0.25s;
}

.sub-menu li::before {
    content: '';
    position: absolute;
    left: 1rem;
    top: 50%;
    width: 1rem;
    height: 2px;
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.5), rgba(59, 130, 246, 0.2));
    pointer-events: none;
    transition: var(--transition);
}

.sub-menu li:hover::before {
    width: 1.25rem;
    background: linear-gradient(90deg, var(--blue-600), rgba(59, 130, 246, 0.5));
}

.sub-menu li a {
    padding: 0.65rem 0.75rem 0.65rem 2.5rem;
    font-size: 0.8rem;
    font-weight: 400;
    border-radius: 8px;
    margin: 0 0.5rem 0.25rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    justify-content: flex-start;
    transition: var(--transition);
}

.sub-menu li a:hover {
    transform: translateX(4px);
    background: rgba(59, 130, 246, 0.2);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
}

.bx-chevron-down {
    font-size: 1.2rem;
    transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    color: var(--blue-600);
    flex-shrink: 0;
}

.nav ul li.open > a .bx-chevron-down {
    transform: rotate(180deg);
    filter: drop-shadow(0 0 6px var(--blue-600));
}

/* ============================================================
   8. SIDEBAR FOOTER
============================================================ */
.sidebar-footer {
    margin: 1rem;
    padding: 0;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid var(--sidebar-border);
    border-radius: 16px;
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(10px);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    flex-shrink: 0;
    margin-top: auto;
}

.sidebar-footer::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--blue-500), var(--blue-600), var(--blue-light), var(--blue-500));
    background-size: 200% 100%;
    animation: gradientShift 3s linear infinite;
}

.footer-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(59, 130, 246, 0.05));
}

.footer-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--blue-500), var(--blue-700));
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.4);
    flex-shrink: 0;
}

.footer-icon i {
    font-size: 1.5rem;
    color: #fff;
}

.footer-content {
    flex: 1;
    min-width: 0;
}

.footer-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary-light);
    margin-bottom: 0.25rem;
}

.footer-subtitle {
    font-size: 0.75rem;
    color: var(--text-secondary-light);
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.footer-subtitle::before {
    content: '';
    width: 6px;
    height: 6px;
    background: var(--success);
    border-radius: 50%;
    animation: pulse 2s infinite;
    flex-shrink: 0;
}

.footer-divider {
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--sidebar-border), transparent);
}

.footer-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    background: rgba(0, 0, 0, 0.2);
}

.version {
    font-size: 0.75rem;
    color: var(--text-secondary-light);
    font-weight: 700;
    background: rgba(59, 130, 246, 0.15);
    padding: 0.35rem 0.75rem;
    border-radius: 8px;
    border: 1px solid rgba(59, 130, 246, 0.3);
}

.status-online {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: var(--blue-500);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-online::before {
    content: '';
    width: 8px;
    height: 8px;
    background: var(--blue-500);
    border-radius: 50%;
    animation: pulse-ring 2s infinite;
    flex-shrink: 0;
}

/* ============================================================
   9. TOP NAVBAR
============================================================ */
.top-navbar {
    width: 100%;
    height: var(--topbar-h);
    background: var(--surface);
    position: relative;
    z-index: 999;
    box-shadow: 0 1px 0 var(--border), 0 2px 12px rgba(15, 36, 108, 0.06);
    flex-shrink: 0;
}

.navbar-container {
    max-width: 100%;
    padding: 0 2rem;
    height: 100%;
    overflow: visible;
    display: flex;
    align-items: center;
}

.navbar-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    height: 100%;
    width: 100%;
    overflow: visible;
}

.navbar-left {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    flex: 1;
}

.navbar-right {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    overflow: visible;
}

/* ============================================================
   10. BUTTONS & CONTROLS
============================================================ */
.toggle-btn {
    background: transparent;
    border: 1px solid var(--border);
    width: 40px;
    height: 40px;
    border-radius: var(--r-sm);
    color: var(--text-600);
    font-size: 1.4rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--transition);
}

.toggle-btn:hover {
    background: #EEF2FF;
    color: var(--navy);
    border-color: var(--blue-600);
}

.icon-btn {
    background: transparent;
    border: none;
    width: 40px;
    height: 40px;
    border-radius: var(--r-sm);
    color: var(--text-600);
    font-size: 1.3rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--transition);
    position: relative;
}

.icon-btn:hover {
    background: #EEF2FF;
    color: var(--navy);
}

.badge-dot {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 7px;
    height: 7px;
    background: var(--error);
    border-radius: 50%;
    border: 2px solid var(--surface);
    animation: pulse-ring 2s infinite;
}

/* ============================================================
   11. TIME DISPLAY
============================================================ */
.time-display {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.875rem;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 100px;
    color: var(--text-600);
    font-size: 0.85rem;
    font-weight: 500;
    transition: var(--transition);
    box-shadow: inset 0 1px 2px rgba(59, 130, 246, 0.05);
    white-space: nowrap;
}

.time-display:hover {
    background: #F9FBFF;
    border-color: var(--blue-600);
    box-shadow: inset 0 1px 2px rgba(59, 130, 246, 0.1), 0 2px 8px rgba(59, 130, 246, 0.08);
}

.date-separator {
    color: var(--text-400);
    font-size: 0.65rem;
    margin: 0 0.25rem;
}

/* ============================================================
   12. PROFILE DROPDOWN
============================================================ */
.profile-wrapper {
    position: relative;
    z-index: 1000;
}

.profile-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--navy), var(--blue-600));
    color: #fff;
    font-size: 0.8rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border: 2px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 2px 10px rgba(15, 36, 108, 0.3);
    transition: var(--transition);
    user-select: none;
}

.profile-avatar:hover {
    transform: scale(1.08);
}

.profile-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    width: 250px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    box-shadow: 0 12px 40px rgba(15, 36, 108, 0.14);
    z-index: 99999;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-8px) scale(0.97);
    transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
}

.profile-dropdown.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0) scale(1);
}

.dropdown-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: linear-gradient(135deg, #EEF2FF, var(--surface));

}

.dropdown-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--navy), var(--blue-600));
    color: #fff;
    font-size: 0.85rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(15, 36, 108, 0.2);
    text-transform: uppercase;
}

.dropdown-name {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-900);
    line-height: 1.2;
}

.dropdown-email {
    font-size: 0.7rem;
    color: var(--text-400);
    margin-top: 2px;
    font-weight: 400;
}

.dropdown-role {
    display: inline-block;
    margin-top: 6px;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--blue-700);
    background: #EEF2FF;
    border: 1px solid rgba(59, 130, 246, 0.25);
    padding: 3px 10px;
    border-radius: 20px;
    box-shadow: inset 0 1px 2px rgba(59, 130, 246, 0.1);
}

.dropdown-divider {
    height: 1px;
    background: var(--border);
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text-900);
    text-decoration: none;
    transition: var(--transition);
}

.dropdown-item i {
    font-size: 1.1rem;
    color: var(--text-400);
    transition: var(--transition);
}

.dropdown-item:hover {
    background: #EEF2FF;
    color: var(--blue-600);
}

.dropdown-item:hover i {
    color: var(--blue-600);
}

.dropdown-logout {
    color: var(--error);
}

.dropdown-logout i {
    color: var(--error);
}

.dropdown-logout:hover {
    background: #fff5f5;
}

/* ============================================================
   13. MAIN CONTENT
============================================================ */
.main-content {
    padding: 2rem;
    flex: 1;
}

/* ============================================================
   14. OVERLAY
============================================================ */
.overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 998;
    display: none;
    opacity: 0;
    transition: opacity 0.3s;
}

.overlay.show {
    display: block;
    opacity: 1;
}

/* ============================================================
   15. ANIMATIONS & KEYFRAMES
============================================================ */
@keyframes rotate {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

@keyframes logoFloat {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(-5px); }
}

@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.6; transform: scale(0.95); }
}

@keyframes pulse-ring {
    0%   { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
    70%  { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
    100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
}

@keyframes statusGlow {
    0%, 100% { box-shadow: 0 0 20px rgba(59, 130, 246, 0.3), inset 0 0 10px rgba(59, 130, 246, 0.1); }
    50%       { box-shadow: 0 0 30px rgba(59, 130, 246, 0.5), inset 0 0 20px rgba(59, 130, 246, 0.2); }
}

@keyframes gradientShift {
    0%   { background-position: 0% 50%; }
    100% { background-position: 200% 50%; }
}

@keyframes slideIn {
    from { opacity: 0; transform: translateX(-10px); }
    to   { opacity: 1; transform: translateX(0); }
}

/* ============================================================
   16. RESPONSIVE BREAKPOINTS
============================================================ */
@media (max-width: 768px) {
    :root {
        --sidebar-w: 280px;
    }

    .sidebar {
        transform: translateX(-100%);
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .page-wrapper {
        margin-left: 0;
    }

    .navbar-container {
        padding: 0 1rem;
    }

    .main-content {
        padding: 1rem;
    }

    .time-display {
        display: none;
    }
}

@media (max-width: 480px) {
    .navbar-right {
        gap: 0.5rem;
    }

    .nav-label .main-text,
    .nav-label .sub-text {
        font-size: 0.85rem;
    }

    .sidebar-footer {
        margin: 0.75rem;
        padding: 0;
    }
}

.badge-dot {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 7px;
    height: 7px;
    background: #ef4444;
    border-radius: 50%;
    border: 2px solid var(--surface);
    animation: pulse-ring 2s infinite;
}

/* 5.3 Time Display */
.time-display {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.875rem;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 100px;
    color: var(--text-600);
    font-size: 0.85rem;
    font-weight: 500;
    transition: var(--transition);
    box-shadow: inset 0 1px 2px rgba(59, 130, 246, 0.05);
}

.time-display:hover {
    background: #F9FBFF;
    border-color: var(--blue-600);
    box-shadow: inset 0 1px 2px rgba(59, 130, 246, 0.1), 0 2px 8px rgba(59, 130, 246, 0.08);
}

.date-separator {
    color: var(--text-400);
    font-size: 0.65rem;
    margin: 0 0.25rem;
}

/* 5.4 Profile & Dropdown */
.profile-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    width: 250px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    box-shadow: 0 12px 40px rgba(15, 36, 108, 0.14);
    z-index: 99999;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-8px) scale(0.97);
    transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
}

.profile-dropdown.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0) scale(1);
}

.dropdown-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: linear-gradient(135deg, #EEF2FF, var(--surface));

}

.dropdown-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--navy), var(--blue-600));
    color: #fff;
    font-size: 0.85rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(15, 36, 108, 0.2);
    text-transform: uppercase;
}

.dropdown-name  { 
    font-size: 0.9rem; 
    font-weight: 700; 
    color: var(--text-900);
    line-height: 1.2;
}

.dropdown-email { 
    font-size: 0.7rem; 
    color: var(--text-400); 
    margin-top: 2px;
    font-weight: 400;
}

.dropdown-role {
    display: inline-block;
    margin-top: 6px;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--blue-700);
    background: #EEF2FF;
    border: 1px solid rgba(59, 130, 246, 0.25);
    padding: 3px 10px;
    border-radius: 20px;
    box-shadow: inset 0 1px 2px rgba(59, 130, 246, 0.1);
}

.dropdown-divider { height: 1px; background: var(--border); }

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text-900);
    text-decoration: none;
    transition: var(--transition);
}

.dropdown-item i           { font-size: 1.1rem; color: var(--text-400); transition: var(--transition); }
.dropdown-item:hover       { background: #EEF2FF; color: var(--blue-600); }
.dropdown-item:hover i     { color: var(--blue-600); }
.dropdown-logout           { color: #dc2626; }
.dropdown-logout i         { color: #dc2626; }
.dropdown-logout:hover     { background: #fff5f5; }

/* ============================================================
   6. ANIMATIONS & KEYFRAMES
============================================================ */
@keyframes rotate {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

@keyframes logoFloat {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(-5px); }
}

@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.6; transform: scale(0.95); }
}

@keyframes pulse-ring {
    0%   { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
    70%  { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
    100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
}

@keyframes statusGlow {
    0%, 100% { box-shadow: 0 0 20px rgba(59, 130, 246, 0.3), inset 0 0 10px rgba(59, 130, 246, 0.1); }
    50%       { box-shadow: 0 0 30px rgba(59, 130, 246, 0.5), inset 0 0 20px rgba(59, 130, 246, 0.2); }
}

@keyframes gradientShift {
    0%   { background-position: 0% 50%; }
    100% { background-position: 200% 50%; }
}

@keyframes slideIn {
    from { opacity: 0; transform: translateX(-10px); }
    to   { opacity: 1; transform: translateX(0); }
}

/* ============================================================
   7. RESPONSIVE BREAKPOINTS
============================================================ */
@media (max-width: 768px) {
    .sidebar            { transform: translateX(-100%); }
    .sidebar.show       { transform: translateX(0); }
    .page-wrapper       { margin-left: 0; }
    .navbar-container   { padding: 0 1rem; }
    .main-content       { padding: 1rem; }
}
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 0 0 12px 12px;
            position: relative;
            opacity: 0;
            margin-top: 0.25rem;
        }

        .nav ul li.open .sub-menu {
            max-height: 600px;
            padding: 0.5rem 0 0.5rem 0.5rem;
            opacity: 1;
        }

        .sub-menu::before {
            content: '';
            position: absolute;
            left: 1.3rem;
            top: 0.5rem;
            bottom: 0.5rem;
            width: 2px;
            background: linear-gradient(180deg, var(--accent-color), rgba(59, 130, 246, 0.1));
            border-radius: 2px;
        }

        .sub-menu li {
            position: relative;
            animation: slideIn 0.3s ease forwards;
            opacity: 0;
        }

        .nav ul li.open .sub-menu li:nth-child(1) { animation-delay: 0.05s; }
        .nav ul li.open .sub-menu li:nth-child(2) { animation-delay: 0.1s; }
        .nav ul li.open .sub-menu li:nth-child(3) { animation-delay: 0.15s; }
        .nav ul li.open .sub-menu li:nth-child(4) { animation-delay: 0.2s; }
        .nav ul li.open .sub-menu li:nth-child(5) { animation-delay: 0.25s; }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .sub-menu li::before {
            content: '';
            position: absolute;
            left: 1rem;
            top: 50%;
            width: 1rem;
            height: 2px;
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.5), rgba(59, 130, 246, 0.2));
            transition: var(--transition);
            pointer-events: none;
        }

        .sub-menu li:hover::before {
            width: 1.25rem;
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.7), rgba(59, 130, 246, 0.5));
        }

        .sub-menu li a {
            padding: 0.65rem 0.75rem 0.65rem 2.5rem;
            font-size: 0.8rem;
            font-weight: 400;
            border-radius: 8px;
            margin: 0 0.5rem 0.25rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .sub-menu li a:hover {
            transform: translateX(4px);
            background: rgba(59, 130, 246, 0.2);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
        }
        .bx-chevron-down {
            font-size: 1.2rem;
            transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            color: var(--blue-600);
        }

        .nav ul li.open > a .bx-chevron-down {
            transform: rotate(180deg);
            filter: drop-shadow(0 0 6px var(--blue-600));
        }

        .sidebar-footer {
            margin: 1rem;
            padding: 0;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--sidebar-border);
            border-radius: 16px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .sidebar-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--blue-500), var(--blue-600), var(--blue-light), var(--blue-500));
            background-size: 200% 100%;
            animation: gradientShift 3s linear infinite;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            100% { background-position: 200% 50%; }
        }

        .footer-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.25rem;
            background: linear-gradient(
                135deg,
                rgba(59, 130, 246, 0.15),
                rgba(59, 130, 246, 0.05)
            );
        }

        .footer-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--blue-600), var(--blue-700));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(59, 130, 246, 0.4);
            flex-shrink: 0;
            position: relative;
            animation: iconFloat 3s ease-in-out infinite;
        }

        @keyframes iconFloat {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-5px) rotate(5deg); }
        }

        .footer-icon::before {
            content: '';
            position: absolute;
            inset: -2px;
            background: linear-gradient(135deg, var(--blue-600), var(--blue-500));
            border-radius: 14px;
            opacity: 0.5;
            filter: blur(8px);
            z-index: -1;
            animation: pulse 2s ease-in-out infinite;
        }

        .footer-icon i {
            font-size: 1.5rem;
            color: #ffffff;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
        }

        .footer-content {
            flex: 1;
            min-width: 0;
        }

        .footer-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary-light);
            margin-bottom: 0.25rem;
            line-height: 1.3;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .footer-subtitle {
            font-size: 0.75rem;
            color: var(--text-secondary-light);
            font-weight: 500;
            line-height: 1.2;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-subtitle::before {
            content: '';
            width: 6px;
            height: 6px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse 2s infinite;
            box-shadow: 0 0 8px var(--success);
        }

        .footer-divider {
            height: 1px;
            background: linear-gradient(
                90deg,
                transparent,
                var(--sidebar-border),
                transparent
            );
            margin: 0;
        }

        .footer-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            background: rgba(0, 0, 0, 0.2);
        }

        .version {
            font-size: 0.75rem;
            color: var(--text-light);
            font-weight: 700;
            background: rgba(59, 130, 246, 0.15);
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .status-online::before {
            content: '';
            width: 8px;
            height: 8px;
            background: var(--blue-500);
            border-radius: 50%;
            animation: pulse-ring 2s infinite;
        }

        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
            display: none;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .overlay.show { display: block; opacity: 1; }
    </style>
</head>
<body>
    <div class="overlay" id="overlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="brand">
                <div class="logo">
                    <i class='bx <?= htmlspecialchars($role_icon) ?>'></i>
                </div>
                <div class="brand-info">
                    <h1>GRADES & ASSESSMENT</h1>
                    <p>Academic Records Management</p>
                </div>
            </div>

            <div class="system-status">
                <div class="status-content">
                    <div class="status-left">
                        <span class="status-indicator"></span>
                        <span class="status-text">Online & Operational</span>
                    </div>
                </div>
            </div>
        </div>

        <nav class="nav">
            <?php if (!empty($menus[$role_id])): ?>
                <ul>
                    <?php foreach ($menus[$role_id] as $item): 
                        if (isset($item['is_section']) && $item['is_section']): ?>
                            </ul>
                            <span class="section-title"><?= htmlspecialchars($item['label'], ENT_QUOTES) ?></span>
                            <ul>
                        <?php else:
                            $is_active = isset($item['link']) && strpos($item['link'], $current_page) !== false ? ' active' : '';
                            $has_submenu = isset($item['submenu']);
                        ?>
                            <li class="<?= $is_active ? 'open' : '' ?>">
                                <a href="<?= htmlspecialchars($item['link']) ?>" class="<?= $is_active ?>" <?= $has_submenu ? 'onclick="toggleSubmenu(event)"' : '' ?>>
                                    <span class="left">
                                        <div class="icon-wrapper">
                                            <i class='bx <?= htmlspecialchars($item['icon']) ?> icon'></i>
                                        </div>
                                        <span class="nav-label">
                                            <span class="main-text"><?= htmlspecialchars($item['label']) ?></span>
                                            <span class="sub-text"><?= htmlspecialchars($item['sublabel']) ?></span>
                                        </span>
                                    </span>
                                    <?php if ($has_submenu): ?>
                                        <i class='bx bx-chevron-down'></i>
                                    <?php endif; ?>
                                </a>
                                <?php if ($has_submenu): ?>
                                    <ul class="sub-menu">
                                        <?php foreach ($item['submenu'] as $subitem): ?>
                                            <li>
                                                <a href="<?= htmlspecialchars($subitem['link']) ?>">
                                                    <i class='bx <?= htmlspecialchars($subitem['icon']) ?> icon'></i>
                                                    <span><?= htmlspecialchars($subitem['label']) ?></span>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>

            <?php endif; ?>

            <div class="sidebar-footer">
                <div class="footer-header">
                    <div class="footer-icon">
                        <i class='bx bx-shield-alt-2'></i>
                    </div>
                    <div class="footer-content">
                        <div class="footer-title">Secure Platform</div>
                        <div class="footer-subtitle">All systems operational</div>
                    </div>
                </div>

                <div class="footer-divider"></div>

                <div class="footer-bottom">
                    <span class="version">System</span>
                    <div class="status-online">Online</div>
                </div>
            </div>
        </nav>
    </aside>

    <!-- PAGE WRAPPER — groups navbar + main so they scroll together -->
    <div class="page-wrapper" id="pageWrapper">

        <nav class="top-navbar" id="topNavbar">
            <div class="navbar-container">
                <div class="navbar-content">
                    <div class="navbar-left">
                        <button class="toggle-btn" id="toggleBtn" aria-label="Toggle sidebar"><i class='bx bx-menu'></i></button>
                    </div>
                    <div class="navbar-right">
                        <div class="time-display" id="timeDisplay">
                            <span id="currentTime"></span>
                            <span class="date-separator">•</span>
                            <span id="currentDate"></span>
                        </div>
                        <button class="icon-btn" id="searchBtn" title="Search"><i class='bx bx-search'></i></button>
                        <button class="icon-btn" id="notificationBtn" title="Notifications"><i class='bx bx-bell'></i><span class="badge-dot"></span></button>
                        <div class="profile-wrapper" id="profileWrapper">
                            <div class="profile-avatar" id="profileBtn"><?= htmlspecialchars($initials, ENT_QUOTES) ?></div>
                            <div class="profile-dropdown" id="profileDropdown">
                                <div class="dropdown-header">
                                    <div class="dropdown-avatar"><?= htmlspecialchars($initials, ENT_QUOTES) ?></div>
                                    <div>
                                        <div class="dropdown-name"><?= htmlspecialchars($user_name, ENT_QUOTES) ?></div>
                                        <div class="dropdown-role"><?= htmlspecialchars($role_label, ENT_QUOTES) ?></div>
                                    </div>
                                </div>
                                <div class="dropdown-divider"></div>
                                <a href="#" class="dropdown-item"><i class='bx bx-user'></i><span>My Profile</span></a>
                                <a href="#" class="dropdown-item"><i class='bx bx-cog'></i><span>Settings</span></a>
                                <div class="dropdown-divider"></div>
                                <a href="<?= htmlspecialchars($base_path) ?>/logout.php" class="dropdown-item dropdown-logout"><i class='bx bx-log-out'></i><span>Log Out</span></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <main class="main-content" id="mainContent">

<script>
    function toggleSubmenu(element) {
        const parent = element.parentElement;
        document.querySelectorAll('.nav ul li.open').forEach(item => { if (item !== parent) item.classList.remove('open'); });
        parent.classList.toggle('open');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const sidebar     = document.getElementById('sidebar');
        const pageWrapper = document.getElementById('pageWrapper');
        const toggleBtn   = document.getElementById('toggleBtn');
        const overlay     = document.getElementById('overlay');
        const profileBtn  = document.getElementById('profileBtn');
        const profileDD   = document.getElementById('profileDropdown');
        const profileWrap = document.getElementById('profileWrapper');

        function updateTime() {
            const now = new Date();
            const phTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
            let hours = phTime.getHours();
            const mins = String(phTime.getMinutes()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            const days   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            document.getElementById('currentTime').textContent = `${hours}:${mins} ${ampm}`;
            document.getElementById('currentDate').textContent = `${days[phTime.getDay()]}, ${months[phTime.getMonth()]} ${phTime.getDate()}`;
        }
        updateTime();
        setInterval(updateTime, 1000);

        toggleBtn.addEventListener('click', e => {
            e.stopPropagation();
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            } else {
                sidebar.classList.toggle('collapsed');
                pageWrapper.classList.toggle('expanded');
            }
        });

        overlay.addEventListener('click', () => { sidebar.classList.remove('show'); overlay.classList.remove('show'); });

        document.querySelectorAll('.nav a').forEach(link => {
            link.addEventListener('click', function () {
                if (this.hasAttribute('onclick')) return;
                document.querySelectorAll('.nav a').forEach(a => a.classList.remove('active'));
                this.classList.add('active');
                if (window.innerWidth <= 768) { sidebar.classList.remove('show'); overlay.classList.remove('show'); }
            });
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('show', 'collapsed');
                overlay.classList.remove('show');
                pageWrapper.classList.remove('expanded');
            }
        });

        profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDD.classList.toggle('show'); });
        document.addEventListener('click', e => { if (!profileWrap.contains(e.target)) profileDD.classList.remove('show'); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') profileDD.classList.remove('show'); });
    });
</script>
