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
        'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText =
        'background:var(--card-bg,#fff);border-radius:12px;padding:24px;width:92%;overflow-y:auto;transition:max-width 0.2s,max-height 0.2s;' +
        'max-width:' + sizes[currentSize].maxWidth + ';max-height:' + sizes[currentSize].maxHeight + ';';

      const applySize = (s) => {
        currentSize = s;
        this._previewModalSize = s;
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
        btn.type = 'button';
        btn.className = 'nu-size-btn';
        btn.dataset.size = s;
        btn.textContent = s === 'compact' ? '▣ Sm' : s === 'standard' ? '▣ Md' : '⛶ Lg';
        btn.title = s.charAt(0).toUpperCase() + s.slice(1);
        btn.style.cssText =
          'border:1px solid var(--border-color,#ddd);border-radius:6px;padding:3px 8px;font-size:11px;cursor:pointer;' +
          'background:transparent;color:var(--text,#333);transition:all 0.15s;';
        btn.addEventListener('click', () => applySize(s));
        controls.appendChild(btn);
      });

      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText =
        'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);margin-left:4px;';
      closeBtn.addEventListener('click', () => overlay.remove());
      controls.appendChild(closeBtn);

      header.appendChild(controls);
      box.appendChild(header);

      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      box.appendChild(formWrap);

      // inject toggle script if not present
      if (!window.nuToggleContainer) {
        window.nuToggleContainer = function(btn) {
          if (!btn) return;
          var tid = btn.getAttribute('data-target');
          if (!tid) return;
          var body = document.getElementById(tid);
          if (!body) return;
          var hidden = body.style.display === 'none' || body.style.display === '';
          body.style.display = hidden ? 'block' : 'none';
          btn.innerHTML = hidden ? '&#9660;' : '&#9654;';
        };
      }

      overlay.appendChild(box);
      document.body.appendChild(overlay);
      applySize(currentSize);
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

      this._dispatchFormOpened(box);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        const formEl = overlay.querySelector('.nu-generated-form');
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, true);
      }
    } catch (err) {
      console.error('previewForm error', err);
      this.toast('Preview error: ' + err.message, 'error');
    }
  },

  // ─── EDIT RECORD — modal with breadcrumb ─────────────────────────────────────
  async editRecord(code, id, fromBrowseLabel, displayMode) {
    try {
      const json = await this.apiJson(
        'api/form.php?action=render&code=' + encodeURIComponent(code) + '&id=' + encodeURIComponent(id),
        { credentials: 'same-origin' }
      );
      if (!json.success) { this.toast(json.error || 'Failed', 'error'); return; }

      const browseLabel = fromBrowseLabel || code;
      const mode        = displayMode || 'inline';

      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay';
      overlay.style.cssText =
        'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText =
        'background:var(--card-bg,#fff);border-radius:12px;padding:24px;max-width:900px;max-height:90vh;overflow-y:auto;width:92%;';

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
      closeBtn.type = 'button';
      closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);';
      closeBtn.addEventListener('click', () => overlay.remove());
      header.appendChild(closeBtn);

      box.appendChild(header);
      const formWrap = document.createElement('div');
      formWrap.innerHTML = json.html;
      box.appendChild(formWrap);

      overlay.appendChild(box);
      document.body.appendChild(overlay);
      overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

      this._dispatchFormOpened(box);
      if (window.nuForm && typeof window.nuForm.init === 'function') {
        const formEl = overlay.querySelector('.nu-generated-form');
        if (formEl) window.nuForm.init(formEl.dataset.formCode || code, {}, false);
      }
    } catch (err) {
      console.error('editRecord error', err);
      this.toast('Error: ' + err.message, 'error');
    }
  },

  addRecord(code, formLabel, displayMode) {
    return this.previewForm(code, formLabel);
  },

  // ─── BROWSE FORM — dispatches to inline / modal / fullpage ───────────────────
  async browseForm(code, page, query, formLabel, displayMode) {
    const mode = (displayMode || 'inline').toLowerCase();
    if (mode === 'modal') {
      return this._browseModal(code, page, query, formLabel);
    } else if (mode === 'fullpage') {
      return this._browseFullPage(code, page, query, formLabel);
    } else {
      return this._browseInline(code, page, query, formLabel);
    }
  },

  // ─── Shared: fetch browse data ───────────────────────────────────────────────
  async _fetchBrowseData(code, page, query) {
    page  = page  || 1;
    query = query || '';
    const json = await this.apiJson(
      'api/form.php?action=list&code=' + encodeURIComponent(code) +
      '&page=' + encodeURIComponent(page) +
      '&q='    + encodeURIComponent(query),
      { credentials: 'same-origin' }
    );
    if (!json.success) throw new Error(json.error || 'Browse failed');
    return json;
  },

  // ─── Shared: build browse table DOM ─────────────────────────────────────────
  _buildBrowseTable(json, code, page, query, label, displayMode, container, onEdit) {
    const data              = json.data || {};
    const layout            = Array.isArray(data.layout)  ? data.layout  : [];
    const records           = Array.isArray(data.records) ? data.records : [];
    const currentQuery      = data.query || query || '';
    const searchEnabled     = String(data.browsesearchenabled || 0) === '1';
    const searchPlaceholder = data.browsesearchplaceholder || 'Search...';

    container.innerHTML = '';

    if (searchEnabled) {
      const searchWrap = document.createElement('div');
      searchWrap.style.cssText = 'margin-bottom:16px;display:flex;gap:8px;';
      const searchInput = document.createElement('input');
      searchInput.type = 'text';
      searchInput.className = 'nu-input';
      searchInput.placeholder = searchPlaceholder;
      searchInput.value = currentQuery;
      searchInput.style.flex = '1';
      const searchBtn = document.createElement('button');
      searchBtn.className = 'nu-btn nu-btn-primary';
      searchBtn.textContent = 'Search';
      searchBtn.onclick = () => this.browseForm(code, 1, searchInput.value.trim(), label, displayMode);
      const clearBtn = document.createElement('button');
      clearBtn.className = 'nu-btn nu-btn-ghost';
      clearBtn.textContent = 'Clear';
      clearBtn.onclick = () => this.browseForm(code, 1, '', label, displayMode);
      searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') this.browseForm(code, 1, searchInput.value.trim(), label, displayMode);
      });
      searchWrap.appendChild(searchInput);
      searchWrap.appendChild(searchBtn);
      searchWrap.appendChild(clearBtn);
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
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    if (!records.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = layout.length + 1;
      td.style.cssText = 'padding:40px;text-align:center;color:#666;';
      td.textContent = currentQuery ? 'No matching records' : 'No records found';
      tr.appendChild(td);
      tbody.appendChild(tr);
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
          if ((f.fieldtype || f.type) === 'lookup' && row[displayKey] !== undefined && row[displayKey] !== null) {
            value = row[displayKey];
          } else if (row[fieldName] !== undefined && row[fieldName] !== null) {
            value = row[fieldName];
          }
          td.textContent = String(value);
          tr.appendChild(td);
        });
        const actionTd = document.createElement('td');
        actionTd.style.cssText = 'padding:12px;display:flex;gap:8px;';
        const editBtn = document.createElement('button');
        editBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
        editBtn.textContent = 'Edit';
        editBtn.onclick = () => (onEdit ? onEdit(row) : this.editRecord(code, row.id, label, displayMode));
        actionTd.appendChild(editBtn);
        tr.appendChild(actionTd);
        tbody.appendChild(tr);
      });
    }
    table.appendChild(tbody);
    tableWrap.appendChild(table);
    container.appendChild(tableWrap);

    if ((data.pages || 1) > 1) {
      const pagination = document.createElement('div');
      pagination.style.cssText = 'display:flex;gap:4px;flex-wrap:wrap;align-items:center;margin-top:16px;';
      const prevBtn = document.createElement('button');
      prevBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
      prevBtn.textContent = '← Prev';
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
      nextBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
      nextBtn.textContent = 'Next →';
      nextBtn.disabled = (data.page || 1) >= (data.pages || 1);
      nextBtn.onclick = () => this.browseForm(code, (data.page || 1) + 1, currentQuery, label, displayMode);
      pagination.appendChild(nextBtn);
      const meta = document.createElement('span');
      meta.style.cssText = 'margin-left:8px;color:#666;font-size:13px;';
      meta.textContent = 'Total: ' + (data.total || 0) + ' records';
      pagination.appendChild(meta);
      container.appendChild(pagination);
    }
  },

  // ─── MODE 1: INLINE ──────────────────────────────────────────────────────────
  async _browseInline(code, page, query, formLabel) {
    try {
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
      h3.style.cssText = 'margin:0;font-size:18px;';
      h3.textContent = label;
      header.appendChild(h3);
      const btnGroup = document.createElement('div');
      btnGroup.style.cssText = 'display:flex;gap:8px;';
      const addBtn = document.createElement('button');
      addBtn.className = 'nu-btn nu-btn-primary nu-btn-sm';
      addBtn.textContent = '+ Add Record';
      addBtn.onclick = () => this.addRecord(code, label);
      btnGroup.appendChild(addBtn);
      const previewBtn = document.createElement('button');
      previewBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
      previewBtn.textContent = '⊞ Preview Form';
      previewBtn.onclick = () => this.previewForm(code, label);
      btnGroup.appendChild(previewBtn);
      const backBtn = document.createElement('button');
      backBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
      backBtn.textContent = '← Forms';
      backBtn.onclick = () => this.loadModule('forms');
      btnGroup.appendChild(backBtn);
      header.appendChild(btnGroup);
      container.appendChild(header);

      this._buildBrowseTable(json, code, page, query, label, 'inline', container);
    } catch (err) {
      console.error('_browseInline error', err);
      this.toast('Error: ' + err.message, 'error');
    }
  },

  // ─── MODE 2: MODAL ───────────────────────────────────────────────────────────
  async _browseModal(code, page, query, formLabel) {
    try {
      const json  = await this._fetchBrowseData(code, page, query);
      const data  = json.data || {};
      const label = formLabel || data.form_name || code;

      let overlay = document.querySelector('.nu-browse-overlay');
      let isNew   = false;
      if (!overlay) {
        isNew   = true;
        overlay = document.createElement('div');
        overlay.className = 'nu-browse-overlay nu-form-overlay';
        overlay.style.cssText =
          'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
      }

      const box = document.createElement('div');
      box.style.cssText =
        'background:var(--card-bg,#fff);border-radius:12px;padding:24px;width:96%;max-width:1100px;max-height:92vh;overflow-y:auto;';

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:8px;';

      const bc = this._renderBreadcrumb([
        { label: 'Forms', action: () => { overlay.remove(); this.loadModule('forms'); } },
        { label: label,   action: () => this._browseModal(code, 1, '', label) },
        { label: 'Browse' }
      ]);
      bc.style.marginBottom = '0';
      header.appendChild(bc);

      const rightBtns = document.createElement('div');
      rightBtns.style.cssText = 'display:flex;gap:6px;flex-shrink:0;align-items:center;';
      const addBtn = document.createElement('button');
      addBtn.className = 'nu-btn nu-btn-primary nu-btn-sm';
      addBtn.textContent = '+ Add';
      addBtn.onclick = () => this.addRecord(code, label);
      rightBtns.appendChild(addBtn);
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.innerHTML = '&times;';
      closeBtn.style.cssText = 'background:none;border:none;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;color:var(--text,#333);';
      closeBtn.addEventListener('click', () => overlay.remove());
      rightBtns.appendChild(closeBtn);
      header.appendChild(rightBtns);

      box.appendChild(header);
      const tableContainer = document.createElement('div');
      this._buildBrowseTable(json, code, page, query, label, 'modal', tableContainer);
      box.appendChild(tableContainer);

      overlay.innerHTML = '';
      overlay.appendChild(box);

      if (isNew) {
        document.body.appendChild(overlay);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
      }
    } catch (err) {
      console.error('_browseModal error', err);
      this.toast('Error: ' + err.message, 'error');
    }
  },

  // ─── MODE 3: FULL PAGE ───────────────────────────────────────────────────────
  async _browseFullPage(code, page, query, formLabel) {
    try {
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
      h3.style.cssText = 'margin:0;font-size:20px;';
      h3.textContent = label;
      header.appendChild(h3);
      const btnGroup = document.createElement('div');
      btnGroup.style.cssText = 'display:flex;gap:8px;';
      const addBtn = document.createElement('button');
      addBtn.className = 'nu-btn nu-btn-primary nu-btn-sm';
      addBtn.textContent = '+ Add Record';
      addBtn.onclick = () => this.addRecord(code, label);
      btnGroup.appendChild(addBtn);
      const exitBtn = document.createElement('button');
      exitBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
      exitBtn.textContent = '✕ Exit Full Page';
      exitBtn.onclick = () => { this._exitFullPage(); this.loadModule('forms'); };
      btnGroup.appendChild(exitBtn);
      header.appendChild(btnGroup);
      container.appendChild(header);

      this._buildBrowseTable(json, code, page, query, label, 'fullpage', container);
    } catch (err) {
      console.error('_browseFullPage error', err);
      this._exitFullPage();
      this.toast('Error: ' + err.message, 'error');
    }
  }
};

