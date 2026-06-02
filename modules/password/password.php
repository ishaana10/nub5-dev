<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db     = NuDatabase::getInstance();
$isAdmin = $auth->hasPermission('system.config');
?>

<div class="nu-password-module">

<!-- ── Tab nav ──────────────────────────────────────────────────────────────── -->
<div class="nu-tabs" style="margin-bottom:24px;">
    <button class="nu-tab-btn nu-tab-active" onclick="pwdShowTab('change')" id="tab-change">Change Password</button>
    <?php if ($isAdmin): ?>
    <button class="nu-tab-btn" onclick="pwdShowTab('policy')" id="tab-policy">Password Policy</button>
    <button class="nu-tab-btn" onclick="pwdShowTab('reset')" id="tab-reset">Admin Reset</button>
    <?php endif; ?>
</div>

<!-- ── PANEL: Change own password ───────────────────────────────────────────── -->
<div id="pwd-panel-change">
    <div class="nu-card" style="max-width:480px;">
        <div class="nu-card-header"><h3 class="nu-card-title">Change Your Password</h3></div>
        <div class="nu-card-body">
            <div id="pwd-change-alert" style="display:none;margin-bottom:12px;"></div>

            <div class="nu-field">
                <label for="pwd-current">Current Password</label>
                <div class="nu-input-wrap">
                    <input type="password" class="nu-input" id="pwd-current" autocomplete="current-password">
                    <button type="button" class="nu-input-eye" onclick="pwdToggleVis('pwd-current',this)" aria-label="Show/hide">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <div class="nu-field">
                <label for="pwd-new">New Password</label>
                <div class="nu-input-wrap">
                    <input type="password" class="nu-input" id="pwd-new" autocomplete="new-password" oninput="pwdStrength(this.value)">
                    <button type="button" class="nu-input-eye" onclick="pwdToggleVis('pwd-new',this)" aria-label="Show/hide">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <!-- Strength bar -->
                <div style="margin-top:6px;">
                    <div id="pwd-strength-bar" style="height:4px;border-radius:2px;background:var(--border-color);overflow:hidden;">
                        <div id="pwd-strength-fill" style="height:100%;width:0;transition:width .3s,background .3s;"></div>
                    </div>
                    <span id="pwd-strength-label" style="font-size:12px;color:var(--text-secondary);"></span>
                </div>
                <!-- Policy checklist -->
                <ul id="pwd-checklist" style="margin-top:8px;padding:0;list-style:none;font-size:12px;color:var(--text-secondary);display:flex;flex-wrap:wrap;gap:4px 12px;"></ul>
            </div>

            <div class="nu-field">
                <label for="pwd-confirm">Confirm New Password</label>
                <div class="nu-input-wrap">
                    <input type="password" class="nu-input" id="pwd-confirm" autocomplete="new-password">
                    <button type="button" class="nu-input-eye" onclick="pwdToggleVis('pwd-confirm',this)" aria-label="Show/hide">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="nu-card-footer" style="display:flex;justify-content:flex-end;gap:8px;">
            <button class="nu-btn nu-btn-primary" onclick="pwdChangeSubmit()">Update Password</button>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- ── PANEL: Password Policy ────────────────────────────────────────────────── -->
