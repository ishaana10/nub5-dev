<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

$r = [];
$r[] = '=== SERVER FILE VERIFICATION ===';
$files = ['index.php', 'config.php', 'core/Auth.php', 'core/Database.php'];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        $r[] = $f . ':';
        $r[] = '  md5    : ' . md5_file($path);
        $r[] = '  size   : ' . filesize($path) . ' bytes';
        $r[] = '  modified: ' . date('Y-m-d H:i:s', filemtime($path));
    } else {
        $r[] = $f . ': NOT FOUND';
    }
}

$r[] = '';
$r[] = '=== SESSION ON THIS GET REQUEST ===';
$r[] = 'session_name(): ' . session_name();
$r[] = 'session_id(): ' . session_id();
if (empty($_SESSION)) {
    $r[] = 'SESSION: (empty)';
} else {
    $r[] = 'SESSION: ' . json_encode($_SESSION);
    $r[] = 'nu_user_id = ' . ($_SESSION['nu_user_id'] ?? 'NOT SET');
}

$r[] = '';
$r[] = '=== KEY LINE CHECK IN index.php ===';
$src = file_get_contents(__DIR__ . '/index.php');
$r[] = 'session_write_close present: ' . (strpos($src, 'session_write_close') !== false ? 'YES' : 'NO - OLD FILE ON SERVER!');
$r[] = 'config.php session_start present: ' . (strpos(file_get_contents(__DIR__ . '/config.php'), 'session_start') !== false ? 'YES' : 'NO - OLD FILE ON SERVER!');

$r[] = '';
$r[] = '=== ENVIRONMENT ===';
$r[] = 'PHP: ' . PHP_VERSION;
$r[] = 'session_save_path: ' . session_save_path();

// Simulate login and check session after
if (isset($_POST['test_login'])) {
    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/Auth.php';
    $auth = NuAuth::getInstance();
    $result = $auth->login('globeadmin', 'password');
    $r[] = '';
    $r[] = '=== AFTER login() ===';
    $r[] = 'result: ' . json_encode($result);
    $r[] = 'session_id: ' . session_id();
    $r[] = 'SESSION: ' . json_encode($_SESSION);
    // Write close and show what happens
    session_write_close();
    $r[] = 'session_write_close() called';
    $r[] = 'session_status after write_close: ' . session_status() . ' (1=none, 2=active, 3=disabled)';
    // Re-open session to check it was saved
    session_start();
    $r[] = 'SESSION after re-open: ' . json_encode($_SESSION);
    $r[] = '^ This must show nu_user_id for index.php redirect to work';
}

?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Debug v3</title>
<style>body{font-family:monospace;background:#111;color:#eee;padding:20px;font-size:13px;line-height:1.8;}
.ok{color:#4caf50;}.err{color:#f55;}.warn{color:#ff0;}.info{color:#4af;}
button{padding:12px 24px;background:#4f8cff;color:#fff;border:0;cursor:pointer;font-size:15px;border-radius:4px;margin-top:16px;}
</style></head>
<body>
<h2>&#128269; Debug v3 - File + Session Verification</h2>
<pre><?php
foreach ($r as $line) {
    $cls = '';
    if (strpos($line, 'NO -') !== false || strpos($line, 'NOT') !== false || strpos($line, 'empty') !== false) $cls = 'err';
    elseif (strpos($line, 'YES') !== false || strpos($line, 'nu_user_id') !== false) $cls = 'ok';
    elseif (strpos($line, '===') !== false) $cls = 'info';
    elseif (strpos($line, 'must') !== false || strpos($line, 'OLD') !== false) $cls = 'warn';
    echo $cls ? "<span class=\"$cls\">" . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "</span>\n" : htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "\n";
}
?></pre>
<form method="post">
    <button type="submit" name="test_login" value="1">&#9654; Test login + session_write_close + re-open</button>
</form>
<p style="color:#888;margin-top:20px;">&#9888; DELETE debug_login.php after fixing!</p>
</body></html>
