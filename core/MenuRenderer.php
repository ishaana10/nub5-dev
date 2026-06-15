<?php
declare(strict_types=1);
/**
 * NuMenuRenderer
 * Renders the sidebar <nav> from nu_menus, filtered by the current user's role.
 *
 * Each navigable link emits three data attributes consumed by NuApp JS:
 *   data-default-view  = 'browse' | 'preview'
 *   data-browse-mode   = 'inline' | 'popup'
 *   data-preview-mode  = 'inline' | 'popup'
 */
class NuMenuRenderer
{
    // ── Built-in SVG icon library ────────────────────────────────────────────
    private static array $icons = [
        'dashboard'  => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'forms'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'file-text'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>',
        'reports'    => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'pie-chart'  => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'queries'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'database'   => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'menus'      => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'users'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'roles'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
        'audit'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><circle cx="17" cy="17" r="3"/><line x1="21" y1="21" x2="19.1" y2="19.1"/>',
        'clipboard'  => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
        'files'      => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'paperclip'  => '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
        'workflow'   => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'ai'         => '<path d="M12 2a10 10 0 1 0 10 10H12V2z"/><path d="M12 2a10 10 0 0 1 10 10"/>',
        'link'       => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'password'   => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor"/>',
        'lock'       => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'alert'      => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'copy'       => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'inspector'  => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><line x1="19" y1="19" x2="23" y2="23"/><circle cx="19" cy="19" r="3"/>',
        'layout'     => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
        'divider'    => '',
        'group'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'default'    => '<circle cx="12" cy="12" r="9"/>',
    ];

    /** Item types that carry open-mode attributes */
    private static array $openModeTypes = ['form', 'report', 'query'];

    // ────────────────────────────────────────────────────────────────────────
    // Public entry point
    // ────────────────────────────────────────────────────────────────────────
    public static function render(?array $currentUser): string
    {
        $userRole = strtolower((string)($currentUser['usr_role'] ?? ''));
        $isAdmin  = in_array($userRole, ['globeadmin', 'admin'], true);

        $rows = self::fetchMenuRows();
        if (empty($rows)) return '';

        // ── Role filtering ───────────────────────────────────────────────────
        $visible = array_filter($rows, static function (array $item) use ($userRole, $isAdmin): bool {
            // menu_role_access: blank = visible to everyone
            $roles = trim($item['menu_role_access'] ?? '');
            if ($roles === '' || $isAdmin) return true;
            $allowed = array_map('trim', explode(',', strtolower($roles)));
            return in_array($userRole, $allowed, true);
        });

        if (empty($visible)) return '';

        // ── Build parent → children map ──────────────────────────────────────
        $topLevel = [];
        $childMap = [];
        foreach ($visible as $item) {
            $pid = (int)($item['menu_parent_id'] ?? 0);
            if ($pid === 0) {
                $topLevel[] = $item;
            } else {
                $childMap[$pid][] = $item;
            }
        }

        if (empty($topLevel)) return '';

        // ── Render ───────────────────────────────────────────────────────────
        $html = "\n<nav class=\"nu-nav\" id=\"nuDynNav\">\n";
        foreach ($topLevel as $item) {
            $html .= self::renderItem($item, $childMap[(int)$item['menu_id']] ?? []);
        }
        $html .= "</nav>\n";
        return $html;
    }

