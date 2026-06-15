<?php
declare(strict_types=1);
/**
 * NuMenuRenderer
 * Renders the sidebar <nav> from nu_menus, filtered by the current user's role.
 *
 * Usage (in index.php):
 *   require_once __DIR__ . '/core/MenuRenderer.php';
 *   echo NuMenuRenderer::render($currentUser);
 *
 * Fallback:
 *   If nu_menus is empty OR the table doesn't exist yet, the renderer returns
 *   an empty string and index.php falls back to the hardcoded nav partial.
 */
class NuMenuRenderer
{
    // ── SVG icon library ──────────────────────────────────────────────────────
    // Keyed by menu_icon value stored in nu_menus.
    // Extend as needed; unknown icons fall back to a generic circle.
    private static array $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'forms'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'reports'   => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'queries'   => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'menus'     => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'roles'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'audit'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/><circle cx="17" cy="17" r="3"/><line x1="21" y1="21" x2="19.1" y2="19.1"/>',
        'files'     => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'workflow'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'ai'        => '<path d="M12 2a10 10 0 1 0 10 10H12V2z"/><path d="M12 2a10 10 0 0 1 10 10"/>',
        'link'      => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'password'  => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
        'alert'     => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'inspector' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><line x1="19" y1="19" x2="23" y2="23"/><circle cx="19" cy="19" r="3"/>',
        'divider'   => '',
        'group'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'default'   => '<circle cx="12" cy="12" r="9"/>',
    ];

    /**
     * Main entry-point.
     * Returns empty string if nu_menus has no active rows (caller shows hardcoded fallback).
     */
    public static function render(?array $currentUser): string
    {
        $userRole = strtolower((string)($currentUser['usr_role'] ?? ''));
        $isAdmin  = ($userRole === 'globeadmin' || $userRole === 'admin');

        try {
            $db   = NuDatabase::getInstance();
            $rows = $db->fetchAll(
                'SELECT menu_id, menu_label, menu_type, menu_target,
                        menu_parent_id, menu_order, menu_roles,
                        menu_active, menu_icon
                 FROM   nu_menus
                 WHERE  menu_active = 1
                 ORDER  BY menu_parent_id ASC, menu_order ASC, menu_id ASC'
            );
        } catch (Throwable $e) {
            // Table probably doesn't exist yet — fall back gracefully.
            error_log('[MenuRenderer] ' . $e->getMessage());
            return '';
        }

        if (empty($rows)) {
            return '';  // triggers hardcoded fallback in index.php
        }

        // ── Filter by role ────────────────────────────────────────────────────
        $visible = array_filter($rows, static function (array $item) use ($userRole, $isAdmin): bool {
            $roles = trim($item['menu_roles'] ?? '');
            if ($roles === '') return true;          // empty = visible to all
            if ($isAdmin)      return true;          // admin bypasses all restrictions
            $allowed = array_map('trim', explode(',', strtolower($roles)));
            return in_array($userRole, $allowed, true);
        });

        // ── Separate top-level and children ───────────────────────────────────
        $topLevel = [];
        $children = [];   // keyed by parent menu_id
        foreach ($visible as $item) {
            $pid = (int)$item['menu_parent_id'];
            if ($pid === 0) {
                $topLevel[] = $item;
            } else {
                $children[$pid][] = $item;
            }
        }

        if (empty($topLevel)) return '';

        // ── Build HTML ────────────────────────────────────────────────────────
        $html = "\n<nav class=\"nu-nav\" id=\"nuDynNav\">\n";

        foreach ($topLevel as $item) {
            $html .= self::renderItem($item, $children[$item['menu_id']] ?? []);
        }

        $html .= "</nav>\n";
        return $html;
    }

    // ── Render a single top-level item (with optional children) ───────────────
    private static function renderItem(array $item, array $kids): string
    {
        $type   = $item['menu_type'];
        $label  = htmlspecialchars((string)$item['menu_label'], ENT_QUOTES, 'UTF-8');
        $target = htmlspecialchars((string)$item['menu_target'], ENT_QUOTES, 'UTF-8');
        $iconKey = strtolower(trim($item['menu_icon'] ?? 'default'));
        $svgBody = self::$icons[$iconKey] ?? self::$icons['default'];

        // ── Divider ──────────────────────────────────────────────────────────
        if ($type === 'divider') {
            return "<hr class=\"nu-nav-divider\">\n";
        }

        // ── Group header (no link, just a label + children) ──────────────────
        if ($type === 'group') {
            $out  = "<div class=\"nu-nav-group-label\">{$label}</div>\n";
            foreach ($kids as $child) {
                $out .= self::renderItem($child, []);
            }
            return $out;
        }

        // ── URL item (external / absolute link) ──────────────────────────────
        if ($type === 'url') {
            $href = $target ?: '#';
            $out  = "<a href=\"{$href}\" class=\"nu-nav-item\" target=\"_blank\" rel=\"noopener noreferrer\">\n";
            $out .= self::svgIcon($svgBody);
            $out .= "  <span>{$label}</span>\n";
            $out .= "</a>\n";
            foreach ($kids as $child) {
                $out .= self::renderItem($child, []);
            }
            return $out;
        }

        // ── Standard module items (form / report / query) ─────────────────────
        $module = htmlspecialchars($type,   ENT_QUOTES, 'UTF-8');
        $code   = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');

        $hasKids    = !empty($kids);
        $itemClass  = 'nu-nav-item' . ($hasKids ? ' nu-nav-item--has-children' : '');
        $ariaExp    = $hasKids ? ' aria-expanded="false"' : '';

        $out  = "<a href=\"#{$code}\" class=\"{$itemClass}\" data-module=\"{$module}\" data-target=\"{$code}\"\n";
        $out .= "   onclick=\"NuApp.loadModule('{$module}','{$code}'); return false;\"{$ariaExp}>\n";
        $out .= self::svgIcon($svgBody);
        $out .= "  <span>{$label}</span>\n";
        if ($hasKids) {
            $out .= "  <svg class=\"nu-nav-chevron\" width=\"14\" height=\"14\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><polyline points=\"6 9 12 15 18 9\"/></svg>\n";
        }
        $out .= "</a>\n";

        if ($hasKids) {
            $out .= "<ul class=\"nu-nav-children\">\n";
            foreach ($kids as $child) {
                $out .= "<li>" . self::renderItem($child, []) . "</li>\n";
            }
            $out .= "</ul>\n";
        }

        return $out;
    }

    // ── Inline SVG helper ─────────────────────────────────────────────────────
    private static function svgIcon(string $body): string
    {
        if ($body === '') return '';
        return "  <svg width=\"20\" height=\"20\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" aria-hidden=\"true\">\n    {$body}\n  </svg>\n";
    }
}
