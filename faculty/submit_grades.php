<?php
/* 
 * FACULTY SUBMIT GRADES
 * This file is included by index.php (router)
 * Do not include HTML head/body tags - only content
 */

// Start output buffering to allow header redirects after content output
ob_start();

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csrf.php';

require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/grading_logic.php';

// Faculty access control
requireRole([1]);



$faculty_id = $_SESSION['user_id'];
$message = '';
$msg_type = 'success';
$error = '';


// Helper: Fetch grade categories for a subject
function getGradeCategories($conn, $subject_id) {
    $stmt = $conn->prepare("SELECT gc.category_id, gc.category_name, gc.weight, gc.input_mode, gci.item_id, gci.item_label, gci.item_order 
        FROM grade_categories gc 
        LEFT JOIN grade_category_items gci ON gc.category_id = gci.category_id 
        WHERE gc.subject_id = ? 
        ORDER BY gc.category_id, gci.item_order");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $cid = $row['category_id'];
        if (!isset($categories[$cid])) {
            $categories[$cid] = [
                'category_id' => $cid,
                'category_name' => $row['category_name'],
                'weight' => $row['weight'],
                'input_mode' => $row['input_mode'],
                'items' => []
            ];
        }
        if ($row['item_id']) {
            $categories[$cid]['items'][] = [
                'item_id' => $row['item_id'],
                'item_label' => $row['item_label'],
                'item_order' => $row['item_order']
            ];
        }
    }
    $stmt->close();
    return array_values($categories);
}

// Helper: Check if any grades exist for a subject
function hasGradesForSubject($conn, $subject_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM grades WHERE subject_id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['cnt'] > 0;
}

// Helper: Fetch existing grade scores per student
function getExistingScores($conn, $student_id, $subject_id, $academic_period) {
    $stmt = $conn->prepare("SELECT item_id, raw_score, max_score FROM grade_components WHERE student_id = ? AND subject_id = ? AND academic_period = ?");
    $stmt->bind_param("iis", $student_id, $subject_id, $academic_period);
    $stmt->execute();
    $result = $stmt->get_result();
    $scores = [];
    while ($row = $result->fetch_assoc()) {
        $scores[$row['item_id']] = $row;
    }
    $stmt->close();
    return $scores;
}

