// ─── Select2 helpers ─────────────────────────────────────────────────────────
(function () {
  'use strict';

  function _s2BuildFresh(el) {
    var fresh = document.createElement('select');
    var skip = { 'data-select2-id': 1, 'data-nu-s2': 1 };
    for (var i = 0; i < el.attributes.length; i++) {
      var attr = el.attributes[i];
      if (!skip[attr.name]) fresh.setAttribute(attr.name, attr.value);
    }
    for (var j = 0; j < el.childNodes.length; j++) {
      fresh.appendChild(el.childNodes[j].cloneNode(true));
    }
    var next = el.nextElementSibling;
    while (next && next.classList &&
           (next.classList.contains('select2') || next.classList.contains('select2-container'))) {
      var rem = next; next = next.nextElementSibling; rem.parentNode.removeChild(rem);
    }
    el.parentNode.replaceChild(fresh, el);
    return fresh;
  }

  function _s2InitOne(el) {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
    var $ = jQuery;
    var fresh = _s2BuildFresh(el);
    var opts = {
      width: '100%',
      theme: (window.nuUXOptions && window.nuUXOptions.nuSelect2Theme) || 'default',
      placeholder: fresh.dataset.placeholder || 'Select\u2026',
      allowClear:  fresh.dataset.allowClear !== 'false',
      multiple:    fresh.dataset.selectMode === 'multiple' || fresh.hasAttribute('multiple'),
      dropdownParent: $(document.body),
    };
    try {
      $(fresh).select2(opts);
      fresh.setAttribute('data-nu-s2', '1');
    } catch (err) {
      console.error('[nu-select2] init FAILED', err.message, fresh);
    }
  }

  function nuInitSelect2(scope) {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
    var root = (scope instanceof Element) ? scope : document;
    var targets = Array.prototype.slice.call(
      root.querySelectorAll('select[data-select-type="select2"], select.nu-select2')
    );
    targets.forEach(function (el) { _s2InitOne(el); });
  }

  window.nuInitSelect2    = nuInitSelect2;
  window.nuDestroySelect2 = function (el) { _s2BuildFresh(el); };
  window.nuReinitSelect2  = function (el) { _s2InitOne(el); };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { nuInitSelect2(document); });
  } else {
    nuInitSelect2(document);
  }

  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    if (scope && scope.dataset && scope.dataset.nuS2Done) return;
    nuInitSelect2(scope);
  });

}());


// ─── Permission helpers ───────────────────────────────────────────────────────
window.NuPerms = {
  _editRoles: new Set(['globeadmin', 'admin']),

  canEdit() {
    const role = (window.nuUserRole || '').toLowerCase();
    if (this._editRoles.has(role)) return true;
    return !!(window.nuUserPerms && window.nuUserPerms.canEdit);
  },

  canAdd() {
    const role = (window.nuUserRole || '').toLowerCase();
    if (this._editRoles.has(role)) return true;
    return !!(window.nuUserPerms && window.nuUserPerms.canAdd);
  },

  canDelete() {
    const role = (window.nuUserRole || '').toLowerCase();
    if (this._editRoles.has(role)) return true;
    return !!(window.nuUserPerms && window.nuUserPerms.canDelete);
  }
};