<div id="pwd-panel-policy" style="display:none;">
    <div class="nu-card" style="max-width:640px;">
        <div class="nu-card-header"><h3 class="nu-card-title">Password Policy Settings</h3></div>
        <div class="nu-card-body">
            <div id="pwd-policy-alert" style="display:none;margin-bottom:12px;"></div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="nu-field">
                    <label>Minimum Length</label>
                    <input type="number" class="nu-input" id="pol-min-length" min="6" max="128" value="8">
                </div>
                <div class="nu-field">
                    <label>Password History (# to remember)</label>
                    <input type="number" class="nu-input" id="pol-history" min="0" max="24" value="5">
                    <small style="color:var(--text-secondary);">0 = disabled</small>
                </div>
                <div class="nu-field">
                    <label>Expiry (days, 0 = never)</label>
                    <input type="number" class="nu-input" id="pol-expiry" min="0" value="0">
                </div>
                <div class="nu-field">
                    <label>Expiry Warning (days before)</label>
                    <input type="number" class="nu-input" id="pol-expiry-warn" min="0" value="7">
                </div>
            </div>

            <div style="margin-top:16px;display:flex;flex-direction:column;gap:10px;">
                <label class="nu-check-row"><input type="checkbox" id="pol-uppercase" checked>
                    Require at least one <strong>uppercase</strong> letter (A–Z)</label>
                <label class="nu-check-row"><input type="checkbox" id="pol-lowercase" checked>
                    Require at least one <strong>lowercase</strong> letter (a–z)</label>
                <label class="nu-check-row"><input type="checkbox" id="pol-number" checked>
                    Require at least one <strong>number</strong> (0–9)</label>
                <label class="nu-check-row"><input type="checkbox" id="pol-special">
                    Require at least one <strong>special character</strong> (!@#$…)</label>
                <label class="nu-check-row"><input type="checkbox" id="pol-no-username" checked>
                    Password must <strong>not contain</strong> the username</label>
                <label class="nu-check-row"><input type="checkbox" id="pol-force-first" checked>
                    Force password change on <strong>first login</strong></label>
            </div>
        </div>
        <div class="nu-card-footer" style="display:flex;justify-content:flex-end;gap:8px;">
            <button class="nu-btn nu-btn-ghost" onclick="pwdLoadPolicy()">Reset</button>
            <button class="nu-btn nu-btn-primary" onclick="pwdSavePolicy()">Save Policy</button>
        </div>
    </div>
</div>

<!-- ── PANEL: Admin Reset ─────────────────────────────────────────────────────── -->
<div id="pwd-panel-reset" style="display:none;">
    <div class="nu-card" style="max-width:480px;">
        <div class="nu-card-header"><h3 class="nu-card-title">Admin: Reset User Password</h3></div>
        <div class="nu-card-body">
            <div id="pwd-reset-alert" style="display:none;margin-bottom:12px;"></div>
            <div class="nu-field">
                <label>Select User</label>
                <select class="nu-input" id="rst-user-id">
                    <?php
                    $users = $db->fetchAll("SELECT usr_id, usr_username, usr_email FROM nu_users WHERE usr_active=1 ORDER BY usr_username");
                    foreach ($users as $u):
                    ?>
                    <option value="<?php echo $u['usr_id']; ?>"><?php echo htmlspecialchars($u['usr_username']); ?> <?php echo $u['usr_email'] ? '(' . htmlspecialchars($u['usr_email']) . ')' : ''; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="nu-field">
                <label for="rst-new-pwd">New Password</label>
                <div class="nu-input-wrap">
                    <input type="password" class="nu-input" id="rst-new-pwd" autocomplete="new-password">
                    <button type="button" class="nu-input-eye" onclick="pwdToggleVis('rst-new-pwd',this)" aria-label="Show/hide">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="margin-top:6px;" onclick="pwdGenerate('rst-new-pwd')">Generate Strong Password</button>
            </div>
            <div class="nu-field">
                <label class="nu-check-row"><input type="checkbox" id="rst-force" checked>
                    Force user to change password on next login</label>
            </div>
        </div>
        <div class="nu-card-footer" style="display:flex;justify-content:flex-end;">
            <button class="nu-btn nu-btn-primary" onclick="pwdAdminReset()">Reset Password</button>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /nu-password-module -->

<style>
.nu-input-wrap { position:relative; }
.nu-input-wrap .nu-input { padding-right:40px; }
.nu-input-eye { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--text-secondary); padding:2px; }
.nu-input-eye:hover { color:var(--text-primary); }
.nu-tabs { display:flex; gap:4px; border-bottom:2px solid var(--border-color); }
.nu-tab-btn { background:none; border:none; padding:8px 18px; cursor:pointer; font-size:14px; font-weight:500; color:var(--text-secondary); border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .15s,border-color .15s; }
.nu-tab-btn:hover { color:var(--text-primary); }
.nu-tab-active { color:var(--accent) !important; border-bottom-color:var(--accent) !important; }
.nu-check-row { display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; }
.nu-check-row input { width:16px; height:16px; flex-shrink:0; }
.nu-card-footer { padding:16px 24px; border-top:1px solid var(--border-color); }
</style>

<script>
(function() {
    // ── cached policy ─────────────────────────────────────────────────────────
    let _policy = null;

    // ── Tab switching ─────────────────────────────────────────────────────────
    window.pwdShowTab = function(tab) {
        ['change','policy','reset'].forEach(t => {
            const panel = document.getElementById('pwd-panel-' + t);
            const btn   = document.getElementById('tab-' + t);
            if (!panel) return;
            panel.style.display = t === tab ? '' : 'none';
            if (btn) btn.classList.toggle('nu-tab-active', t === tab);
        });
        if (tab === 'policy' && !_policy) pwdLoadPolicy();
    };

    // ── Alerts ────────────────────────────────────────────────────────────────
    function showAlert(elId, msg, type) {
        const el = document.getElementById(elId);
        if (!el) return;
        const bg  = type === 'success' ? 'var(--success-light,#d1fae5)' : 'var(--danger-light,#fee2e2)';
        const col = type === 'success' ? 'var(--success,#059669)'       : 'var(--danger,#dc2626)';
        el.style.cssText = `display:block;padding:10px 14px;border-radius:var(--radius-md);background:${bg};color:${col};font-size:14px;`;
        el.textContent = msg;
    }

    // ── Show/hide password ────────────────────────────────────────────────────
    window.pwdToggleVis = function(inputId, btn) {
        const inp = document.getElementById(inputId);
        if (!inp) return;
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show);
    };

    // ── Generate strong password ──────────────────────────────────────────────
    window.pwdGenerate = function(targetId) {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%^&*';
        let pwd = '';
        const arr = new Uint32Array(16);
        crypto.getRandomValues(arr);
        arr.forEach(v => { pwd += chars[v % chars.length]; });
        const inp = document.getElementById(targetId);
        if (inp) { inp.value = pwd; inp.type = 'text'; }
    };

    // ── Password strength meter ───────────────────────────────────────────────
    window.pwdStrength = function(val) {
        let score = 0;
        if (val.length >= 8)  score++;
        if (val.length >= 12) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        const fill   = document.getElementById('pwd-strength-fill');
        const label  = document.getElementById('pwd-strength-label');
        const levels = ['','Very Weak','Weak','Fair','Strong','Very Strong'];
        const colors = ['','#ef4444','#f97316','#eab308','#22c55e','#16a34a'];
        if (fill)  { fill.style.width  = (score * 20) + '%'; fill.style.background = colors[score] || '#ef4444'; }
        if (label) { label.textContent = val.length ? levels[score] : ''; }
        pwdUpdateChecklist(val);
    };

    function pwdUpdateChecklist(val) {
        if (!_policy) return;
        const p   = _policy;
        const ul  = document.getElementById('pwd-checklist');
        if (!ul) return;
        const checks = [];
        if (p.policy_min_length)       checks.push({ ok: val.length >= +p.policy_min_length,  text: `Min ${p.policy_min_length} chars` });
        if (+p.policy_require_uppercase) checks.push({ ok: /[A-Z]/.test(val),                  text: 'Uppercase' });
        if (+p.policy_require_lowercase)  checks.push({ ok: /[a-z]/.test(val),                  text: 'Lowercase' });
        if (+p.policy_require_number)   checks.push({ ok: /[0-9]/.test(val),                   text: 'Number' });
        if (+p.policy_require_special)  checks.push({ ok: /[^A-Za-z0-9]/.test(val),            text: 'Special char' });
        ul.innerHTML = checks.map(c =>
            `<li style="color:${c.ok?'var(--success,#059669)':'var(--text-secondary)'}">${c.ok?'✓':'○'} ${c.text}</li>`
        ).join('');
    }

    // ── Change own password ───────────────────────────────────────────────────
    window.pwdChangeSubmit = async function() {
        showAlert('pwd-change-alert', '', '');
        const payload = {
            current_password:  document.getElementById('pwd-current').value,
            new_password:      document.getElementById('pwd-new').value,
            confirm_password:  document.getElementById('pwd-confirm').value,
        };
        try {
            const res  = await fetch('../api/password.php?action=change_password', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
            const data = await res.json();
            showAlert('pwd-change-alert', data.message || (data.success ? 'Done.' : 'Error.'), data.success ? 'success' : 'error');
            if (data.success) { document.getElementById('pwd-current').value = ''; document.getElementById('pwd-new').value = ''; document.getElementById('pwd-confirm').value = ''; pwdStrength(''); }
        } catch(e) { showAlert('pwd-change-alert', 'Network error.', 'error'); }
    };

    // ── Load policy into form ─────────────────────────────────────────────────
    window.pwdLoadPolicy = async function() {
        try {
            const res  = await fetch('../api/password.php?action=get_policy');
            const data = await res.json();
            if (!data.success) return;
            _policy = data.policy;
            const p  = _policy;
            const g  = id => document.getElementById(id);
            if (g('pol-min-length'))    g('pol-min-length').value    = p.policy_min_length;
            if (g('pol-history'))       g('pol-history').value       = p.policy_history_count;
            if (g('pol-expiry'))        g('pol-expiry').value        = p.policy_expiry_days;
            if (g('pol-expiry-warn'))   g('pol-expiry-warn').value   = p.policy_expiry_warning_days;
            if (g('pol-uppercase'))     g('pol-uppercase').checked   = !!+p.policy_require_uppercase;
            if (g('pol-lowercase'))     g('pol-lowercase').checked   = !!+p.policy_require_lowercase;
            if (g('pol-number'))        g('pol-number').checked      = !!+p.policy_require_number;
            if (g('pol-special'))       g('pol-special').checked     = !!+p.policy_require_special;
            if (g('pol-no-username'))   g('pol-no-username').checked = !!+p.policy_disallow_username;
            if (g('pol-force-first'))   g('pol-force-first').checked = !!+p.policy_force_change_on_first_login;
        } catch(e) {}
    };

    // ── Save policy ───────────────────────────────────────────────────────────
    window.pwdSavePolicy = async function() {
        const g = id => document.getElementById(id);
        const payload = {
            policy_min_length:                  parseInt(g('pol-min-length')?.value  || 8),
            policy_require_uppercase:           g('pol-uppercase')?.checked  ? 1 : 0,
            policy_require_lowercase:            g('pol-lowercase')?.checked  ? 1 : 0,
            policy_require_number:              g('pol-number')?.checked     ? 1 : 0,
            policy_require_special:             g('pol-special')?.checked    ? 1 : 0,
            policy_disallow_username:           g('pol-no-username')?.checked ? 1 : 0,
            policy_history_count:               parseInt(g('pol-history')?.value     || 5),
            policy_expiry_days:                 parseInt(g('pol-expiry')?.value      || 0),
            policy_expiry_warning_days:         parseInt(g('pol-expiry-warn')?.value || 7),
            policy_force_change_on_first_login: g('pol-force-first')?.checked ? 1 : 0,
        };
        try {
            const res  = await fetch('../api/password.php?action=save_policy', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
            const data = await res.json();
            showAlert('pwd-policy-alert', data.message || (data.success ? 'Saved.' : 'Error.'), data.success ? 'success' : 'error');
            if (data.success) { _policy = { ..._policy, ...payload }; }
        } catch(e) { showAlert('pwd-policy-alert', 'Network error.', 'error'); }
    };

    // ── Admin reset ───────────────────────────────────────────────────────────
    window.pwdAdminReset = async function() {
        const payload = {
            user_id:      parseInt(document.getElementById('rst-user-id')?.value || 0),
            new_password: document.getElementById('rst-new-pwd')?.value || '',
            force_change: document.getElementById('rst-force')?.checked ?? true,
        };
        try {
            const res  = await fetch('../api/password.php?action=admin_reset', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
            const data = await res.json();
            showAlert('pwd-reset-alert', data.message || (data.success ? 'Done.' : 'Error.'), data.success ? 'success' : 'error');
            if (data.success) { document.getElementById('rst-new-pwd').value = ''; }
        } catch(e) { showAlert('pwd-reset-alert', 'Network error.', 'error'); }
    };

    // ── Auto-load policy for checklist once on page load ──────────────────────
    (async () => {
        try {
            const res  = await fetch('../api/password.php?action=get_policy');
            const data = await res.json();
            if (data.success) _policy = data.policy;
        } catch(e) {}
    })();

})();
</script>
