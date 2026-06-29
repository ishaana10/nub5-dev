# nuvis

A modern, responsive rebuild of the nuBuilder Forte low-code platform — PHP/MySQL, fully self-hosted.

> **Live:** [ict-fj.com/nuvis/](http://ict-fj.com/nuvis/) · **Host:** A2Hosting (cPanel) · **Stack:** PHP 7.4, MySQL 5.7+, Apache

---

## Architecture

```
nuvis/
├── config.php                    # Global configuration
├── config.local.php.example      # Local override template
├── config.user_fields.php.example
├── index.php                     # App shell (login + SPA dashboard)
├── setup.php                     # Web-based installer
├── migrate.php                   # Migration runner from nuBuilder Forte 4.9
├── manifest.json                 # PWA manifest
├── sw.js                         # PWA service worker
├── .htaccess                     # Apache rewrite + security rules
│
├── install.sql                   # Base schema + seed data
├── install_phase3.sql            # Phase 3 schema additions
├── install_phase4.sql
├── install_phase5.sql
├── install_phase6.sql
├── install_phase7.sql
├── install_appcloner.sql         # App Cloner module schema
├── install_dashboard_widgets.sql # Dashboard widget schema
├── install_email.sql             # Email system schema
├── install_email_forms.sql       # Email-forms link schema
├── install_errorlog.sql          # Error log schema
├── install_menus_seed.sql        # Menu seed data
├── migrate_phase6_patch1.sql     # Phase 6 hotfix
├── migrate_phase8.sql            # Phase 8 migration
├── migrate_widget_icon.sql       # Widget icon migration
│
├── core/
│   ├── Database.php              # PDO database abstraction layer
│   ├── Auth.php                  # Session auth, lockout, 2FA hooks, CSRF, RBAC
│   ├── Audit.php                 # Audit trail logging
│   ├── Api.php                   # REST API foundation (token auth + rate limiting)
│   ├── FormRenderer.php          # Metadata-to-form renderer
│   ├── ReportRenderer.php        # SQL report renderer (tables + Chart.js)
│   ├── QueryExecutor.php         # Safe SELECT-only SQL executor
│   ├── MenuRenderer.php          # Dynamic menu renderer
│   ├── EmailService.php          # SMTP email service (full)
│   ├── Emailer.php               # Lightweight emailer wrapper
│   ├── ErrorLogger.php           # Structured error logging
│   ├── AppCloner.php             # App template clone engine (38KB)
│   ├── Workflow.php              # Workflow engine core
│   ├── PluginManager.php         # Plugin registration + loader
│   ├── module_bootstrap.php      # Module bootstrap helper
│   └── oldApi.php                # Legacy API shim (compatibility)
│
├── api/
│   ├── auth.php                  # Login / logout / me
│   ├── crud.php                  # Generic CRUD for any table
│   ├── form.php                  # Form runtime API
│   ├── report.php                # Report runtime API
│   ├── query.php                 # Query runtime API
│   ├── upload.php                # File upload handler
│   ├── export.php                # CSV / JSON export
│   └── import.php                # CSV import
│
├── modules/
│   ├── dashboard/                # KPI cards, activity feed, quick actions
│   ├── forms/                    # Form builder + runtime preview
│   ├── reports/                  # Report builder + table/chart output
│   ├── queries/                  # Query builder + parameter forms
│   ├── users/                    # User CRUD with roles
│   ├── roles/                    # Role/permission matrix
│   ├── menus/                    # Menu builder
│   ├── audit/                    # Audit trail viewer
│   ├── files/                    # File manager
│   ├── import_export/            # Data import/export tools
│   ├── appcloner/                # App template cloner UI
│   ├── calendar/                 # Calendar / scheduler module
│   ├── documents/                # Document management module
│   ├── errorlog/                 # Error log viewer
│   ├── inspector/                # Schema/data inspector
│   ├── integrations/             # External integrations
│   ├── password/                 # Password change UI
│   ├── password_policy/          # Password policy manager
│   ├── plugins/                  # Plugin management UI
│   ├── widgets/                  # Dashboard widget builder
│   ├── workflow/                 # Workflow engine UI
│   ├── ai/                       # AI integration module
│   └── email-settings.html       # Standalone email settings UI
│
└── assets/
    ├── css/nubuilder-next.css    # Design system (CSS custom properties)
    └── js/nubuilder-next.js      # App framework + utilities
```

---

## Requirements

- PHP 7.4 (see Deployment Notes before attempting upgrade)
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` enabled
- Modern browser (Chrome, Firefox, Safari, Edge)

---

## Quick Start

1. Upload all files to your web server
2. Visit `setup.php` in your browser
3. Follow the environment check and database installation steps
4. Login with `globeadmin` / `password` — **change this immediately**

## Manual Installation

```bash
# Import base schema
mysql -u root -p < install.sql

# Import module schemas as needed
mysql -u root -p < install_appcloner.sql
mysql -u root -p < install_dashboard_widgets.sql
mysql -u root -p < install_email.sql
mysql -u root -p < install_errorlog.sql
mysql -u root -p < install_menus_seed.sql

# Apply migrations in order
mysql -u root -p < install_phase3.sql
mysql -u root -p < install_phase4.sql
mysql -u root -p < install_phase5.sql
mysql -u root -p < install_phase6.sql
mysql -u root -p < migrate_phase6_patch1.sql
mysql -u root -p < install_phase7.sql
mysql -u root -p < migrate_phase8.sql
mysql -u root -p < migrate_widget_icon.sql

# Set directory permissions
chmod 755 uploads/ logs/

# Copy and edit config
cp config.local.php.example config.local.php
```

---

## Deployment Notes

| Setting | Value |
|---------|-------|
| Host | A2Hosting via cPanel |
| Live URL | ict-fj.com/nbv5/ |
| PHP Version | 7.4 (host default) |
| Web Server | Apache (LiteSpeed/EasyApache) |
| Database | MySQL 5.7+ |

### PHP Version Upgrade (Status: Blocked)

- PHP 8.1 upgrade attempted **27 May 2026** using per-folder `.htaccess` `AddHandler`
- All PHP files in `/nbv5/` returned **403 Forbidden** when `AddHandler application/x-httpd-ea-php81` was added
- `php_value` directives in `.htaccess` cause **500 Internal Server Error** — do not use them
- No parent `/public_html/.htaccess` found; likely a **ModSecurity rule or host-level restriction**
- **Next step:** Contact A2Hosting support to whitelist PHP 8.1 for `/public_html/nbv5/` or get correct handler string
- Host supports up to PHP 8.5 — upgrade is possible once host restriction is resolved

### .htaccess Rules

- Do **not** add `php_value` directives — not supported on this host
- Do **not** add `AddHandler` PHP version overrides without confirming with A2Hosting support first
- Hidden file protection uses `RewriteRule` (not `FilesMatch Order/Deny`) for Apache 2.4 compatibility

---

## Core Features

### Design System
- Fully responsive — mobile to desktop
- Dark / Light / Auto theme support
- Card-based UI with CSS custom properties
- Accessible keyboard navigation
- PWA ready (service worker + web manifest)

### Security
- Password hashing (bcrypt)
- Login attempt lockout
- Session timeout + regeneration
- 2FA hooks (TOTP ready)
- CSRF token generation
- Role-based permissions (RBAC)
- Full audit trail

### API Layer
- Token-based authentication with rate limiting
- Generic CRUD endpoints for any table
- Form / Report / Query runtime APIs
- File upload with type/size validation
- CSV / JSON import and export

### Runtime Renderers
- **FormRenderer** — turns JSON metadata into live data-entry forms with validation
- **ReportRenderer** — executes SQL safely, renders tables or Chart.js charts
- **QueryExecutor** — parameter binding, SELECT-only validation, CSV export

### Builders
- Visual form builder with drag-and-drop canvas
- Report builder with SQL + column definitions + chart support
- Query builder with parameter definitions
- Menu builder for navigation management

### Business Modules
- Dashboard with KPI cards and activity feed
- User / Role / Permission management
- Audit trail with pagination
- File manager with upload/delete
- Import / Export tools (CSV, JSON)
- Email system (SMTP, templates, form-triggered emails)
- Error log viewer with structured output
- Password management + configurable password policies
- App Cloner — clone and template entire applications
- Dashboard widgets (configurable, icon support)

### Extended Modules (In Progress)
- **Workflow engine** — `core/Workflow.php` + `modules/workflow/` scaffolded
- **Calendar / Scheduler** — `modules/calendar/` scaffolded
- **Document management** — `modules/documents/` scaffolded
- **AI integration** — `modules/ai/` scaffolded
- **Plugin system** — `core/PluginManager.php` + `modules/plugins/` scaffolded
- **Integrations** — `modules/integrations/` scaffolded
- **Inspector** — `modules/inspector/` scaffolded

---

## Database Schema

### Core Tables
- `nu_users` — User accounts with roles
- `nu_roles` — Role definitions
- `nu_permissions` — Permission catalog
- `nu_role_permissions` — Role-permission mapping

### Builder Tables
- `nu_forms` — Form metadata (JSON layout)
- `nu_reports` — Report metadata (SQL + columns)
- `nu_queries` — Query metadata (SQL + parameters)
- `nu_menus` — Navigation structure

### System Tables
- `nu_api_tokens` — API keys
- `nu_api_usage` — Rate limiting
- `nu_audit_log` — Activity logging
- `nu_files` — Upload tracking
- `nu_email_*` — Email config, templates, queue, log
- `nu_error_log` — Structured error log
- `nu_widgets` — Dashboard widget definitions
- `nu_app_templates` — App Cloner templates

---


## Development Roadmap

### Phase 1 — Foundation ✅ Complete
- [x] App shell + responsive design system
- [x] Authentication + security layer (bcrypt, lockout, CSRF, RBAC)
- [x] Database abstraction (PDO)
- [x] Audit trail
- [x] REST API foundation (token auth, rate limiting)
- [x] Dashboard module (KPI cards, activity feed)
- [x] User / Role management
- [x] File uploads

### Phase 2 — Builders & Runtime ✅ Complete
- [x] Runtime form renderer (JSON → form, validation)
- [x] Runtime report renderer (tables + Chart.js)
- [x] Safe query executor with SELECT-only validation
- [x] Advanced field types (lookup, subform, repeater)
- [x] Menu builder UI
- [x] Import / Export (CSV, JSON)
- [x] Chart.js integration
- [x] SQL security hardening

### Phase 3 — Extended Modules ✅ Partially Complete
- [x] Email system (SMTP, templates, form-triggered, settings UI)
- [x] Error log module (structured logging, viewer)
- [x] Password management + policy module
- [x] App Cloner (clone/template entire apps)
- [x] Dashboard widget builder (configurable, icon support)
- [x] MenuRenderer core class
- [x] Workflow engine core (`Workflow.php`)
- [x] Plugin manager core (`PluginManager.php`)
- [ ] Workflow engine — UI builder + trigger configuration
- [ ] Calendar / Scheduler — full UI + recurring events
- [ ] Document management — full UI + versioning
- [ ] AI integration — model config + form-assist features
- [ ] Plugin marketplace / registry
- [ ] External integrations (webhooks, REST connectors)
- [ ] Inspector tool — full schema/data browser

### Phase 4 — Polish & Scale 🔲 Planned
- [ ] Real-time collaboration (WebSockets or polling)
- [ ] OpenAPI / Swagger documentation
- [ ] Mobile PWA — offline support + push notifications
- [ ] Multi-language / i18n support
- [ ] MSSQL database compatibility
- [ ] Performance profiling + query optimization tools

---

## Security Notes

- All query executor SQL is validated as SELECT-only
- Dangerous keywords blocked at parser level
- Parameters bound with type sanitization
- File uploads restricted by type and size
- Sessions regenerate on login
- Passwords hashed with bcrypt
- `.htaccess` blocks direct access to `config.php`, `core/`, `sessions/`

---

## License

Open Source — MIT License