// Helper: Fetch grade status and values
function getGradeStatus($conn, $student_id, $subject_id, $academic_period) {
    $stmt = $conn->prepare("SELECT percentage, numeric_grade, remarks, status, is_locked FROM grades WHERE student_id = ? AND subject_id = ? AND academic_period = ?");
    $stmt->bind_param("iis", $student_id, $subject_id, $academic_period);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

// Helper: Fetch assessment components for a subject (legacy fallback)
function getAssessmentComponents($conn, $subject_id) {
    $stmt = $conn->prepare("SELECT component_id, component_name, weight FROM assessment_components WHERE subject_id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $components = [];
    while ($row = $result->fetch_assoc()) {
        $components[] = $row;
    }
    $stmt->close();
    return $components;
}

// Helper: Fetch grade components for a student/subject/period
function getGradeComponents($conn, $student_id, $subject_id, $academic_period) {
    $stmt = $conn->prepare("SELECT component_id, raw_score, max_score FROM grade_components WHERE student_id = ? AND subject_id = ? AND academic_period = ?");
    $stmt->bind_param("iis", $student_id, $subject_id, $academic_period);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[$row['component_id']] = $row;
    }
    $stmt->close();
    return $data;
}

// retrieve flash (if any)
if ($flash = getFlash()) {
    $message = $flash['msg'];
    $msg_type = $flash['type'];
}

// --- Handle POST submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (empty($_POST['csrf_token']) || !csrf_check($_POST['csrf_token'])) {
        setFlash('Invalid security token. Please try again.', 'error');
        header('Location: ?page=submit_grades');
        exit;
    }
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
    $academic_period = isset($_POST['academic_period']) ? trim($_POST['academic_period']) : '';
    $scores = isset($_POST['scores']) ? $_POST['scores'] : [];
    
    // Validate
    if ($student_id <= 0 || $subject_id <= 0 || !$academic_period || empty($scores)) {
        setFlash('Please provide all required fields.', 'error');
        header('Location: ?page=submit_grades');
        exit;
    }
    
    // Fetch categories with weights and input modes
    $stmt = $conn->prepare("SELECT gc.category_id, gc.weight, gc.input_mode FROM grade_categories gc WHERE gc.subject_id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $category_weights = [];
    $category_modes = [];
    while ($row = $result->fetch_assoc()) {
        $category_weights[$row['category_id']] = $row['weight'];
        $category_modes[$row['category_id']] = $row['input_mode'];
    }
    $stmt->close();
    
    // Calculate grade using category averages
    $final_percentage = 0;
    $all_valid = true;
    $category_count = [];
    $category_sum = [];
    
    // Parse scores and calculate category averages
    foreach ($scores as $cat_id => $categories) {
        if (!isset($category_count[$cat_id])) {
            $category_count[$cat_id] = 0;
            $category_sum[$cat_id] = 0;
        }
        
        $inputMode = isset($category_modes[$cat_id]) ? $category_modes[$cat_id] : 'raw';
        
        foreach ($categories as $item_id => $values) {
            $raw = isset($values['raw']) ? floatval($values['raw']) : 0;
            $max = isset($values['max']) ? floatval($values['max']) : 0;
            
            // Handle different input modes
            if ($inputMode === 'percentage') {
                // Percentage mode: raw value is already the percentage
                if ($raw < 0 || $raw > 100) {
                    setFlash('Percentage must be between 0 and 100.', 'error');
                    header('Location: ?page=submit_grades');
                    exit;
                }
                $item_pct = $raw;
            } else {
                // Raw/Max mode: calculate percentage from raw/max
                if ($max <= 0) {
                    setFlash('Max score must be greater than 0 for all items.', 'error');
                    header('Location: ?page=submit_grades');
                    exit;
                }
                if ($raw < 0 || $raw > $max) {
                    setFlash('Raw score cannot exceed max score.', 'error');
                    header('Location: ?page=submit_grades');
                    exit;
                }
                $item_pct = ($raw / $max) * 100;
            }
            
            $category_sum[$cat_id] += $item_pct;
            $category_count[$cat_id]++;
        }
    }
    
    // Compute final percentage: sum of (category_average * weight/100)
    foreach ($category_weights as $cat_id => $weight) {
        if (isset($category_count[$cat_id]) && $category_count[$cat_id] > 0) {
            $category_avg = $category_sum[$cat_id] / $category_count[$cat_id];
            $final_percentage += ($category_avg * ($weight / 100));
        }
    }
    
    list($numeric_grade, $remarks) = convertGrade($final_percentage);
    
    // Insert/update grades
    $stmt = $conn->prepare("INSERT INTO grades (student_id, subject_id, academic_period, percentage, numeric_grade, remarks, status, is_locked)
        VALUES (?, ?, ?, ?, ?, ?, 'Pending', 1)
        ON DUPLICATE KEY UPDATE percentage=VALUES(percentage), numeric_grade=VALUES(numeric_grade), remarks=VALUES(remarks), status='Pending', is_locked=1");
    $stmt->bind_param("iisdss", $student_id, $subject_id, $academic_period, $final_percentage, $numeric_grade, $remarks);
    if (!$stmt->execute()) {
        error_log('DB error: ' . $stmt->error);
        setFlash('Database error. Please try again.', 'error');
        header('Location: ?page=submit_grades');
        exit;
    }
    $stmt->close();
    
    // Insert/update grade_components
    foreach ($scores as $cat_id => $categories) {
        foreach ($categories as $item_id => $values) {
            $raw = floatval($values['raw']);
            $max = floatval($values['max']);
            $stmt = $conn->prepare("INSERT INTO grade_components (student_id, subject_id, academic_period, category_id, item_id, raw_score, max_score)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE raw_score=VALUES(raw_score), max_score=VALUES(max_score)");
            $stmt->bind_param("iisiidd", $student_id, $subject_id, $academic_period, $cat_id, $item_id, $raw, $max);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    logAction($conn, $faculty_id, "Encoded grade for student $student_id in subject $subject_id ($academic_period): $final_percentage% ($numeric_grade)");
    setFlash('Grade submitted successfully.', 'success');
    
    // Don't redirect immediately - let the page render and show the success message
    // The JavaScript at the bottom will handle the redirect after displaying the alert
}


// Fetch faculty's subjects
$subjects = [];
$stmt = $conn->prepare("SELECT subject_id, subject_code, subject_name FROM subjects WHERE faculty_id = ? ORDER BY subject_name");
$stmt->bind_param("i", $faculty_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}
$stmt->close();

// Fetch all enrolled students for faculty's subjects (for filter)
$students = [];
$sections = [];
$programs = [
    'Bachelor of Science in Information Technology',
    'Bachelor of Science in Hospitality Management',
    'Bachelor of Science in Office Administration',
    'Bachelor of Science in Business Administration',
    'Bachelor of Science in Criminology',
    'Bachelor of Elementary Education',
    'Bachelor of Secondary Education',
    'Bachelor of Science in Computer Engineering',
    'Bachelor of Science in Tourism Management',
    'Bachelor of Science in Entrepreneurship',
    'Bachelor of Science in Accounting Information System',
    'Bachelor of Science in Psychology',
    'Bachelor of Science in Information Science',
];
$year_levels = [1, 2, 3, 4];
if (!empty($subjects)) {
    $subject_ids = array_column($subjects, 'subject_id');
    $placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
    $stmt = $conn->prepare("SELECT DISTINCT u.user_id, u.full_name, u.program, u.year_level, u.section
        FROM enrollments e
        JOIN users u ON e.student_id = u.user_id
        WHERE e.subject_id IN ($placeholders) AND e.status = 'Active'
        ORDER BY u.full_name");
    $stmt->bind_param(str_repeat('i', count($subject_ids)), ...$subject_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
        if ($row['section'] && !in_array($row['section'], $sections)) $sections[] = $row['section'];
    }
    $stmt->close();
}
sort($sections);

// Fetch all grades for this faculty's subjects (for status/locking)
$existing_grades = [];
if (!empty($subjects)) {
    $subject_ids = array_column($subjects, 'subject_id');
    $placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
    $stmt = $conn->prepare("SELECT g.student_id, g.subject_id, g.academic_period, g.percentage, g.numeric_grade, g.is_locked, g.status
        FROM grades g
        WHERE g.subject_id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($subject_ids)), ...$subject_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $key = $row['student_id'] . '_' . $row['subject_id'] . '_' . $row['academic_period'];
        $existing_grades[$key] = $row;
    }
    $stmt->close();
}

// Period options
$periods = [
    '1st Year - 1st Semester',
    '1st Year - 2nd Semester',
    '2nd Year - 1st Semester',
    '2nd Year - 2nd Semester',
    '3rd Year - 1st Semester',
    '3rd Year - 2nd Semester'
];
$csrf_token = csrf_token();
?>

<style>
    /* Dynamic Component Manager Styles */
    .component-manager {
        background: var(--surface);
        border-top: 0;
        border-bottom: 2px solid var(--border);
        border-top: none;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .component-manager-header {
        display: none;
    }
    
    .component-lock-message {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid var(--danger);
        color: #991b1b;
        padding: 1rem;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 500;
    }
    
    .category-block {
        background: var(--background);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .category-header {
        display: grid;
        grid-template-columns: 1fr 1fr auto;
        gap: 1rem;
        align-items: end;
        margin-bottom: 1rem;
    }
    
    .category-input-group {
        display: flex;
        flex-direction: column;
    }
    
    .category-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.4rem;
    }
    
    .category-input,
    .item-input,
    .weight-input {
        padding: 0.7rem 0.9rem;
        border: 2px solid var(--border);
        border-radius: 6px;
        font-size: 0.9rem;
        color: var(--text-primary);
        background: var(--surface);
        transition: var(--transition);
    }
    
    .category-input:focus,
    .item-input:focus,
    .weight-input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .weight-input {
        width: 100%;
    }
    
    .category-actions {
        display: flex;
        gap: 0.5rem;
    }
    
    .btn-remove {
        padding: 0.6rem 1rem;
        background: var(--danger);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 600;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    
    .btn-remove:hover {
        background: #dc2626;
        transform: translateY(-2px);
    }
    
    .items-container {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .items-header {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.75rem;
    }

    .items-example {
        color: var(--text-secondary);
        font-weight: 400;
    }
    
    .item-row {
        display: flex;
        gap: 0.75rem;
        align-items: center;
        margin-bottom: 0.75rem;
    }
    
    .item-row:last-child {
        margin-bottom: 0;
    }
    
    .item-input-wrapper {
        flex: 1;
    }
    
    .item-input {
        width: 100%;
    }
    
    .item-display {
        display: inline-block;
        padding: 0.7rem 0.9rem;
        background: var(--background);
        border-radius: 6px;
        color: var(--text-primary);
        font-size: 0.9rem;
        word-break: break-word;
    }
    
    .btn-remove-item {
        padding: 0.6rem 0.8rem;
        background: #f97316;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.8rem;
        transition: var(--transition);
    }
    
    .btn-remove-item:hover {
        background: #ea580c;
    }
    
    .btn-add-item {
        padding: 0.6rem 1rem;
        background: var(--secondary);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 600;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.4rem;
        width: 100%;
        justify-content: center;
        margin-top: 0.5rem;
    }
    
    .btn-add-item:hover {
        background: #059669;
        transform: translateY(-2px);
    }
    
    .btn-add-category {
        padding: 0.75rem 1.5rem;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.9rem;
        font-weight: 600;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }
    
    .btn-add-category:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }

    .btn-edit-components {
        transition: var(--transition);
    }

    .btn-edit-components:hover {
        background: var(--primary-dark) !important;
        transform: translateY(-2px);
    }
    
    .component-manager.hidden {
        display: none;
    }
    
    .weight-total {
        padding: 1rem;
        background: rgba(59, 130, 246, 0.05);
        border: 1px solid var(--primary);
        border-radius: 6px;
        margin-bottom: 1rem;
        text-align: center;
    }
    
    .weight-total-label {
        font-size: 0.85rem;
        color: var(--text-secondary);
        display: block;
        margin-bottom: 0.25rem;
    }
    
    .weight-total-value {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--primary);
    }
    
    .weight-total-value.invalid {
        color: var(--danger);
    }
    
    .btn-save-components {
        padding: 0.85rem 2rem;
        background: linear-gradient(135deg, var(--secondary) 0%, #059669 100%);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 1rem;
        font-weight: 600;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        width: 100%;
        justify-content: center;
    }
    
    .btn-save-components:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }
    
    .btn-save-components:active {
        transform: translateY(0);
    }

    /* Input Mode Toggle */
    .mode-toggle {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .mode-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0;
        margin-right: 0.5rem;
    }

    .mode-button {
        padding: 0.6rem 1rem;
        border: 2px solid var(--border);
        border-radius: 6px;
        background: var(--surface);
        color: var(--text-primary);
        cursor: pointer;
        font-weight: 500;
        font-size: 0.85rem;
        transition: var(--transition);
    }

    .mode-button.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    .mode-button:hover:not(.active) {
        border-color: var(--primary);
        background: rgba(59, 130, 246, 0.05);
    }

    .mode-input-hidden {
        display: none;
    }
    
    .component-not-found {
        background: rgba(59, 130, 246, 0.05);
        border: 1px solid var(--primary);
        color: #1e40af;
        padding: 1rem;
        border-radius: 6px;
        margin: 0px 1.5rem 1.5rem 1.5rem;
    }
    
    .component-not-found-icon {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }
    
    .component-not-found h4 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0 0 0.25rem 0;
    }
    
    .component-not-found p {
        font-size: 0.9rem;
        color: var(--text-secondary);
        margin: 0;
    }
    
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

    .main-content {
        padding: 0.7rem 2rem 2rem 2rem;
        flex: 1;
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

    .header-card {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        color: white;
        padding: 2.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow-lg);
        position: relative;
        overflow: hidden;
    }

    .header-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        animation: float 6s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translate(-50%, -50%) rotate(0deg); }
        50% { transform: translate(-50%, -50%) rotate(180deg); }
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .header-card h1 {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        position: relative;
        z-index: 1;
    }

    .header-card p {
        font-size: 1.1rem;
        opacity: 0.9;
        margin: 0;
        position: relative;
        z-index: 1;
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
        padding: 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        margin-bottom: 2rem;
    }

    .filters-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    .filters-header h2 {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }

    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
    }

    .filter-label {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .filter-select {
        padding: 0.75rem 1rem;
        border: 2px solid var(--border);
        border-radius: 8px;
        font-size: 1rem;
        background: var(--surface);
        color: var(--text-primary);
        transition: var(--transition);
        cursor: pointer;
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .apply-filter-btn {
        padding: 0.7rem 2.5rem;
        font-size: 1.1rem;
        border-radius: 8px;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        font-weight: 600;
        border: none;
        box-shadow: var(--shadow);
        cursor: pointer;
        transition: var(--transition);
    }

    .apply-filter-btn:hover {
        background: linear-gradient(135deg, var(--primary-dark), var(--primary));
        box-shadow: var(--shadow-lg);
        transform: translateY(-2px);
    }

    .apply-filter-btn:active {
        transform: translateY(0);
    }

    /* table card styling like student pages */
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
        border-color: var(--primary);
    }

    .table-wrap {
        overflow-x: auto;
    }

    .subject-title {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        flex-direction: column;
    }
    
    .subject-title > div:first-child {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        width: 100%;
    }
    
    .subject-title .title {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
    }
    
    .subject-title .subtitle {
        font-size: 0.9rem;
        color: var(--text-secondary);
        margin-top: 0.25rem;
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

    .badge-approved {
        background: #f0fdf4;
        color: #166534;
        border: 1px solid #22c55e;
    }

    .badge-pending {
        background: #fef3c7;
        color: #92400e;
    }

    .subject-info p {
        font-size: 0.9rem;
        opacity: 0.9;
        margin: 0;
    }

    .grades-table-container {
        padding: 0;
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

    .student-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .student-avatar {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.9rem;
        flex-shrink: 0;
    }

    .student-details h4 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0 0 0.25rem 0;
    }

    .student-details span {
        font-size: 0.85rem;
        color: var(--text-secondary);
    }

    .period-select {
        padding: 0.75rem;
        border: 2px solid var(--border);
        border-radius: 8px;
        font-size: 0.9rem;
        background: var(--surface);
        color: var(--text-primary);
        min-width: 180px;
        transition: var(--transition);
    }

    .period-select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .percentage-input {
        padding: 0.75rem;
        border: 2px solid var(--border);
        border-radius: 8px;
        font-size: 0.9rem;
        background: var(--surface);
        color: var(--text-primary);
        width: 120px;
        transition: var(--transition);
    }

    .percentage-input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .grade-display {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: rgba(16, 185, 129, 0.1);
        color: var(--secondary);
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.85rem;
    }

    .submit-btn {
        padding: 0.75rem 1.5rem;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 100px;
        justify-content: center;
    }

    .submit-btn:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .submit-btn:active {
        transform: translateY(0);
    }

    .no-data {
        text-align: center;
        padding: 3rem 2rem;
        color: var(--text-secondary);
    }

    .no-data-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .no-data h3 {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .no-data p {
        font-size: 1rem;
        margin: 0;
    }

    /* Score Input Styling */
    .score-inputs {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        align-items: center;
        width: 100%;
    }

    .score-input {
        width: 85px;
        padding: 0.6rem 0.7rem;
        border: 2px solid var(--border);
        border-radius: 4px;
        font-size: 0.9rem;
        text-align: center;
        background: #ffffff;
        color: var(--text-primary);
        transition: var(--transition);
        -moz-appearance: textfield;
        pointer-events: auto;
    }

    .score-input::-webkit-outer-spin-button,
    .score-input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .score-input:hover {
        border-color: var(--primary);
        background: #fafbff;
    }

    .score-input:focus {
        outline: none;
        border-color: var(--primary);
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
    }

    .score-input:disabled {
        background: #f0f0f0;
        color: var(--text-secondary);
        cursor: not-allowed;
        border-color: var(--border);
    }

    .score-input.score-pct {
        width: 90px;
    }

    .score-label {
        font-size: 0.7rem;
        color: var(--text-secondary);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    @media (max-width: 768px) {
        .submit-grades-page {
            padding: 1rem;
        }

        .header-card {
            padding: 2rem;
        }

        .header-card h1 {
            font-size: 2rem;
        }

        .filters-grid {
            grid-template-columns: 1fr;
        }

        .grades-table {
            font-size: 0.85rem;
        }

        .grades-table th,
        .grades-table td {
            padding: 0.75rem 0.5rem;
        }

        .student-info {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .period-select,
        .percentage-input {
            min-width: auto;
            width: 100%;
        }

        .submit-btn {
            width: 100%;
        }
    }
</style>

<script>
    // ===== DYNAMIC COMPONENT MANAGER =====
    
    function toggleComponentManager(subjectId, button) {
        // Try to find the manager by form first
        let manager = null;
        const form = document.getElementById(`component-form-${subjectId}`);
        if (form) {
            manager = form.closest('.component-manager');
        }
        // If form is not present (locked state), find manager by subject id
        if (!manager) {
            manager = document.querySelector(`.component-manager[data-subject='${subjectId}']`);
        }
        if (!manager) return;
        manager.classList.toggle('hidden');
        // Update button text
        if (manager.classList.contains('hidden')) {
            button.innerHTML = '<i class="bx bx-edit"></i> Edit Components';
        } else {
            button.innerHTML = '<i class="bx bx-hide"></i> Hide Components';
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // Hide all component managers by default (they only show when user clicks Edit)
        document.querySelectorAll('.component-manager').forEach(manager => {
            manager.classList.add('hidden');
        });
        
        // Hide all tables and rows by default until filters are applied
        document.querySelectorAll('.table-card').forEach(card => {
            card.style.display = 'none';
        });
        document.querySelectorAll('.grade-row').forEach(row => {
            row.style.display = 'none';
        });
        
        // Initialize component managers with existing data
        const componentFormsData = <?= json_encode(
            array_map(function($subj) use ($conn) {
                $categories = getGradeCategories($conn, $subj['subject_id']);
                return [
                    'subject_id' => $subj['subject_id'],
                    'categories' => $categories
                ];
            }, $subjects)
        ) ?>;
        
        componentFormsData.forEach(data => {
            initializeComponentManager(data.subject_id, data.categories);
        });
        
        // Event listeners for add category buttons
        document.querySelectorAll('.btn-add-category').forEach(btn => {
            btn.addEventListener('click', function() {
                addCategory(this.dataset.subject);
            });
        });
        
        // Event listeners for component form submissions
        document.querySelectorAll('.component-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                saveComponents(this);
            });
        });

        // Event listeners for grade row form submissions
        document.querySelectorAll('.grade-row-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Period is already set in the hidden input with the default value
                // No need to get it from a select since the Period column was removed
                
                // Gather all score inputs and create hidden fields
                const row = this.closest('.grade-row');
                const scores = {};
                
                row.querySelectorAll('.score-input').forEach(input => {
                    const cat = parseInt(input.dataset.cat);
                    const item = parseInt(input.dataset.item);
                    const value = parseFloat(input.value) || 0;
                    
                    if (!scores[cat]) scores[cat] = {};
                    if (!scores[cat][item]) scores[cat][item] = {};
                    
                    // Detect input type
                    if (input.classList.contains('score-raw')) {
                        scores[cat][item].raw = value;
                    } else if (input.classList.contains('score-max')) {
                        scores[cat][item].max = value;
                    } else if (input.classList.contains('score-pct')) {
                        // For percentage mode: store percentage as raw, max=100
                        scores[cat][item].raw = value;
                        scores[cat][item].max = 100;
                    }
                });
                
                // Create hidden inputs for scores
                for (const cat in scores) {
                    for (const item in scores[cat]) {
                        // For raw/max mode: both raw and max exist
                        // For percentage mode: they're already set to raw=percentage, max=100
                        const rawInput = document.createElement('input');
                        rawInput.type = 'hidden';
                        rawInput.name = `scores[${cat}][${item}][raw]`;
                        rawInput.value = scores[cat][item].raw || 0;
                        this.appendChild(rawInput);
                        
                        const maxInput = document.createElement('input');
                        maxInput.type = 'hidden';
                        maxInput.name = `scores[${cat}][${item}][max]`;
                        maxInput.value = scores[cat][item].max || 100;
                        this.appendChild(maxInput);
                    }
                }
                
                // Submit the form
                this.submit();
            });
        });

        // Real-time grade calculation on score input
        document.querySelectorAll('.score-input').forEach(input => {
            input.addEventListener('input', function() {
                const row = this.closest('.grade-row');
                if (row) calculateRowGrade(row);
            });
        });
    });
    
    function initializeComponentManager(subjectId, categories) {
        const container = document.querySelector(
            `#component-form-${subjectId} .categories-container`
        );
        if (!container) return;
        
        container.innerHTML = '';
        
        if (categories && categories.length > 0) {
            categories.forEach((cat, idx) => {
                renderCategoryBlock(subjectId, idx, cat);
            });
        }
        
        updateWeightTotal(subjectId);
    }
    
    function renderCategoryBlock(subjectId, index, categoryData = null) {
        const container = document.querySelector(
            `#component-form-${subjectId} .categories-container`
        );
        if (!container) return;
        
        const categoryName = categoryData ? categoryData.category_name : '';
        const weight = categoryData ? categoryData.weight : 0;
        const inputMode = categoryData ? (categoryData.input_mode || 'raw') : 'raw';
        const items = categoryData ? categoryData.items : [];
        
        const block = document.createElement('div');
        block.className = 'category-block';
        block.dataset.categoryIndex = index;
        
        // Render existing items as input fields for editing
        let itemsHtml = items.map((item, itemIdx) => `
            <div class="item-row">
                <div class="item-input-wrapper">
                    <input type="text" class="item-input" 
                           name="categories[${index}][items][]" 
                           value="${escapeHtml(item.item_label)}"
                           placeholder="e.g., Quiz #1">
                </div>
                <button type="button" class="btn-remove-item" onclick="removeItemRow(this)">
                    <i class='bx bx-trash'></i>
                </button>
            </div>
        `).join('');
        
        block.innerHTML = `
            <div class="category-header">
                <div class="category-input-group">
                    <label class="category-label">Category Name</label>
                    <input type="text" class="category-input category-name" 
                           name="categories[${index}][name]" 
                           value="${escapeHtml(categoryName)}" 
                           placeholder="e.g., Quizzes"
                           onchange="updateWeightTotal(${subjectId})">
                </div>
                <div class="category-input-group">
                    <label class="category-label">Weight (%)</label>
                    <input type="number" class="weight-input category-weight" 
                           name="categories[${index}][weight]" 
                           min="0" max="100" step="0.01"
                           value="${weight}" 
                           placeholder="0.00"
                           onchange="updateWeightTotal(${subjectId})">
                </div>
                <div class="category-input-group">
                    <label class="category-label">Input Mode</label>
                    <div class="mode-toggle">
                        <button type="button" class="mode-button ${inputMode === 'raw' ? 'active' : ''}" 
                                onclick="toggleMode(this, '${index}', 'raw')">
                            Raw / Max
                        </button>
                        <button type="button" class="mode-button ${inputMode === 'percentage' ? 'active' : ''}" 
                                onclick="toggleMode(this, '${index}', 'percentage')">
                            Direct %
                        </button>
                        <input type="hidden" class="category-mode" 
                               name="categories[${index}][mode]" 
                               value="${inputMode}">
                    </div>
                </div>
                <div class="category-actions">
                    <button type="button" class="btn-remove" onclick="removeCategory(this, ${subjectId})">
                        <i class='bx bx-trash'></i> Remove
                    </button>
                </div>
            </div>
            
            <div class="items-container">
                <div class="items-header">Items <span class="items-example">(e.g., Quiz #1, Quiz #2)</span></div>
                ${itemsHtml}
                <button type="button" class="btn-add-item" onclick="addItemRow(this)">
                    <i class='bx bx-plus'></i> Add Item
                </button>
            </div>
        `;
        
        container.appendChild(block);
    }
    
    function toggleMode(button, categoryIndex, mode) {
        // Update button active state
        const buttons = button.parentElement.querySelectorAll('.mode-button');
        buttons.forEach(btn => btn.classList.remove('active'));
        button.classList.add('active');
        
        // Update hidden input
        const hiddenInput = button.parentElement.querySelector('.category-mode');
        if (hiddenInput) hiddenInput.value = mode;
    }
    
    function addCategory(subjectId) {
        const form = document.getElementById(`component-form-${subjectId}`);
        const container = form.querySelector('.categories-container');
        const existingBlocks = container.querySelectorAll('.category-block');
        const newIndex = existingBlocks.length;
        
        renderCategoryBlock(subjectId, newIndex);
        updateWeightTotal(subjectId);
    }
    
    function addItemRow(button) {
        const itemsContainer = button.parentElement;
        const itemRow = document.createElement('div');
        itemRow.className = 'item-row';
        
        // Find the category index from parent structure
        const categoryBlock = button.closest('.category-block');
        const categoryIndex = categoryBlock.dataset.categoryIndex;
        
        // Find how many items already exist
        const existingItems = itemsContainer.querySelectorAll('.item-row');
        const itemIndex = existingItems.length;
        
        itemRow.innerHTML = `
            <div class="item-input-wrapper">
                <input type="text" class="item-input" 
                       name="categories[${categoryIndex}][items][]" 
                       placeholder="e.g., Quiz #1">
            </div>
            <button type="button" class="btn-remove-item" onclick="removeItemRow(this)">
                <i class='bx bx-trash'></i>
            </button>
        `;
        
        button.parentElement.insertBefore(itemRow, button);
    }
    
    function removeItemRow(button) {
        button.closest('.item-row').remove();
    }
    
    function removeCategory(button, subjectId) {
        button.closest('.category-block').remove();
        updateWeightTotal(subjectId);
    }
    
    function updateWeightTotal(subjectId) {
        const form = document.getElementById(`component-form-${subjectId}`);
        if (!form) return;
        
        const weights = Array.from(form.querySelectorAll('.category-weight'))
            .map(input => parseFloat(input.value) || 0)
            .reduce((a, b) => a + b, 0);
        
        const totalElement = document.querySelector(
            `.weight-total-value[data-subject="${subjectId}"]`
        );
        if (totalElement) {
            totalElement.textContent = weights.toFixed(2) + '%';
            totalElement.classList.toggle('invalid', Math.abs(weights - 100) > 0.01);
        }
    }
    
    function saveComponents(form) {
        const subjectId = form.dataset.subject;
        const categoryBlocks = form.querySelectorAll('.category-block');
        const categories = [];
        
        categoryBlocks.forEach(block => {
            const nameInput = block.querySelector('.category-name');
            const weightInput = block.querySelector('.category-weight');
            const modeInput = block.querySelector('.category-mode');
            const itemRows = block.querySelectorAll('.item-row');
            
            const categoryName = nameInput.value.trim();
            const weight = parseFloat(weightInput.value) || 0;
            const mode = modeInput.value || 'raw';
            
            if (categoryName) {
                // Gather items from input fields
                const items = [];
                itemRows.forEach(row => {
                    const inputField = row.querySelector('.item-input');
                    
                    if (inputField) {
                        const itemValue = inputField.value.trim();
                        if (itemValue) {
                            items.push(itemValue);
                        }
                    }
                });
                
                categories.push({
                    name: categoryName,
                    weight: weight,
                    mode: mode,
                    items: items
                });
            }
        });
        
        // Check weight total
        const weightTotal = categories.reduce((sum, cat) => sum + cat.weight, 0);
        if (Math.abs(weightTotal - 100) > 0.01) {
            alert('Component weights must sum to 100%. Currently: ' + weightTotal.toFixed(2) + '%');
            return;
        }
        
        // Send data via AJAX to prevent page reload
        const formData = new FormData(form);
        
        // Add categories data
        categories.forEach((cat, catIdx) => {
            formData.append(`categories[${catIdx}][name]`, cat.name);
            formData.append(`categories[${catIdx}][weight]`, cat.weight);
            formData.append(`categories[${catIdx}][mode]`, cat.mode);
            cat.items.forEach((item, itemIdx) => {
                formData.append(`categories[${catIdx}][items][]`, item);
            });
        });
        
        // Show loading state
        const submitBtn = form.querySelector('.btn-save-components');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bx bx-loader-alt" style="animation: spin 1s linear infinite;"></i> Saving...';
        
        // Send via fetch
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // Show success message
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert-card alert-success';
            alertDiv.innerHTML = '<i class="bx bx-check-circle"></i> Components saved successfully!';
            alertDiv.style.marginBottom = '1rem';
            form.parentElement.insertBefore(alertDiv, form);
            
            // Auto-dismiss alert
            setTimeout(() => {
                alertDiv.style.opacity = '0';
                alertDiv.style.transition = 'opacity 0.3s ease-out';
                setTimeout(() => alertDiv.remove(), 300);
            }, 2000);
            
            // Reset button
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error saving components. Please try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    }
    
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
</script>

