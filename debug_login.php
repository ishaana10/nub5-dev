<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Register shutdown FIRST - before anything else loads
$nu_debug_sess_id = null; // will be set after session starts
register_shutdown_function(function() use (&$nu_debug_sess_id) {
    $status = session_status();
    $id     = session_id();
    $data   = $_SESSION ?? [];
    $path   = session_save_path();
    $file   = rtrim($path ?: '/tmp', '/') . '/sess_' . $id;
    $size   = file_exists($file) ? filesize($file) : -1;
    $fileContents = file_exists($file) ? file_get_contents($file) : 'FILE NOT FOUND';

    // Write our own debug log
    $log  = date('H:i:s') . " SHUTDOWN\n";
    $log .= "  session_status: $status (1=none,2=active,3=disabled)\n";
    $log .= "  session_id: $id\n";
    $log .= "  _SESSION keys: " . implode(',', array_keys($data)) . "\n";
    $log .= "  session file: $file\n";
    $log .= "  file size at shutdown: $size\n";
    $log .= "  file contents: $fileContents\n";
    $log .= "  REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? '?') . "\n";
    $log .= "---\n";

    file_put_contents(__DIR__ . '/sessions/debug_shutdown.log', $log, FILE_APPEND);
});

$before = [
    'session.cookie_secure'    => ini_get('session.cookie_secure'),
    'session.cookie_samesite'  => ini_get('session.cookie_samesite'),
    'session.cookie_path'      => ini_get('session.cookie_path'),
    'session.cookie_httponly'  => ini_get('session.cookie_httponly'),
    'session.save_path'        => ini_get('session.save_path'),
    'session.name'             => ini_get('session.name'),
    'session.use_strict_mode'  => ini_get('session.use_strict_mode'),
    'session.use_only_cookies' => ini_get('session.use_only_cookies'),
];

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';

$after = [
    'session.cookie_secure'   => ini_get('session.cookie_secure'),
    'session.cookie_samesite' => ini_get('session.cookie_samesite'),
    'session.cookie_path'     => ini_get('session.cookie_path'),
    'session.cookie_httponly' => ini_get('session.cookie_httponly'),
    'session.save_path'       => ini_get('session.save_path'),
    'session.name'            => ini_get('session.name'),
];

$auth = NuAuth::getInstance();
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Debug v5</title>
<style>body{font-family:monospace;background:#111;color:#eee;padding:20px;font-size:13px;line-height:1.8;}
.ok{color:#4caf50;}.err{color:#f55;}.warn{color:#ff0;}.info{color:#4af;}
table{border-collapse:collapse;width:100%;margin-bottom:20px;}
td,th{border:1px solid #333;padding:6px 12px;text-align:left;}
th{background:#1e2a3a;color:#4af;}
tr:nth-child(even){background:#1a1a2e;}
pre{background:#1a1a2e;padding:12px;overflow:auto;border:1px solid #333;}
button{padding:10px 20px;background:#4f8cff;color:#fff;border:0;cursor:pointer;font-size:14px;border-radius:4px;margin:4px;}
</style></head>
<body>
<h2>&#128269; Debug v5 - Shutdown Session Tracer</h2>

<h3 class="info">1. Session Settings</h3>
<table>
<tr><th>Setting</th><th>Before</th><th>After config.php</th></tr>
<?php foreach ($before as $k => $v): $a = $after[$k] ?? $v; ?>
<tr><td><?= $k ?></td><td><?= h($v ?: '(empty)') ?></td><td><?= h($a ?: '(empty)') ?></td></tr>
<?php endforeach; ?>
</table>

<h3 class="info">2. Current Session State (this GET request)</h3>
<table>
<tr><th>Key</th><th>Value</th></tr>
<tr><td>session_id()</td><td><?= session_id() ?></td></tr>
<tr><td>session_status()</td><td><?= session_status() ?></td></tr>
<tr><td>$_SESSION</td><td><?= h(json_encode($_SESSION)) ?></td></tr>
<tr><td>Cookies from browser</td><td><?= h(json_encode($_COOKIE)) ?></td></tr>
<?php
$sf = rtrim(session_save_path(), '/') . '/sess_' . session_id();
$exists = file_exists($sf);
$size   = $exists ? filesize($sf) : -1;
?>
<tr><td>Session file</td><td><?= h($sf) ?></td></tr>
<tr><td>File exists / size</td><td><?= $exists ? "<span class=\"" . ($size > 0 ? 'ok' : 'err') . "\">YES / $size bytes</span>" : '<span class="err">NO</span>' ?></td></tr>
<tr><td>File contents NOW</td><td><?= $exists ? h(file_get_contents($sf)) : '(none)' ?></td></tr>
</table>

<h3 class="info">3. Shutdown Log (previous requests)</h3>
<?php
$logFile = __DIR__ . '/sessions/debug_shutdown.log';
if (file_exists($logFile)) {
    echo '<pre>' . h(file_get_contents($logFile)) . '</pre>';
    echo '<form method="post"><button name="clear_log" value="1" style="background:#c0392b">&#10007; Clear log</button></form>';
} else {
    echo '<p class="warn">No shutdown log yet - reload this page to generate one.</p>';
}
if (isset($_POST['clear_log'])) {
    file_put_contents($logFile, '');
    echo '<p class="ok">Log cleared.</p>';
}
?>

<h3 class="info">4. Live Login Test</h3>
<?php
if (isset($_POST['do_login'])) {
    $r = $auth->login('globeadmin', 'password');
    echo '<p class="ok">login() = ' . h(json_encode($r)) . '</p>';
    echo '<p>SESSION after login: ' . h(json_encode($_SESSION)) . '</p>';
    session_write_close();
    $size2 = file_exists($sf) ? filesize($sf) : -1;
    echo '<p>Session file size after write_close: <span class="' . ($size2 > 0 ? 'ok' : 'err') . '">' . $size2 . ' bytes</span></p>';
}
$loggedIn = $auth->checkAuth();
echo '<p>checkAuth() = ' . ($loggedIn ? '<span class="ok">TRUE</span>' : '<span class="err">FALSE</span>') . '</p>';
?>
<form method="post">
    <button name="do_login" value="1">&#9654; Login test</button>
</form>

<?php
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<p style="color:#555;margin-top:30px;">&#9888; DELETE debug_login.php after fixing!</p>
</body></html>
