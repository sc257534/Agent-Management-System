<?php
// Set the default timezone to Kolkata (Asia/Kolkata)
date_default_timezone_set('Asia/Kolkata');

// Start the session to manage login state
session_start();

// === V3.0 REDESIGN & FEATURE UPDATE ===
// 1. Complete UI/UX overhaul with a modern, responsive, dark-themed design using advanced CSS.
// 2. Sectional Actions: "Add Agent" form is now integrated directly into the Agents page.
// 3. Inactivity Timeout: 15-minute auto-logout functionality is confirmed and active.
// 4. Enhanced UX: Live-filtering search, animated KPIs, and cleaner component styling.
// =======================================


// === 1. CONFIGURATION ===
$servername = "Your_DB_SeverName";
$username = "Your_DB_Username";
$password = "Your_DB_Password"; // IMPORTANT: REPLACE WITH YOUR DB PASSWORD
$dbname = "Your_DB_Name";

// --- Admin Credentials ---
$admin_user = "admin";
$admin_pass_hash = password_hash("password", PASSWORD_DEFAULT); // Default password is 'password'

// --- Inactivity Timeout (15 minutes) ---
$inactivity_timeout = 900; // 15 minutes * 60 seconds

// === 2. DATABASE CONNECTION ===
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Helper function wrapper for prepared statements
if (!function_exists('execute_query')) {
    function execute_query($conn, $sql, $params = []) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            die("SQL Prepare Error: " . $conn->error);
        }
        if ($params) {
            $types = str_repeat('s', count($params)); // Treat all as string for simplicity
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        if (strtoupper(substr(trim($sql), 0, 6)) === 'SELECT' || strtoupper(substr(trim($sql), 0, 4)) === 'SHOW') {
            return $stmt->get_result();
        }
        return $stmt;
    }
}


// === HELPER: Ensure 'Direct Applicant' agent and admin tables/user exist ===
execute_query($conn, "INSERT IGNORE INTO agents (agent_id, name, phone) VALUES (1, 'Direct Applicant', '')");
execute_query($conn, "CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
)");
$check_admin = execute_query($conn, "SELECT id FROM admin_users WHERE username = ?", [$admin_user]);
if ($check_admin->num_rows == 0) {
    execute_query($conn, "INSERT INTO admin_users (username, password) VALUES (?, ?)", [$admin_user, $admin_pass_hash]);
}


// === 3. PHP LOGIC & ACTIONS ===

// --- CSRF Token Management ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Inactivity Logout Timer ---
if (isset($_SESSION['loggedin']) && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactivity_timeout)) {
    session_unset(); session_destroy(); session_start();
    $_SESSION['message'] = ['type' => 'info', 'text' => 'You were automatically logged out due to inactivity.'];
    header("Location: index.php"); exit();
}
if (isset($_SESSION['loggedin'])) $_SESSION['last_activity'] = time();


// --- Login & Logout Logic ---
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy(); header("Location: index.php"); exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $user_input = $_POST['username'];
    $pass_input = $_POST['password'];
    $result = execute_query($conn, "SELECT password FROM admin_users WHERE username = ?", [$user_input]);
    $user = $result->fetch_assoc();
    if ($user && password_verify($pass_input, $user['password'])) {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $user_input;
        $_SESSION['last_activity'] = time();
        header("Location: index.php?section=dashboard"); exit();
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid username or password.'];
        header("Location: index.php"); exit();
    }
}

// --- Section Routing & Auth Check ---
$section = 'login';
if (isset($_SESSION['loggedin'])) {
    $section = $_GET['section'] ?? 'dashboard';
}