<script>
    // Alert auto-dismiss
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

<!-- Messages -->

<?php if ($flash = getFlash()): ?>
    <div class="alert-card <?= $flash['type'] === 'error' ? 'alert-error' : 'alert-success' ?>">
        <i class='bx <?= $flash['type'] === 'error' ? 'bx-error-circle' : 'bx-check-circle' ?>'></i>
        <?= htmlspecialchars($flash['msg'], ENT_QUOTES) ?>
    </div>
<?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
            <h2>Encode Grades</h2>
            <p>Encode and submit student grades for your assigned subjects</p>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <div class="filters-header">
            <i class='bx bx-filter-alt'></i>
            <h2>Filters</h2>
        </div>
        <form id="filterForm" onsubmit="applyFilter(event)">
            <div class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">
                        <i class='bx bx-book'></i>
                        Program
                    </label>
                    <select id="filter_program" class="filter-select" name="program">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= htmlspecialchars($program, ENT_QUOTES) ?>">
                                <?= htmlspecialchars($program, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">
                        <i class='bx bx-calendar'></i>
                        Year Level
                    </label>
                    <select id="filter_year" class="filter-select" name="year">
                        <option value="">All Years</option>
                        <?php foreach ($year_levels as $year): ?>
                            <option value="<?= htmlspecialchars($year, ENT_QUOTES) ?>">
                                <?= htmlspecialchars($year, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">
                        <i class='bx bx-group'></i>
                        Section
                    </label>
                    <select id="filter_section" class="filter-select" name="section">
                        <option value="">All Sections</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= htmlspecialchars($section, ENT_QUOTES) ?>">
                                <?= htmlspecialchars($section, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top: 1rem; text-align: right;">
                <button type="button" id="applyFilterBtn" class="apply-filter-btn">Apply</button>
            </div>
        </form>
    </div>

    <!-- Subject Tables -->
    <div class="content-section">

    <?php if (count($students) > 0 && count($subjects) > 0): ?>
        <?php foreach ($subjects as $subject): ?>
            <?php
            // Fetch enrolled students for this subject
            $enrolled_students = [];
            $stmt = $conn->prepare("SELECT u.user_id, u.full_name, u.program, u.year_level, u.section FROM enrollments e JOIN users u ON e.student_id = u.user_id WHERE e.subject_id = ? AND e.status = 'Active' ORDER BY u.full_name");
            $stmt->bind_param("i", $subject['subject_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $enrolled_students[] = $row;
            }
            $stmt->close();
            $components = getAssessmentComponents($conn, $subject['subject_id']);
            $weight_sum = 0;
            foreach ($components as $c) $weight_sum += $c['weight'];
            ?>
            <div class="table-card">
                <!-- Merged Subject Title & Component Manager Header -->
                <div class="subject-title">
                    <div style="display: flex; justify-content: space-between; align-items: start; width: 100%;">
                        <div>
                            <div class="title"><?= htmlspecialchars($subject['subject_code'], ENT_QUOTES) ?></div>
                            <div class="subtitle"><?= htmlspecialchars($subject['subject_name'], ENT_QUOTES) ?></div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 1.1rem; color: var(--text-primary); font-weight: 600;">
                            <i class='bx bx-layer' style="font-size:1.3rem;"></i>
                            <span>Grade Components</span>
                        </div>
                    </div>
                </div>
                
                <?php 
                    $has_grades = hasGradesForSubject($conn, $subject['subject_id']);
                    $categories = getGradeCategories($conn, $subject['subject_id']);
                ?>
                <!-- ...existing code... -->
                <!-- Dynamic Component Manager -->
                <div class="component-manager" data-subject="<?= $subject['subject_id'] ?>">
                    <?php 
                    $has_grades = hasGradesForSubject($conn, $subject['subject_id']);
                    $categories = getGradeCategories($conn, $subject['subject_id']);
                    ?>
                    
                    <?php
                        $grade_status = null;
                        if (!empty($enrolled_students)) {
                            $default_period = '3rd Year - 2nd Semester';
                            $grade_status = getGradeStatus($conn, $enrolled_students[0]['user_id'], $subject['subject_id'], $default_period);
                        }
                        $is_locked = $grade_status && isset($grade_status['is_locked']) ? $grade_status['is_locked'] : 0;
                    ?>
                        <form id="component-form-<?= $subject['subject_id'] ?>" class="component-form" method="POST" data-subject="<?= $subject['subject_id'] ?>">
                            <input type="hidden" name="action" value="save_components">
                            <input type="hidden" name="subject_id" value="<?= $subject['subject_id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                            
                            <div class="categories-container"></div>
                            
                            <button type="button" class="btn-add-category" data-subject="<?= $subject['subject_id'] ?>">
                                <i class='bx bx-plus'></i>
                                Add Category
                            </button>
                            
                            <div class="weight-total">
                                <span class="weight-total-label">Total Weight</span>
                                <div class="weight-total-value" data-subject="<?= $subject['subject_id'] ?>">0%</div>
                            </div>
                            
                            <button type="submit" class="btn-save-components">
                                <i class='bx bx-save'></i>
                                Save Components
                            </button>
                        </form>
                </div>
                
                <!-- Show message if no categories exist -->
                <?php if (empty($categories) && !$has_grades): ?>
                    <div class="component-not-found">
                        <div class="component-not-found-icon"><i class='bx bx-info-circle'></i></div>
                        <h4>Define grade components above before encoding grades.</h4>
                        <p>Add categories and items using the component manager above.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Edit Button (always shown) -->
                <div style="padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--surface);">
                    <h3 style="margin: 0; font-size: 1.1rem; color: var(--text-primary); font-weight: 600;">Student Grade Table</h3>
                    <button type="button" class="btn-edit-components" onclick="toggleComponentManager(<?= $subject['subject_id'] ?>, this)" style="padding: 0.6rem 1.2rem; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class='bx bx-edit'></i> Edit Components
                    </button>
                </div>
                
                <div class="table-wrap">
                    <?php if (empty($categories) && $has_grades): ?>
                        <div class="no-data">
                            <div class="no-data-icon"><i class='bx bx-error'></i></div>
                            <h3>No grade components configured.</h3>
                            <p>Components cannot be modified after grades have been submitted.</p>
                        </div>
                    <?php elseif (empty($categories)): ?>
                        <!-- Already shown above -->
                    <?php elseif (empty($enrolled_students)): ?>
                        <div class="no-data">
                            <div class="no-data-icon"><i class='bx bx-user-x'></i></div>
                            <h3>No active students enrolled in this subject.</h3>
                        </div>
                    <?php else: ?>
                    <table class="grades-table">
                        <thead>
                            <!-- Row 1: Category headers with colspan -->
                            <tr>
                                <th style="min-width:200px;">Student</th>
                                <?php foreach ($categories as $cat): ?>
                                    <th colspan="<?= count($cat['items']) ?: 1 ?>" style="text-align:center;"><?= htmlspecialchars($cat['category_name'], ENT_QUOTES) ?><br><span style="font-weight:400;font-size:0.75em;">(<?= $cat['weight'] ?>%)</span></th>
                                <?php endforeach; ?>
                                <th>Weighted %</th>
                                <th>Grade</th>
                                <th>Remarks</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                            <!-- Row 2: Item labels -->
                            <tr>
                                <th></th>
                                <?php foreach ($categories as $cat): ?>
                                    <?php if (!empty($cat['items'])): ?>
                                        <?php foreach ($cat['items'] as $item): ?>
                                            <th style="text-align:center;font-size:0.85em;font-weight:500;"><?= htmlspecialchars($item['item_label'], ENT_QUOTES) ?></th>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <th></th>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enrolled_students as $student): ?>
                                <?php
                                $default_period = '3rd Year - 2nd Semester';
                                $grade_status = getGradeStatus($conn, $student['user_id'], $subject['subject_id'], $default_period);
                                $is_locked = $grade_status && $grade_status['status'] === 'Approved' && $grade_status['is_locked'];
                                $existing_scores = getExistingScores($conn, $student['user_id'], $subject['subject_id'], $default_period);
                                ?>
                                <tr class="grade-row" data-student="<?= htmlspecialchars($student['full_name'], ENT_QUOTES) ?>" data-locked="<?= $is_locked ? '1' : '0' ?>" data-program="<?= htmlspecialchars($student['program'], ENT_QUOTES) ?>" data-year="<?= htmlspecialchars($student['year_level'], ENT_QUOTES) ?>" data-section="<?= htmlspecialchars($student['section'], ENT_QUOTES) ?>">
                                    <td style="text-align:left;">
                                        <div class="student-info">
                                            <div class="student-avatar"><?= strtoupper(substr($student['full_name'], 0, 1)) ?></div>
                                            <div class="student-details">
                                                <h4><?= htmlspecialchars($student['full_name'], ENT_QUOTES) ?></h4>
                                                <span>ID: <?= $student['user_id'] ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <!-- Item input cells per category -->
                                    <?php foreach ($categories as $cat): ?>
                                        <?php if (!empty($cat['items'])): ?>
                                            <?php foreach ($cat['items'] as $item): ?>
                                                <td style="text-align:center; min-width:120px; padding:0.75rem;">
                                                    <?php if ($is_locked): ?>
                                                        <div style="color:var(--text-secondary);font-size:0.85em;">Locked</div>
                                                    <?php else: ?>
                                                        <?php if ($cat['input_mode'] === 'percentage'): ?>
                                                            <!-- Percentage input mode -->
                                                            <div class="score-inputs">
                                                                <?php 
                                                                    $pct_value = '';
                                                                    if (isset($existing_scores[$item['item_id']])) {
                                                                        $s = $existing_scores[$item['item_id']];
                                                                        if ($s['max_score'] == 100) {
                                                                            $pct_value = $s['raw_score'];
                                                                        } else {
                                                                            if ($s['max_score'] > 0) {
                                                                                $pct_value = ($s['raw_score'] / $s['max_score']) * 100;
                                                                            }
                                                                        }
                                                                    }
                                                                ?>
                                                                <input type="number" class="score-input score-pct" data-cat="<?= $cat['category_id'] ?>" data-item="<?= $item['item_id'] ?>" min="0" max="100" step="0.01" placeholder="0" <?php if ($pct_value !== '' && $pct_value !== '0') echo 'value="' . floatval($pct_value) . '"'; ?> <?= $is_locked ? 'disabled' : '' ?>>
                                                                <span class="score-label">%</span>
                                                            </div>
                                                        <?php else: ?>
                                                            <!-- Raw/Max input mode (default) -->
                                                            <div class="score-inputs">
                                                                <input type="number" class="score-input score-raw" data-cat="<?= $cat['category_id'] ?>" data-item="<?= $item['item_id'] ?>" min="0" step="0.01" placeholder="Raw" value="<?= isset($existing_scores[$item['item_id']]) ? htmlspecialchars($existing_scores[$item['item_id']]['raw_score']) : '' ?>" <?= $is_locked ? 'disabled' : '' ?>>
                                                                <input type="number" class="score-input score-max" data-cat="<?= $cat['category_id'] ?>" data-item="<?= $item['item_id'] ?>" min="0" step="0.01" placeholder="Max" value="<?= isset($existing_scores[$item['item_id']]) ? htmlspecialchars($existing_scores[$item['item_id']]['max_score']) : '' ?>" <?= $is_locked ? 'disabled' : '' ?>>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <td></td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <!-- Grade cells -->
                                    <td class="weighted-grade-cell" style="text-align:center;font-weight:600;">
                                        <?php if ($grade_status): ?>
                                            <?= number_format($grade_status['percentage'], 2) ?>%
                                        <?php else: ?>
                                            <span class="weighted-grade">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="numeric-grade-cell" style="text-align:center;font-weight:600;">
                                        <?php if ($grade_status): ?>
                                            <?= htmlspecialchars($grade_status['numeric_grade'], ENT_QUOTES) ?>
                                        <?php else: ?>
                                            <span class="numeric-grade">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="remarks-cell" style="text-align:center;font-size:0.9em;">
                                        <?php if ($grade_status): ?>
                                            <?= htmlspecialchars($grade_status['remarks'], ENT_QUOTES) ?>
                                        <?php else: ?>
                                            <span class="remarks">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($is_locked): ?>
                                            <span class="badge-status badge-approved"><i class='bx bx-check-circle'></i> Approved</span>
                                        <?php elseif ($grade_status && $grade_status['status'] === 'Pending'): ?>
                                            <span class="badge-status badge-pending"><i class='bx bx-time'></i> Pending</span>
                                        <?php elseif ($grade_status && $grade_status['status'] === 'Returned'): ?>
                                            <span class="badge-status badge-pending" style="background:#fef9c3;color:#92400e;"><i class='bx bx-undo'></i> Returned</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-pending"><i class='bx bx-time'></i> Not Submitted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if (!$is_locked): ?>
                                            <form method="POST" class="grade-row-form" data-student="<?= $student['user_id'] ?>" data-subject="<?= $subject['subject_id'] ?>" autocomplete="off">
                                                <input type="hidden" name="student_id" value="<?= $student['user_id'] ?>">
                                                <input type="hidden" name="subject_id" value="<?= $subject['subject_id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                                <input type="hidden" name="academic_period" class="hidden-period" value="<?= htmlspecialchars($default_period, ENT_QUOTES) ?>">
                                                <button type="submit" class="submit-btn" style="font-size:0.85rem;padding:0.5rem 1rem;">
                                                    <i class='bx bx-send'></i> Submit
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </table>
                    <?php endif; ?>
                </div>
                <?php if ($weight_sum != 100): ?>
                    <div class="alert-card alert-error" style="margin:1rem;">Warning: Assessment component weights for this subject do not sum to 100 (current: <?= $weight_sum ?>%).</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

<script>
// Existing grades data (passed from PHP)
const existingGrades = <?= json_encode($existing_grades) ?>;

// Only update Section dropdown when Program changes
function updateSectionOptions() {
    const program = document.getElementById('filter_program').value;
    const sectionSelect = document.getElementById('filter_section');
    sectionSelect.innerHTML = '<option value="">All Sections</option>';
    if (program === 'Bachelor of Science in Information Technology') {
        for (let i = 32001; i <= 32100; i++) {
            const sec = `BSIT-${i}-IM`;
            sectionSelect.innerHTML += `<option value="${sec}">${sec}</option>`;
        }
    } else {
        <?php foreach ($sections as $section): ?>
            sectionSelect.innerHTML += `<option value="<?= htmlspecialchars($section, ENT_QUOTES) ?>"><?= htmlspecialchars($section, ENT_QUOTES) ?></option>`;
        <?php endforeach; ?>
    }
}

// Only filter students when Apply is clicked
function applyFilter(e) {
    if (e) e.preventDefault();
    filterTable();
}

function filterTable() {
    const programFilter = document.getElementById('filter_program').value;
    const yearFilter = document.getElementById('filter_year').value;
    const sectionFilter = document.getElementById('filter_section').value;
    const rows = document.querySelectorAll('.grade-row');
    const tableCards = document.querySelectorAll('.table-card');
    let anyVisible = false;
    
    // If no filter applied, hide all tables
    const noFilter = !programFilter && !yearFilter && !sectionFilter;
    if (noFilter) {
        tableCards.forEach(card => {
            card.style.display = 'none';
        });
        rows.forEach(row => {
            row.style.display = 'none';
        });
        return;
    }
    
    // Show tables when filters are applied
    tableCards.forEach(card => {
        card.style.display = '';
        // Hide all component managers - they only show when user clicks Edit button
        const componentManager = card.querySelector('.component-manager');
        if (componentManager) {
            componentManager.classList.add('hidden');
        }
    });
    
    // Filter rows based on applied filters
    rows.forEach(row => {
        const studentInfo = row.querySelector('.student-details');
        const studentId = studentInfo.querySelector('span').textContent.replace('ID: ', '');
        const studentData = window.studentsData ? window.studentsData[studentId] : null;
        let match = true;
        if (programFilter && (!studentData || studentData.program !== programFilter)) match = false;
        if (yearFilter && (!studentData || String(studentData.year_level) !== yearFilter)) match = false;
        if (sectionFilter && (!studentData || studentData.section !== sectionFilter)) match = false;
        row.style.display = match ? '' : 'none';
        if (match) anyVisible = true;
    });
    
    // Optionally, show/hide no-data message if you have one
    const noDataDiv = document.getElementById('noDataMsg');
    if (noDataDiv) noDataDiv.style.display = anyVisible ? 'none' : '';
}

// Pass students data to JS for filtering
window.studentsData = {};
<?php foreach ($students as $stu): ?>
    window.studentsData["<?= $stu['user_id'] ?>"] = {
        program: "<?= addslashes($stu['program']) ?>",
        year_level: "<?= addslashes($stu['year_level']) ?>",
        section: "<?= addslashes($stu['section']) ?>"
    };
<?php endforeach; ?>

document.addEventListener('DOMContentLoaded', function() {
    // Hide all tables and rows by default
    document.querySelectorAll('.table-card').forEach(card => {
        card.style.display = 'none';
    });
    document.querySelectorAll('.grade-row').forEach(row => {
        row.style.display = 'none';
    });
    
    // Attach event listeners for filters
    document.getElementById('filter_program').addEventListener('change', function() {
        updateSectionOptions();
    });
    document.getElementById('applyFilterBtn').addEventListener('click', function(e) {
        applyFilter(e);
    });
});


// Real-time grade calculation logic for category-based system
function computeNumericGrade(pct) {
    if (pct >= 98) return { grade: '1.00', remarks: 'Excellent' };
    if (pct >= 95) return { grade: '1.25', remarks: 'Excellent' };
    if (pct >= 92) return { grade: '1.50', remarks: 'Very Good' };
    if (pct >= 89) return { grade: '1.75', remarks: 'Very Good' };
    if (pct >= 86) return { grade: '2.00', remarks: 'Good' };
    if (pct >= 83) return { grade: '2.25', remarks: 'Good' };
    if (pct >= 80) return { grade: '2.50', remarks: 'Good' };
    if (pct >= 77) return { grade: '2.75', remarks: 'Satisfactory' };
    if (pct >= 75) return { grade: '3.00', remarks: 'Passed' };
    return { grade: '5.00', remarks: 'Failed' };
}

// Calculate row grade in real-time as user enters scores
function calculateRowGrade(row) {
    // Get all score inputs and organize by category
    const scores = {};
    let hasValidData = false;

    row.querySelectorAll('.score-input').forEach(input => {
        const cat = parseInt(input.dataset.cat);
        const item = parseInt(input.dataset.item);
        const isRaw = input.classList.contains('score-raw');
        const value = parseFloat(input.value) || 0;

        if (!scores[cat]) scores[cat] = {};
        if (!scores[cat][item]) scores[cat][item] = {};

        if (isRaw) scores[cat][item].raw = value;
        else scores[cat][item].max = value;
    });

    // Validate and calculate percentages per item
    const categoryPercentages = {};
    for (const cat in scores) {
        let itemCount = 0;
        let percentageSum = 0;

        for (const item in scores[cat]) {
            const raw = scores[cat][item].raw || 0;
            const max = scores[cat][item].max || 0;

            if (max > 0) {
                const itemPct = (raw / max) * 100;
                percentageSum += itemPct;
                itemCount++;
                hasValidData = true;
            }
        }

        if (itemCount > 0) {
            categoryPercentages[cat] = percentageSum / itemCount;
        }
    }

    // Update display if we have valid data (actual calculation happens server-side)
    if (hasValidData) {
        const weightedGradeCell = row.querySelector('.weighted-grade-cell');
        if (weightedGradeCell && weightedGradeCell.querySelector('.weighted-grade')) {
            weightedGradeCell.querySelector('.weighted-grade').textContent = 'Calc...';
        }
    }
}

// Old calculateWeightedGrade function for legacy component-based system
function calculateWeightedGrade(tr) {
    let weightedSum = 0;
    let totalWeight = 0;
    tr.querySelectorAll('input.component-raw').forEach(function(rawInput) {
        const td = rawInput.closest('td');
        const maxInput = td.querySelector('input.component-max');
        const weightInput = td.querySelector('input[type="hidden"][name*="weight"]');
        const raw = parseFloat(rawInput.value) || 0;
        const max = parseFloat(maxInput.value) || 0;
        const weight = parseFloat(weightInput.value) || 0;
        if (max > 0 && raw >= 0 && raw <= max && weight > 0) {
            const pct = (raw / max) * 100;
            weightedSum += pct * (weight / 100);
            totalWeight += weight;
        }
    });
    let finalPct = weightedSum;
    let badge = tr.querySelector('.weighted-grade');
    if (badge) {
        badge.textContent = finalPct.toFixed(2) + '%';
        badge.style.background = finalPct >= 75 ? 'rgba(34,197,94,0.12)' : 'rgba(239,68,68,0.12)';
        badge.style.color = finalPct >= 75 ? '#22C55E' : '#EF4444';
    }
    let numericCell = tr.querySelector('.numeric-grade');
    let remarksCell = tr.querySelector('.remarks');
    if (numericCell && remarksCell) {
        const result = computeNumericGrade(finalPct);
        numericCell.textContent = result.grade;
        remarksCell.textContent = result.remarks;
        numericCell.style.color = finalPct >= 75 ? '#22C55E' : '#EF4444';
        remarksCell.style.color = finalPct >= 75 ? '#22C55E' : '#EF4444';
    }
}
</script>

    <?php else: ?>
        <div class="no-data">
            <div class="no-data-icon">
                <i class='bx bx-book-open'></i>
            </div>
            <h3>No Data Available</h3>
            <p>No students or subjects are currently assigned to you.</p>
        </div>
    <?php endif; ?>
</div>

<?php 
// Close output buffering
ob_end_flush();
?>
