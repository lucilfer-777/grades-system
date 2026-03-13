<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/csrf.php';

// Prevent caching for protected content
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// ensure authenticated
if (!isset($_SESSION["user_id"], $_SESSION["role_id"])) {
    header("Location: auth/login.php");
    exit;
}

$role_id = (int)$_SESSION["role_id"];
$page = $_GET['page'] ?? 'dashboard';


?>
<?php
// central router after login, consolidating previous dashboard/index.php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/csrf.php';

// Prevent caching for protected content
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// ensure authenticated
if (!isset($_SESSION["user_id"], $_SESSION["role_id"])) {
    header("Location: auth/login.php");
    exit;
}

$role_id = (int)$_SESSION["role_id"];
$page = $_GET['page'] ?? 'dashboard';

// Handle POST for save_components before any output
if ($page === 'submit_grades' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_components') {
    requireRole([1]);
    require_once __DIR__ . '/includes/flash.php';
    
    if (empty($_POST['csrf_token']) || !csrf_check($_POST['csrf_token'])) {
        setFlash('Invalid security token. Please try again.', 'error');
        header('Location: ?page=submit_grades');
        exit;
    }
    
    $subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
    $faculty_id = $_SESSION['user_id'];
    
    if ($subject_id <= 0) {
        setFlash('Invalid subject.', 'error');
        header('Location: ?page=submit_grades');
        exit;
    }
    
    // Check if any grades exist for this subject
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM grades WHERE subject_id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['cnt'] > 0) {
        setFlash('Components are locked after grade submission.', 'error');
        header('Location: ?page=submit_grades');
        exit;
    }
    
    // Parse categories and items from POST
    $categories_data = isset($_POST['categories']) ? $_POST['categories'] : [];
    
    // Start transaction
    $conn->begin_transaction();
    try {
        // Delete existing categories for this subject (cascade deletes items)
        $stmt = $conn->prepare("DELETE FROM grade_categories WHERE subject_id = ?");
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        $stmt->close();
        
        // Re-insert new categories and items
        if (!empty($categories_data)) {
            foreach ($categories_data as $cat_data) {
                $name = isset($cat_data['name']) ? trim($cat_data['name']) : '';
                $weight = isset($cat_data['weight']) ? floatval($cat_data['weight']) : 0;
                $inputMode = isset($cat_data['mode']) ? trim($cat_data['mode']) : 'raw';
                $items = isset($cat_data['items']) ? $cat_data['items'] : [];
                
                if (empty($name) || $weight < 0) continue;
                
                // Insert category with input_mode
                $stmt = $conn->prepare("INSERT INTO grade_categories (subject_id, category_name, weight, input_mode) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isds", $subject_id, $name, $weight, $inputMode);
                $stmt->execute();
                $category_id = $stmt->insert_id;
                $stmt->close();
                
                // Insert items for this category
                if (!empty($items)) {
                    $item_order = 1;
                    foreach ($items as $item_label) {
                        $item_label = trim($item_label);
                        if (!empty($item_label)) {
                            $stmt = $conn->prepare("INSERT INTO grade_category_items (category_id, item_label, item_order) VALUES (?, ?, ?)");
                            $stmt->bind_param("isi", $category_id, $item_label, $item_order);
                            $stmt->execute();
                            $stmt->close();
                            $item_order++;
                        }
                    }
                }
            }
        }
        
        $conn->commit();
        logAction($conn, $faculty_id, "Updated grade components for subject $subject_id");
        setFlash('Grade components saved successfully.', 'success');
        header('Location: ?page=submit_grades');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Component save error: ' . $e->getMessage());
        setFlash('Error saving components. Please try again.', 'error');
        header('Location: ?page=submit_grades');
        exit;
    }
}

// Handle POST-only handlers before rendering sidebar (to prevent headers-already-sent errors)
if ($page === 'request_correction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([1]);
    ob_start();
    include __DIR__ . '/faculty/request_correction.php';
    ob_end_clean();
    exit;
}