// --- Form & Deletion Handler (requires login) ---
if (isset($_SESSION['loggedin'])) {
    if (($_SERVER["REQUEST_METHOD"] == "POST" && (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token'])) ||
        (isset($_GET['action']) && str_contains($_GET['action'], 'delete_') && (!isset($_GET['token']) || $_GET['token'] !== $_SESSION['csrf_token']))
    ) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid security token. Please try again.'];
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php?section=dashboard')); exit();
    }
    
    // Deletion Logic
    if (isset($_GET['action']) && str_contains($_GET['action'], 'delete_') && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $action = $_GET['action'];
        $redirect_section = 'dashboard';
        switch ($action) {
            case 'delete_application':
                $conn->begin_transaction();
                try {
                    execute_query($conn, "DELETE FROM payments WHERE app_id = ?", [$id]);
                    execute_query($conn, "DELETE FROM application_logs WHERE app_id = ?", [$id]);
                    execute_query($conn, "DELETE FROM applications WHERE app_id = ?", [$id]);
                    $conn->commit();
                    $_SESSION['message'] = ['type' => 'success', 'text' => 'Application and all related data purged.'];
                } catch (mysqli_sql_exception $e) {
                    $conn->rollback();
                    $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to purge application data.'];
                }
                $redirect_section = 'applications';
                break;
            case 'delete_agent':
                if ($id == 1) {
                    $_SESSION['message'] = ['type' => 'error', 'text' => 'Cannot delete the default "Direct Applicant" agent.'];
                } else {
                    execute_query($conn, "UPDATE applications SET agent_id = 1 WHERE agent_id = ?", [$id]);
                    execute_query($conn, "DELETE FROM agents WHERE agent_id = ?", [$id]);
                    $_SESSION['message'] = ['type' => 'success', 'text' => 'Agent deleted. Applications reassigned.'];
                }
                $redirect_section = 'agents';
                break;
        }
        header("Location: index.php?section=$redirect_section"); exit();
    }
    
    // Form Submission Logic
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['form_type'])) {
        $form_type = $_POST['form_type'];
        $redirect_url = "index.php?section=" . ($_POST['redirect_section'] ?? 'dashboard');
        
        try {
            switch ($form_type) {
                // All other form cases remain unchanged and fully functional.
                // Cases: add_application, update_application, add_payment, add_log, update_settings
                case 'add_agent':
                    execute_query($conn, "INSERT INTO agents (name, phone) VALUES (?, ?)", [$_POST['name'], $_POST['phone']]);
                    $_SESSION['message'] = ['type' => 'success', 'text' => 'New agent added successfully!'];
                    break;
                
                case 'add_application':
                    $conn->begin_transaction();
                    
                    execute_query($conn, "INSERT INTO applications (agent_id, applicant_name, app_type, app_number, cost, received_date, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)", 
                        [$_POST['agent_id'], $_POST['applicant_name'], $_POST['app_type'], $_POST['app_number'], $_POST['cost'], $_POST['received_date'], $_POST['remarks']]);
                    $new_app_id = $conn->insert_id;

                    execute_query($conn, "INSERT INTO application_logs (app_id, description, update_date) VALUES (?, 'Application created.', ?)", 
                        [$new_app_id, $_POST['received_date'] . ' ' . date('H:i:s')]);
                    
                    if (!empty($_POST['advance_payment']) && floatval($_POST['advance_payment']) > 0) {
                        $advance_amount = floatval($_POST['advance_payment']);
                        execute_query($conn, "INSERT INTO payments (agent_id, app_id, amount, notes, payment_date) VALUES (?, ?, ?, ?, ?)", 
                            [$_POST['agent_id'], $new_app_id, $advance_amount, 'Advance payment on creation', $_POST['received_date']]);

                        $log_desc = "Advance Payment of ₹" . number_format($advance_amount, 2) . " recorded on application creation.";
                        execute_query($conn, "INSERT INTO application_logs (app_id, description, update_date) VALUES (?, ?, ?)", 
                            [$new_app_id, $log_desc, $_POST['received_date'] . ' ' . date('H:i:s')]);
                    }
                    
                    $conn->commit();
                    $_SESSION['message'] = ['type' => 'success', 'text' => 'New application created!'];
                    $redirect_url = "index.php?section=application_detail&id={$new_app_id}";
                    break;

                case 'update_application':
                    $app_id = $_POST['app_id'];
                    $status = $_POST['status'];
                    $remarks = $_POST['remarks'];
                    $completed_date = ($status === 'Completed' && empty($_POST['completed_date'])) ? date('Y-m-d') : (!empty($_POST['completed_date']) ? $_POST['completed_date'] : NULL);
                    $app_number = $_POST['app_number_update'];
                    
                    execute_query($conn, "UPDATE applications SET status = ?, completed_date = ?, remarks = ?, app_number = ? WHERE app_id = ?", [$status, $completed_date, $remarks, $app_number, $app_id]);

                    $log_desc = "Application details updated. Status set to: " . htmlspecialchars($status) . (empty($app_number) ? '' : ". App No. updated to: " . htmlspecialchars($app_number));
                    execute_query($conn, "INSERT INTO application_logs (app_id, description, update_date) VALUES (?, ?, ?)", 
                        [$app_id, $log_desc, date('Y-m-d H:i:s')]);
                    
                    $_SESSION['message'] = ['type' => 'success', 'text' => 'Application details updated.'];
                    $redirect_url = "index.php?section=application_detail&id={$app_id}";
                    break;
                    
                case 'add_payment':
                    $app_id = $_POST['app_id'];
                    $amount = floatval($_POST['amount']);
                    $agent_id = intval($_POST['agent_id']);
                    $payment_date = $_POST['payment_date'];
                    
                    execute_query($conn, "INSERT INTO payments (agent_id, app_id, amount, notes, payment_date) VALUES (?, ?, ?, ?, ?)", 
                        [$agent_id, $app_id, $amount, $_POST['notes'], $payment_date]);

                    $log_desc = "Payment of ₹" . number_format($amount, 2) . " recorded.";
                    if (!empty($_POST['notes'])) $log_desc .= " Notes: " . htmlspecialchars($_POST['notes']);
                    
                    execute_query($conn, "INSERT INTO application_logs (app_id, description, update_date) VALUES (?, ?, ?)", 
                        [$app_id, $log_desc, $payment_date . ' ' . date('H:i:s')]);

                    $_SESSION['message'] = ['type' => 'success', 'text' => 'Payment recorded successfully.'];
                    $redirect_url = "index.php?section=application_detail&id={$app_id}";
                    break;
                
                case 'add_log':
                    $app_id = $_POST['app_id'];
                    $update_date_time = $_POST['update_date'] . ' ' . ($_POST['update_time'] ?? date('H:i:s')); 
                    execute_query($conn, "INSERT INTO application_logs (app_id, description, update_date) VALUES (?, ?, ?)", 
                        [$app_id, $_POST['description'], $update_date_time]);
                    $_SESSION['message'] = ['type' => 'success', 'text' => 'Work log added.'];
                    $redirect_url = "index.php?section=application_detail&id={$app_id}";
                    break;

                case 'update_settings':
                    $current_username = $_SESSION['username'];
                    $current_password = $_POST['current_password'];
                    $new_username = $_POST['new_username'];
                    $new_password = $_POST['new_password'];
                    $confirm_password = $_POST['confirm_password'];
                    $user_result = execute_query($conn, "SELECT password FROM admin_users WHERE username = ?", [$current_username]);
                    $user = $user_result->fetch_assoc();
                    if (!$user || !password_verify($current_password, $user['password'])) {
                        $_SESSION['message'] = ['type' => 'error', 'text' => 'Your current password is not correct.'];
                        header("Location: index.php?section=settings"); exit();
                    }
                    $update_fields = []; $update_params = [];
                    if (!empty($new_username) && $new_username !== $current_username) {
                        $check_result = execute_query($conn, "SELECT id FROM admin_users WHERE username = ?", [$new_username]);
                        if ($check_result->num_rows > 0) {
                            $_SESSION['message'] = ['type' => 'error', 'text' => 'That username is already taken.'];
                            header("Location: index.php?section=settings"); exit();
                        }
                        $update_fields[] = "username = ?"; $update_params[] = $new_username;
                    }
                    if (!empty($new_password)) {
                        if ($new_password !== $confirm_password) {
                            $_SESSION['message'] = ['type' => 'error', 'text' => 'New passwords do not match.'];
                            header("Location: index.php?section=settings"); exit();
                        }
                        $update_fields[] = "password = ?"; $update_params[] = password_hash($new_password, PASSWORD_DEFAULT);
                    }
                    if (!empty($update_fields)) {
                        $sql = "UPDATE admin_users SET " . implode(', ', $update_fields) . " WHERE username = ?";
                        $update_params[] = $current_username;
                        execute_query($conn, $sql, $update_params);
                        if (!empty($new_username) && $new_username !== $current_username) $_SESSION['username'] = $new_username;
                        $_SESSION['message'] = ['type' => 'success', 'text' => 'Your settings have been updated.'];
                    } else {
                        $_SESSION['message'] = ['type' => 'info', 'text' => 'No changes were made.'];
                    }
                    $redirect_url = "index.php?section=settings";
                    break;
            }
        } catch (mysqli_sql_exception $e) {
             if($conn->in_transaction) $conn->rollback();
             $_SESSION['message'] = ['type' => 'error', 'text' => 'Database error: ' . $e->getMessage()];
        }
        header("Location: {$redirect_url}"); exit();
    }
}

// Check for session messages from redirects
$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);

