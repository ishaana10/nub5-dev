window.NuApp = {
  currentModule: 'dashboard',
  _previewModalSize: 'standard', // compact | standard | full

  init() {
    this.bindEvents();
    this.loadTheme();
  },

  bindEvents() {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
      themeToggle.addEventListener('click', () => this.toggleTheme());
    }
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', () => this.logout());
    }
    window.addEventListener('hashchange', () => {
      const module = (window.location.hash || '').replace('#', '');
      if (module && module !== this.currentModule) {
        this.loadModule(module);
      }
    });
  },

  async logout() {
    try {
      await fetch('api/auth.php?action=logout', { method: 'POST', credentials: 'same-origin' });
    } catch (e) {}
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
    if (active) active.classList.add('active');
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
    setTimeout(() => {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 4000);
  },

  _execModuleScripts(container) {
    container.querySelectorAll('script').forEach(function (oldScript) {
      const s = document.createElement('script');
      Array.from(oldScript.attributes).forEach(function (attr) {
        s.setAttribute(attr.name, attr.value);
      });
      s.textContent = oldScript.textContent;
      oldScript.parentNode.replaceChild(s, oldScript);
    });
  },

  async loadModule(module) {
    this._exitFullPage();
    this.currentModule = module;
    const container = document.getElementById('contentArea');
    const pageTitle = document.getElementById('pageTitle');
    if (!container) { console.error('contentArea not found'); return; }
    if (pageTitle) {
      pageTitle.textContent = module.charAt(0).toUpperCase() + module.slice(1);
    }
    this.setActiveNavByModule(module);
    container.innerHTML = '<div class="nu-spinner" style="margin:40px auto;"></div>';
    try {
      const res = await fetch('modules/' + module + '/' + module + '.php', { credentials: 'same-origin' });
      const html = await res.text();
      if (!res.ok) {
        container.innerHTML =
          '<div style="padding:24px;border:2px solid red;background:#fee;">' +
          '<h3>Module load failed</h3><p>Status: ' + res.status + '</p>' +
          '<pre style="font-size:12px;overflow:auto;">' + html.substring(0, 2000) + '</pre></div>';
        return;
      }
      container.innerHTML = html;
      container.style.display = 'block';
      container.style.visibility = 'visible';
      container.style.opacity = '1';
      this._execModuleScripts(container);
      this.initModuleScripts(module);
    } catch (err) {
      console.error('loadModule error', err);
      container.innerHTML =
        '<div style="padding:24px;border:2px solid red;background:#fee;">' +
        '<h3>Error</h3><p>' + String(err.message || err) + '</p></div>';
    }
  },

  initModuleScripts(module) {
    if (module === 'forms') {
      if (window.nbFormBuilder && typeof window.nbFormBuilder._initAfterLoad === 'function') {
        window.nbFormBuilder._initAfterLoad();
      }
    }
  },

  async apiJson(url, options) {
    const res = await fetch(url, options || {});
    const text = await res.text();
    console.log('api raw response:', url, text);
    let json = null;
    try { json = JSON.parse(text); } catch (e) { throw new Error('Invalid JSON response'); }
    return json;
  },

  // ─── PATCH 2 helper: stamp parent_id onto subform containers ────────────────
  _stampSubformParentId(box, recordId) {
    if (!recordId) return;
    box.querySelectorAll('.nu-subform-container').forEach(function (el) {
      el.dataset.parentId = String(recordId);
    });
  },

  _dispatchFormOpened(box) {
    if (window.nuSubform && typeof window.nuSubform.initAll === 'function') {
      window.nuSubform.initAll(box);
    }
    document.dispatchEvent(new CustomEvent('nu:form:opened', { detail: { scope: box } }));
  },

  // ─── BREADCRUMB HELPER ───────────────────────────────────────────────────────
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

  // ─── FULL-PAGE MODE HELPERS ──────────────────────────────────────────────────
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

  // ─── PREVIEW FORM — resizable modal (compact / standard / full) ──────────────
  async previewForm(code, formLabel) {
    try {
      const json = await this.apiJson(
        'api/form.php?action=render&code=' + encodeURIComponent(code),
        { credentials: 'same-origin' }
      );
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
      overlay.style.cssText =
        'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-c