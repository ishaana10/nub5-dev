<?php
declare(strict_types=1);
/**
 * NuErrorLogger — captures PHP errors, uncaught exceptions, SQL errors, and JS errors.
 * PHP 7.4 compatible.
 *
 * Usage (called once in config.php / bootstrap):
 *   NuErrorLogger::register();
 *
 * SQL errors are captured by wrapping NuDatabase::query() externally
 * OR by calling NuErrorLogger::logSql($sql, $error, $params) directly in catch blocks.
 *
 * JS errors are posted to api/errorlog.php?action=log_js and stored here via logJs().
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
     * Call once after DB is available.
     */
    public static function register(): void {
        if (self::$registered) return;
        self::$registered = true;

        // PHP errors → our handler
        set_error_handler([self::getInstance(), 'handlePhpError']);

        // Uncaught exceptions → our handler
        set_exception_handler([self::getInstance(), 'handleException']);

        // Fatal errors via shutdown function
        register_shutdown_function([self::getInstance(), 'handleShutdown']);
    }

    /**
     * PHP set_error_handler callback.
     */
    public function handlePhpError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
        $severity = $this->phpErrnoToSeverity($errno);
        $type     = self::TYPE_PHP;
        $message  = $errstr;
        $context  = [
            'errno'   => $errno,
            'errtype' => $this->phpErrnoToName($errno),
            'file'    => $this->stripRoot($errfile),
            'line'    => $errline,
        ];
        $trace = $this->buildTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8));
        $this->write($type, $severity, $message, $context, $trace, $errfile, $errline);
        // Return false to let PHP also write to its own error log
        return false;
    }

    /**
     * PHP set_exception_handler callback.
     */
    public function handleException(\Throwable $e): void {
        $severity = self::SEV_FATAL;
        $type     = self::TYPE_PHP;
        $message  = get_class($e) . ': ' . $e->getMessage();
        $context  = [
            'exception' => get_class($e),
            'code'      => $e->getCode(),
            'file'      => $this->stripRoot($e->getFile()),
            'line'      => $e->getLine(),
        ];
        $trace = $this->buildTrace($e->getTrace());
        $this->write($type, $severity, $message, $context, $trace, $e->getFile(), $e->getLine());
    }

    /**
     * Shutdown handler — catches fatal errors that set_error_handler misses.
     */
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
     * Log a SQL error. Call this in catch blocks around DB operations.
     *
     * @param string      $sql     The query that failed
     * @param string      $error   PDOException message or error string
     * @param array       $params  Bound parameters (will be sanitised)
     * @param string|null $callerFile
     * @param int         $callerLine
     */
    public static function logSql(
        string $sql,
        string $error,
        array $params = [],
        ?string $callerFile = null,
        int $callerLine = 0
    ): void {
        // Redact password-like params
        $safeParams = [];
        foreach ($params as $k => $v) {
            $key = strtolower((string)$k);
            $safeParams[$k] = (str_contains($key, 'pass') || str_contains($key, 'secret') || str_contains($key, 'token'))
                ? '***' : $v;
        }
        $context = [
            'sql'    => $sql,
            'params' => $safeParams,
        ];
        if ($callerFile) $context['file'] = self::getInstance()->stripRoot($callerFile);
        if ($callerLine) $context['line'] = $callerLine;

        $trace = self::getInstance()->buildTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8));
        self::getInstance()->write(self::TYPE_SQL, self::SEV_ERROR, $error, $context, $trace, $callerFile ?? '', $callerLine);
    }

    /**
     * Log a JS error received from the frontend.
     * Called by api/errorlog.php after validating the POST payload.
     *
     * @param array $payload  Keys: message, source, lineno, colno, stack, url, userAgent
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
            self::TYPE_JS,
            self::SEV_ERROR,
            $message,
            $context,
            $trace,
            $payload['source'] ?? '',
            (int)($payload['lineno'] ?? 0)
        );
    }

    /**
     * Log an application-level message (call anywhere in PHP).
     * e.g.  NuErrorLogger::logApp('Form save failed', ['form_id'=>5], NuErrorLogger::SEV_WARNING);
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
    // Internal write
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
        // Determine session user if available (don't touch session if shutting down)
        $userId   = null;
        $userName = null;
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $userId   = $_SESSION['nu_user_id']   ?? null;
                $userName = $_SESSION['nu_username']  ?? null;
            }
        } catch (\Throwable $e) { /* ignore */ }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';

        // Try to write to DB; fall back to file log silently
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
        } catch (\Throwable $dbErr) {
            // Last resort — write to PHP error log
            error_log("[NuErrorLogger] DB write failed: " . $dbErr->getMessage());
            error_log("[NuErrorLogger] Original [{$type}] {$severity}: {$message}");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function buildTrace(array $frames): string {
        $lines = [];
        foreach ($frames as $i => $f) {
            $file  = $this->stripRoot($f['file'] ?? '');
            $line  = $f['line']     ?? '?';
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
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_PARSE             => 'E_PARSE',
            E_NOTICE            => 'E_NOTICE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_CORE_WARNING      => 'E_CORE_WARNING',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_STRICT            => 'E_STRICT',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
            E_ALL               => 'E_ALL',
        ];
        return $map[$errno] ?? "E_UNKNOWN({$errno})";
    }

    private function __clone() {}
    public function __wakeup() { throw new \RuntimeException('Cannot unserialize NuErrorLogger.'); }
}