// ─── NuApp ────────────────────────────────────────────────────────────────────
window.NuApp = {
  currentModule: 'dashboard',
  _previewModalSize: 'standard',

  _systemModules: new Set([
    'dashboard','forms','reports','queries','calendar','ai','integrations',
    'menus','users','roles','audit','files','workflow','inspector','errorlog',
    'password_policy','appcloner','password'
  ]),

  init() {
    this.bindEvents();
    this.loadTheme();
    this.initNavGroups();
  },

  initNavGroups() {
    document.querySelectorAll('.nu-nav-group').forEach((group) => {
      const btn = group.querySelector('.nu-nav-group-label');
      const ul  = group.querySelector('.nu-nav-children');
      if (!btn || !ul) return;
      ul.classList.add('nu-nav-children--collapsed');
      btn.setAttribute('aria-expanded', 'false');
    });
  },

  bindEvents() {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) themeToggle.addEventListener('click', () => this.toggleTheme());
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) logoutBtn.addEventListener('click', () => this.logout());

    const nav = document.querySelector('.nu-nav');
    if (nav) {
      nav.addEventListener('click', (e) => {
        const btn = e.target.closest('.nu-nav-group-label');
        if (!btn) return;
        const group = btn.closest('.nu-nav-group');
        if (!group) return;
        const ul = group.querySelector('.nu-nav-children');
        if (!ul) return;
        const isOpen = btn.getAttribute('aria-expanded') !== 'false';
        btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        ul.classList.toggle('nu-nav-children--collapsed', isOpen);
      });
    }

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.nu-nav-form-item')) {
        document.querySelectorAll('.nu-nav-form-dropdown').forEach(d => d.classList.remove('open'));
      }
    });
  },

  async logout() {
    try { await fetch('api/auth.php?action=logout', { method: 'POST', credentials: 'same-origin' }); } catch (e) {}
    window.location.reload();
  },

  loadTheme() {
    let saved = 'auto';
    try { saved = localStorage.getItem('nu-theme') || 'auto'; } catch (e) {}
    document.documentElement.setAttribute('data-theme', saved);
  },

  toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'light' ? 'dark' : current === 'dark' ? 'auto' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('nu-theme', next); } catch (e) {}
  },

  setActiveNavByModule(module) {
    document.querySelectorAll('.nu-nav-item').forEach((item) => item.classList.remove('active'));
    const active = document.querySelector('.nu-nav-item[data-module="' + module + '"]');
    if (active) {
      active.classList.add('active');
      const group = active.closest('.nu-nav-group');
      if (group) {
        const ul  = group.querySelector('.nu-nav-children');
        const btn = group.querySelector('.nu-nav-group-label');
        if (ul)  ul.classList.remove('nu-nav-children--collapsed');
        if (btn) btn.setAttribute('aria-expanded', 'true');
      }
    }
  },

  toast(message, type) {
    let container = document.querySelector('.nu-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'nu-toast-container';
      document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'nu-toast ' + (type || 'success');
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 4000);
  },

  _execModuleScripts(container) {
    container.querySelectorAll('script').forEach(function (oldScript) {
      const s = document.createElement('script');
      Array.from(oldScript.attributes).forEach(function (attr) { s.setAttribute(attr.name, attr.value); });
      s.textContent = oldScript.textContent;
      oldScript.parentNode.replaceChild(s, oldScript);
    });
  },

  _initFormWidgets(scope) {
    if (scope) scope.dataset.nuS2Done = '1';
    if (typeof window.nuInitSelect2 === 'function') window.nuInitSelect2(scope);
  },

  _dispatchFormOpened(box) {
    if (window.nuSubform && typeof window.nuSubform.initAll === 'function') {
      window.nuSubform.initAll(box);
    }
    document.dispatchEvent(new CustomEvent('nu:form:opened', { detail: { scope: box } }));
  },

  _renderBreadcrumb(crumbs) {
    const nav = document.createElement('nav');
    nav.setAttribute('aria-label', 'breadcrumb');
    nav.style.cssText = 'margin-bottom:14px;display:flex;align-items:center;flex-wrap:wrap;gap:4px;font-size:13px;';
    crumbs.forEach((crumb, i) => {
      if (i > 0) {
        const sep = document.createElement('span');
        sep.textContent = '/';
        sep.style.cssText = 'color:var(--text-muted,#999);margin:0 2px;';
        nav.appendChild(sep);
      }
      if (crumb.action && i < crumbs.length - 1) {
        const a = document.createElement('a');
        a.href = '#';
        a.textContent = crumb.label;
        a.style.cssText = 'color:var(--primary,#4f6bed);text-decoration:none;font-weight:500;';
        a.addEventListener('mouseenter', () => a.style.textDecoration = 'underline');
        a.addEventListener('mouseleave', () => a.style.textDecoration = 'none');
        a.addEventListener('click', (e) => { e.preventDefault(); crumb.action(); });
        nav.appendChild(a);
      } else {
        const span = document.createElement('span');
        span.textContent = crumb.label;
        span.style.cssText = 'color:var(--text-muted,#666);font-weight:400;';
        nav.appendChild(span);
      }
    });
    return nav;
  },

  _enterFullPage() {
    const sidebar = document.querySelector('.nu-sidebar, #sidebar, [class*="sidebar"]');
    const header  = document.querySelector('.nu-header, #header, header');
    const main    = document.getElementById('contentArea');
    if (sidebar) { sidebar.dataset.nuFpHidden = sidebar.style.display; sidebar.style.display = 'none'; }
    if (header)  { header.dataset.nuFpHidden  = header.style.display;  header.style.display  = 'none'; }
    if (main) {
      main.dataset.nuFpStyle = main.getAttribute('style') || '';
      main.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:9000;overflow-y:auto;padding:24px;background:var(--bg-page,#f5f6fa);';
    }
    document.body.dataset.nuFullPage = '1';
  },

  _exitFullPage() {
    if (!document.body.dataset.nuFullPage) return;
    const sidebar = document.querySelector('.nu-sidebar, #sidebar, [class*="sidebar"]');
    const header  = document.querySelector('.nu-header, #header, header');
    const main    = document.getElementById('contentArea');
    if (sidebar && sidebar.dataset.nuFpHidden !== undefined) { sidebar.style.display = sidebar.dataset.nuFpHidden; delete sidebar.dataset.nuFpHidden; }
    if (header  && header.dataset.nuFpHidden  !== undefined) { header.style.display  = header.dataset.nuFpHidden;  delete header.dataset.nuFpHidden;  }
    if (main    && main.dataset.nuFpStyle     !== undefined) { main.setAttribute('style', main.dataset.nuFpStyle);  delete main.dataset.nuFpStyle;     }
    delete document.body.dataset.nuFullPage;
  },

  async loadModule(module, defaultView, browseMode, previewMode) {
    module      = (module      || 'dashboard').trim();
    defaultView = ['browse','preview'].includes(defaultView) ? defaultView : 'browse';
    browseMode  = ['inline','popup'].includes(browseMode)    ? browseMode  : 'inline';
    previewMode = ['inline','popup'].includes(previewMode)   ? previewMode : 'inline';

    this._exitFullPage();
    this.currentModule = module;

    const container = document.getElementById('contentArea');
    const pageTitle  = document.getElementById('pageTitle');
    if (!container) { console.error('contentArea not found'); return; }
    if (pageTitle) pageTitle.textContent = module.charAt(0).toUpperCase() + module.slice(1);
    this.setActiveNavByModule(module);

    if (this._systemModules.has(module)) {
      container.innerHTML = '<div class="nu-spinner" style="margin:40px auto;"></div>';
      try {
        const res  = await fetch('modules/' + module + '/' + module + '.php', { credentials: 'same-origin' });
        const html = await res.text();
        if (!res.ok) {
          container.innerHTML = '<div style="padding:24px;border:2px solid red;background:#fee;"><h3>Module load failed</h3><p>Status: ' + res.status + '</p><pre style="font-size:12px;overflow:auto;">' + html.substring(0, 2000) + '</pre></div>';
          return;
        }
        container.innerHTML = html;
        container.style.display    = 'block';
        container.style.visibility = 'visible';
        container.style.opacity    = '1';
        this._execModuleScripts(container);
        this.initModuleScripts(module);
      } catch (err) {
        console.error('loadModule error', err);
        container.innerHTML = '<div style="padding:24px;border:2px solid red;background:#fee;"><h3>Error</h3><p>' + String(err.message || err) + '</p></div>';
      }
      return;
    }

    if (defaultView === 'preview') {
      return previewMode === 'popup'
        ? this.previewForm(module, module, 'modal')
        : this._openFormInline(module, module, null, true);
    } else {
      return browseMode === 'popup'
        ? this._browseModal(module, 1, '', module)
        : this._browseInline(module, 1, '', module);
    }
  },

  initModuleScripts(module) {
    if (module === 'forms' && window.nbFormBuilder && typeof window.nbFormBuilder._initAfterLoad === 'function') {
      window.nbFormBuilder._initAfterLoad();
    }
  },

  async apiJson(url, options) {
    const res  = await fetch(url, options || {});
    const text = await res.text();
    console.log('api raw response:', url, text);
    let json = null;
    try { json = JSON.parse(text); } catch (e) { throw new Error('Invalid JSON response'); }
    return json;
  },

  async previewForm(code, formLabel, displayMode) {
    const mode = (displayMode || 'modal').toLowerCase();
    if (mode === 'inline')   return this._openFormInline(code, formLabel, null, true);
    if (mode === 'fullpage') return this._openFormFullPage(code, formLabel, null, true);

    try {
      const json = await this.apiJson('api/form.php?action=render&code=' + encodeURIComponent(code), { credentials: 'same-origin' });
      if (!json.success) { this.toast(json.error || 'Preview failed', 'error'); return; }

      const label = formLabel || code;
      const sizes = {
        compact:  { maxWidth: '560px',  maxHeight: '80vh' },
        standard: { maxWidth: '900px',  maxHeight: '90vh' },
        full:     { maxWidth: '98vw',   maxHeight: '96vh' },
      };
      let currentSize = this._previewModalSize || 'standard';

      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;width:92%;overflow-y:auto;transition:max-width 0.2s,max-height 0.2s;max-width:' + sizes[currentSize].maxWidth + ';max-height:' + sizes[currentSize].maxHeight + ';';

      const applySize = (s) => {
        currentSize = s; this._previewModalSize = s;
        box.style.maxWidth  = sizes[s].maxWidth;
        box.style.maxHeight = sizes[s].maxHeight;
        box.querySelectorAll('.nu-size-btn').forEach(b => {
          b.style.fontWeight = b.dataset.size === s ? '700' : '400';
          b.style.background = b.dataset.size === s ? 'var(--primary,#4f6bed)' : 'transparent';
          b.style.color      = b.dataset.size === s ? '#fff' : 'var(--text,#333)';
        });
      };

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:8px;';
      const titleWrap = document.createElement('div');
      titleWrap.style.cssText = 'display:flex;flex-direction:column;gap:4px;min-width:0;';
      const bc = this._renderBreadcrumb([
        { label: 'Forms', action: () => { overlay.remove(); this.loadModule('forms'); } },
        { label: label }
      ]);
      bc.style.marginBottom = '0';
      titleWrap.appendChild(bc);
      header.appendChild(titleWrap);

      const controls = document.createElement('div');
      controls.style.cssText = 'display:flex;align-items:center;gap:4px;flex-shrink:0;';
      ['compact','standard','full'].forEach(s => {
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'nu-size-btn'; btn.dataset.size = s;
        btn.textContent = s === 'compact' ? '\u25a3 Sm' : s === 'standard' ? '\u25a3 Md' : '\u26f6 Lg';
        btn.title = s.charAt(0).toUpperCase() + s.slice(1);
        btn.style.cssText = 'border:1px solid var(--border-color,#ddd);border-radius:6px;padding:3px 8px;font-size:11px;cursor:pointer;background:transparent;color:var(--text,#333);transition:all 0.15s;';
        btn.addEventListener('click', () => applySize(s));
        controls.appendChild(btn);
      });
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button'; closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);margin-left:4px;';
      closeBtn.addEventListener('click', () => overlay.remove());
      controls.appendChild(closeBtn);
      header.appendChild(controls);
      box.appendChild(header);

      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      box.appendChild(formWrap);

      const formEl = box.querySelector('.nu-generated-form');
      if (formEl) formEl.dataset.displayMode = 'modal';

      overlay.appendChild(box);
      document.body.appendChild(overlay);
      applySize(currentSize);
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

      this._initFormWidgets(box);
      this._execModuleScripts(box);
      this._dispatchFormOpened(box);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, true);
      }
    } catch (err) {
      console.error('previewForm error', err);
      this.toast('Preview error: ' + err.message, 'error');
    }
  },

  async editRecord(code, id, fromBrowseLabel, displayMode) {
    const mode        = (displayMode || 'modal').toLowerCase();
    const browseLabel = fromBrowseLabel || code;
    if (mode === 'inline')   return this._openFormInline(code, browseLabel, id, false);
    if (mode === 'fullpage') return this._openFormFullPage(code, browseLabel, id, false);

    try {
      const json = await this.apiJson('api/form.php?action=render&code=' + encodeURIComponent(code) + '&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
      if (!json.success) { this.toast(json.error || 'Failed', 'error'); return; }

      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;max-width:900px;max-height:90vh;overflow-y:auto;width:92%;';

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;';
      const bc = this._renderBreadcrumb([
        { label: 'Forms',     action: () => { overlay.remove(); this.loadModule('forms'); } },
        { label: browseLabel, action: () => { overlay.remove(); this.browseForm(code, 1, '', browseLabel, mode); } },
        { label: 'Edit #' + id }
      ]);
      bc.style.marginBottom = '0';
      header.appendChild(bc);
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button'; closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);';
      closeBtn.addEventListener('click', () => overlay.remove());
      header.appendChild(closeBtn);
      box.appendChild(header);

      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      box.appendChild(formWrap);

      const formEl = box.querySelector('.nu-generated-form');
      if (formEl) formEl.dataset.displayMode = 'modal';

      overlay.appendChild(box);
      document.body.appendChild(overlay);
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

      this._initFormWidgets(box);
      this._execModuleScripts(box);
      this._dispatchFormOpened(box);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, false);
      }
    } catch (err) {
      console.error('editRecord error', err);
      this.toast('Error: ' + err.message, 'error');
    }
  },

  async viewRecord(code, id, fromBrowseLabel, displayMode) {
    const mode        = (displayMode || 'modal').toLowerCase();
    const browseLabel = fromBrowseLabel || code;

    try {
      const json = await this.apiJson(
        'api/form.php?action=render&code=' + encodeURIComponent(code) + '&id=' + encodeURIComponent(id),
        { credentials: 'same-origin' }
      );
      if (!json.success) { this.toast(json.error || 'Failed', 'error'); return; }

      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;max-width:900px;max-height:90vh;overflow-y:auto;width:92%;';

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;';
      const bc = this._renderBreadcrumb([
        { label: browseLabel, action: () => { overlay.remove(); this.browseForm(code, 1, '', browseLabel, mode); } },
        { label: 'View #' + id }
      ]);
      bc.style.marginBottom = '0';
      header.appendChild(bc);

      const badge = document.createElement('span');
      badge.textContent = '\uD83D\uDD12 View Only';
      badge.style.cssText = 'font-size:11px;padding:3px 8px;border-radius:10px;background:var(--bg-elevated,#f0f0f0);color:var(--text-muted,#888);margin-right:8px;';
      header.appendChild(badge);

      const closeBtn = document.createElement('button');
      closeBtn.type = 'button'; closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);';
      closeBtn.addEventListener('click', () => overlay.remove());
      header.appendChild(closeBtn);
      box.appendChild(header);

      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      box.appendChild(formWrap);

      box.querySelectorAll('input, textarea, select, button[type="submit"]').forEach(el => {
        if (el.type === 'submit') { el.style.display = 'none'; return; }
        el.setAttribute('disabled', 'disabled');
        el.style.opacity = '0.7';
        el.style.cursor  = 'default';
      });
      box.querySelectorAll('button.nu-btn-ghost').forEach(el => {
        if (el.textContent.trim() === 'Cancel') el.textContent = 'Close';
      });

      const formEl = box.querySelector('.nu-generated-form');
      if (formEl) {
        formEl.dataset.displayMode = 'modal';
        formEl.dataset.viewOnly    = '1';
      }

      overlay.appendChild(box);
      document.body.appendChild(overlay);
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

      this._initFormWidgets(box);
      this._execModuleScripts(box);
      this._dispatchFormOpened(box);
    } catch (err) {
      console.error('viewRecord error', err);
      this.toast('Error: ' + err.message, 'error');
    }
  },

  addRecord(code, formLabel, displayMode) {
    return this._openFormInline(code, formLabel, null, false);
  },

  async browseForm(code, page, query, formLabel, displayMode) {
    const mode = (displayMode || 'inline').toLowerCase();
    if (mode === 'modal')    return this._browseModal(code, page, query, formLabel);
    if (mode === 'fullpage') return this._browseFullPage(code, page, query, formLabel);
    return this._browseInline(code, page, query, formLabel);
  },

  async _fetchBrowseData(code, page, query) {
    page  = page  || 1;
    query = query || '';
    const json = await this.apiJson(
      'api/form.php?action=list&code=' + encodeURIComponent(code) +
      '&page=' + encodeURIComponent(page) + '&q=' + encodeURIComponent(query),
      { credentials: 'same-origin' }
    );
    if (!json.success) throw new Error(json.error || 'Browse failed');
    return json;
  },

  // ── Browse table builder ─────────────────────────────────────────────────
  _buildBrowseTable(json, code, page, query, label, displayMode, container, onEdit, canEdit, canAdd) {
    const _canEdit          = (canEdit !== undefined) ? canEdit : NuPerms.canEdit();
    const _canAdd           = (canAdd  !== undefined) ? canAdd  : NuPerms.canAdd();
    const data              = json.data || {};
    const layout            = Array.isArray(data.layout)  ? data.layout  : [];
    const records           = Array.isArray(data.records) ? data.records : [];
    const currentQuery      = data.query || query || '';
    const searchEnabled     = String(data.browsesearchenabled || 0) === '1';
    const searchPlaceholder = data.browsesearchplaceholder || 'Search...';

    container.innerHTML = '';

    // ── Search bar row (shown when search is enabled or user can add) ──
    if (searchEnabled || _canAdd) {
      const searchWrap = document.createElement('div');
      searchWrap.style.cssText = 'margin-bottom:16px;display:flex;gap:8px;align-items:stretch;';

      if (searchEnabled) {
        const searchInput = document.createElement('input');
        searchInput.type = 'text'; searchInput.className = 'nu-input';
        searchInput.placeholder = searchPlaceholder; searchInput.value = currentQuery;
        searchInput.style.flex = '1';
        const searchBtn = document.createElement('button');
        searchBtn.className = 'nu-btn nu-btn-primary'; searchBtn.textContent = 'Search';
        searchBtn.onclick = () => this.browseForm(code, 1, searchInput.value.trim(), label, displayMode);
        const clearBtn = document.createElement('button');
        clearBtn.className = 'nu-btn nu-btn-ghost'; clearBtn.textContent = 'Clear';
        clearBtn.onclick = () => this.browseForm(code, 1, '', label, displayMode);
        searchInput.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') this.browseForm(code, 1, searchInput.value.trim(), label, displayMode);
        });
        searchWrap.appendChild(searchInput);
        searchWrap.appendChild(searchBtn);
        searchWrap.appendChild(clearBtn);
      } else {
        const spacer = document.createElement('div');
        spacer.style.flex = '1';
        searchWrap.appendChild(spacer);
      }

      if (_canAdd) {
        const addBtn = document.createElement('button');
        addBtn.className = 'nu-btn nu-btn-primary';
        addBtn.textContent = '+ Add New Record';
        addBtn.onclick = () => this.addRecord(code, label, displayMode);
        searchWrap.appendChild(addBtn);
      }

      container.appendChild(searchWrap);
    }

    const tableWrap = document.createElement('div');
    tableWrap.style.cssText = 'overflow-x:auto;';
    const table = document.createElement('table');
    table.style.cssText = 'width:100%;border-collapse:collapse;';
    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    headRow.style.cssText = 'border-bottom:2px solid var(--border-color,#ddd);background:var(--table-head-bg,#f8f9fa);';
    layout.forEach((f) => {
      const th = document.createElement('th');
      th.style.cssText = 'padding:12px;text-align:left;font-size:13px;font-weight:600;';
      th.textContent = f.fieldlabel || f.label || f.fieldname || f.name || '';
      headRow.appendChild(th);
    });
    const actionTh = document.createElement('th');
    actionTh.textContent = 'Actions';
    actionTh.style.cssText = 'padding:12px;text-align:left;font-size:13px;font-weight:600;';
    headRow.appendChild(actionTh);
    thead.appendChild(headRow); table.appendChild(thead);

    const tbody = document.createElement('tbody');
    if (!records.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = layout.length + 1;
      td.style.cssText = 'padding:40px;text-align:center;color:#666;';
      td.textContent = currentQuery ? 'No matching records' : 'No records found';
      tr.appendChild(td); tbody.appendChild(tr);
    } else {
      records.forEach((row) => {
        const tr = document.createElement('tr');
        tr.style.cssText = 'border-bottom:1px solid var(--border-color,#ddd);transition:background 0.15s;';
        tr.addEventListener('mouseenter', () => tr.style.background = 'var(--row-hover,#f5f7ff)');
        tr.addEventListener('mouseleave', () => tr.style.background = '');
        layout.forEach((f) => {
          const td = document.createElement('td');
          td.style.cssText = 'padding:12px;';
          const fieldName  = f.fieldname || f.name;
          const displayKey = fieldName + '_display';
          let value = '';
          if ((f.fieldtype || f.type) === 'lookup' && row[displayKey] != null) value = row[displayKey];
          else if (row[fieldName] != null) value = row[fieldName];
          td.textContent = String(value);
          tr.appendChild(td);
        });

        const actionTd = document.createElement('td');
        actionTd.style.cssText = 'padding:12px;display:flex;gap:8px;align-items:center;';

        if (_canEdit) {
          const editBtn = document.createElement('button');
          editBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
          editBtn.textContent = '\u270E Edit';
          editBtn.onclick = () => (onEdit ? onEdit(row) : this.editRecord(code, row.id, label, displayMode));
          actionTd.appendChild(editBtn);
        } else {
          const viewBtn = document.createElement('button');
          viewBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
          viewBtn.style.cssText = 'color:var(--text-muted,#666);';
          viewBtn.textContent = '\uD83D\uDC41 View';
          viewBtn.onclick = () => this.viewRecord(code, row.id, label, displayMode);
          actionTd.appendChild(viewBtn);
        }

        tr.appendChild(actionTd); tbody.appendChild(tr);
      });
    }
    table.appendChild(tbody); tableWrap.appendChild(table); container.appendChild(tableWrap);

    if ((data.pages || 1) > 1) {
      const pagination = document.createElement('div');
      pagination.style.cssText = 'display:flex;gap:4px;flex-wrap:wrap;align-items:center;margin-top:16px;';
      const prevBtn = document.createElement('button');
      prevBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm'; prevBtn.textContent = '\u2190 Prev';
      prevBtn.disabled = (data.page || 1) <= 1;
      prevBtn.onclick = () => this.browseForm(code, (data.page || 1) - 1, currentQuery, label, displayMode);
      pagination.appendChild(prevBtn);
      for (let i = 1; i <= (data.pages || 1); i++) {
        const pageBtn = document.createElement('button');
        pageBtn.className = 'nu-btn ' + (i === (data.page || 1) ? 'nu-btn-primary' : 'nu-btn-ghost') + ' nu-btn-sm';
        pageBtn.textContent = i;
        pageBtn.onclick = () => this.browseForm(code, i, currentQuery, label, displayMode);
        pagination.appendChild(pageBtn);
      }
      const nextBtn = document.createElement('button');
      nextBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm'; nextBtn.textContent = 'Next \u2192';
      nextBtn.disabled = (data.page || 1) >= (data.pages || 1);
      nextBtn.onclick = () => this.browseForm(code, (data.page || 1) + 1, currentQuery, label, displayMode);
      pagination.appendChild(nextBtn);
      const meta = document.createElement('span');
      meta.style.cssText = 'margin-left:8px;color:#666;font-size:13px;';
      meta.textContent = 'Total: ' + (data.total || 0) + ' records';
      pagination.appendChild(meta); container.appendChild(pagination);
    }
  },

  async _browseInline(code, page, query, formLabel) {
    try {
      const _canAdd  = NuPerms.canAdd();
      const _canEdit = NuPerms.canEdit();
      const json  = await this._fetchBrowseData(code, page, query);
      const data  = json.data || {};
      const label = formLabel || data.form_name || code;
      const container = document.getElementById('contentArea');
      if (!container) { this.toast('Content area not found', 'error'); return; }
      container.innerHTML = '';
      const bc = this._renderBreadcrumb([
        { label: 'Forms', action: () => this.loadModule('forms') },
        { label: label,   action: () => this._browseInline(code, 1, '', label) },
        { label: 'Browse' }
      ]);
      container.appendChild(bc);
      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;';
      const h3 = document.createElement('h3');
      h3.style.cssText = 'margin:0;font-size:18px;'; h3.textContent = label;
      header.appendChild(h3);
      const btnGroup = document.createElement('div');
      btnGroup.style.cssText = 'display:flex;gap:8px;align-items:center;';
      const previewBtn = document.createElement('button');
      previewBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm'; previewBtn.textContent = '\u229e Preview Form';
      previewBtn.onclick = () => this.previewForm(code, label);
      btnGroup.appendChild(previewBtn);
      const backBtn = document.createElement('button');
      backBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm'; backBtn.textContent = '\u2190 Forms';
      backBtn.onclick = () => this.loadModule('forms');
      btnGroup.appendChild(backBtn);
      header.appendChild(btnGroup); container.appendChild(header);
      this._buildBrowseTable(json, code, page, query, label, 'inline', container, null, _canEdit, _canAdd);
    } catch (err) { console.error('_browseInline error', err); this.toast('Error: ' + err.message, 'error'); }
  },

  async _browseModal(code, page, query, formLabel) {
    try {
      const _canAdd  = NuPerms.canAdd();
      const _canEdit = NuPerms.canEdit();
      const json  = await this._fetchBrowseData(code, page, query);
      const data  = json.data || {};
      const label = formLabel || data.form_name || code;
      let overlay = document.querySelector('.nu-browse-overlay');
      let isNew = false;
      if (!overlay) {
        isNew = true;
        overlay = document.createElement('div');
        overlay.className = 'nu-browse-overlay nu-form-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';
      }
      const box = document.createElement('div');
      box.style.cssText = 'background:var(--card-bg,#fff);border-radius:12px;padding:24px;width:96%;max-width:1100px;max-height:92vh;overflow-y:auto;';
      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:8px;';
      const bc = this._renderBreadcrumb([
        { label: 'Forms', action: () => { overlay.remove(); this.loadModule('forms'); } },
        { label: label,   action: () => this._browseModal(code, 1, '', label) },
        { label: 'Browse' }
      ]);
      bc.style.marginBottom = '0'; header.appendChild(bc);
      const rightBtns = document.createElement('div');
      rightBtns.style.cssText = 'display:flex;gap:6px;flex-shrink:0;align-items:center;';
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button'; closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);';
      closeBtn.addEventListener('click', () => overlay.remove());
      rightBtns.appendChild(closeBtn); header.appendChild(rightBtns);
      box.appendChild(header);
      const tableContainer = document.createElement('div');
      this._buildBrowseTable(json, code, page, query, label, 'modal', tableContainer, null, _canEdit, _canAdd);
      box.appendChild(tableContainer);
      overlay.innerHTML = ''; overlay.appendChild(box);
      if (isNew) {
        document.body.appendChild(overlay);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
      }
    } catch (err) { console.error('_browseModal error', err); this.toast('Error: ' + err.message, 'error'); }
  },

  async _browseFullPage(code, page, query, formLabel) {
    try {
      const _canAdd  = NuPerms.canAdd();
      const _canEdit = NuPerms.canEdit();
      const json  = await this._fetchBrowseData(code, page, query);
      const data  = json.data || {};
      const label = formLabel || data.form_name || code;
      this._enterFullPage();
      const container = document.getElementById('contentArea');
      if (!container) { this.toast('Content area not found', 'error'); return; }
      container.innerHTML = '';
      const bc = this._renderBreadcrumb([
        { label: 'Forms', action: () => { this._exitFullPage(); this.loadModule('forms'); } },
        { label: label,   action: () => this._browseFullPage(code, 1, '', label) },
        { label: 'Browse' }
      ]);
      container.appendChild(bc);
      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;';
      const h3 = document.createElement('h3');
      h3.style.cssText = 'margin:0;font-size:20px;'; h3.textContent = label;
      header.appendChild(h3);
      const btnGroup = document.createElement('div');
      btnGroup.style.cssText = 'display:flex;gap:8px;';
      const exitBtn = document.createElement('button');
      exitBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm'; exitBtn.textContent = '\u2715 Exit Full Page';
      exitBtn.onclick = () => { this._exitFullPage(); this.loadModule('forms'); };
      btnGroup.appendChild(exitBtn); header.appendChild(btnGroup); container.appendChild(header);
      this._buildBrowseTable(json, code, page, query, label, 'fullpage', container, null, _canEdit, _canAdd);
    } catch (err) { this._exitFullPage(); console.error('_browseFullPage error', err); this.toast('Error: ' + err.message, 'error'); }
  },

  async _openFormInline(code, formLabel, id, isPreview) {
    try {
      const url = 'api/form.php?action=render&code=' + encodeURIComponent(code) + (id ? '&id=' + encodeURIComponent(id) : '');
      const json = await this.apiJson(url, { credentials: 'same-origin' });
      if (!json.success) { this.toast(json.error || 'Failed', 'error'); return; }
      const label     = formLabel || code;
      const container = document.getElementById('contentArea');
      if (!container) { this.toast('Content area not found', 'error'); return; }
      container.innerHTML = '';
      const crumbs = [
        { label: 'Forms', action: () => this.loadModule('forms') },
        { label: label,   action: () => this.browseForm(code, 1, '', label, 'inline') },
        { label: isPreview ? 'Preview' : (id ? 'Edit #' + id : 'New Record') }
      ];
      container.appendChild(this._renderBreadcrumb(crumbs));
      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      container.appendChild(formWrap);
      const formEl = container.querySelector('.nu-generated-form');
      if (formEl) {
        formEl.dataset.displayMode = 'inline';
        formEl.dataset.fromBrowse  = label;
      }
      this._initFormWidgets(container);
      this._execModuleScripts(container);
      this._dispatchFormOpened(container);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, isPreview);
      }
    } catch (err) { console.error('_openFormInline error', err); this.toast('Error: ' + err.message, 'error'); }
  },

  async _openFormFullPage(code, formLabel, id, isPreview) {
    try {
      const url = 'api/form.php?action=render&code=' + encodeURIComponent(code) + (id ? '&id=' + encodeURIComponent(id) : '');
      const json = await this.apiJson(url, { credentials: 'same-origin' });
      if (!json.success) { this.toast(json.error || 'Failed', 'error'); return; }
      const label = formLabel || code;
      this._enterFullPage();
      const container = document.getElementById('contentArea');
      if (!container) { this.toast('Content area not found', 'error'); return; }
      container.innerHTML = '';
      const exitAction = () => { this._exitFullPage(); this.loadModule('forms'); };
      container.appendChild(this._renderBreadcrumb([
        { label: 'Forms', action: exitAction },
        { label: label,   action: () => { this._exitFullPage(); this.browseForm(code, 1, '', label, 'fullpage'); } },
        { label: isPreview ? 'Preview' : (id ? 'Edit #' + id : 'New Record') }
      ]));
      const exitBtn = document.createElement('button');
      exitBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm'; exitBtn.style.cssText = 'margin-bottom:12px;';
      exitBtn.textContent = '\u2715 Exit Full Page'; exitBtn.onclick = exitAction;
      container.appendChild(exitBtn);
      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      container.appendChild(formWrap);
      const formEl = container.querySelector('.nu-generated-form');
      if (formEl) formEl.dataset.displayMode = 'fullpage';
      this._initFormWidgets(container);
      this._execModuleScripts(container);
      this._dispatchFormOpened(container);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, isPreview);
      }
    } catch (err) { this._exitFullPage(); console.error('_openFormFullPage error', err); this.toast('Error: ' + err.message, 'error'); }
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function () { NuApp.init(); });
} else {
  NuApp.init();
}

