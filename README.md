# nuBuilder Next v5.0.0

A modern, responsive rebuild of the nuBuilder Forte low-code platform.

## Architecture

```
nubuilder-next/
├── config.php              # Global configuration
├── index.php               # App shell (login + dashboard)
├── setup.php               # Web-based installer
├── install.sql             # Database schema + seed data
├── migrate.php             # Migration from nuBuilder Forte 4.9
├── core/
│   ├── Database.php        # PDO database layer
│   ├── Auth.php            # Session auth, 2FA, permissions
│   ├── Audit.php           # Audit trail logging
│   ├── Api.php             # REST API foundation
│   ├── FormRenderer.php    # Runtime form renderer
│   ├── ReportRenderer.php  # Runtime report renderer
│   └── QueryExecutor.php   # Safe SQL query executor
├── api/
│   ├── auth.php            # Login/logout/me
│   ├── crud.php            # Generic CRUD for any table
│   ├── form.php            # Form runtime API
│   ├── report.php          # Report runtime API
│   ├── query.php           # Query runtime API
│   ├── upload.php          # File upload handler
│   ├── export.php          # CSV/JSON export
│   └── import.php          # CSV import
├── modules/
│   ├── dashboard/          # KPI cards, activity, quick actions
│   ├── forms/              # Form builder + runtime preview
│   ├── reports/            # Report builder + table/chart output
│   ├── queries/            # Query builder + parameter forms
│   ├── users/              # User CRUD with roles
│   ├── roles/              # Role/permission matrix
│   ├── menus/              # Menu builder
│   ├── audit/              # Audit trail viewer
│   ├── files/              # File manager
│   └── import_export/      # Data import/export tools
└── assets/
    ├── css/nubuilder-next.css   # Modern design system
    └── js/nubuilder-next.js     # App framework + utilities
```

## Requirements

- PHP 7.4 (host default — see Deployment Notes before upgrading)
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite enabled
- Modern browser (Chrome, Firefox, Safari, Edge)

## Quick Start

1. Upload files to your web server
2. Visit `setup.php` in your browser
3. Follow the environment check and database installation
4. Login with `globeadmin` / `password` (change immediately)

## Manual Installation

```bash
# Import database schema
mysql -u root -p < install.sql

# Update config.php with your credentials
# Ensure these directories are writable:
chmod 755 uploads/ logs/
```

## Deployment Notes

> **Hosting:** A2Hosting via cPanel — ict-fj.com/nbv5/

| Setting | Value |
|---------|-------|
| PHP Version | 7.4 (host default) |
| Web Server | Apache (LiteSpeed/EasyApache) |
| Database | MySQL 5.7+ |

### PHP Version Upgrade (Status: Blocked)

- PHP 8.1 upgrade was attempted on **27 May 2026** using per-folder `.htaccess` `AddHandler`
- All PHP files in `/nbv5/` returned **403 Forbidden** when `AddHandler application/x-httpd-ea-php81` was added
- `php_value` directives in `.htaccess` cause **500 Internal Server Error** on this host — do not use them
- No parent `/public_html/.htaccess` found; issue is likely a **ModSecurity rule or host-level restriction** on PHP handler overrides
- **Next step:** Contact A2Hosting support to either whitelist PHP 8.1 for `/public_html/nbv5/` or obtain the correct per-folder handler string for this account
- Host supports up to PHP 8.5 — upgrade is possible once host-side restriction is resolved

### .htaccess Rules

- Do **not** add `php_value` directives — not supported on this host configuration
- Do **not** add `AddHandler` PHP version overrides without confirming with A2Hosting support first
- Hidden file protection uses `RewriteRule` (not `FilesMatch Order/Deny`) for Apache 2.4 compatibility

## Key Features

### Design System
- Fully responsive from mobile to desktop
- Dark / Light / Auto theme support
- Modern card-based UI with CSS custom properties
- Accessible navigation with keyboard support

### Security
- Password hashing (bcrypt)
- Login attempt lockout
- Session timeout + regeneration
- 2FA hooks (TOTP ready)
- CSRF token generation
- Role-based permissions
- Full audit trail

### API Layer
- Token-based authentication with rate limiting
- Generic CRUD endpoints for any table
- Form/Report/Query runtime APIs
- File upload with validation
- CSV/JSON import and export

### Runtime Renderers
- **Form Renderer**: Turns metadata into real data-entry forms with validation
- **Report Renderer**: Executes SQL safely, renders tables or Chart.js charts
- **Query Executor**: Parameter binding, SQL validation, CSV export

### Builders
- Visual form builder with drag-and-drop canvas
- Report builder with SQL + column definitions + chart support
- Query builder with parameter definitions
- Menu builder for navigation management

### Business Operations
- Dashboard with KPI cards and activity feed
- User/role/permission management
- Audit trail with pagination
- File manager with upload/delete
- Import/Export tools (CSV, JSON)

## Migration from nuBuilder Forte 4.9

Run `migrate.php` after installing nuBuilder Next. It maps:
- `zzzzsys_user` → `nu_users`
- `zzzzsys_form` → `nu_forms`
- `zzzzsys_report` → `nu_reports`
- `zzzzsys_browse` → `nu_queries`

## Database Schema

### Core Tables
- `nu_users` - User accounts with roles
- `nu_roles` - Role definitions
- `nu_permissions` - Permission catalog
- `nu_role_permissions` - Role-permission mapping

### Builder Tables
- `nu_forms` - Form metadata (JSON layout)
- `nu_reports` - Report metadata (SQL + columns)
- `nu_queries` - Query metadata (SQL + parameters)
- `nu_menus` - Navigation structure

### System Tables
- `nu_api_tokens` - API keys
- `nu_api_usage` - Rate limiting
- `nu_audit_log` - Activity logging
- `nu_files` - Upload tracking

## Development Roadmap

### Phase 1 (Complete)
- [x] App shell + responsive design system
- [x] Authentication + security layer
- [x] Database abstraction (PDO)
- [x] Audit trail
- [x] API foundation
- [x] Dashboard module
- [x] User/Role management
- [x] File uploads

### Phase 2 (Complete)
- [x] Runtime form renderer
- [x] Runtime report renderer (tables + charts)
- [x] Safe query executor with validation
- [x] Advanced field types (lookup, subform, repeater)
- [x] Menu builder UI
- [x] Import/Export (CSV, JSON)
- [x] Chart.js integration
- [x] SQL security hardening

### Phase 3 (Future)
- [ ] Workflow engine (approvals, notifications)
- [ ] Advanced dashboard widgets
- [ ] Real-time collaboration
- [ ] Plugin/extension system
- [ ] Mobile PWA support
- [ ] REST API documentation (OpenAPI)

## Security Notes

- All SQL queries are validated to be SELECT-only
- Dangerous keywords are blocked at parser level
- Parameters are bound with type sanitization
- File uploads are restricted by type and size
- Sessions regenerate on login
- Passwords are hashed with bcrypt

## License

Open Source - MIT License
