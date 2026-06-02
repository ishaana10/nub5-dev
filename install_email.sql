-- ============================================================
-- nub5-dev Email System Migration
-- Run once after install_phase7.sql
-- ============================================================

-- Email configuration table (stored per-app, editable in admin)
CREATE TABLE IF NOT EXISTS `nu_email_settings` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NOT NULL,
  `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default SMTP settings (values overridden by config.php constants)
INSERT IGNORE INTO `nu_email_settings` (`setting_key`, `setting_value`) VALUES
  ('driver',        'mail'),
  ('smtp_host',     ''),
  ('smtp_port',     '587'),
  ('smtp_secure',   'tls'),
  ('smtp_auth',     '1'),
  ('smtp_username', ''),
  ('smtp_password', ''),
  ('from_email',    'noreply@example.com'),
  ('from_name',     'nub5-dev');

-- Email templates table
CREATE TABLE IF NOT EXISTS `nu_email_templates` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(150) NOT NULL,
  `slug`        VARCHAR(100) NOT NULL UNIQUE COMMENT 'Machine-readable key used in code: e.g. form_submission',
  `description` TEXT,
  `subject`     VARCHAR(255) NOT NULL,
  `body`        LONGTEXT NOT NULL COMMENT 'HTML body, supports {{variable}} placeholders',
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed starter templates
INSERT IGNORE INTO `nu_email_templates` (`name`, `slug`, `subject`, `body`, `description`) VALUES
(
  'Form Submission Notification',
  'form_submission',
  'New form submission: {{form_name}}',
  '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:auto">
<h2 style="color:#2d7dd2">New Form Submission</h2>
<p>A new submission was received for the form <strong>{{form_name}}</strong>.</p>
<table style="width:100%;border-collapse:collapse">
  <tr><td style="padding:8px;border:1px solid #ddd"><strong>Submitted by</strong></td><td style="padding:8px;border:1px solid #ddd">{{submitted_by}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd"><strong>Date</strong></td><td style="padding:8px;border:1px solid #ddd">{{submitted_at}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd"><strong>Record ID</strong></td><td style="padding:8px;border:1px solid #ddd">{{record_id}}</td></tr>
</table>
<p style="margin-top:16px"><a href="{{review_url}}" style="background:#2d7dd2;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px">Review Submission</a></p>
<hr/><p style="font-size:12px;color:#888">This is an automated notification from nub5-dev.</p>
</body></html>',
  'Sent when a form is submitted. Variables: {{form_name}}, {{submitted_by}}, {{submitted_at}}, {{record_id}}, {{review_url}}'
),
(
  'Welcome / Account Created',
  'user_welcome',
  'Welcome to {{app_name}}, {{user_name}}!',
  '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:auto">
<h2 style="color:#2d7dd2">Welcome, {{user_name}}!</h2>
<p>Your account on <strong>{{app_name}}</strong> has been created.</p>
<p><strong>Username:</strong> {{username}}<br/><strong>Temporary Password:</strong> {{temp_password}}</p>
<p>Please log in and change your password immediately.</p>
<p><a href="{{login_url}}" style="background:#2d7dd2;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px">Log In Now</a></p>
<hr/><p style="font-size:12px;color:#888">nub5-dev Automated Notification</p>
</body></html>',
  'Sent when a new user account is created. Variables: {{user_name}}, {{username}}, {{app_name}}, {{temp_password}}, {{login_url}}'
),
(
  'Password Reset',
  'password_reset',
  'Password Reset Request - {{app_name}}',
  '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:auto">
<h2 style="color:#2d7dd2">Password Reset</h2>
<p>Hi {{user_name}}, we received a request to reset your password.</p>
<p><a href="{{reset_url}}" style="background:#e63946;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px">Reset My Password</a></p>
<p style="color:#888;font-size:12px">This link expires in 1 hour. If you did not request this, ignore this email.</p>
<hr/><p style="font-size:12px;color:#888">nub5-dev Automated Notification</p>
</body></html>',
  'Password reset link email. Variables: {{user_name}}, {{app_name}}, {{reset_url}}'
),
(
  'Workflow Action Notification',
  'workflow_notification',
  'Action Required: {{workflow_name}} - Step {{step_name}}',
  '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:auto">
<h2 style="color:#2d7dd2">Workflow Notification</h2>
<p>Hi {{recipient_name}},</p>
<p>The workflow <strong>{{workflow_name}}</strong> requires your attention at step: <strong>{{step_name}}</strong>.</p>
<p><strong>Details:</strong> {{message}}</p>
<p><a href="{{action_url}}" style="background:#2d7dd2;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px">Take Action</a></p>
<hr/><p style="font-size:12px;color:#888">nub5-dev Automated Notification</p>
</body></html>',
  'Workflow step notification. Variables: {{recipient_name}}, {{workflow_name}}, {{step_name}}, {{message}}, {{action_url}}'
);

-- Email log table
CREATE TABLE IF NOT EXISTS `nu_email_log` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `recipient`     VARCHAR(500) NOT NULL,
  `subject`       VARCHAR(255) NOT NULL,
  `status`        ENUM('SENT','FAIL') NOT NULL DEFAULT 'SENT',
  `error_message` VARCHAR(1000) DEFAULT NULL,
  `template_slug` VARCHAR(100) DEFAULT NULL,
  `sent_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_status`   (`status`),
  INDEX `idx_sent_at`  (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
