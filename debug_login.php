<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Simulate exactly what index.php does - load config first which starts session
require_once __DIR__ . '/config.php';

$r = [];
$r[] = '=== ENVIRONMENT ===';
$r[] = 'PHP: ' . PHP_VERSION;
$r[] = 'session_name(): ' . session_name();
$r[] = 'session_status(): ' . session_status() . ' (2=active)';
$r[] = 'session_id(): ' . session_id();
$r[] = 'session_save_path(): ' . session_save_path();
$r[] = 'headers_sent(): ' . (headers_sent($hf, $hl) ? "YES - in $hf line $hl" : 'NO');
$r[] = '';

$r[] = '=== COOKIES BROWSER SENT ===';
if (empty($_COOKIE)) {
    $r[] = '(none) - browser sent NO cookies';
} else {
    foreach ($_COOKIE as $k => $v) {
        $r[] = "  $k = " . substr($v, 0, 40);
    }
}
$r[] = '';

$r[] = '=== SESSION DATA ON THIS REQUEST ===';
if (empty($_SESSION)) {
    $r[] = '(empty) - no session data';
} else {
    foreach ($_SESSION as $k => $v) {
        $r[] = "  $k = " . (is_array($v) ? json_encode($v) : $v);
    }
}
$r[] = '';

// --- Simulate a login and check what session_regenerate_id does ---
if (isset($_POST['test_login'])) {
    $r[] = '=== SIMULATING login() ===';

    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/Auth.php';

    $auth   = NuAuth::getInstance();
    $result = $auth->login('globeadmin', 'password');

    $r[] = 'login() result: ' . json_encode($result);
    $r[] = 'session_id() AFTER login: ' . session_id();
    $r[] = 'SESSION after login: ' . json_encode($_SESSION);
    $r[] = 'session_name() AFTER login: ' . session_name();
    $r[] = '';
    $r[] = '>>> NOW CHECK: does cookie "' . session_name() . '" appear in your browser?';
    $r[] = '>>> Open DevTools > Application > Cookies and look for: ' . session_name();
    $r[] = '>>> If you see PHPSESSID instead - that is the bug.';
}

// --- Check what checkAuth returns right now ---
$r[] = '=== CHECKING checkAuth() NOW ===';
try {
    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/Auth.php';
    $auth      = NuAuth::getInstance();
    $loggedIn  = $auth->checkAuth();
    $r[] = 'checkAuth() = ' . ($loggedIn ? 'TRUE - you ARE logged in' : 'FALSE - not logged in');
    $r[] = 'SESSION: ' . json_encode($_SESSION);
} catch (Throwable $e) {
    $r[] = 'ERROR: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Debug Login v2</title>
<style>
body{font-family:monospace;background:#111;color:#eee;padding:20px;font-size:14px;}
.ok{color:#4caf50;} .err{color:#f44;} .warn{color:#ff0;} .info{color:#4af;}
pre{white-space:pre-wrap;line-height:1.7;}
button{padding:12px 24px;background:#4f8cff;color:#fff;border:0;cursor:pointer;font-size:15px;margin:5px;border-radius:4px;}
h2{color:#fff;}
</style>
</head>
<body>
<h2>&#128269; NuBuilder Login Debugger v2</h2>
<pre><?php
foreach ($r as $line) {
    if (strpos($line, 'ERROR') !== false || strpos($line, '(empty)') !== false || strpos($line, '(none)') !== false) {
        echo '<span class="err">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</span>' . "\n";
    } elseif (strpos($line, '>>>') !== false || strpos($line, 'FALSE') !== false) {
        echo '<span class="warn">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</span>' . "\n";
    } elseif (strpos($line, 'TRUE') !== false || strpos($line, 'OK') !== false) {
        echo '<span class="ok">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</span>' . "\n";
    } elseif (strpos($line, '===') !== false) {
        echo '<span class="info">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</span>' . "\n";
    } else {
        echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "\n";
    }
}
?></pre>

<form method="post">
    <button type="submit" name="test_login" value="1">&#9654; Simulate login() then check cookies</button>
</form>

<hr style="border-color:#333;margin:20px 0">
<h3 style="color:#fff">&#128269; Manual Cookie Check Instructions</h3>
<ol style="color:#ccc;line-height:2">
    <li>Click the button above</li>
    <li>Open browser DevTools (F12)</li>
    <li>Go to <b>Application</b> tab &rarr; <b>Cookies</b> &rarr; click your site URL</li>
    <li>Look for cookie named <b>nu5sess</b></li>
    <li>If you see <b>PHPSESSID</b> instead of <b>nu5sess</b> &mdash; paste that here</li>
    <li>Also check if the cookie has <b>Secure</b> flag &mdash; if site is HTTP not HTTPS, secure cookies won't be sent back</li>
</ol>

<p style="color:#888;">&#9888; DELETE debug_login.php after fixing!</p>
</body>
</html>