window.closeNuForm = function (btn) {
  const overlay = btn ? btn.closest('.nu-form-overlay') : null;
  if (overlay) { overlay.remove(); return; }
  NuApp.loadModule('forms');
};

window.submitNuForm = async function (formElement) {
  if (!formElement) { NuApp.toast('Form element not found', 'error'); return; }

  if (formElement.dataset.viewOnly === '1') { NuApp.toast('View only \u2014 saving is disabled', 'error'); return; }

  const formCode    = formElement.dataset.formCode;
  const recordId    = formElement.dataset.recordId;
  const displayMode = formElement.dataset.displayMode || 'inline';
  const fromBrowse  = formElement.dataset.fromBrowse  || null;
  const url = 'api/form.php?action=save&code=' + encodeURIComponent(formCode) +
    (recordId ? '&id=' + encodeURIComponent(recordId) : '');

  const formData = new FormData(formElement);
  const data = {};

  formData.forEach((value, key) => {
    if (Object.prototype.hasOwnProperty.call(data, key)) {
      if (!Array.isArray(data[key])) data[key] = [data[key]];
      data[key].push(value);
    } else {
      data[key] = value;
    }
  });

  formElement.querySelectorAll('select[name]').forEach((sel) => {
    const name = sel.name;
    if (!name) return;
    const selected = Array.from(sel.options)
      .filter(function (o) { return o.selected; })
      .map(function (o) { return o.value; });
    if (sel.multiple) {
      data[name] = selected;
    } else {
      data[name] = selected.length ? selected[0] : '';
    }
  });

  formElement.querySelectorAll('input[type="checkbox"]').forEach((el) => {
    if (!Object.prototype.hasOwnProperty.call(data, el.name)) data[el.name] = '';
  });

  if (window._nuFormBeforeSave && typeof window._nuFormBeforeSave[formCode] === 'function') {
    const result = window._nuFormBeforeSave[formCode](formElement, data);
    if (result === false) return;
    if (result && typeof result.then === 'function') {
      try { const v = await result; if (v === false) return; } catch (e) { return; }
    }
  }
  try {
    const json = await NuApp.apiJson(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    if (!json.success) { NuApp.toast(json.error || 'Save failed', 'error'); return; }
    NuApp.toast(recordId ? 'Updated' : 'Saved');
    if (window._nuFormAfterSave && typeof window._nuFormAfterSave[formCode] === 'function') {
      window._nuFormAfterSave[formCode](formElement, json);
    }
    const overlay = formElement.closest('.nu-form-overlay');
    if (overlay) overlay.remove();
    if (typeof NuApp.browseForm === 'function' && formCode) {
      NuApp.browseForm(formCode, 1, '', fromBrowse || null, 'inline');
    } else {
      NuApp.loadModule('forms');
    }
  } catch (e) { console.error('submitNuForm error', e); NuApp.toast('Error: ' + e.message, 'error'); }
};

window.openFormBuilder = function ()                               { return NuApp.openFormBuilder ? NuApp.openFormBuilder() : (window.nbFormBuilder ? window.nbFormBuilder.open() : null); };
window.previewForm     = function (code, label, mode)              { return NuApp.previewForm(code, label, mode); };
window.editForm        = function (id)                             { return window.nbFormBuilder ? window.nbFormBuilder.edit(id) : null; };
window.addRecord       = function (code, label, mode)              { return NuApp.addRecord(code, label, mode); };
window.editRecord      = function (code, id, label, mode)          { return NuApp.editRecord(code, id, label, mode); };
window.viewRecord      = function (code, id, label, mode)          { return NuApp.viewRecord(code, id, label, mode); };
window.browseForm      = function (code, page, query, label, mode) { return NuApp.browseForm(code, page, query, label, mode); };
window.browseFormPage  = function (code, page, query, label, mode) { return NuApp.browseForm(code, page, query, label, mode); };
window.deleteForm      = function (id, name) {
  if (!confirm('Delete form ' + (name || '') + '?')) return;
  NuApp.apiJson('api/crud.php?table=nu_forms&id=' + encodeURIComponent(id), {
    method: 'DELETE', credentials: 'same-origin'
  }).then(function (json) {
    if (json.success) { NuApp.toast('Deleted'); NuApp.loadModule('forms'); }
    else NuApp.toast(json.error || 'Failed', 'error');
  }).catch(function (e) { NuApp.toast('Error: ' + e.message, 'error'); });
};
