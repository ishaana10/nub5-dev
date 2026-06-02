<?php
/**
 * EmailService - Core email sending service for nub5-dev
 * Supports PHP mail(), SMTP (via PHPMailer-compatible native SMTP), and sendmail.
 * Can be reused from forms, workflows, and other modules.
 */
class EmailService {

    private $config;

    public function __construct(array $config = []) {
        // Merge with global config if available
        $defaults = [
            'driver'        => 'mail',   // 'mail' | 'smtp' | 'sendmail'
            'smtp_host'     => '',
            'smtp_port'     => 587,
            'smtp_secure'   => 'tls',    // 'tls' | 'ssl' | ''
            'smtp_auth'     => true,
            'smtp_username' => '',
            'smtp_password' => '',
            'from_email'    => 'noreply@example.com',
            'from_name'     => 'nub5-dev',
            'reply_to'      => '',
        ];

        // Pull from global config.php constants if defined
        if (defined('EMAIL_DRIVER'))        $defaults['driver']        = EMAIL_DRIVER;
        if (defined('EMAIL_SMTP_HOST'))     $defaults['smtp_host']     = EMAIL_SMTP_HOST;
        if (defined('EMAIL_SMTP_PORT'))     $defaults['smtp_port']     = EMAIL_SMTP_PORT;
        if (defined('EMAIL_SMTP_SECURE'))   $defaults['smtp_secure']   = EMAIL_SMTP_SECURE;
        if (defined('EMAIL_SMTP_USERNAME')) $defaults['smtp_username'] = EMAIL_SMTP_USERNAME;
        if (defined('EMAIL_SMTP_PASSWORD')) $defaults['smtp_password'] = EMAIL_SMTP_PASSWORD;
        if (defined('EMAIL_FROM'))          $defaults['from_email']    = EMAIL_FROM;
        if (defined('EMAIL_FROM_NAME'))     $defaults['from_name']     = EMAIL_FROM_NAME;

        $this->config = array_merge($defaults, $config);
    }

