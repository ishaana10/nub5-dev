<?php
/**
 * Background worker – called by api/appcloner.php via exec()
 * Usage: php appcloner_worker.php <jobId>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit;
$jobId   = preg_replace('/[^a-z0-9_.]/i', '', $argv[1] ?? '');
if (!$jobId) exit(1);

$root    = dirname(__DIR__);
$jobFile = "$root/temp/nu_cloner_job_$jobId.json";
if (!file_exists($jobFile)) exit(1);

$p = json_decode(file_get_contents($jobFile), true);
unlink($jobFile);

require_once "$root/config.php";
require_once "$root/core/Database.php";
require_once "$root/core/AppCloner.php";

$rowFilters = $p['rowFilters'] ?? [];
$cloner = new AppCloner([
    'progressId'          => $jobId,
    'dryRun'              => (bool)($p['dryRun'] ?? false),
    'schemaOnly'          => (bool)($p['schemaOnly'] ?? false),
    'databaseMode'        => $p['databaseMode'] ?? 'fail',
    'fileMode'            => $p['fileMode']     ?? 'fail',
    'copyFiles'           => (bool)($p['copyFiles'] ?? false),
    'includeTablesAndViews' => $p['includeTables'] ?? [],
    'excludeTablesAndViews' => $p['excludeTables'] ?? [],
    'rowFilters'          => $rowFilters,
    'webhookUrl'          => $p['webhookUrl'] ?? null,
    'logFile'             => "$root/temp/nu_cloner_log_$jobId.txt",
], $p['sourceDB'] ?? null);

$cloner->clone(
    $p['targetDB'],
    $p['targetHost']    ?? 'localhost',
    $p['targetUser']    ?? '',
    $p['targetPass']    ?? '',
    $p['targetCharset'] ?? 'utf8mb4',
    (int)($p['targetPort'] ?? 3306),
    array_map('intval', $p['opts']),
    $p['insertType']    ?? 'INSERT',
    $p['sourcePath']    ?? $root,
    $p['targetPath']    ?? ''
);
