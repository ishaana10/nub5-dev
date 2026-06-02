# Email System — nub5-dev

## Architecture

```
core/EmailService.php      ← PHP service class (send, render template, log)
api/email.php              ← REST API endpoint
assets/js/email-manager.js ← JS helper for frontend modules
install_email.sql          ← DB migration (run once)
```

## 1. Database Setup

Run `install_email.sql` in phpMyAdmin or via MySQL CLI:

```sql
SOURCE install_email.sql;
```

This creates three tables:
- `nu_email_settings` — per-app SMTP config storage
- `nu_email_templates` — HTML email templates with `{{variable}}` placeholders
- `nu_email_log` — audit log of every sent/failed email

## 2. Configuration (`config.php`)

Add these constants to your `config.php` or `config.local.php`:

```php
// Email driver: 'mail' | 'smtp' | 'sendmail'
define('EMAIL_DRIVER',        'smtp');
define('EMAIL_SMTP_HOST',     'mail.yourdomain.com');
define('EMAIL_SMTP_PORT',     587);          // 465 for SSL
define('EMAIL_SMTP_SECURE',   'tls');        // 'ssl' or ''
define('EMAIL_SMTP_USERNAME', 'user@yourdomain.com');
define('EMAIL_SMTP_PASSWORD', 'your_password');
define('EMAIL_FROM',          'noreply@yourdomain.com');
define('EMAIL_FROM_NAME',     'Your App Name');
```

For A2Hosting shared hosting, use cPanel → Email Accounts SMTP credentials.

## 3. Sending Email from PHP

### Direct send
```php
require_once __DIR__ . '/core/EmailService.php';
$svc    = new EmailService();
$result = $svc->send('user@example.com', 'Hello', '<p>Hello world</p>');
// $result = ['success' => true, 'message' => 'Email sent via SMTP.']
```

### Via template (recommended)
```php
$rendered = EmailService::renderTemplate('form_submission', [
    'form_name'    => 'Contact Us',
    'submitted_by' => 'John Doe',
    'submitted_at' => date('Y-m-d H:i'),
    'record_id'    => 42,
    'review_url'   => 'https://yourapp.com/forms/view/42',
]);
$svc->send('admin@example.com', $rendered['subject'], $rendered['body']);
```

### Integrating into form-handler.php (on save event)
```php
// Inside form-handler.php after successful record save:
if ($form_config['email_notification_enabled']) {
    require_once __DIR__ . '/../core/EmailService.php';
    $svc = new EmailService();
    $rendered = EmailService::renderTemplate('form_submission', [
        'form_name'    => $form_name,
        'submitted_by' => $_SESSION['user_name'] ?? 'Unknown',
        'submitted_at' => date('Y-m-d H:i:s'),
        'record_id'    => $new_record_id,
        'review_url'   => BASE_URL . '/index.php?form_id=' . $form_id . '&record_id=' . $new_record_id,
    ]);
    if ($rendered) {
        $svc->send($form_config['notification_email'], $rendered['subject'], $rendered['body']);
    }
}
```

## 4. REST API Reference

| Method | Action              | Description                         |
|--------|---------------------|-------------------------------------|
| POST   | `send`              | Send email (direct or via template) |
| POST   | `test`              | Send test email to verify config    |
| GET    | `templates`         | List all templates                  |
| POST   | `save_template`     | Create or update a template         |
| POST   | `delete_template`   | Delete a template by id             |
| GET    | `logs`              | Paginated email send log            |

### Send via template
```json
POST /api/email.php
{
  "action": "send",
  "to": "user@example.com",
  "template_slug": "form_submission",
  "variables": {
    "form_name": "Contact Us",
    "submitted_by": "Jane",
    "submitted_at": "2026-06-02 10:00",
    "record_id": "99",
    "review_url": "https://yourapp.com/review/99"
  }
}
```

## 5. Frontend (JavaScript)

Include `assets/js/email-manager.js` on any admin page.

```javascript
// Send a template email
await EmailManager.sendTemplate('user@example.com', 'form_submission', { form_name: 'My Form' });

// Render template manager UI into a div
EmailManager.renderTemplatesTable('emailTemplatesContainer');

// Quick test
EmailManager.sendTest('you@example.com');
```

## 6. Built-in Templates

| Slug                    | Use Case                        | Key Variables |
|-------------------------|---------------------------------|---------------|
| `form_submission`       | Form save notification          | `form_name`, `submitted_by`, `submitted_at`, `record_id`, `review_url` |
| `user_welcome`          | New user account creation       | `user_name`, `username`, `app_name`, `temp_password`, `login_url` |
| `password_reset`        | Password reset link             | `user_name`, `app_name`, `reset_url` |
| `workflow_notification` | Workflow step action required   | `recipient_name`, `workflow_name`, `step_name`, `message`, `action_url` |

## 7. Adding New Templates

Either via the Admin UI (`EmailManager.renderTemplatesTable()`) or directly in SQL:

```sql
INSERT INTO nu_email_templates (name, slug, subject, body) VALUES
('My Template', 'my_slug', 'Subject here {{var}}', '<p>Hello {{name}}</p>');
```
