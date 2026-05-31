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

// nbFormBuilder
window.nbFormBuilder = (function () {

  function _esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
  function _val(obj, k, def) { return (obj && obj[k] !== undefined) ? obj[k] : (def || ''); }
  function _chk(obj, k) { return (obj && obj[k]) ? 'checked' : ''; }
  function _el(id) { return document.getElementById(id); }

  function _canvasEmpty() {
    var canvas = _el('formCanvas');
    var empty  = _el('canvasEmpty');
    if (!canvas || !empty) return;
    empty.style.display = canvas.querySelectorAll('.nb-cfield').length ? 'none' : 'block';
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

  function _fieldPanel(type, extra) {
    extra = extra || {};
    var widthSel = '<select class="nu-input nu-field-width">' +
      ['25%', '33%', '50%', '66%', '75%', '100%'].map(function (w) {
        return '<option' + (_val(extra, 'width', '100%') === w ? ' selected' : '') + '>' + w + '</option>';
      }).join('') + '</select>';

    var html = '<div class="nb-fp-grid">' +
      _row('Label', '<input type="text" class="nu-input nu-builder-label" value="' + _esc(_val(extra, 'label')) + '" placeholder="Field label">') +
      _row('Field Name (DB column)', '<input type="text" class="nu-input nu-builder-name" value="' + _esc(_val(extra, 'name')) + '" placeholder="field_name">') +
      _row('Width', widthSel) +
      _row('Default Value',   _inp('nu-field-default',     extra, 'default_value',  'default value')) +
      _row('Placeholder',     _inp('nu-field-placeholder', extra, 'placeholder',    'hint text')) +
      _row('Help Text',       _inp('nu-field-help',        extra, 'help_text',      'shown under field')) +
      _row('CSS Class',       _inp('nu-field-cssclass',    extra, 'css_class',      'my-custom-class')) +
      _row('Tab',             _inp('nu-field-tab',         extra, 'tab',            'tab name')) +
      _row('Section',         _inp('nu-field-section',     extra, 'section',        'section heading')) +
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
        '<input type="text" class="nu-input nu-calc-expression" value="' + _esc(_val(extra, 'calculated')) + '" placeholder="getValue(\'qty\') * getValue(\'price\')"></div>';
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

  var _dragTool    = null;
  var _dragField   = null;
  // ─── dropEnabled flag — set false to lock canvas drag-and-drop reordering ────
  var _dropEnabled = true;

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

  function _initCanvasDrop() {
    var canvas = _el('formCanvas');
    if (!canvas) return;
    canvas.addEventListener('dragover', function (e) {
      // only accept palette tool drops when _dropEnabled is false (field reorder blocked but new drops still allowed)
      e.preventDefault();
      canvas.classList.add('drag-over');
    });
    canvas.addEventListener('dragleave', function () { canvas.classList.remove('drag-over'); });
    canvas.addEventListener('drop', function (e) {
      e.preventDefault();
      canvas.classList.remove('drag-over');
      if (_dragTool) { _addField(_dragTool); _dragTool = null; }
    });
  }

  function _makeDraggable(el) {
    el.setAttribute('draggable', 'true');
    el.addEventListener('dragstart', function (e) {
      if (!_dropEnabled) { e.preventDefault(); return; }
      _dragField = el; el.classList.add('drag-source'); e.dataTransfer.effectAllowed = 'move';
    });
    el.addEventListener('dragend', function () {
      el.classList.remove('drag-source');
      document.querySelectorAll('.nb-cfield').forEach(function (f) { f.style.outline = ''; });
      _dragField = null;
    });
    el.addEventListener('dragover', function (e) {
      if (!_dropEnabled) return;       // ← reorder blocked when drop disabled
      if (!_dragField || _dragField === el) return;
      e.preventDefault();
      var r = el.getBoundingClientRect();
      var canvas = _el('formCanvas');
      if (e.clientY > r.top + r.height / 2) canvas.insertBefore(_dragField, el.nextSibling);
      else canvas.insertBefore(_dragField, el);
    });
  }

  // ─── confirm before removing a field card ────────────────────────────────────
  // Always confirms. Message adapts based on whether the form is already saved.
  function _confirmFieldDelete(card) {
    var editId  = _el('editFormId');
    var isSaved = editId && editId.value && editId.value !== '';
    var labelEl = card.querySelector('.nb-cfield-label');
    var label   = labelEl ? (labelEl.textContent || '').trim() : 'this field';

    var msg = isSaved
      ? 'Remove field "' + label + '"?\n\nThis form has already been saved. The database column will be dropped when you save again. This cannot be undone.'
      : 'Remove field "' + label + '"?\n\nThe field will be removed from the layout.';

    return confirm(msg);
  }

  function _addField(type, label, name, required, extraData) {
    var canvas = _el('formCanvas');
    if (!canvas) return;
    var extra = extraData || {};
    if (!label) label = type.charAt(0).toUpperCase() + type.slice(1).replace(/_/g, ' ') + ' Field';
    if (!name)  name  = type + '_' + Date.now();
    extra.label    = extra.label    !== undefined ? extra.label    : label;
    extra.name     = extra.name     !== undefined ? extra.name     : name;
    extra.required = extra.required !== undefined ? extra.required : (required || false);

    var card = document.createElement('div');
    card.className    = 'nb-cfield nu-builder-field';
    card.dataset.type = type;
    card.dataset.width          = extra.width          || '100%';
    card.dataset.default        = extra.default_value  || '';
    card.dataset.placeholder    = extra.placeholder    || '';
    card.dataset.help           = extra.help_text      || '';
    card.dataset.cssClass       = extra.css_class      || '';
    card.dataset.sortOrder      = extra.sort_order     || '';
    card.dataset.rows           = extra.rows           || '3';
    card.dataset.min            = extra.min            || '';
    card.dataset.max            = extra.max            || '';
    card.dataset.step           = extra.step           || '';
    card.dataset.accept         = extra.accept         || '';
    card.dataset.multipleUpload = extra.multiple_upload ? '1' : '0';
    card.dataset.legend         = extra.legend         || '';
    card.dataset.select2        = extra.select2        ? '1' : '0';
    card.dataset.tab            = extra.tab            || '';
    card.dataset.section        = extra.section        || '';
    card.dataset.visibilityRule = extra.visibility_rule || '';
    card.dataset.readonlyRule   = extra.readonly_rule   || '';
    card.dataset.css            = extra.css            || '';
    card.dataset.onchange       = extra.js_onchange    || '';
    card.dataset.htmlContent    = extra.html_content   || '';
    card.dataset.buttonAction   = extra.button_action  || '';

    var typeLabel = type.replace(/_/g, ' ');
    card.innerHTML =
      '<div class="nb-cfield-header" onclick="nbFormBuilder.toggleField(this)">' +
        '<span class="nb-cfield-drag" title="Drag to reorder" onclick="event.stopPropagation()">&#x2807;</span>' +
        '<span class="nb-cfield-type-badge">' + typeLabel + '</span>' +
        '<span class="nb-cfield-label">' + _esc(extra.label) + '</span>' +
        '<div class="nb-cfield-actions">' +
          '<button type="button" class="nb-cfield-btn" onclick="event.stopPropagation();nbFormBuilder.toggleField(this.closest(\'.nb-cfield\').querySelector(\'.nb-cfield-header\'))">&#x2699;</button>' +
          '<button type="button" class="nb-cfield-btn del" onclick="event.stopPropagation();nbFormBuilder._deleteField(this);">&#x2715;</button>' +
        '</div>' +
      '</div>' +
      '<div class="nb-cfield-body">' + _fieldPanel(type, extra) + '</div>';

    canvas.appendChild(card);
    _canvasEmpty();
    _makeDraggable(card);

    var lInput = card.querySelector('.nu-builder-label');
    if (lInput) {
      lInput.addEventListener('input', function () {
        card.querySelector('.nb-cfield-label').textContent = lInput.value || '(no label)';
      });
    }
    if (canvas.querySelectorAll('.nb-cfield').length === 1) {
      card.querySelector('.nb-cfield-body').classList.add('open');
    }
  }

  // ─── helpers to restore pk/table-mode UI state ───────────────────────────────
  function _restorePkType(pkType) {
    var radio = document.querySelector('input[name="formPkType"][value="' + pkType + '"]');
    if (!radio) return;
    radio.checked = true;
    document.querySelectorAll('.nb-pk-card').forEach(function (c) { c.classList.remove('selected'); });
    var card = radio.closest('.nb-pk-card');
    if (card) card.classList.add('selected');
  }

  function _restoreTableMode(tableMode, formTable) {
    var radio = document.querySelector('input[name="formTableMode"][value="' + tableMode + '"]');
    if (!radio) return;
    radio.checked = true;
    document.querySelectorAll('.nb-tmode-card').forEach(function (c) { c.classList.remove('selected'); });
    var card = radio.closest('.nb-tmode-card');
    if (card) card.classList.add('selected');

    var existingWrap = document.getElementById('existingTableWrap');
    var newTableWrap = document.getElementById('newTableWrap');
    var existingSelect = document.getElementById('builderFormTableExisting');
    var pkCards = document.querySelectorAll('.nb-pk-card');

    if (tableMode === 'existing') {
      if (existingWrap) existingWrap.style.display = '';
      if (newTableWrap) newTableWrap.style.display = 'none';
      if (existingSelect && formTable) existingSelect.value = formTable;
      pkCards.forEach(function (c) { c.style.opacity = '0.45'; c.style.pointerEvents = 'none'; });
    } else {
      if (existingWrap) existingWrap.style.display = 'none';
      if (newTableWrap) newTableWrap.style.display = '';
      pkCards.forEach(function (c) { c.style.opacity = ''; c.style.pointerEvents = ''; });
    }
  }

  return {
    _canvasEmpty: _canvasEmpty,

    // ─── called by the delete (×) button on each field card ──────────────────
    _deleteField: function (btn) {
      var card = btn.closest('.nb-cfield');
      if (!card) return;
      if (!_confirmFieldDelete(card)) return;
      card.remove();
      _canvasEmpty();
    },

    _initAfterLoad: function () {
      _initToolbox();
      _initCanvasDrop();
    },

    // ─── dropEnabled public API ───────────────────────────────────────────────
    // Call nbFormBuilder.setDropEnabled(false) to lock field reordering (e.g. preview mode)
    // Call nbFormBuilder.setDropEnabled(true)  to re-enable it
    setDropEnabled: function (val) {
      _dropEnabled = !!val;
      var canvas = _el('formCanvas');
      if (!canvas) return;
      canvas.querySelectorAll('.nb-cfield-drag').forEach(function (h) {
        h.style.visibility = _dropEnabled ? 'visible' : 'hidden';
        h.title = _dropEnabled ? 'Drag to reorder' : 'Reordering disabled';
      });
    },

    selectDisplayMode: function (mode, clickedCard) {
      var radio = clickedCard ? clickedCard.querySelector('input[type=radio]') : document.getElementById('browseDisplayMode' + mode.charAt(0).toUpperCase() + mode.slice(1));
      if (radio) radio.checked = true;
      document.querySelectorAll('.nb-display-mode-card').forEach(function (c) { c.classList.remove('selected'); });
      if (clickedCard) {
        clickedCard.classList.add('selected');
      } else {
        var target = document.querySelector('.nb-display-mode-card input[value="' + mode + '"]');
        if (target) target.closest('.nb-display-mode-card').classList.add('selected');
      }
    },

    selectPkType: function (pkType, clickedCard) {
      var radio = clickedCard ? clickedCard.querySelector('input[type=radio]') :
        document.querySelector('input[name="formPkType"][value="' + pkType + '"]');
      if (radio) radio.checked = true;
      document.querySelectorAll('.nb-pk-card').forEach(function (c) { c.classList.remove('selected'); });
      var target = clickedCard || (radio ? radio.closest('.nb-pk-card') : null);
      if (target) target.classList.add('selected');
    },

    selectTableMode: function (tableMode, clickedCard) {
      var radio = clickedCard ? clickedCard.querySelector('input[type=radio]') :
        document.querySelector('input[name="formTableMode"][value="' + tableMode + '"]');
      if (radio) radio.checked = true;
      document.querySelectorAll('.nb-tmode-card').forEach(function (c) { c.classList.remove('selected'); });
      var target = clickedCard || (radio ? radio.closest('.nb-tmode-card') : null);
      if (target) target.classList.add('selected');

      var existingWrap   = document.getElementById('existingTableWrap');
      var newTableWrap   = document.getElementById('newTableWrap');
      var pkCards        = document.querySelectorAll('.nb-pk-card');

      if (tableMode === 'existing') {
        if (existingWrap) existingWrap.style.display = '';
        if (newTableWrap) newTableWrap.style.display = 'none';
        pkCards.forEach(function (c) { c.style.opacity = '0.45'; c.style.pointerEvents = 'none'; });
      } else {
        if (existingWrap) existingWrap.style.display = 'none';
        if (newTableWrap) newTableWrap.style.display = '';
        pkCards.forEach(function (c) { c.style.opacity = ''; c.style.pointerEvents = ''; });
      }
    },

    toggleField: function (header) {
      var body = header ? header.nextElementSibling : null;
      if (!body) return;
      body.classList.toggle('open');
    },

    toggleSelectSource: function (sel) {
      var wrap   = sel.closest('.nb-fp-full') || sel.closest('.nb-fp');
      var panel  = wrap ? wrap.parentElement : sel.closest('.nb-fp-grid');
      if (!panel) return;
      var staticBlock = panel.querySelector('.nu-static-block');
      var sqlBlock    = panel.querySelector('.nu-sql-block');
      if (staticBlock) staticBlock.style.display = sel.value === 'static' ? '' : 'none';
      if (sqlBlock)    sqlBlock.style.display    = sel.value === 'sql'    ? '' : 'none';
    },

    edit: function (form) {
      if (!form) return;
      var canvas = _el('formCanvas');
      if (canvas) canvas.innerHTML = '';

      var nameEl  = _el('builderFormName');
      var codeEl  = _el('builderFormCode');
      var tableEl = _el('builderFormTable');
      var idEl    = _el('editFormId');

      if (nameEl)  nameEl.value  = form.form_name  || '';
      if (codeEl)  codeEl.value  = form.form_code  || '';
      if (tableEl) tableEl.value = form.form_table || '';
      if (idEl)    idEl.value    = form.form_id    || '';

      // restore advanced form settings
      var browseFields = {
        formBrowseSql:               form.browse_sql              || '',
        formBrowseColumns:           form.browse_columns          || '',
        formBrowseSearchPlaceholder: form.browse_search_placeholder || '',
        formBrowseSearchFields:      form.browse_search_fields    || '',
        formBrowsePageSize:          form.browse_page_size        || 20,
        formBrowseDefaultSort:       form.browse_default_sort     || '',
        formCustomJs:                form.form_custom_js          || '',
        formJsBeforeSave:            form.form_js_before_save     || '',
        formJsAfterSave:             form.form_js_after_save      || '',
        formCustomPhp:               form.form_custom_php         || '',
        formCustomCss:               form.form_custom_css         || ''
      };
      Object.keys(browseFields).forEach(function (k) {
        var el = _el(k);
        if (el) el.value = browseFields[k];
      });
      var searchEnabledEl = _el('formBrowseSearchEnabled');
      if (searchEnabledEl) searchEnabledEl.checked = !!form.browse_search_enabled;

      // restore display mode
      if (form.browse_display_mode) {
        var dmRadio = document.querySelector('input[name="browseDisplayMode"][value="' + form.browse_display_mode + '"]');
        if (dmRadio) {
          dmRadio.checked = true;
          var dmCard = dmRadio.closest('.nb-display-mode-card');
          document.querySelectorAll('.nb-display-mode-card').forEach(function (c) { c.classList.remove('selected'); });
          if (dmCard) dmCard.classList.add('selected');
        }
      }

      // restore pk type and table mode
      _restorePkType(form.form_pk_type || 'autoincrement');
      _restoreTableMode(form.form_table_mode || 'new', form.form_table || '');

      // restore fields onto canvas
      var layout = [];
      try { layout = JSON.parse(form.form_layout || '[]'); } catch (e) { layout = []; }
      if (Array.isArray(layout)) {
        layout.forEach(function (f) {
          _addField(f.type || f.fieldtype || 'text', f.label || f.fieldlabel || '', f.name || f.fieldname || '', f.required || false, f);
        });
      }

      _canvasEmpty();
    }
  };
})();