// --- Data Fetching for Views (if logged in) ---
$dashboard_data = [];
$critical_alerts = [];
if ($section == 'dashboard') {
    $dashboard_data['total_agents'] = execute_query($conn, "SELECT COUNT(agent_id) as count FROM agents")->fetch_assoc()['count'] ?? 0;
    $dashboard_data['pending_apps'] = execute_query($conn, "SELECT COUNT(app_id) as count FROM applications WHERE status='Pending' OR status='Processing'")->fetch_assoc()['count'] ?? 0;
    $dashboard_data['revenue_30_days'] = execute_query($conn, "SELECT COALESCE(SUM(amount), 0) as sum FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['sum'] ?? 0;
    
    $total_cost_q = execute_query($conn, "SELECT COALESCE(SUM(cost), 0) as total FROM applications")->fetch_assoc()['total'] ?? 0;
    $total_paid_q = execute_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM payments")->fetch_assoc()['total'] ?? 0;
    $dashboard_data['total_dues'] = $total_cost_q - $total_paid_q;
    
    // Chart data fetching
    $app_type_data_result = execute_query($conn, "SELECT app_type, COUNT(app_id) as count FROM applications GROUP BY app_type");
    $app_type_data = [];
    while ($row = $app_type_data_result->fetch_assoc()) $app_type_data[$row['app_type']] = $row['count'];
    $dashboard_data['app_type_chart'] = json_encode(['labels' => array_keys($app_type_data), 'data' => array_values($app_type_data)]);
    
    $timeline_result = execute_query($conn, "SELECT DATE(received_date) as date, COUNT(app_id) as count FROM applications WHERE received_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY DATE(received_date) ORDER BY date ASC");
    $timeline_data = ['labels' => [], 'data' => []];
    while($row = $timeline_result->fetch_assoc()){ $timeline_data['labels'][] = $row['date']; $timeline_data['data'][] = $row['count']; }
    $dashboard_data['timeline_chart'] = json_encode($timeline_data);

    // Critical Alerts Data
    $alerts_dues_result = execute_query($conn, "SELECT a.app_id, a.applicant_name, (a.cost - COALESCE(p.total_paid, 0)) as due_amount FROM applications a LEFT JOIN (SELECT app_id, SUM(amount) as total_paid FROM payments GROUP BY app_id) p ON a.app_id = p.app_id HAVING due_amount > 0");
    while($row = $alerts_dues_result->fetch_assoc()) $critical_alerts[] = ['type' => 'due', 'app_id' => $row['app_id'], 'text' => htmlspecialchars($row['applicant_name']) . " has a due of ₹" . number_format($row['due_amount'], 2)];
    $alerts_pending_result = execute_query($conn, "SELECT app_id, applicant_name, DATEDIFF(CURDATE(), received_date) as age FROM applications WHERE status = 'Pending' AND received_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    while($row = $alerts_pending_result->fetch_assoc()) $critical_alerts[] = ['type' => 'pending', 'app_id' => $row['app_id'], 'text' => htmlspecialchars($row['applicant_name']) . "'s application is pending for " . $row['age'] . " days"];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMS :: Operational Control Center</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/phosphor-icons"></script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    
    <style>
        /* === V3.0 REDESIGN STYLES === */
        :root {
            --color-bg: #101828;
            --color-bg-secondary: #1D2939;
            --color-border: #344054;
            --color-text-primary: #F2F4F7;
            --color-text-secondary: #98A2B3;
            --color-primary: #00BFFF; /* DeepSkyBlue */
            --color-primary-light: #B0E0E6; /* PowderBlue */
            --color-success: #12B76A;
            --color-warning: #F79009;
            --color-error: #F04438;
            --font-family: 'Inter', sans-serif;
            --border-radius: 8px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --sidebar-width: 260px;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: var(--font-family);
            background-color: var(--color-bg);
            color: var(--color-text-primary);
            font-size: 16px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* === LOGIN PAGE === */
        .login-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
        }
        .login-box {
            background-color: var(--color-bg-secondary);
            padding: 2.5rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--color-border);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-box .logo-icon { font-size: 3rem; color: var(--color-primary); }
        .login-box h1 { margin: 0.5rem 0 0.25rem; font-size: 1.5rem; }
        .login-box p { margin: 0 0 2rem; color: var(--color-text-secondary); }
        .login-box form .form-group { text-align: left; margin-bottom: 1.5rem; }
        .login-box form .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.875rem; }

        /* === MAIN APP LAYOUT === */
        .app-wrapper { display: flex; }
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--color-bg-secondary);
            border-right: 1px solid var(--color-border);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
            transition: transform 0.3s ease-in-out;
        }
        .logo { display: flex; align-items: center; gap: 0.75rem; padding-bottom: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--color-border); }
        .logo-icon { font-size: 2rem; color: var(--color-primary); }
        .logo-text { font-size: 1.25rem; font-weight: 600; white-space: nowrap; }
        .main-nav { flex-grow: 1; }
        .nav-link {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            border-radius: var(--border-radius);
            color: var(--color-text-secondary);
            text-decoration: none;
            font-weight: 500;
            transition: background-color 0.2s, color 0.2s;
        }
        .nav-link:hover { background-color: var(--color-border); color: var(--color-text-primary); }
        .nav-link.active { background-color: var(--color-primary); color: var(--color-bg-secondary); }
        .nav-link i { font-size: 1.5rem; }
        .user-profile {
            display: flex; align-items: center; gap: 0.75rem;
            padding-top: 1.5rem; margin-top: auto; border-top: 1px solid var(--color-border);
        }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--color-primary); color: var(--color-bg-secondary); display: grid; place-items: center; font-weight: 600; }
        .user-info { flex-grow: 1; }
        .user-info .username { display: block; font-weight: 600; font-size: 0.875rem; }
        .user-info .role { display: block; font-size: 0.75rem; color: var(--color-text-secondary); }
        #logout-btn { color: var(--color-text-secondary); font-size: 1.5rem; transition: color 0.2s; }
        #logout-btn:hover { color: var(--color-error); }
        
        .main-content-wrapper {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            padding: 1.5rem;
            transition: margin-left 0.3s ease-in-out, width 0.3s ease-in-out;
        }
        .top-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        #mobile-menu-toggle { display: none; }
        .page-title { margin: 0; font-size: 1.75rem; font-weight: 700; flex-grow: 1; }
        .global-search { position: relative; }
        .global-search i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--color-text-secondary); }
        .global-search input { padding-left: 2.75rem; width: 300px; }
        
        /* === GENERAL COMPONENTS === */
        .card {
            background-color: var(--color-bg-secondary);
            border: 1px solid var(--color-border);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }
        .card-header { padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--color-border); }
        .card-header h3 { margin: 0; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.625rem 1rem;
            border-radius: var(--border-radius);
            border: 1px solid transparent;
            font-weight: 600; font-size: 0.875rem;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary { background-color: var(--color-primary); color: var(--color-bg-secondary); border-color: var(--color-primary); }
        .btn-primary:hover { opacity: 0.9; }
        .btn-secondary { background-color: var(--color-border); color: var(--color-text-primary); border-color: var(--color-border); }
        .btn-secondary:hover { background-color: #475467; }
        .btn-success { background-color: var(--color-success); color: #fff; }
        .btn-danger { background-color: var(--color-error); color: #fff; }
        .new-app-btn { background-color: var(--color-primary); color: var(--color-bg-secondary); padding: 0.75rem 1.25rem; border-radius: var(--border-radius); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; }
        .new-app-btn i { font-size: 1.25rem; }

        /* Form Elements */
        .form-input, .form-select, .form-textarea {
            width: 100%;
            background-color: var(--color-bg);
            border: 1px solid var(--color-border);
            color: var(--color-text-primary);
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(0, 191, 255, 0.2);
        }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.875rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; }
        
        /* Data Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--color-border); }
        .data-table thead { background-color: #1d2939; }
        .data-table th { font-weight: 600; color: var(--color-text-secondary); font-size: 0.875rem; text-transform: uppercase; }
        .data-table tbody tr:hover { background-color: #1d2939; }
        .data-table td b { color: var(--color-text-primary); }
        .data-table td small { color: var(--color-text-secondary); }
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .status-Pending { background-color: rgba(247, 144, 9, 0.1); color: var(--color-warning); }
        .status-Processing { background-color: rgba(0, 191, 255, 0.1); color: var(--color-primary); }
        .status-Completed { background-color: rgba(18, 183, 106, 0.1); color: var(--color-success); }
        .status-Rejected { background-color: rgba(240, 68, 56, 0.1); color: var(--color-error); }
        .due-positive { color: var(--color-warning); font-weight: 600; }
        .due-zero { color: var(--color-text-secondary); }
        .action-icon { font-size: 1.5rem; color: var(--color-text-secondary); transition: color 0.2s; }
        .action-icon:hover { color: var(--color-primary); }
        
        /* === DASHBOARD SPECIFIC === */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; }
        .kpi-tile { background: var(--color-bg-secondary); border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 1.5rem; }
        .kpi-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; }
        .kpi-icon { font-size: 1.5rem; color: var(--color-text-secondary); }
        .kpi-title { margin: 0; font-size: 0.875rem; font-weight: 500; color: var(--color-text-secondary); }
        .kpi-value { margin: 0; font-size: 2rem; font-weight: 700; }
        .kpi-value.currency.positive { color: var(--color-success); }
        .kpi-value.currency.negative { color: var(--color-error); }
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .chart-container { height: 350px; }
        .alerts-panel .alert-item { display: flex; align-items: center; gap: 1rem; padding: 0.75rem 0; border-bottom: 1px solid var(--color-border); }
        .alerts-panel .alert-item:last-child { border-bottom: none; }
        .alert-icon { font-size: 1.75rem; }
        .alert-icon.due { color: var(--color-warning); }
        .alert-icon.pending { color: var(--color-primary); }

        /* === APPLICATION DETAIL PAGE === */
        .detail-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem; align-items: flex-start; }
        .status-switch { display: flex; background: var(--color-bg); border-radius: var(--border-radius); border: 1px solid var(--color-border); padding: 4px; }
        .status-option { flex: 1; text-align: center; padding: 0.5rem; border-radius: 6px; cursor: pointer; font-weight: 500; transition: all 0.2s; }
        .status-option.active { background-color: var(--color-primary); color: var(--color-bg-secondary); }
        #status-select { display: none; }
        .action-tracker .tab-nav { display: flex; border-bottom: 1px solid var(--color-border); margin-bottom: 1.5rem; }
        .action-tracker .tab-link { padding: 0.75rem 1.25rem; cursor: pointer; color: var(--color-text-secondary); font-weight: 500; border-bottom: 2px solid transparent; }
        .action-tracker .tab-link.active { color: var(--color-primary); border-bottom-color: var(--color-primary); }
        .action-tracker .tab-content { display: none; }
        .action-tracker .tab-content.active { display: block; }
        .timeline { list-style: none; padding: 0; position: relative; }
        .timeline::before { content: ''; position: absolute; left: 16px; top: 0; bottom: 0; width: 2px; background: var(--color-border); }
        .timeline-item { position: relative; padding-left: 50px; margin-bottom: 2rem; }
        .timeline-icon { position: absolute; left: 0; top: 0; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; background-color: var(--color-border); }
        .timeline-item.payment .timeline-icon { background-color: var(--color-success); }
        .timeline-item.log .timeline-icon { background-color: var(--color-primary); }
        .timeline-icon i { font-size: 1.25rem; color: #fff; }
        .timeline-content .timestamp { font-size: 0.875rem; color: var(--color-text-secondary); margin-bottom: 0.25rem; }
        .timeline-content p { margin: 0; }
        
        /* === AGENTS PAGE === */
        .agents-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: flex-start; }
        .agent-card { display: flex; flex-direction: column; height: 100%; }
        .agent-card-header { display: flex; justify-content: space-between; align-items: flex-start; }
        .agent-card-header h3 { margin: 0; }
        .agent-card-header p { margin: 0; color: var(--color-text-secondary); }
        .agent-card .delete-btn { font-size: 1.25rem; color: var(--color-text-secondary); }
        .agent-card .delete-btn:hover { color: var(--color-error); }
        .agent-stats { margin-top: auto; padding-top: 1rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.875rem; }
        .agent-stats div { display: flex; justify-content: space-between; }
        .agent-stats span { color: var(--color-text-secondary); }
        .agent-stats b { font-weight: 600; color: var(--color-text-primary); }

        /* === UTILITIES & RESPONSIVENESS === */
        hr { border: 0; border-top: 1px solid var(--color-border); margin: 1.5rem 0; }
        a { color: var(--color-primary); text-decoration: none; }

        @media (max-width: 1200px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 992px) {
            :root { --sidebar-width: 220px; }
            .page-title { font-size: 1.5rem; }
            .global-search input { width: 200px; }
            .agents-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); z-index: 1000; }
            .sidebar.mobile-active { transform: translateX(0); }
            .main-content-wrapper { margin-left: 0; width: 100%; }
            #mobile-menu-toggle { display: block; background: none; border: none; color: var(--color-text-primary); font-size: 1.5rem; cursor: pointer; }
            .top-bar { flex-wrap: wrap; }
            .global-search { order: 3; width: 100%; }
            .global-search input { width: 100%; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 576px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .top-bar-actions { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
    <?php if ($section == 'login'): ?>
    <div class="login-wrapper">
        <div class="login-box">
            <i class="ph-duotone ph-buildings logo-icon"></i>
            <h1>AMS Portal Login</h1>
            <p>Operational Control Center</p>
            <form method="post">
                <input type="hidden" name="login" value="1">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" class="form-input" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">Authenticate</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="app-wrapper">
        <aside class="sidebar" id="sidebar">
            <div class="logo">
                <i class="ph-duotone ph-buildings logo-icon"></i><span class="logo-text">AMS Portal</span>
            </div>
            <nav class="main-nav">
                <?php
                    $nav_items = [
                        'dashboard' => ['label' => 'Dashboard', 'icon' => 'ph-gauge'], 'applications' => ['label' => 'Applications', 'icon' => 'ph-files'],
                        'agents' => ['label' => 'Agents', 'icon' => 'ph-users-three'], 'settings' => ['label' => 'Settings', 'icon' => 'ph-gear-six'],
                    ];
                    $current_section = explode('_', $section)[0]; 
                ?>
                <?php foreach($nav_items as $key => $item): ?>
                <a href="?section=<?= $key ?>" class="nav-link <?= ($current_section == $key) ? 'active' : '' ?>">
                    <i class="ph-duotone <?= $item['icon'] ?>"></i><span class="nav-label"><?= $item['label'] ?></span>
                </a>
                <?php endforeach; ?>
            </nav>
            <div class="user-profile">
                <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
                <div class="user-info">
                    <span class="username"><?= htmlspecialchars($_SESSION['username']) ?></span><span class="role">Administrator</span>
                </div>
                <a href="?action=logout" id="logout-btn" title="Logout"><i class="ph-duotone ph-power"></i></a>
            </div>
        </aside>

        <div class="main-content-wrapper">
            <header class="top-bar">
                <button id="mobile-menu-toggle" class="btn"><i class="ph ph-list"></i></button>
                <h1 class="page-title"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $current_section))) ?></h1>
                <div class="global-search">
                    <i class="ph ph-magnifying-glass"></i>
                    <input type="text" class="form-input" placeholder="Live filter applicants..." onkeyup="filterTable(this.value)">
                </div>
                <div class="top-bar-actions">
                    <a href="?section=add_application" class="new-app-btn"><i class="ph ph-plus-circle"></i> New Application</a>
                </div>
            </header>

            <main>
                <?php
                // === VIEW ROUTER ===
                switch ($section) {
                    case 'dashboard':
                        ?>
                        <div class="kpi-grid">
                            <div class="kpi-tile"><div class="kpi-header"><i class="ph-duotone ph-users-three kpi-icon"></i><h4 class="kpi-title">Active Agents</h4></div><p class="kpi-value" data-target="<?= $dashboard_data['total_agents'] ?>">0</p></div>
                            <div class="kpi-tile"><div class="kpi-header"><i class="ph-duotone ph-clock-countdown kpi-icon"></i><h4 class="kpi-title">Pending Apps</h4></div><p class="kpi-value" data-target="<?= $dashboard_data['pending_apps'] ?>">0</p></div>
                            <div class="kpi-tile"><div class="kpi-header"><i class="ph-duotone ph-chart-line-up kpi-icon"></i><h4 class="kpi-title">Revenue (30d)</h4></div><p class="kpi-value currency positive" data-target="<?= $dashboard_data['revenue_30_days'] ?>">₹0.00</p></div>
                            <div class="kpi-tile"><div class="kpi-header"><i class="ph-duotone ph-wallet kpi-icon"></i><h4 class="kpi-title">Total Dues</h4></div><p class="kpi-value currency <?= $dashboard_data['total_dues'] > 0 ? 'negative' : 'positive' ?>" data-target="<?= $dashboard_data['total_dues'] ?>">₹0.00</p></div>
                        </div>
                        <div class="dashboard-grid">
                            <div class="card"><div class="card-header"><h3>Application Velocity (90 Days)</h3></div><div class="chart-container"><canvas id="timelineChart"></canvas></div></div>
                            <div class="card"><div class="card-header"><h3>Application Type Breakdown</h3></div><div class="chart-container"><canvas id="appTypeChart"></canvas></div></div>
                        </div>
                        <div class="card">
                           <div class="card-header"><h3><i class="ph-duotone ph-warning"></i>Critical Alerts</h3></div>
                            <div class="alerts-panel">
                                <?php if (empty($critical_alerts)): ?><p>No critical alerts. System nominal. ✅</p><?php else: ?>
                                    <?php foreach ($critical_alerts as $alert): ?>
                                    <div class="alert-item">
                                        <i class="ph-duotone <?= $alert['type'] == 'due' ? 'ph-currency-inr' : 'ph-timer' ?> alert-icon <?= $alert['type'] ?>"></i>
                                        <span class="alert-text"><?= $alert['text'] ?></span>
                                        <a href="?section=application_detail&id=<?= $alert['app_id'] ?>" class="btn-secondary btn">View</a>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                        break;
                    case 'applications':
                        $all_agents = execute_query($conn, "SELECT agent_id, name FROM agents ORDER BY name");
                        $all_agents_for_select = [];
                        while($a = $all_agents->fetch_assoc()) $all_agents_for_select[] = $a;
                        
                        $where_clauses = []; $params = [];
                        
                        if (!empty($_GET['filter_agent'])) { $where_clauses[] = 'a.agent_id = ?'; $params[] = $_GET['filter_agent']; }
                        if (!empty($_GET['search'])) { $where_clauses[] = '(a.applicant_name LIKE ? OR a.app_number LIKE ?)'; $search_term = '%' . $_GET['search'] . '%'; $params[] = $search_term; $params[] = $search_term; }
                        
                        $status_filter = $_GET['filter_status'] ?? 'active'; // Default to 'active'
                        if ($status_filter === 'active') {
                            $where_clauses[] = "a.status NOT IN ('Completed', 'Rejected')";
                        } elseif (!empty($status_filter)) {
                            $where_clauses[] = 'a.status = ?';
                            $params[] = $status_filter;
                        }
                        
                        $sql = "SELECT a.*, ag.name as agent_name, (a.cost - COALESCE(p.total_paid, 0)) as due FROM applications a 
                                JOIN agents ag ON a.agent_id = ag.agent_id
                                LEFT JOIN (SELECT app_id, SUM(amount) as total_paid FROM payments GROUP BY app_id) p ON a.app_id = p.app_id";
                        if (!empty($where_clauses)) $sql .= " WHERE " . implode(' AND ', $where_clauses);
                        $sql .= " GROUP BY a.app_id ORDER BY a.received_date DESC";
                        
                        $applications = execute_query($conn, $sql, $params);
                        ?>
                        <div class="card">
                            <form method="GET" class="filter-form">
                                <input type="hidden" name="section" value="applications">
                                <div class="form-grid">
                                     <div class="form-group">
                                         <label class="form-label">Search Name/Number</label>
                                         <input type="text" name="search" class="form-input" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                                     </div>
                                     <div class="form-group">
                                         <label class="form-label">Agent</label>
                                         <select name="filter_agent" class="form-select">
                                             <option value="">All Agents</option>
                                             <?php foreach($all_agents_for_select as $agent): ?><option value="<?= $agent['agent_id'] ?>" <?= (($_GET['filter_agent'] ?? '') == $agent['agent_id']) ? 'selected' : '' ?>><?= htmlspecialchars($agent['name']) ?></option><?php endforeach; ?>
                                         </select>
                                     </div>
                                     <div class="form-group">
                                         <label class="form-label">Status</label>
                                         <select name="filter_status" class="form-select">
                                             <option value="active" <?= ($status_filter == 'active') ? 'selected' : '' ?>>Active (Pending/Processing)</option>
                                             <option value="" <?= ($status_filter == '') ? 'selected' : '' ?>>All Statuses</option>
                                             <option value="Pending" <?= ($status_filter == 'Pending') ? 'selected' : '' ?>>Pending</option>
                                             <option value="Processing" <?= ($status_filter == 'Processing') ? 'selected' : '' ?>>Processing</option>
                                             <option value="Completed" <?= ($status_filter == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                             <option value="Rejected" <?= ($status_filter == 'Rejected') ? 'selected' : '' ?>>Rejected</option>
                                         </select>
                                     </div>
                                     <div class="form-group" style="align-self: flex-end;">
                                         <button type="submit" class="btn btn-primary">Filter</button>
                                         <a href="?section=applications" class="btn btn-secondary">Reset</a>
                                     </div>
                                </div>
                            </form>
                        </div>

                        <div class="card">
                            <table class="data-table" id="applications-table">
                                <thead><tr><th>Applicant</th><th>Agent</th><th>Type</th><th>Cost</th><th>Due</th><th>Status</th><th>Received</th><th>Action</th></tr></thead>
                                <tbody>
                                    <?php if($applications->num_rows > 0): ?>
                                    <?php while($app = $applications->fetch_assoc()): ?>
                                    <tr>
                                        <td><b><?= htmlspecialchars($app['applicant_name']) ?></b><br><small><?= htmlspecialchars($app['app_number'] ?? 'No App #') ?></small></td>
                                        <td><?= htmlspecialchars($app['agent_name']) ?></td>
                                        <td><?= htmlspecialchars($app['app_type']) ?></td>
                                        <td>₹<?= number_format($app['cost'], 2) ?></td>
                                        <td class="<?= $app['due'] > 0 ? 'due-positive' : 'due-zero' ?>">₹<?= number_format($app['due'], 2) ?></td>
                                        <td><span class="status-badge status-<?= str_replace(' ', '', $app['status']) ?>"><?= $app['status'] ?></span></td>
                                        <td><?= date("d M, Y", strtotime($app['received_date'])) ?></td>
                                        <td><a href="?section=application_detail&id=<?= $app['app_id'] ?>" title="Access Portal"><i class="ph-duotone ph-arrow-square-out action-icon"></i></a></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php else: ?>
                                    <tr><td colspan="8">No applications found matching criteria.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php
                        break;
                    case 'add_application':
                        $all_agents = execute_query($conn, "SELECT agent_id, name FROM agents ORDER BY name");
                        ?>
                        <p><a href="?section=applications">&lt; Back to Applications List</a></p>
                        <div class="card">
                            <div class="card-header"><h3>New Application Entry</h3></div>
                            <form method="POST">
                                <input type="hidden" name="form_type" value="add_application">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                
                                <div class="form-group"><label class="form-label">Applicant Name</label><input type="text" name="applicant_name" class="form-input" required></div>
                                <div class="form-group"><label class="form-label">Agent</label>
                                    <select name="agent_id" class="form-select" required>
                                        <?php while($agent = $all_agents->fetch_assoc()): ?><option value="<?= $agent['agent_id'] ?>"><?= htmlspecialchars($agent['name']) ?></option><?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-grid">
                                    <div class="form-group"><label class="form-label">Application Type</label><select name="app_type" class="form-select"><option>Add_Service_Name_1</option><option>Add_Service_Name_2</option><option>Both</option></select></div>
                                    <div class="form-group"><label class="form-label">Total Cost (₹)</label><input type="number" name="cost" step="0.01" class="form-input" required></div>
                                    <div class="form-group"><label class="form-label">Advance Payment (₹)</label><input type="number" name="advance_payment" step="0.01" class="form-input" value="0.00"></div>
                                    <div class="form-group"><label class="form-label">Received Date</label><input type="date" name="received_date" class="form-input" value="<?= date('Y-m-d') ?>" required></div>
                                </div>
                                <div class="form-group"><label class="form-label">Application Number (Optional)</label><input type="text" name="app_number" class="form-input"></div>
                                <div class="form-group"><label class="form-label">Remarks</label><textarea name="remarks" class="form-textarea" placeholder="Initial notes..."></textarea></div>
                                <div><button type="submit" class="btn btn-primary">Create Application</button></div>
                            </form>
                        </div>
                        <?php
                        break;
                    case 'application_detail':
                         if (!isset($_GET['id'])) { echo "<p>Error: No ID provided.</p>"; break; }
                         $app_id = intval($_GET['id']);
                         $result_app = execute_query($conn, "SELECT a.*, ag.name as agent_name FROM applications a JOIN agents ag ON a.agent_id = ag.agent_id WHERE a.app_id = ?", [$app_id]);
                         $app = $result_app->fetch_assoc();
                         if (!$app) { echo "<p>Error: Application not found.</p>"; break; }
                         $total_paid_result = execute_query($conn, "SELECT COALESCE(SUM(amount), 0) as total_paid FROM payments WHERE app_id = ?", [$app_id]);
                         $total_paid = $total_paid_result->fetch_assoc()['total_paid'];
                         $balance_due = $app['cost'] - $total_paid;
                         $logs_result = execute_query($conn, "SELECT *, (description LIKE 'Payment of%') as is_payment FROM application_logs WHERE app_id = ? ORDER BY update_date DESC, created_at DESC", [$app_id]);
                         ?>
                        <p><a href="?section=applications">&lt; Back to Applications List</a></p>
                        <div class="detail-grid">
                            <div class="card">
                                <h3>Controls & Updates</h3>
                                <form method="POST">
                                    <input type="hidden" name="form_type" value="update_application"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="app_id" value="<?= $app_id ?>">
                                    <div class="form-group"><label class="form-label">Status</label>
                                        <div class="status-switch" id="status-switch-container">
                                            <?php foreach(['Pending', 'Processing', 'Completed', 'Rejected'] as $s): ?><div class="status-option <?= ($app['status'] == $s) ? 'active' : '' ?>" data-value="<?= $s ?>"><?= $s ?></div><?php endforeach; ?>
                                        </div>
                                        <select name="status" id="status-select"><?php foreach(['Pending', 'Processing', 'Completed', 'Rejected'] as $s): ?><option value="<?= $s ?>" <?= ($app['status'] == $s) ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
                                    </div>
                                    <div class="form-group"><label class="form-label">Application Number</label><input type="text" name="app_number_update" class="form-input" value="<?= htmlspecialchars($app['app_number'] ?? '') ?>"></div>
                                    <div class="form-group"><label class="form-label">Remarks</label><textarea name="remarks" class="form-textarea" rows="4"><?= htmlspecialchars($app['remarks']) ?></textarea></div>
                                    <div class="form-group"><label class="form-label">Completed Date</label><input type="date" name="completed_date" value="<?= $app['completed_date'] ?>" class="form-input"></div>
                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                </form>
                                <hr>
                                <h4>Danger Zone</h4><p>Permanently delete this application and all related data.</p>
                                <a href="?action=delete_application&id=<?= $app_id ?>&token=<?= $_SESSION['csrf_token'] ?>" class="delete-btn btn btn-danger">Delete Application</a>
                            </div>
                            <div>
                                <div class="card">
                                    <h2><?= htmlspecialchars($app['applicant_name']) ?></h2><p>Agent: <?= htmlspecialchars($app['agent_name']) ?></p>
                                    <div class="kpi-grid">
                                        <div class="kpi-tile"><h4 class="kpi-title">Total Cost</h4><p class="kpi-value currency">₹<?= number_format($app['cost'], 2) ?></p></div>
                                        <div class="kpi-tile"><h4 class="kpi-title">Total Paid</h4><p class="kpi-value currency positive">₹<?= number_format($total_paid, 2) ?></p></div>
                                        <div class="kpi-tile"><h4 class="kpi-title">Balance Due</h4><p class="kpi-value currency <?= $balance_due > 0 ? 'negative' : 'positive' ?>">₹<?= number_format($balance_due, 2) ?></p></div>
                                    </div>
                                </div>
                                <div class="card action-tracker" id="action-tracker">
                                    <div class="tab-nav"><div class="tab-link active" data-tab="log"><i class="ph ph-list-checks"></i> Add Log</div><div class="tab-link" data-tab="payment"><i class="ph ph-currency-inr"></i> Record Payment</div></div>
                                    <div class="tab-content active" data-tab-content="log">
                                        <form method="POST">
                                            <input type="hidden" name="form_type" value="add_log"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="app_id" value="<?= $app_id ?>">
                                            <div class="form-group"><label class="form-label">Log Description</label><textarea name="description" class="form-textarea" required></textarea></div>
                                            <div class="form-grid"><div class="form-group"><label class="form-label">Date</label><input type="date" name="update_date" value="<?= date('Y-m-d') ?>" class="form-input" required></div><div class="form-group"><label class="form-label">Time</label><input type="time" name="update_time" value="<?= date('H:i') ?>" class="form-input" required></div></div>
                                            <button type="submit" class="btn btn-secondary">Add Log Entry</button>
                                        </form>
                                    </div>
                                    <div class="tab-content" data-tab-content="payment">
                                        <form method="POST">
                                            <input type="hidden" name="form_type" value="add_payment"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="app_id" value="<?= $app_id ?>"><input type="hidden" name="agent_id" value="<?= $app['agent_id'] ?>">
                                            <div class="form-grid"><div class="form-group"><label class="form-label">Payment Amount (₹)</label><input type="number" step="0.01" name="amount" class="form-input" required></div><div class="form-group"><label class="form-label">Payment Date</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" class="form-input" required></div></div>
                                            <div class="form-group"><label class="form-label">Payment Notes</label><input type="text" name="notes" class="form-input" placeholder="e.g., Final installment"></div>
                                            <button type="submit" class="btn btn-success">Record Payment</button>
                                        </form>
                                    </div>
                                </div>
                                <div class="card">
                                    <h3>History & Timeline</h3>
                                    <ul class="timeline">
                                        <?php if ($logs_result->num_rows > 0): $is_first = true; ?>
                                        <?php while($log = $logs_result->fetch_assoc()): ?>
                                        <li class="timeline-item <?= $log['is_payment'] ? 'payment' : 'log' ?> <?= $is_first ? 'latest' : '' ?>">
                                            <div class="timeline-icon"><i class="ph-duotone <?= $log['is_payment'] ? 'ph-receipt' : 'ph-clipboard-text' ?>"></i></div>
                                            <div class="timeline-content"><div class="timestamp"><?= date("d M, Y H:i", strtotime($log['update_date'])) ?></div><p class="description"><?= htmlspecialchars($log['description']) ?></p></div>
                                        </li>
                                        <?php $is_first = false; endwhile; ?>
                                        <?php else: ?><li><p>No history yet.</p></li><?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <?php
                        break;
                    case 'agents':
                        $agents_query = "SELECT ag.agent_id, ag.name, ag.phone, COUNT(a.app_id) as total_apps, SUM(CASE WHEN a.status IN ('Pending', 'Processing') THEN 1 ELSE 0 END) as pending_apps, COALESCE(SUM(a.cost), 0) as total_value, COALESCE(SUM(a.cost), 0) - COALESCE(SUM(p_sub.total_paid), 0) as agent_due FROM agents ag LEFT JOIN applications a ON ag.agent_id = a.agent_id LEFT JOIN (SELECT app_id, SUM(amount) as total_paid FROM payments GROUP BY app_id) p_sub ON a.app_id = p_sub.app_id GROUP BY ag.agent_id, ag.name, ag.phone ORDER BY ag.name";
                        $agents_result = $conn->query($agents_query);
                        ?>
                        <div class="agents-grid">
                            <div>
                                <div class="card">
                                    <div class="card-header"><h3>New Agent Profile</h3></div>
                                     <form method="POST">
                                         <input type="hidden" name="form_type" value="add_agent"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="redirect_section" value="agents">
                                         <div class="form-group"><label class="form-label">Agent Name</label><input type="text" name="name" class="form-input" required></div>
                                         <div class="form-group"><label class="form-label">Phone Number</label><input type="text" name="phone" class="form-input"></div>
                                         <div><button type="submit" class="btn btn-primary">Create Agent</button></div>
                                     </form>
                                </div>
                            </div>
                            <div>
                                <?php if($agents_result->num_rows > 0): while($agent = $agents_result->fetch_assoc()): ?>
                                <div class="card agent-card">
                                    <div class="agent-card-header">
                                        <div><h3><?= htmlspecialchars($agent['name']) ?></h3><p><?= htmlspecialchars($agent['phone'] ?: 'N/A') ?></p></div>
                                        <?php if($agent['agent_id'] != 1): ?><a href="?action=delete_agent&id=<?= $agent['agent_id'] ?>&token=<?= $_SESSION['csrf_token'] ?>" class="delete-btn" title="Delete Agent"><i class="ph ph-trash"></i></a><?php endif; ?>
                                    </div>
                                    <div class="agent-stats">
                                        <div><span>Total Apps</span> <b><?= $agent['total_apps'] ?></b></div>
                                        <div><span>Pending/Processing</span> <b><?= $agent['pending_apps'] ?></b></div>
                                        <div><span>Total Value</span> <b>₹<?= number_format($agent['total_value'] ?? 0, 2) ?></b></div>
                                        <div><span>Agent Dues</span> <b class="<?= ($agent['agent_due'] > 0) ? 'due-positive' : '' ?>">₹<?= number_format($agent['agent_due'], 2) ?></b></div>
                                    </div>
                                </div>
                                <?php endwhile; endif; ?>
                            </div>
                        </div>
                        <?php
                        break;
                    case 'settings':
                        ?>
                        <div class="card">
                            <div class="card-header"><h3>Account Settings</h3></div>
                            <form method="POST">
                                <input type="hidden" name="form_type" value="update_settings"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <div class="form-group"><label for="current_password" class="form-label">Current Password (Required)</label><input type="password" name="current_password" id="current_password" class="form-input" required></div>
                                <hr>
                                <div class="form-group"><label for="new_username" class="form-label">New Username</label><input type="text" name="new_username" id="new_username" class="form-input" placeholder="Current: <?= htmlspecialchars($_SESSION['username']) ?>"></div>
                                <div class="form-grid">
                                    <div class="form-group"><label for="new_password" class="form-label">New Password</label><input type="password" name="new_password" id="new_password" class="form-input" placeholder="Leave blank to keep same"></div>
                                    <div class="form-group"><label for="confirm_password" class="form-label">Confirm New Password</label><input type="password" name="confirm_password" id="confirm_password" class="form-input"></div>
                                </div>
                                <div><button type="submit" class="btn btn-primary">Update Settings</button></div>
                            </form>
                        </div>
                        <?php
                        break;
                }
                ?>
            </main>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($message): ?>
        const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3500, timerProgressBar: true });
        Toast.fire({ icon: '<?= $message['type'] ?>', title: '<?= addslashes($message['text']) ?>' });
        <?php endif; ?>

        document.querySelectorAll('.delete-btn').forEach(button => {
            button.addEventListener('click', function (e) {
                e.preventDefault(); const href = this.href;
                Swal.fire({
                    title: 'Confirm Deletion', text: "This action is permanent and cannot be undone!", icon: 'warning',
                    showCancelButton: true, confirmButtonText: 'Yes, delete it!',
                    background: 'var(--color-bg-secondary)', color: 'var(--color-text-primary)',
                    confirmButtonColor: 'var(--color-error)', cancelButtonColor: 'var(--color-border)'
                }).then((result) => { if (result.isConfirmed) window.location.href = href; });
            });
        });

        document.querySelectorAll('.kpi-value[data-target]').forEach(counter => {
            const target = +counter.getAttribute('data-target');
            const isCurrency = counter.classList.contains('currency');
            let count = 0;
            const updateCount = () => {
                const increment = Math.max(1, Math.abs(target / 100));
                
                if (count < target) {
                    count = Math.min(target, Math.ceil(count + increment));
                } else if (count > target) {
                    count = Math.max(target, Math.floor(count - increment));
                }

                counter.innerText = isCurrency ? '₹' + count.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : count.toLocaleString('en-IN');
                
                if(count !== target) {
                    requestAnimationFrame(updateCount);
                } else {
                    counter.innerText = isCurrency ? '₹' + target.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : target.toLocaleString('en-IN');
                }
            };
            updateCount();
        });

        const timelineCanvas = document.getElementById('timelineChart');
        if (timelineCanvas) { const timelineData = <?= $dashboard_data['timeline_chart'] ?? 'null' ?>; if(timelineData) new Chart(timelineCanvas, { type: 'line', data: { labels: timelineData.labels, datasets: [{ label: 'Applications Received', data: timelineData.data, backgroundColor: 'rgba(0, 191, 255, 0.2)', borderColor: 'rgba(0, 191, 255, 1)', borderWidth: 2, pointBackgroundColor: 'rgba(0, 191, 255, 1)', tension: 0.4, fill: true }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { ticks: { color: '#98A2B3' }, grid: { color: '#344054' } }, x: { ticks: { color: '#98A2B3' }, grid: { display: false } } }, plugins: { legend: { display: false } } } }); }
        const appTypeCanvas = document.getElementById('appTypeChart');
        if(appTypeCanvas) { const appTypeData = <?= $dashboard_data['app_type_chart'] ?? 'null' ?>; if(appTypeData) new Chart(appTypeCanvas, { type: 'doughnut', data: { labels: appTypeData.labels, datasets: [{ data: appTypeData.data, backgroundColor: ['#00BFFF', '#00FFFF', '#48D1CC', '#20B2AA'], borderColor: '#1D2939', borderWidth: 3, hoverOffset: 10 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#F2F4F7' } } } } }); }
        
        const statusSwitch = document.getElementById('status-switch-container');
        if (statusSwitch) {
            const options = statusSwitch.querySelectorAll('.status-option'), hiddenSelect = document.getElementById('status-select');
            options.forEach(option => option.addEventListener('click', () => { options.forEach(o => o.classList.remove('active')); option.classList.add('active'); hiddenSelect.value = option.dataset.value; }));
        }
        
        const actionTracker = document.getElementById('action-tracker');
        if(actionTracker) {
            const tabs = actionTracker.querySelectorAll('.tab-link'), contents = actionTracker.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.addEventListener('click', () => { tabs.forEach(t => t.classList.remove('active')); contents.forEach(c => c.classList.remove('active')); tab.classList.add('active'); actionTracker.querySelector(`.tab-content[data-tab-content="${tab.dataset.tab}"]`).classList.add('active'); }));
        }

        const sidebar = document.getElementById('sidebar'), mobileToggle = document.getElementById('mobile-menu-toggle');
        if (mobileToggle) mobileToggle.addEventListener('click', () => sidebar.classList.toggle('mobile-active'));
        
        phosphor.replace();
    });
    
    function filterTable(searchTerm) {
        const table = document.getElementById('applications-table'); if (!table) return;
        const rows = table.tBodies[0].rows; const lowerCaseSearchTerm = searchTerm.toLowerCase();
        for (let row of rows) {
            const applicantCell = row.cells[0];
            if (applicantCell) row.style.display = applicantCell.textContent.toLowerCase().includes(lowerCaseSearchTerm) ? "" : "none";
        }
    }
    </script>
    <?php endif; ?>
</body>
</html>