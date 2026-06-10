<?php
declare(strict_types=1);
/**
 * NuErrorLogger — captures PHP errors, uncaught exceptions, SQL errors, and JS errors.
 * PHP 7.4 compatible.
 *
 * IMPORTANT — registration order:
 *   Register AFTER NuDatabase and NuAuth are loaded, NOT in config.php.
 *   In index.php, call NuErrorLogger::register() inside the bootstrap try{} block,
 *   after the three core require_once lines.
 *
 * If an error fires before DB is ready the logger falls back silently to
 * PHP's native error_log (no crash, no white screen).
 */
class NuErrorLogger {

    private static bool $registered = false;
    private static ?NuErrorLogger $instance = null;

    // Severity levels
    const SEV_DEBUG   = 'debug';
    const SEV_INFO    = 'info';
    const SEV_WARNING = 'warning';
    const SEV_ERROR   = 'error';
    const SEV_FATAL   = 'fatal';

    // Type labels
    const TYPE_PHP  = 'PHP';
    const TYPE_SQL  = 'SQL';
    const TYPE_JS   = 'JS';
    const TYPE_APP  = 'APP';

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register global PHP error and exception handlers.
     * Safe to call even if DB is not yet available — write() will use file fallback.
     */
    public static function register(): void {
        if (self::$registered) return;
        self::$registered = true;

        set_error_handler([self::getInstance(), 'handlePhpError']);
        set_exception_handler([self::getInstance(), 'handleException']);
        register_shutdown_function([self::getInstance(), 'handleShutdown']);
    }