    /**
     * Send an email.
     *
     * @param string|array $to      Recipient email or ['email' => 'name'] map
     * @param string       $subject Subject line
     * @param string       $body    HTML body
     * @param array        $options ['cc', 'bcc', 'attachments', 'reply_to', 'text_body']
     * @return array ['success' => bool, 'message' => string]
     */
    public function send($to, string $subject, string $body, array $options = []): array {
        try {
            switch ($this->config['driver']) {
                case 'smtp':
                    return $this->sendSmtp($to, $subject, $body, $options);
                case 'sendmail':
                    return $this->sendMail($to, $subject, $body, $options, true);
                default:
                    return $this->sendMail($to, $subject, $body, $options, false);
            }
        } catch (\Throwable $e) {
            $this->logEmail('FAIL', $to, $subject, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // PHP mail() driver
    // -------------------------------------------------------------------------
    private function sendMail($to, string $subject, string $body, array $options, bool $useSendmail): array {
        $toStr    = $this->formatRecipients($to);
        $headers  = $this->buildHeaders($options);
        $textBody = $options['text_body'] ?? strip_tags($body);

        // Multipart MIME
        $boundary = md5(uniqid(time()));
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

        $fullBody  = "--$boundary\r\n";
        $fullBody .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $fullBody .= chunk_split(base64_encode($textBody)) . "\r\n";
        $fullBody .= "--$boundary\r\n";
        $fullBody .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $fullBody .= chunk_split(base64_encode($body)) . "\r\n";
        $fullBody .= "--{$boundary}--";

        $extraParams = $useSendmail ? '-f' . $this->config['from_email'] : '';
        $result = @mail($toStr, $subject, $fullBody, $headers, $extraParams);

        $this->logEmail($result ? 'SENT' : 'FAIL', $to, $subject, $result ? '' : error_get_last()['message'] ?? 'mail() returned false');
        return ['success' => (bool)$result, 'message' => $result ? 'Email sent successfully.' : 'mail() failed.'];
    }

    // -------------------------------------------------------------------------
    // Native SMTP driver (socket-based, no PHPMailer required)
    // -------------------------------------------------------------------------
    private function sendSmtp($to, string $subject, string $body, array $options): array {
        $host    = $this->config['smtp_host'];
        $port    = (int)$this->config['smtp_port'];
        $secure  = strtolower($this->config['smtp_secure']);
        $timeout = 15;

        $address = ($secure === 'ssl') ? "ssl://{$host}" : $host;
        $sock = @fsockopen($address, $port, $errno, $errstr, $timeout);
        if (!$sock) throw new \RuntimeException("SMTP connect failed ({$errno}): {$errstr}");

        $recv = fgets($sock, 512);
        if (substr($recv, 0, 3) !== '220') throw new \RuntimeException("SMTP greeting failed: {$recv}");

        $this->smtpSend($sock, "EHLO " . gethostname());

        if ($secure === 'tls') {
            $this->smtpSend($sock, 'STARTTLS');
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->smtpSend($sock, "EHLO " . gethostname());
        }

        if ($this->config['smtp_auth']) {
            $this->smtpSend($sock, 'AUTH LOGIN');
            $this->smtpSend($sock, base64_encode($this->config['smtp_username']));
            $this->smtpSend($sock, base64_encode($this->config['smtp_password']));
        }

        $fromEmail = $this->config['from_email'];
        $this->smtpSend($sock, "MAIL FROM:<{$fromEmail}>");

        $recipients = $this->normalizeRecipients($to);
        foreach ($recipients as $email => $name) {
            $this->smtpSend($sock, "RCPT TO:<{$email}>");
        }
        foreach (($options['cc'] ?? []) as $cc)  { $this->smtpSend($sock, "RCPT TO:<{$cc}>"); }
        foreach (($options['bcc'] ?? []) as $bcc) { $this->smtpSend($sock, "RCPT TO:<{$bcc}>"); }

        $this->smtpSend($sock, 'DATA', '354');

        $boundary = md5(uniqid(time()));
        $textBody = $options['text_body'] ?? strip_tags($body);
        $toStr    = $this->formatRecipients($to);
        $fromStr  = $this->config['from_name'] ? "{$this->config['from_name']} <{$fromEmail}>" : $fromEmail;
        $replyTo  = $options['reply_to'] ?? $this->config['reply_to'];

        $msg  = "From: {$fromStr}\r\n";
        $msg .= "To: {$toStr}\r\n";
        if (!empty($options['cc']))  $msg .= "Cc: " . implode(', ', $options['cc']) . "\r\n";
        if ($replyTo)                $msg .= "Reply-To: {$replyTo}\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $msg .= "Date: " . date('r') . "\r\n\r\n";
        $msg .= "--$boundary\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($textBody)) . "\r\n";
        $msg .= "--$boundary\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($body)) . "\r\n";
        $msg .= "--{$boundary}--\r\n";
        $msg .= ".";

        $this->smtpSend($sock, $msg);
        $this->smtpSend($sock, 'QUIT', '221');
        fclose($sock);

        $this->logEmail('SENT', $to, $subject, '');
        return ['success' => true, 'message' => 'Email sent via SMTP.'];
    }

    private function smtpSend($sock, string $cmd, string $expectCode = null): string {
        fwrite($sock, $cmd . "\r\n");
        $response = fgets($sock, 512);
        $code = substr($response, 0, 3);
        $expected = $expectCode ?? ($cmd === 'DATA' ? '354' : '2');
        if (strlen($expected) === 1 && $code[0] !== $expected) {
            throw new \RuntimeException("SMTP error for [{$cmd}]: {$response}");
        } elseif (strlen($expected) === 3 && $code !== $expected) {
            throw new \RuntimeException("SMTP error for [{$cmd}]: {$response}");
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Template rendering
    // -------------------------------------------------------------------------
    /**
     * Render an email template from the DB by slug, replacing {{placeholders}}.
     *
     * @param string $slug       Template slug (e.g. 'form_submission')
     * @param array  $variables  Key-value pairs to replace in subject and body
     * @return array ['subject' => string, 'body' => string] or null if not found
     */
    public static function renderTemplate(string $slug, array $variables = [], $db = null): ?array {
        if (!$db) {
            global $db; // fall back to global DB connection
        }
        if (!$db) return null;

        $slug = $db->real_escape_string($slug);
        $row = $db->query("SELECT subject, body FROM nu_email_templates WHERE slug = '{$slug}' AND is_active = 1 LIMIT 1");
        if (!$row || $row->num_rows === 0) return null;

        $tpl = $row->fetch_assoc();
        foreach ($variables as $key => $value) {
            $tpl['subject'] = str_replace('{{' . $key . '}}', $value, $tpl['subject']);
            $tpl['body']    = str_replace('{{' . $key . '}}', $value, $tpl['body']);
        }
        return $tpl;
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------
    private function logEmail(string $status, $to, string $subject, string $error = ''): void {
        global $db;
        if (!$db) return;

        $toStr   = is_array($to) ? implode(',', array_keys($to)) : $to;
        $toStr   = $db->real_escape_string($toStr);
        $subject = $db->real_escape_string($subject);
        $error   = $db->real_escape_string(substr($error, 0, 1000));
        $status  = $db->real_escape_string($status);

        $db->query("INSERT INTO nu_email_log (recipient, subject, status, error_message, sent_at)
                    VALUES ('{$toStr}', '{$subject}', '{$status}', '{$error}', NOW())");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    private function normalizeRecipients($to): array {
        if (is_string($to)) return [$to => ''];
        if (isset($to[0])) { // indexed array of emails
            $out = [];
            foreach ($to as $email) $out[$email] = '';
            return $out;
        }
        return $to; // already ['email' => 'name']
    }

    private function formatRecipients($to): string {
        $parts = [];
        foreach ($this->normalizeRecipients($to) as $email => $name) {
            $parts[] = $name ? "{$name} <{$email}>" : $email;
        }
        return implode(', ', $parts);
    }

    private function buildHeaders(array $options): string {
        $fromEmail = $this->config['from_email'];
        $fromName  = $this->config['from_name'];
        $replyTo   = $options['reply_to'] ?? $this->config['reply_to'];
        $fromStr   = $fromName ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>" : $fromEmail;

        $h  = "From: {$fromStr}\r\n";
        $h .= "MIME-Version: 1.0\r\n";
        if ($replyTo)                        $h .= "Reply-To: {$replyTo}\r\n";
        if (!empty($options['cc']))          $h .= "Cc: " . implode(', ', $options['cc']) . "\r\n";
        if (!empty($options['bcc']))         $h .= "Bcc: " . implode(', ', $options['bcc']) . "\r\n";
        $h .= "X-Mailer: nub5-dev\r\n";
        return $h;
    }
}