document.addEventListener('DOMContentLoaded', function () {
  NuApp.init();
});

window.closeNuForm = function (btn) {
  const overlay = btn ? btn.closest('.nu-form-overlay') : null;
  if (overlay) { overlay.remove(); return; }
  NuApp.loadModule('forms');
};

window.submitNuForm = async function (formElement) {
  if (!formElement) { NuApp.toast('Form element not found', 'error'); return; }
  const formCode = formElement.dataset.formCode;
  const recordId = formElement.dataset.recordId;
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
  formElement.querySelectorAll('input[type="checkbox"]').forEach((el) => {
    if (!Object.prototype.hasOwnProperty.call(data, el.name)) data[el.name] = '';
  });
  try {
    const json = await NuApp.apiJson(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    if (!json.success) { NuApp.toast(json.error || 'Save failed', 'error'); return; }
    NuApp.toast(recordId ? 'Updated' : 'Saved');
    const overlay = formElement.closest('.nu-form-overlay');
    if (overlay) overlay.remove();
    if (typeof NuApp.browseForm === 'function' && formCode) {
      NuApp.browseForm(formCode, 1, '');
    } else {
      NuApp.loadModule('forms');
    }
  } catch (e) {
    console.error('submitNuForm error', e);
    NuApp.toast('Error: ' + e.message, 'error');
  }
};

// ensure nuToggleContainer is available globally for rendered forms
window.nuToggleContainer = window.nuToggleContainer || function(btn) {
  if (!btn) return;
  var tid  = btn.getAttribute('data-target');
  if (!tid) return;
  var body = document.getElementById(tid);
  if (!body) return;
  var hidden = body.style.display === 'none' || body.style.display === '';
  body.style.display = hidden ? 'block' : 'none';
  btn.innerHTML = hidden ? '&#9660;' : '&#9654;';
};

// ─── nbFormBuilder ────────────────────────────────────────────────────────────
window.nbFormBuilder = (function () {

  function _esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
  function _val(obj, k, def) { return (obj && obj[k] !== undefined) ? obj[k] : (def || ''); }
  function _chk(obj, k) { return (obj && obj[k]) ? 'checked' : ''; }
  function _el(id) { return document.getElementById(id); }

  // ── canvas-empty indicator ──────────────────────────────────────────────────
  function _canvasEmpty() {
    var canvas = _el('formCanvas');
    var empty  = _el('canvasEmpty');
    if (!canvas || !empty) return;
    var hasItems = canvas.querySelectorAll('.nb-cfield, .nb-section, .nb-group, .nb-row').length > 0;
    empty.style.display = hasItems ? 'none' : 'block';
  }

  function _inp(cls, obj, k, ph, def) {
    return '<input type="text" class="nu-input ' + cls + '" value="' + _esc(_val(obj, k, def || '')) + '" placeholder="' + _esc(ph || '') + '">';
  }
  function _row(label, inner, full) {
    return '<div class="nb-fp' + (full ? ' nb-fp-full' : '') + '"><label>' + label + '</label>' + inner + '</div>';
  }
  function _chkLbl(cls, obj, k, lbl) {
    return '<label class="nb-fp-check"><input type="checkbox" class="' + cls + '" ' + _chk(obj, k) + '> ' + lbl + '</label>';
  }

  // ── col-span selector (12-col grid) ────────────────────────────────────────
  function _colSelect(currentCol) {
    var cols = [
      { v: 3,  l: '3/12 — Quarter' },
      { v: 4,  l: '4/12 — Third' },
      { v: 6,  l: '6/12 — Half' },
      { v: 8,  l: '8/12 — Two-thirds' },
      { v: 9,  l: '9/12 — Three-quarters' },
      { v: 12, l: '12/12 — Full width' }
    ];
    var cur = parseInt(currentCol, 10) || 12;
    return '<select class="nu-input nu-field-col">' +
      cols.map(function (c) {
        return '<option value="' + c.v + '"' + (c.v === cur ? ' selected' : '') + '>' + c.l + '</option>';
      }).join('') +
      '</select>';
  }

  // ── field panel ─────────────────────────────────────────────────────────────
  function _fieldPanel(type, extra) {
    extra = extra || {};

    var html = '<div class="nb-fp-grid">' +
      _row('Label', '<input type="text" class="nu-input nu-builder-label" value="' + _esc(_val(extra, 'label')) + '" placeholder="Field label">') +
      _row('Field Name (DB column)', '<input type="text" class="nu-input nu-builder-name" value="' + _esc(_val(extra, 'name')) + '" placeholder="field_name">') +
      _row('Column Width', _colSelect(_val(extra, 'col', 12))) +
      _row('Default Value',   _inp('nu-field-default',     extra, 'default_value',  'default value')) +
      _row('Placeholder',     _inp('nu-field-placeholder', extra, 'placeholder',    'hint text')) +
      _row('Help Text',       _inp('nu-field-help',        extra, 'help_text',      'shown under field')) +
      _row('CSS Class',       _inp('nu-field-cssclass',    extra, 'css_class',      'my-custom-class')) +
      _row('Tab',             _inp('nu-field-tab',         extra, 'tab',            'tab name')) +
      _row('Visibility Rule', _inp('nu-field-vis',         extra, 'visibility_rule','JS expression')) +
      _row('Readonly Rule',   _inp('nu-field-readonly',    extra, 'readonly_rule',  'JS expression')) +
      _row('JS On Change',    _inp('nu-field-onchange',    extra, 'js_onchange',    'JS code snippet')) +
      '<div class="nb-fp nb-fp-full" style="flex-direction:row;gap:16px;flex-wrap:wrap;align-items:center;">' +
        _chkLbl('nu-field-required', extra, 'required', 'Required') +
      '</div>';

    if (type === 'textarea') {
      html += _row('Rows', '<input type="number" class="nu-input nu-field-rows" value="' + _val(extra, 'rows', 3) + '" min="1" max="30">');
    }
    if (type === 'number' || type === 'range') {
      html += _row('Min',  _inp('nu-field-min',  extra, 'min',  ''));
      html += _row('Max',  _inp('nu-field-max',  extra, 'max',  ''));
      html += _row('Step', _inp('nu-field-step', extra, 'step', ''));
    }
    if (type === 'file') {
      html += _row('Accept', _inp('nu-field-accept', extra, 'accept', '.pdf,.jpg,.png'));
      html += '<div class="nb-fp">' + _chkLbl('nu-field-multiple-upload', extra, 'multiple_upload', 'Allow multiple files') + '</div>';
    }
    if (type === 'select' || type === 'radio' || type === 'checkbox_group') {
      var srcType = _val(extra, 'source_type', _val(extra, 'sourcetype', 'static'));
      var opts    = (extra.options || []).map(function (o) { return (o.value || '') + '|' + (o.label || o.value || ''); }).join('\n');
      var sqlVal  = _val(extra, 'sql_source', _val(extra, 'sqlsource', ''));
      html += '<div class="nb-fp nb-fp-full"><label>Option Source</label>' +
        '<select class="nu-input nu-select-source-type" onchange="nbFormBuilder.toggleSelectSource(this)">' +
          '<option value="static"' + (srcType === 'static' ? ' selected' : '') + '>Static Options</option>' +
          '<option value="sql"'    + (srcType === 'sql'    ? ' selected' : '') + '>SQL Query</option>' +
        '</select></div>' +
        '<div class="nb-fp nb-fp-full nu-static-block"' + (srcType !== 'static' ? ' style="display:none"' : '') + '>' +
          '<label>Options <span style="font-weight:400;">(value|label per line)</span></label>' +
          '<textarea class="nu-input nu-select-static" rows="4" placeholder="active|Active\npending|Pending">' + _esc(opts) + '</textarea>' +
        '</div>' +
        '<div class="nb-fp nb-fp-full nu-sql-block"' + (srcType !== 'sql' ? ' style="display:none"' : '') + '>' +
          '<label>SQL Query</label>' +
          '<textarea class="nu-input nu-select-sql" rows="3" placeholder="SELECT id, name FROM customers">' + _esc(sqlVal) + '</textarea>' +
        '</div>';
      if (type === 'select') {
        html += '<div class="nb-fp">' + _chkLbl('nu-field-multiple', extra, 'multiple', 'Multi-select') + '</div>';
        html += '<div class="nb-fp">' + _chkLbl('nu-field-select2',  extra, 'select2',  'Use Select2')  + '</div>';
      }
    }
    if (type === 'lookup') {
      var lk    = extra.lookup || {};
      var lkSrc = lk.table ? lk.table + '.' + (lk.display_column || lk.displaycolumn || 'name') : '';
      html += '<div class="nb-fp nb-fp-full"><label>Source (table.column)</label>' +
        '<input type="text" class="nu-input nu-lookup-source" value="' + _esc(lkSrc) + '" placeholder="customers.name"></div>' +
        _row('ID Column',    '<input type="text" class="nu-input nu-lookup-id"     value="' + _esc(lk.id_column || lk.idcolumn || 'id') + '" placeholder="id">') +
        _row('Filter SQL',   '<input type="text" class="nu-input nu-lookup-filter" value="' + _esc(lk.filter || '')                    + '" placeholder="active=1">') +
        '<div class="nb-fp nb-fp-full"><label>Extra Mapping (src:field, comma-sep)</label>' +
        '<input type="text" class="nu-input nu-lookup-extra" value="' + _esc(lk.extra || '') + '" placeholder="dept_id:department"></div>';
    }
    if (type === 'subform') {
      var sf  = extra.subform || {};
      var sfv = sf.form_code ? sf.form_code + '.' + (sf.fk_field || '') : '';
      html += '<div class="nb-fp nb-fp-full"><label>Config (form_code.fk_field)</label>' +
        '<input type="text" class="nu-input nu-subform-config" value="' + _esc(sfv) + '" placeholder="order_items.order_id"></div>' +
        '<div class="nb-fp"><label>View</label>' +
        '<select class="nu-input nu-subform-view">' +
          '<option value="grid"'   + ((sf.view || 'grid') === 'grid'   ? ' selected' : '') + '>Grid (table)</option>' +
          '<option value="form"'   + (sf.view === 'form'               ? ' selected' : '') + '>Form (cards)</option>' +
          '<option value="inline"' + (sf.view === 'inline'             ? ' selected' : '') + '>Inline (editable rows)</option>' +
        '</select></div>';
    }
    if (type === 'calculated') {
      html += '<div class="nb-fp nb-fp-full"><label>Expression</label>' +
        '<input type="text" class="nu-calc-expression" value="' + _esc(_val(extra, 'calculated')) + '" placeholder="getValue(\'qty\') * getValue(\'price\')"></div>';
    }
    if (type === 'html') {
      html += '<div class="nb-fp nb-fp-full"><label>HTML Content</label>' +
        '<textarea class="nu-input nu-html-content" rows="4" placeholder="<strong>Section header</strong>">' + _esc(_val(extra, 'html_content')) + '</textarea></div>';
    }
    if (type === 'button') {
      html += _row('Button Action', _inp('nu-field-button-action', extra, 'button_action', 'JS / procedure code'));
      html += _row('Legend',        _inp('nu-field-legend',        extra, 'legend',        ''));
    }
    html += '</div>';
    return html;
  }

  var _dragTool      = null;
  var _dragField     = null;
  var _dragContainer = null;

  function _initToolbox() {
    document.querySelectorAll('#panelFields .nb-tool').forEach(function (tool) {
      var t = tool.cloneNode(true);
      tool.parentNode.replaceChild(t, tool);
      t.addEventListener('dragstart', function (e) {
        _dragTool = t.dataset.type;
        t.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'copy';
      });
      t.addEventListener('dragend', function () { t.classList.remove('dragging'); });
      t.addEventListener('click',   function () { _addField(t.dataset.type); });
    });
  }

  // ── make a drop-zone accept fields from toolbox ─────────────────────────────
  function _bindDropZone(zone) {
    zone.addEventListener('dragover', function (e) { e.preventDefault(); e.stopPropagation(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function (e) { if (!zone.contains(e.relatedTarget)) zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault(); e.stopPropagation();
      zone.classList.remove('drag-over');
      if (_dragTool) { _addFieldTo(zone, _dragTool); _dragTool = null; }
      else if (_dragField && !zone.contains(_dragField)) { zone.appendChild(_dragField); _dragField = null; }
      _canvasEmpty();
    });
  }

  function _initCanvasDrop() {
    var canvas = _el('formCanvas');
    if (!canvas) return;
    _bindDropZone(canvas);
    _injectCanvasToolbar(canvas);
  }

  // ── canvas toolbar: + Row | + Section | + Group ─────────────────────────────
  function _injectCanvasToolbar(canvas) {
    var existing = canvas.parentNode.querySelector('.nb-canvas-toolbar');
    if (existing) existing.remove();
    var bar = document.createElement('div');
    bar.className = 'nb-canvas-toolbar';
    bar.style.cssText =
      'display:flex;gap:8px;padding:8px 0 4px;margin-bottom:6px;border-bottom:1px dashed var(--border-color,#ddd);';
    var btns = [
      { label: '⊞ + Row',     fn: function () { _addRow(canvas); } },
      { label: '▦ + Section', fn: function () { _addSection(canvas); } },
      { label: '⊟ + Group',   fn: function () { _addGroup(canvas); } }
    ];
    btns.forEach(function (b) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = b.label;
      btn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
      btn.addEventListener('click', b.fn);
      bar.appendChild(btn);
    });
    canvas.parentNode.insertBefore(bar, canvas);
  }

  // ── make a field card draggable within its parent container ─────────────────
  function _makeDraggable(el) {
    el.setAttribute('draggable', 'true');
    el.addEventListener('dragstart', function (e) {
      _dragField = el; el.classList.add('drag-source'); e.dataTransfer.effectAllowed = 'move';
      e.stopPropagation();
    });
    el.addEventListener('dragend', function () {
      el.classList.remove('drag-source');
      _dragField = null;
    });
    el.addEventListener('dragover', function (e) {
      if (!_dragField || _dragField === el) return;
      e.preventDefault(); e.stopPropagation();
      var r = el.getBoundingClientRect();
      var parent = el.parentNode;
      if (e.clientY > r.top + r.height / 2) parent.insertBefore(_dragField, el.nextSibling);
      else parent.insertBefore(_dragField, el);
    });
  }

  // ── make a container (row/section/group) card draggable ─────────────────────
  function _makeContainerDraggable(el) {
    var handle = el.querySelector('.nb-container-drag');
    if (!handle) return;
    el.setAttribute('draggable', 'true');
    handle.addEventListener('mousedown', function () { el.setAttribute('draggable', 'true'); });
    el.addEventListener('dragstart', function (e) {
      _dragContainer = el; el.classList.add('drag-source'); e.dataTransfer.effectAllowed = 'move';
    });
    el.addEventListener('dragend', function () {
      el.classList.remove('drag-source');
      _dragContainer = null;
    });
    el.addEventListener('dragover', function (e) {
      if (!_dragContainer || _dragContainer === el) return;
      e.preventDefault(); e.stopPropagation();
      var r = el.getBoundingClientRect();
      var parent = el.parentNode;
      if (e.clientY > r.top + r.height / 2) parent.insertBefore(_dragContainer, el.nextSibling);
      else parent.insertBefore(_dragContainer, el);
    });
  }

  // ── add a field directly into a specific drop zone ─────────────────────────
  function _addFieldTo(zone, type, extra) {
    extra = extra || {};
    var label = (typeof extra.label === 'string' && extra.label) ||
      (type.charAt(0).toUpperCase() + type.slice(1).replace(/_/g, ' ') + ' Field');
    var name = extra.name || (type + '_' + Date.now());
    extra.label    = extra.label    !== undefined ? extra.label    : label;
    extra.name     = extra.name     !== undefined ? extra.name     : name;
    extra.required = extra.required !== undefined ? extra.required : false;

    var col = parseInt(extra.col, 10) || 12;
    var card = document.createElement('div');
    card.className    = 'nb-cfield nu-builder-field';
    card.dataset.type = type;
    card.dataset.col  = col;

    var typeLabel = type.replace(/_/g, ' ');
    card.innerHTML =
      '<div class="nb-cfield-header" onclick="nbFormBuilder.toggleField(this)">' +
        '<span class="nb-cfield-drag" title="Drag to reorder" onclick="event.stopPropagation()">&#x2807;</span>' +
        '<span class="nb-cfield-type-badge">' + typeLabel + '</span>' +
        '<span class="nb-cfield-label">' + _esc(extra.label) + '</span>' +
        '<span class="nb-col-badge" title="Column span">col-' + col + '</span>' +
        '<div class="nb-cfield-actions">' +
          '<button type="button" class="nb-cfield-btn" onclick="event.stopPropagation();nbFormBuilder.toggleField(this.closest(\'.nb-cfield\').querySelector(\'.nb-cfield-body\'))" title="Expand/Collapse">&#x2BC6;</button>' +
          '<button type="button" class="nb-cfield-btn nb-cfield-del" onclick="event.stopPropagation();this.closest(\'.nb-cfield\').remove();nbFormBuilder._canvasEmpty()" title="Delete field">&#x2715;</button>' +
        '</div>' +
      '</div>' +
      '<div class="nb-cfield-body" style="display:none;">' +
        _fieldPanel(type, extra) +
      '</div>';

    // update col badge + dataset when col selector changes
    card.addEventListener('change', function (e) {
      if (e.target && e.target.classList.contains('nu-field-col')) {
        var v = parseInt(e.target.value, 10) || 12;
        card.dataset.col = v;
        var badge = card.querySelector('.nb-col-badge');
        if (badge) badge.textContent = 'col-' + v;
      }
      // update label display
      if (e.target && e.target.classList.contains('nu-builder-label')) {
        var lbl = card.querySelector('.nb-cfield-label');
        if (lbl) lbl.textContent = e.target.value || '';
      }
    });
    card.addEventListener('input', function (e) {
      if (e.target && e.target.classList.contains('nu-builder-label')) {
        var lbl = card.querySelector('.nb-cfield-label');
        if (lbl) lbl.textContent = e.target.value || '';
      }
    });

    _makeDraggable(card);
    zone.appendChild(card);
    _canvasEmpty();
    return card;
  }

  // ── add a bare field to the root canvas (wraps in a row automatically) ──────
  function _addField(type, extra) {
    var canvas = _el('formCanvas');
    if (!canvas) return;
    // if canvas has a row at the end, add to it; otherwise create a new row
    var rows = canvas.querySelectorAll(':scope > .nb-row');
    var lastRow = rows.length ? rows[rows.length - 1] : null;
    var targetRow;
    if (lastRow) {
      targetRow = lastRow.querySelector('.nb-row-body');
    } else {
      targetRow = _addRow(canvas);
    }
    _addFieldTo(targetRow, type, extra);
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // ROW builder node
  // ─────────────────────────────────────────────────────────────────────────────
  function _addRow(parent, extra) {
    extra = extra || {};
    var row = document.createElement('div');
    row.className = 'nb-row';
    row.style.cssText =
      'border:1px dashed var(--border-color,#ccc);border-radius:8px;margin-bottom:10px;background:var(--bg-offset,#fafafa);';

    var hdr = document.createElement('div');
    hdr.className = 'nb-row-header';
    hdr.style.cssText =
      'display:flex;align-items:center;gap:6px;padding:5px 10px;background:var(--bg-elevated,#f0f0f0);'
      + 'border-radius:7px 7px 0 0;font-size:12px;color:var(--text-muted,#888);cursor:grab;';
    hdr.innerHTML =
      '<span class="nb-container-drag" style="font-size:14px;cursor:grab;">&#x2807;</span>'
      + '<span style="font-weight:600;font-size:12px;">ROW</span>'
      + '<span style="flex:1;"></span>'
      + '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="font-size:11px;padding:2px 7px;" onclick="nbFormBuilder._addFieldToRow(this)">+ Add Field</button>'
      + '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="font-size:11px;padding:2px 7px;color:var(--error,#c00);" onclick="if(confirm(\'Delete row and all its fields?\'))this.closest(\'.nb-row\').remove();nbFormBuilder._canvasEmpty()">✕</button>';

    var body = document.createElement('div');
    body.className = 'nb-row-body';
    body.style.cssText =
      'display:grid;grid-template-columns:repeat(12,1fr);gap:8px;padding:10px;align-items:start;min-height:56px;';

    _bindDropZone(body);

    row.appendChild(hdr);
    row.appendChild(body);
    parent.appendChild(row);
    _makeContainerDraggable(row);
    _canvasEmpty();

    // restore children
    if (extra.children && Array.isArray(extra.children)) {
      extra.children.forEach(function (f) { _addFieldTo(body, f.type || 'text', f); });
    }

    return body; // return the body so callers can append to it
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // SECTION builder node
  // ─────────────────────────────────────────────────────────────────────────────
  function _addSection(parent, extra) {
    extra = extra || {};
    var id  = extra.id  || ('sec_' + Date.now());
    var lbl = extra.label || 'New Section';

    var sec = document.createElement('div');
    sec.className = 'nb-section';
    sec.dataset.id  = id;
    sec.dataset.collapsible = extra.collapsible ? '1' : '0';
    sec.dataset.collapsed   = extra.collapsed   ? '1' : '0';
    sec.style.cssText =
      'border:2px solid var(--primary,#4f6bed);border-radius:10px;margin-bottom:14px;overflow:hidden;';

    var hdr = document.createElement('div');
    hdr.className = 'nb-section-header';
    hdr.style.cssText =
      'display:flex;align-items:center;gap:8px;padding:8px 12px;'
      + 'background:rgba(79,107,237,0.07);border-bottom:1px solid rgba(79,107,237,0.2);';
    hdr.innerHTML =
      '<span class="nb-container-drag" style="cursor:grab;font-size:15px;">&#x2807;</span>'
      + '<span class="nb-section-title-tag" style="font-size:11px;font-weight:700;color:var(--primary,#4f6bed);letter-spacing:.04em;">SECTION</span>'
      + '<input type="text" class="nu-input nb-section-label" value="' + _esc(lbl) + '" style="flex:1;font-weight:600;font-size:13px;" placeholder="Section label" oninput="this.closest(\'.nb-section\').dataset.label=this.value">'
      + '<label style="font-size:11px;display:flex;align-items:center;gap:4px;"><input type="checkbox" class="nb-sec-collapsible" ' + (extra.collapsible ? 'checked' : '') + ' onchange="this.closest(\'.nb-section\').dataset.collapsible=this.checked?1:0"> Collapsible</label>'
      + '<label style="font-size:11px;display:flex;align-items:center;gap:4px;"><input type="checkbox" class="nb-sec-collapsed" '   + (extra.collapsed   ? 'checked' : '') + ' onchange="this.closest(\'.nb-section\').dataset.collapsed=this.checked?1:0">   Collapsed</label>'
      + '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="font-size:11px;padding:2px 7px;" onclick="nbFormBuilder._addRowToContainer(this.closest(\'.nb-section\').querySelector(\'.nb-section-body\')">+ Row</button>'
      + '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="font-size:11px;padding:2px 7px;" onclick="nbFormBuilder._addGroupToContainer(this.closest(\'.nb-section\').querySelector(\'.nb-section-body\')">+ Group</button>'
      + '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="font-size:11px;padding:2px 7px;color:var(--error,#c00);" onclick="if(confirm(\'Delete section and all contents?\'))this.closest(\'.nb-section\').remove();nbFormBuilder._canvasEmpty()">✕</button>';

    var body = document.createElement('div');
    body.className = 'nb-section-body';
    body.style.cssText = 'padding:12px;min-height:60px;';
    _bindDropZone(body);

    sec.appendChild(hdr);
    sec.appendChild(body);
    parent.appendChild(sec);
    _makeContainerDraggable(sec);
    _canvasEmpty();

    // restore children (rows / groups)
    if (extra.children && Array.isArray(extra.children)) {
      extra.children.forEach(function (child) {
        var ct = child.type || 'row';
        if (ct === 'row')   _addRow(body, child);
        else if (ct === 'group') _addGroup(body, child);
        else _addFieldTo(body, ct, child);
      });
    }

    return body;
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // GROUP builder node
  // ─────────────────────────────────────────────────────────────────────────────
  function _addGroup(parent, extra) {
    extra = extra || {};
    var id  = extra.id  || ('grp_' + Date.now());
    var lbl = extra.label || 'New Group';

    var grp = document.createElement('div');
    grp.className = 'nb-group';
    grp.dataset.id  = id;
    grp.dataset.collapsible = extra.collapsible ? '1' : '0';
    grp.dataset.collapsed   = extra.collapsed   ? '1' : '0';
    grp.style.cssText =
      'border:1.5px solid var(--gold,#d19900);border-radius:8px;margin-bottom:10px;overflow:hidden;';

    var hdr = document.createElement('div');
    hdr.className = 'nb-group-header';
    hdr.style.cssText =
      'display:flex;align-items:center;gap:8px;padding:6px 10px;'
      + 'background:rgba(209,153,0,0.07);border-bottom:1px solid rgba(209,153,0,0.25);';
    hdr.innerHTML =
      '<span class="nb-container-drag" style="cursor:grab;font-size:15px;">&#x2807;</span>'
      + '<span class="nb-group-title-tag" style="font-size:11px;font-weight:700;color:#9a7000;letter-spacing:.04em;">GROUP</span>'
      + '<input type="text" class="nu-input nb-group-label" value="' + _esc(lbl) + '" style="flex:1;font-weight:600;font-size:13px;" placeholder="Group label" oninput="this.closest(\'.nb-group\').dataset.label=this.value">'
      + '<label style="font-size:11px;display:flex;align-items:center;gap:4px;"><input type="checkbox" class="nb-grp-collapsible" ' + (extra.collapsible ? 'checked' : '') + ' onchange="this.closest(\'.nb-group\').dataset.collapsible=this.checked?1:0"> Collapsible</label>'
      + '<label style="font-size:11px;display:flex;align-items:center;gap:4px;"><input type="checkbox" class="nb-grp-collapsed" '   + (extra.collapsed   ? 'checked' : '') + ' onchange="this.closest(\'.nb-group\').dataset.collapsed=this.checked?1:0">   Collapsed</label>'
      + '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="font-size:11px;padding:2px 7px;" onclick="nbFormBuilder._addRowToContainer(this.closest(\'.nb-group\').querySelector(\'.nb-group-body\')">+ Row</button>'
      + '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" style="font-size:11px;padding:2px 7px;color:var(--error,#c00);" onclick="if(confirm(\'Delete group and all contents?\'))this.closest(\'.nb-group\').remove();nbFormBuilder._canvasEmpty()">✕</button>';

    var body = document.createElement('div');
    body.className = 'nb-group-body';
    body.style.cssText = 'padding:10px;min-height:48px;';
    _bindDropZone(body);

    grp.appendChild(hdr);
    grp.appendChild(body);
    parent.appendChild(grp);
    _makeContainerDraggable(grp);
    _canvasEmpty();

    // restore children
    if (extra.children && Array.isArray(extra.children)) {
      extra.children.forEach(function (child) {
        var ct = child.type || 'row';
        if (ct === 'row') _addRow(body, child);
        else _addFieldTo(body, ct, child);
      });
    }

    return body;
  }

  // ── public helpers used by inline onclick attrs ────────────────────────────
  function _addRowToContainer(bodyEl) {
    if (!bodyEl) return;
    _addRow(bodyEl);
  }
  function _addGroupToContainer(bodyEl) {
    if (!bodyEl) return;
    _addGroup(bodyEl);
  }
  function _addFieldToRow(btn) {
    var rowBody = btn.closest('.nb-row').querySelector('.nb-row-body');
    if (rowBody) _addFieldTo(rowBody, 'text');
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // SERIALIZE — walks the canvas DOM and produces the layout JSON
  // ─────────────────────────────────────────────────────────────────────────────
  function _serializeField(card) {
    var type = card.dataset.type || 'text';
    var col  = parseInt(card.dataset.col, 10) || 12;
    var body = card.querySelector('.nb-cfield-body');
    if (!body) return { type: type, col: col };

    function v(sel)  { var el = body.querySelector(sel); return el ? el.value.trim() : ''; }
    function chk(sel){ var el = body.querySelector(sel); return el ? el.checked : false; }

    var obj = {
      type:     type,
      col:      col,
      label:    v('.nu-builder-label'),
      name:     v('.nu-builder-name'),
      required: chk('.nu-field-required')
    };

    var extras = [
      ['default_value',  '.nu-field-default'],
      ['placeholder',    '.nu-field-placeholder'],
      ['help_text',      '.nu-field-help'],
      ['css_class',      '.nu-field-cssclass'],
      ['tab',            '.nu-field-tab'],
      ['visibility_rule','.nu-field-vis'],
      ['readonly_rule',  '.nu-field-readonly'],
      ['js_onchange',    '.nu-field-onchange']
    ];
    extras.forEach(function (e) {
      var val = v(e[1]);
      if (val !== '') obj[e[0]] = val;
    });

    if (type === 'textarea') { var r = v('.nu-field-rows'); if (r) obj.rows = parseInt(r, 10); }
    if (type === 'number' || type === 'range') {
      var mn = v('.nu-field-min'); var mx = v('.nu-field-max'); var st = v('.nu-field-step');
      if (mn) obj.min = mn; if (mx) obj.max = mx; if (st) obj.step = st;
    }
    if (type === 'file') {
      var acc = v('.nu-field-accept'); if (acc) obj.accept = acc;
      obj.multiple_upload = chk('.nu-field-multiple-upload');
    }
    if (type === 'select' || type === 'radio' || type === 'checkbox_group') {
      var srcType = v('.nu-select-source-type') || 'static';
      obj.source_type = srcType;
      if (srcType === 'sql') {
        obj.sql_source = v('.nu-select-sql');
      } else {
        var lines = v('.nu-select-static').split('\n');
        obj.options = lines.filter(function (l) { return l.trim(); }).map(function (l) {
          var parts = l.split('|');
          return { value: (parts[0] || '').trim(), label: (parts[1] || parts[0] || '').trim() };
        });
      }
      if (type === 'select') {
        obj.multiple = chk('.nu-field-multiple');
        obj.select2  = chk('.nu-field-select2');
      }
    }
    if (type === 'lookup') {
      var src = v('.nu-lookup-source').split('.');
      obj.lookup = {
        table:          src[0] || '',
        display_column: src[1] || 'name',
        id_column:      v('.nu-lookup-id') || 'id',
        filter:         v('.nu-lookup-filter'),
        extra:          v('.nu-lookup-extra')
      };
    }
    if (type === 'subform') {
      var cfg = v('.nu-subform-config').split('.');
      obj.subform = { form_code: cfg[0] || '', fk_field: cfg[1] || '', view: v('.nu-subform-view') || 'grid' };
    }
    if (type === 'calculated') { obj.calculated = v('.nu-calc-expression'); }
    if (type === 'html')       { obj.html_content = v('.nu-html-content'); }
    if (type === 'button')     { obj.button_action = v('.nu-field-button-action'); obj.legend = v('.nu-field-legend'); }

    return obj;
  }

  function _serializeContainer(el) {
    if (el.classList.contains('nb-row')) {
      var rowBody = el.querySelector('.nb-row-body');
      var children = [];
      if (rowBody) {
        rowBody.querySelectorAll(':scope > .nb-cfield').forEach(function (c) { children.push(_serializeField(c)); });
      }
      return { type: 'row', children: children };
    }
    if (el.classList.contains('nb-section')) {
      var secBody = el.querySelector('.nb-section-body');
      var secChildren = [];
      if (secBody) {
        Array.from(secBody.children).forEach(function (child) {
          if (child.classList.contains('nb-row'))   secChildren.push(_serializeContainer(child));
          else if (child.classList.contains('nb-group')) secChildren.push(_serializeContainer(child));
          else if (child.classList.contains('nb-cfield')) secChildren.push(_serializeField(child));
        });
      }
      var labelInput = el.querySelector('.nb-section-label');
      return {
        type:        'section',
        id:          el.dataset.id || ('s' + Date.now()),
        label:       (labelInput ? labelInput.value : '') || 'Section',
        collapsible: el.dataset.collapsible === '1',
        collapsed:   el.dataset.collapsed   === '1',
        children:    secChildren
      };
    }
    if (el.classList.contains('nb-group')) {
      var grpBody = el.querySelector('.nb-group-body');
      var grpChildren = [];
      if (grpBody) {
        Array.from(grpBody.children).forEach(function (child) {
          if (child.classList.contains('nb-row'))    grpChildren.push(_serializeContainer(child));
          else if (child.classList.contains('nb-cfield')) grpChildren.push(_serializeField(child));
        });
      }