    // ────────────────────────────────────────────────────────────────────────
    // DB fetch — tries the full column list first, falls back for old schemas
    // ────────────────────────────────────────────────────────────────────────
    private static function fetchMenuRows(): array
    {
        $db = NuDatabase::getInstance();

        // Primary query — all columns incl. phase-8 additions
        try {
            return $db->fetchAll(
                "SELECT
                    menu_id, menu_label, menu_type,
                    COALESCE(menu_target,      '')       AS menu_target,
                    COALESCE(menu_code,        '')       AS menu_code,
                    menu_parent_id, menu_order,
                    COALESCE(menu_role_access, '')       AS menu_role_access,
                    menu_active,
                    COALESCE(menu_icon,        'default') AS menu_icon,
                    COALESCE(menu_browse_mode,  'inline') AS menu_browse_mode,
                    COALESCE(menu_preview_mode, 'inline') AS menu_preview_mode,
                    COALESCE(menu_default_view, 'browse') AS menu_default_view
                 FROM  nu_menus
                 WHERE menu_active = 1
                 ORDER BY menu_parent_id ASC, menu_order ASC, menu_id ASC"
            );
        } catch (Throwable $e) {
            error_log('[MenuRenderer] Phase-8 columns missing, using fallback: ' . $e->getMessage());
        }

        // Fallback — derive from old menu_open_mode combined string
        try {
            $rows = $db->fetchAll(
                "SELECT
                    menu_id, menu_label, menu_type,
                    COALESCE(menu_target,      '')       AS menu_target,
                    COALESCE(menu_code,        '')       AS menu_code,
                    menu_parent_id, menu_order,
                    COALESCE(menu_role_access, '')       AS menu_role_access,
                    menu_active,
                    COALESCE(menu_icon,        'default') AS menu_icon,
                    COALESCE(menu_open_mode,   'inline|browse') AS menu_open_mode
                 FROM  nu_menus
                 WHERE menu_active = 1
                 ORDER BY menu_parent_id ASC, menu_order ASC, menu_id ASC"
            );

            // Split the legacy combined string into individual columns
            return array_map(static function (array $row): array {
                $parts = explode('|', $row['menu_open_mode'] ?? 'inline|browse', 2);
                $disp  = in_array($parts[0] ?? '', ['inline','popup']) ? $parts[0] : 'inline';
                $view  = in_array($parts[1] ?? '', ['browse','preview']) ? $parts[1] : 'browse';
                $row['menu_browse_mode']  = $disp;
                $row['menu_preview_mode'] = 'inline';   // safe default for legacy
                $row['menu_default_view'] = $view;
                return $row;
            }, $rows);

        } catch (Throwable $e2) {
            error_log('[MenuRenderer] Fallback query also failed: ' . $e2->getMessage());
            return [];
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Render a single item (recursively renders children inside groups)
    // ────────────────────────────────────────────────────────────────────────
    private static function renderItem(array $item, array $kids): string
    {
        $type   = (string)($item['menu_type'] ?? 'form');
        $label  = htmlspecialchars((string)($item['menu_label'] ?? ''), ENT_QUOTES, 'UTF-8');

        // ── Divider ──────────────────────────────────────────────────────────
        if ($type === 'divider') {
            return "<hr class=\"nu-nav-divider\">\n";
        }

        // ── Icon (built-in SVG | external URL | emoji/text) ──────────────────
        $iconHtml = self::resolveIcon($item['menu_icon'] ?? 'default');

        // ── Group with children ──────────────────────────────────────────────
        if (!empty($kids)) {
            $groupId = 'nu-group-' . (int)$item['menu_id'];
            $out  = "<div class=\"nu-nav-group\">\n";
            $out .= "  <button class=\"nu-nav-group-label\" type=\"button\"
                        aria-expanded=\"true\" aria-controls=\"{$groupId}\">\n";
            $out .= $iconHtml;
            $out .= "    <span>{$label}</span>\n";
            $out .= "    <svg class=\"nu-nav-chevron\" width=\"14\" height=\"14\" viewBox=\"0 0 24 24\"
                        fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" aria-hidden=\"true\">
                        <polyline points=\"6 9 12 15 18 9\"/></svg>\n";
            $out .= "  </button>\n";
            $out .= "  <ul class=\"nu-nav-children\" id=\"{$groupId}\">\n";
            foreach ($kids as $child) {
                $out .= "    <li>" . self::renderItem($child, []) . "</li>\n";
            }
            $out .= "  </ul>\n";
            $out .= "</div>\n";
            return $out;
        }

        // ── URL item (external link) ─────────────────────────────────────────
        if ($type === 'url') {
            $rawTarget = trim($item['menu_target'] ?? $item['menu_code'] ?? '');
            $href = htmlspecialchars($rawTarget ?: '#', ENT_QUOTES, 'UTF-8');
            $out  = "<a href=\"{$href}\" class=\"nu-nav-item\" target=\"_blank\" rel=\"noopener noreferrer\">\n";
            $out .= $iconHtml;
            $out .= "  <span>{$label}</span>\n";
            $out .= "</a>\n";
            return $out;
        }

        // ── Standard leaf item (form / report / query) ───────────────────────
        $module = trim($item['menu_target'] ?? '') !== ''
            ? trim($item['menu_target'])
            : trim($item['menu_code'] ?? '');

        if ($module === '') {
            return "<!-- nu_menus id={$item['menu_id']} skipped: no target/code -->\n";
        }

        $moduleSafe = htmlspecialchars($module, ENT_QUOTES, 'UTF-8');

        // Resolve and sanitise the three open-mode values
        $browseMode  = self::sanitiseDisplay($item['menu_browse_mode']  ?? 'inline');
        $previewMode = self::sanitiseDisplay($item['menu_preview_mode'] ?? 'inline');
        $defaultView = self::sanitiseView($item['menu_default_view']    ?? 'browse');

        // Build data-* attributes only for types that support open-mode
        $openAttrs = '';
        if (in_array($type, self::$openModeTypes, true)) {
            $openAttrs  = " data-default-view=\"{$defaultView}\"";
            $openAttrs .= " data-browse-mode=\"{$browseMode}\"";
            $openAttrs .= " data-preview-mode=\"{$previewMode}\"";
        }

        // JS call passes all three values so NuApp can decide how to open
        $jsCall = "NuApp.loadModule("
            . "'{$moduleSafe}',"
            . "'{$defaultView}',"
            . "'{$browseMode}',"
            . "'{$previewMode}'"
            . "); return false;";

        $out  = "<a href=\"javascript:void(0)\" class=\"nu-nav-item\"
               data-module=\"{$moduleSafe}\"{$openAttrs}\n";
        $out .= "   onclick=\"{$jsCall}\">\n";
        $out .= $iconHtml;
        $out .= "  <span>{$label}</span>\n";
        $out .= "</a>\n";
        return $out;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Icon resolution
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Returns ready-to-embed HTML for an icon.
     * Handles three cases:
     *   1. Built-in key  → inline <svg>
     *   2. http(s):// URL → <img> tag
     *   3. Anything else → <span> (emoji / custom text)
     */
    private static function resolveIcon(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') $raw = 'default';

        // External URL
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            $src = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
            return "  <img src=\"{$src}\" class=\"nu-nav-icon-img\"
                       width=\"20\" height=\"20\" alt=\"\" aria-hidden=\"true\">\n";
        }

        // Built-in SVG
        $key     = strtolower($raw);
        $svgBody = self::$icons[$key] ?? null;
        if ($svgBody !== null) {
            if ($svgBody === '') return '';  // divider has no icon
            return "  <svg width=\"20\" height=\"20\" viewBox=\"0 0 24 24\"
                       fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" aria-hidden=\"true\">\n"
                 . "    {$svgBody}\n  </svg>\n";
        }

        // Emoji / custom text fallback
        $safe = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        return "  <span class=\"nu-nav-icon-emoji\" aria-hidden=\"true\">{$safe}</span>\n";
    }

    // ────────────────────────────────────────────────────────────────────────
    // Value sanitisers (mirror the PHP API helpers)
    // ────────────────────────────────────────────────────────────────────────

    private static function sanitiseDisplay(string $v): string
    {
        $v = strtolower(trim($v));
        return in_array($v, ['inline', 'popup'], true) ? $v : 'inline';
    }

    private static function sanitiseView(string $v): string
    {
        $v = strtolower(trim($v));
        return in_array($v, ['browse', 'preview'], true) ? $v : 'browse';
    }
}