// include sidebar (role-based) and output layout wrapper
include __DIR__ . '/template/sidebar.php';
?>
            <div style="max-width: 1400px; margin: 0 auto;">
                <?php
                switch ($page) {

                    case 'dashboard':
                        if ($role_id === 1) {
                            ob_start();
                            include __DIR__ . '/faculty/dashboard.php';
                            echo ob_get_clean();
                        } elseif ($role_id === 2) {
                            ob_start();
                            include __DIR__ . '/registrar/dashboard.php';
                            echo ob_get_clean();
                        } elseif ($role_id === 3) {
                            ob_start();
                            include __DIR__ . '/student/dashboard.php';
                            echo ob_get_clean();
                        } elseif ($role_id === 4) {
                            ob_start();
                            include __DIR__ . '/admin/dashboard.php';
                            echo ob_get_clean();
                        }
                        break;

                    // Faculty pages
                    case 'subjects':
                        requireRole([1]);
                        echo '<h2>My Assigned Subjects</h2>';
                        echo '<p>Subject list would go here</p>';
                        break;
                    case 'submit_grades':
                        requireRole([1]);
                        ob_start();
                        include __DIR__ . '/faculty/submit_grades.php';
                        echo ob_get_clean();
                        break;
                    case 'view_grades':
                        if ($role_id === 1) {
                            requireRole([1]);
                            ob_start();
                            include __DIR__ . '/faculty/grade_sheet.php';
                            echo ob_get_clean();
                        } else {
                            requireRole([3]);
                            ob_start();
                            include __DIR__ . '/student/view_grades.php';
                            echo ob_get_clean();
                        }
                        break;
                    case 'request_correction':
                        requireRole([1]);
                        ob_start();
                        include __DIR__ . '/faculty/request_correction.php';
                        echo ob_get_clean();
                        break;

                    // Registrar pages
                    case 'pending_enrollments':
                        requireRole([2]);
                        ob_start();
                        include __DIR__ . '/registrar/pending_enrollments.php';
                        echo ob_get_clean();
                        break;
                    case 'pending_grades':
                        requireRole([2]);
                        ob_start();
                        include __DIR__ . '/registrar/pending_grades.php';
                        echo ob_get_clean();
                        break;
                    case 'pending_corrections':
                        requireRole([2]);
                        ob_start();
                        include __DIR__ . '/registrar/approve_correction.php';
                        echo ob_get_clean();
                        break;

                    // Student pages
                    case 'request_enrollment':
                        requireRole([3]);
                        ob_start();
                        include __DIR__ . '/student/enrollment_request.php';
                        echo ob_get_clean();
                        break;
                    case 'enrollment_status':
                        requireRole([3]);
                        ob_start();
                        include __DIR__ . '/student/enrollment_status.php';
                        echo ob_get_clean();
                        break;

                    // Admin pages
                    case 'user_management':
                        requireRole([4]);
                        ob_start();
                        include __DIR__ . '/admin/user_management.php';
                        echo ob_get_clean();
                        break;
                    case 'grade_reports':
                        requireRole([4]);
                        ob_start();
                        include __DIR__ . '/admin/grade_reports.php';
                        echo ob_get_clean();
                        break;
                    case 'audit_logs':
                        requireRole([4]);
                        ob_start();
                        include __DIR__ . '/admin/audit_logs.php';
                        echo ob_get_clean();
                        break;

                    // Shared

                    default:
                        if ($role_id === 1) {
                            ob_start();
                            include __DIR__ . '/faculty/dashboard.php';
                            echo ob_get_clean();
                        } elseif ($role_id === 2) {
                            ob_start();
                            include __DIR__ . '/registrar/dashboard.php';
                            echo ob_get_clean();
                        } elseif ($role_id === 3) {
                            ob_start();
                            include __DIR__ . '/student/dashboard.php';
                            echo ob_get_clean();
                        } elseif ($role_id === 4) {
                            ob_start();
                            include __DIR__ . '/admin/dashboard.php';
                            echo ob_get_clean();
                        }
                }
                ?>
            </div>
        </main>
    </div>
</body>
</html>