    /** PHP set_error_handler callback. */
    public function handlePhpError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
        $severity = $this->phpErrnoToSeverity($errno);
        $context  = [
            'errno'   => $errno,
            'errtype' => $this->phpErrnoToName($errno),
            'file'    => $this->stripRoot($errfile),
            'line'    => $errline,
        ];
        $trace = $this->buildTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8));
        $this->write(self::TYPE_PHP, $severity, $errstr, $context, $trace, $errfile, $errline);
        return false; // let PHP also write to its own error_log
    }

    /** PHP set_exception_handler callback. */
    public function handleException(\Throwable $e): void {
        $message = get_class($e) . ': ' . $e->getMessage();
        $context = [
            'exception' => get_class($e),
            'code'      => $e->getCode(),
            'file'      => $this->stripRoot($e->getFile()),
            'line'      => $e->getLine(),
        ];
        $trace = $this->buildTrace($e->getTrace());
        $this->write(self::TYPE_PHP, self::SEV_FATAL, $message, $context, $trace, $e->getFile(), $e->getLine());
    }

    /** Shutdown handler — catches E_ERROR / E_PARSE / E_COMPILE_ERROR etc. */
    public function handleShutdown(): void {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->write(
                self::TYPE_PHP,
                self::SEV_FATAL,
                $error['message'],
                [
                    'errno'   => $error['type'],
                    'errtype' => $this->phpErrnoToName($error['type']),
                    'file'    => $this->stripRoot($error['file']),
                    'line'    => $error['line'],
                ],
                null,
                $error['file'],
                $error['line']
            );
        }
    }

    /**
     * Log a SQL error manually in a catch block.
     *   NuErrorLogger::logSql($sql, $e->getMessage(), $params, __FILE__, __LINE__);
     */
    public static function logSql(
        string $sql,
        string $error,
        array $params = [],
        ?string $callerFile = null,
        int $callerLine = 0
    ): void {
        $safeParams = [];
        foreach ($params as $k => $v) {
            $key = strtolower((string)$k);
            $safeParams[$k] = (str_contains($key, 'pass') || str_contains($key, 'secret') || str_contains($key, 'token'))
                ? '***' : $v;
        }
        $context = ['sql' => $sql, 'params' => $safeParams];
        if ($callerFile) $context['file'] = self::getInstance()->stripRoot($callerFile);
        if ($callerLine) $context['line'] = $callerLine;
        $trace = self::getInstance()->buildTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8));
        self::getInstance()->write(self::TYPE_SQL, self::SEV_ERROR, $error, $context, $trace, $callerFile ?? '', $callerLine);
    }

    /**
     * Log a JS error payload (called from api/errorlog.php).
     */
    public static function logJs(array $payload): void {
        $message = $payload['message'] ?? 'Unknown JS error';
        $context = [
            'source'    => $payload['source']    ?? '',
            'lineno'    => $payload['lineno']     ?? 0,
            'colno'     => $payload['colno']      ?? 0,
            'url'       => $payload['url']        ?? '',
            'userAgent' => $payload['userAgent']  ?? '',
        ];
        $trace = $payload['stack'] ?? null;
        self::getInstance()->write(
            self::TYPE_JS, self::SEV_ERROR, $message, $context, $trace,
            $payload['source'] ?? '', (int)($payload['lineno'] ?? 0)
        );
    }

    /**
     * Log any app-level message from PHP.
     *   NuErrorLogger::logApp('Save failed', ['form_id' => 5], NuErrorLogger::SEV_WARNING);
     */
    public static function logApp(
        string $message,
        array $context = [],
        string $severity = self::SEV_INFO
    ): void {
        $trace = self::getInstance()->buildTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8));
        self::getInstance()->write(self::TYPE_APP, $severity, $message, $context, $trace);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal write — NEVER throws, NEVER crashes the app
    // ──────────────────────────────────────────────────────────────────────────

    private function write(
        string $type,
        string $severity,
        string $message,
        array $context = [],
        ?string $trace = null,
        string $file = '',
        int $line = 0
    ): void {
        // Session user — only when session is already active
        $userId   = null;
        $userName = null;
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $userId   = $_SESSION['nu_user_id']  ?? null;
                $userName = $_SESSION['nu_username'] ?? null;
            }
        } catch (\Throwable $ignored) {}

        $requestUri    = $_SERVER['REQUEST_URI']    ?? '';
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';

        // ── Try DB first ─────────────────────────────────────────────────────
        // Guard: NuDatabase class may not be loaded yet (e.g. during login page
        // bootstrap). If it is not available, fall straight through to file log.
        if (class_exists('NuDatabase', false)) {
            try {
                $db = NuDatabase::getInstance();
                $db->query(
                    "INSERT INTO nu_error_log
                        (errlog_type, errlog_severity, errlog_message, errlog_context,
                         errlog_trace, errlog_file, errlog_line,
                         errlog_request_uri, errlog_request_method,
                         errlog_user_id, errlog_user_name, errlog_created_at)
                     VALUES
                        (:type, :sev, :msg, :ctx,
                         :trace, :file, :line,
                         :uri, :method,
                         :uid, :uname, NOW())",
                    [
                        ':type'   => $type,
                        ':sev'    => $severity,
                        ':msg'    => mb_substr($message, 0, 2000),
                        ':ctx'    => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':trace'  => $trace ? mb_substr($trace, 0, 8000) : null,
                        ':file'   => mb_substr($this->stripRoot($file), 0, 500),
                        ':line'   => $line,
                        ':uri'    => mb_substr($requestUri, 0, 500),
                        ':method' => $requestMethod,
                        ':uid'    => $userId,
                        ':uname'  => $userName,
                    ]
                );
                return; // success — skip file fallback
            } catch (\Throwable $dbErr) {
                // DB write failed — fall through to file log below
                error_log('[NuErrorLogger] DB write failed: ' . $dbErr->getMessage());
            }
        }

        // ── File fallback — always works, even before DB is ready ─────────────
        $logDir  = defined('NU_ROOT') ? NU_ROOT . '/logs' : __DIR__ . '/../logs';
        $logFile = $logDir . '/nuerror.log';
        // Create logs/ directory if it does not exist
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $cleanFile = $this->stripRoot($file);
        $ctxJson   = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $entry     = sprintf(
            "[%s] [%s] [%s] %s | %s:%d | %s | %s\n",
            date('Y-m-d H:i:s'),
            $type,
            strtoupper($severity),
            $message,
            $cleanFile,
            $line,
            $requestUri,
            $ctxJson
        );
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        // Also keep PHP's native error_log in sync
        error_log("[NuErrorLogger] [{$type}] {$severity}: {$message} | {$cleanFile}:{$line}");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function buildTrace(array $frames): string {
        $lines = [];
        foreach ($frames as $i => $f) {
            $file  = $this->stripRoot($f['file'] ?? '');
            $line  = $f['line'] ?? '?';
            $fn    = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
            $lines[] = "#{$i} {$fn}() — {$file}:{$line}";
        }
        return implode("\n", $lines);
    }

    private function stripRoot(string $path): string {
        $root = defined('NU_ROOT') ? NU_ROOT : '';
        if ($root && str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        }
        return $path;
    }

    private function phpErrnoToSeverity(int $errno): string {
        return match(true) {
            in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])  => self::SEV_FATAL,
            in_array($errno, [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING])     => self::SEV_WARNING,
            in_array($errno, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED])            => self::SEV_INFO,
            default => self::SEV_ERROR,
        };
    }

    private function phpErrnoToName(int $errno): string {
        $map = [
            E_ERROR           => 'E_ERROR',
            E_WARNING         => 'E_WARNING',
            E_PARSE           => 'E_PARSE',
            E_NOTICE          => 'E_NOTICE',
            E_CORE_ERROR      => 'E_CORE_ERROR',
            E_CORE_WARNING    => 'E_CORE_WARNING',
            E_COMPILE_ERROR   => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR      => 'E_USER_ERROR',
            E_USER_WARNING    => 'E_USER_WARNING',
            E_USER_NOTICE     => 'E_USER_NOTICE',
            E_STRICT          => 'E_STRICT',
            E_DEPRECATED      => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            E_ALL             => 'E_ALL',
        ];
        return $map[$errno] ?? "E_UNKNOWN({$errno})";
    }

    private function __clone() {}
    public function __wakeup() { throw new \RuntimeException('Cannot unserialize NuErrorLogger.'); }
}
