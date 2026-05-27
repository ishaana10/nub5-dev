<?php
// ============================================================
// DEBUG LOGIN - TEMPORARY DIAGNOSTIC FILE
// Access: https://ict-fj.com/nbv5u/m/debug_login.php
// DELETE THIS FILE after fixing login!
// ============================================================
ini_set('display_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$results = [];
$results[] = '✅ PHP VERSION: ' . PHP_VERSION;
$results[] = '✅ SESSION STATUS: ' . session_status() . ' (2=active)';
$results[] = '✅ SESSION ID: ' . session_id();
$results[] = '✅ SESSION SAVE PATH: ' . session_save_path();
$results[] = '✅ SESSION DATA: ' . json_encode($_SESSION);

// --- Test config load ---
try {
    require_once __DIR__ . '/config.php';
    $results[] = '✅ config.php loaded OK';
    $results[] = '   dbHost: ' . ($nuConfig['dbHost'] ?? 'NOT SET');
    $results[] = '   dbName: ' . ($nuConfig['dbName'] ?? 'NOT SET');
    $results[] = '   dbUser: ' . ($nuConfig['dbUser'] ?? 'NOT SET');
} catch (Throwable $e) {
    $results[] = '❌ config.php FAILED: ' . $e->getMessage();
}

// --- Test raw PDO connection ---
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $nuConfig['dbHost']    ?? 'localhost',
        $nuConfig['dbPort']    ?? 3306,
        $nuConfig['dbName']    ?? '',
        $nuConfig['dbCharset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $nuConfig['dbUser'] ?? '', $nuConfig['dbPassword'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $results[] = '✅ PDO DB CONNECTION OK';
} catch (Throwable $e) {
    $results[] = '❌ PDO DB CONNECTION FAILED: ' . $e->getMessage();
    $pdo = null;
}

// --- Test nu_users table ---
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM nu_users");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        $results[] = '✅ nu_users table EXISTS, rows: ' . $row['cnt'];
    } catch (Throwable $e) {
        $results[] = '❌ nu_users table ERROR: ' . $e->getMessage();
    }

    // --- Test globeadmin user lookup ---
    try {
        $stmt = $pdo->prepare("SELECT usr_id, usr_username, usr_password, usr_active, usr_failed_attempts FROM nu_users WHERE usr_username = :u LIMIT 1");
        $stmt->execute([':u' => 'globeadmin']);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $results[] = '✅ USER FOUND: usr_id=' . $user['usr_id'] . ' usr_active=' . $user['usr_active'] . ' failed_attempts=' . $user['usr_failed_attempts'];
            $results[] = '   password hash: ' . substr($user['usr_password'], 0, 20) . '...';

            // Test password_verify
            $testPass = 'password';
            $verified = password_verify($testPass, $user['usr_password']);
            $results[] = ($verified ? '✅' : '❌') . ' password_verify("password", hash) = ' . ($verified ? 'TRUE' : 'FALSE');

            if (!$verified) {
                // Try to show what hash format is stored
                $results[] = '   Hash starts with: ' . substr($user['usr_password'], 0, 7);
                $results[] = '   (should be $2y$12 for bcrypt)';
            }
        } else {
            $results[] = '❌ USER "globeadmin" NOT FOUND in nu_users';
        }
    } catch (Throwable $e) {
        $results[] = '❌ USER LOOKUP ERROR: ' . $e->getMessage();
    }
}

// --- Test Database.php class load ---
try {
    require_once __DIR__ . '/core/Database.php';
    $results[] = '✅ core/Database.php loaded OK';
    $db = NuDatabase::getInstance();
    $results[] = '✅ NuDatabase::getInstance() OK';
} catch (Throwable $e) {
    $results[] = '❌ core/Database.php FAILED: ' . $e->getMessage();
}

// --- Test Auth.php class load ---
try {
    require_once __DIR__ . '/core/Auth.php';
    $results[] = '✅ core/Auth.php loaded OK';
} catch (Throwable $e) {
    $results[] = '❌ core/Auth.php FAILED: ' . $e->getMessage();
}

// --- Simulate a full login attempt ---
if (isset($_POST['test_login'])) {
    $results[] = '';
    $results[] = '=== LOGIN ATTEMPT ===';
    try {
        $auth   = NuAuth::getInstance();
        $result = $auth->login('globeadmin', 'password');
        $results[] = 'login() returned: ' . json_encode($result);
        $results[] = 'SESSION after login: ' . json_encode($_SESSION);
    } catch (Throwable $e) {
        $results[] = '❌ login() THREW EXCEPTION: ' . $e->getMessage();
        $results[] = '   File: ' . $e->getFile() . ' Line: ' . $e->getLine();
        $results[] = '   Trace: ' . $e->getTraceAsString();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Debug Login</title>
<style>
body { font-family: monospace; background:#111; color:#0f0; padding:20px; }
pre { white-space:pre-wrap; word-break:break-all; }
.err { color:#f55; }
.ok  { color:#0f0; }
form { margin-top:20px; }
button { padding:10px 20px; background:#4f8cff; color:#fff; border:0; cursor:pointer; font-size:16px; }
h2 { color:#fff; }
</style>
</head>
<body>
<h2>🔍 NuBuilder Login Debugger</h2>
<pre><?php foreach ($results as $r) {
    $cls = strpos($r, '❌') !== false ? 'err' : 'ok';
    echo '<span class="' . $cls . '">' . htmlspecialchars($r, ENT_QUOTES, 'UTF-8') . '</span>' . "\n";
} ?></pre>

<form method="post">
    <button type="submit" name="test_login" value="1">▶ Run Full Login Attempt (globeadmin / password)</button>
</form>

<p style="color:#888;margin-top:30px;">⚠ DELETE debug_login.php after fixing!</p>
</body>
</html>
