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

  // ─── BREADCRUMB HELPER ────────────────────────────────────────────────
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

  // ─── FULL-PAGE MODE HELPERS ────────────────────────────────────────────
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

  // ─── PREVIEW FORM — resizable modal (compact / standard / full) ──────────
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

  // ─── EDIT RECORD — modal with breadcrumb ─────────────────────────────────
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

  // ─── BROWSE FORM — dispatches to inline / modal / fullpage ───────────────
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

  // ─── Shared: fetch browse data ─────────────────────────────────────────────
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

  // ─── Shared: build browse table DOM ──────────────────────────────────────
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

  // ─── MODE 1: INLINE ──────────────────────────────────────────────────
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

  // ─── MODE 2: MODAL ────────────────────────────────────────────────────
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

  // ─── MODE 3: FULL PAGE ──────────────────────────────────────────────────
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

// ─── SAVE FORM — reads all builder fields including form_type, form_code, table_mode, pk_type ─
window.saveForm = async function () {
  const formId    = (document.getElementById('editFormId')    || {}).value || '';
  const formName  = ((document.getElementById('builderFormName')  || {}).value || '').trim();
  const formCode  = ((document.getElementById('builderFormCode')  || {}).value || '').trim();
  const tableMode = (document.querySelector('input[name="formTableMode"]:checked') || {}).value || 'new';
  const pkType    = (document.querySelector('input[name="formPkType"]:checked')    || {}).value || 'autoincrement';
  // ← FIX: read form_type from the radio cards
  const formType  = (document.querySelector('input[name="formType"]:checked')      || {}).value || 'main';

  // resolve table name: existing-table select or new-table text input
  let formTable = '';
  if (tableMode === 'existing') {
    formTable = ((document.getElementById('builderFormTableExisting') || {}).value || '').trim();
  } else {
    formTable = ((document.getElementById('builderFormTable') || {}).value || '').trim();
  }

  if (!formName) { NuApp.toast('Form name is required', 'error'); return; }

  // collect fields from canvas
  const fields = [];
  document.querySelectorAll('#formCanvas .nb-cfield').forEach(function (card, idx) {
    const type      = card.dataset.type || 'text';
    const labelEl   = card.querySelector('.nu-builder-label');
    const nameEl    = card.querySelector('.nu-builder-name');
    const widthEl   = card.querySelector('.nu-field-width');
    const reqEl     = card.querySelector('.nu-field-required');
    const label     = labelEl  ? labelEl.value.trim()  : '';
    const name      = nameEl   ? nameEl.value.trim()   : '';
    const width     = widthEl  ? widthEl.value         : '100%';
    const required  = reqEl    ? reqEl.checked          : false;

    const field = {
      type:             type,
      fieldtype:        type,
      label:            label,
      fieldlabel:       label,
      name:             name,
      fieldname:        name,
      width:            width,
      required:         required,
      sort_order:       idx,
      default_value:    (card.querySelector('.nu-field-default')     || {}).value || '',
      placeholder:      (card.querySelector('.nu-field-placeholder') || {}).value || '',
      help_text:        (card.querySelector('.nu-field-help')        || {}).value || '',
      css_class:        (card.querySelector('.nu-field-cssclass')    || {}).value || '',
      tab:              (card.querySelector('.nu-field-tab')         || {}).value || '',
      section:          (card.querySelector('.nu-field-section')     || {}).value || '',
      visibility_rule:  (card.querySelector('.nu-field-vis')         || {}).value || '',
      readonly_rule:    (card.querySelector('.nu-field-readonly')    || {}).value || '',
      js_onchange:      (card.querySelector('.nu-field-onchange')    || {}).value || '',
      rows:             parseInt((card.querySelector('.nu-field-rows')  || {}).value || '3', 10),
      min:              (card.querySelector('.nu-field-min')         || {}).value || '',
      max:              (card.querySelector('.nu-field-max')         || {}).value || '',
      step:             (card.querySelector('.nu-field-step')        || {}).value || '',
      accept:           (card.querySelector('.nu-field-accept')      || {}).value || '',
      multiple_upload:  !!((card.querySelector('.nu-field-multiple-upload') || {}).checked),
      legend:           (card.querySelector('.nu-field-legend')      || {}).value || '',
      select2:          !!((card.querySelector('.nu-field-select2')  || {}).checked),
      multiple:         !!((card.querySelector('.nu-field-multiple') || {}).checked),
      html_content:     (card.querySelector('.nu-html-content')      || {}).value || '',
      button_action:    (card.querySelector('.nu-field-button-action') || {}).value || '',
      calculated:       (card.querySelector('.nu-calc-expression')   || {}).value || '',
    };

    // select / radio / checkbox_group options
    const srcTypeEl = card.querySelector('.nu-select-source-type');
    if (srcTypeEl) {
      field.source_type = srcTypeEl.value || 'static';
      field.sourcetype  = field.source_type;
      if (field.source_type === 'static') {
        const raw = ((card.querySelector('.nu-select-static') || {}).value || '').trim();
        field.options = raw.split('\n').filter(Boolean).map(function (line) {
          const parts = line.split('|');
          return { value: (parts[0] || '').trim(), label: (parts[1] || parts[0] || '').trim() };
        });
      } else {
        field.sql_source  = ((card.querySelector('.nu-select-sql') || {}).value || '').trim();
        field.sqlsource   = field.sql_source;
      }
    }

    // lookup
    const lkSrcEl = card.querySelector('.nu-lookup-source');
    if (lkSrcEl) {
      const lkSrc   = lkSrcEl.value.trim();
      const lkParts = lkSrc.split('.');
      field.lookup = {
        table:          lkParts[0] || '',
        display_column: lkParts[1] || 'name',
        displaycolumn:  lkParts[1] || 'name',
        id_column:      ((card.querySelector('.nu-lookup-id')     || {}).value || 'id').trim(),
        idcolumn:       ((card.querySelector('.nu-lookup-id')     || {}).value || 'id').trim(),
        filter:         ((card.querySelector('.nu-lookup-filter') || {}).value || '').trim(),
        extra:          ((card.querySelector('.nu-lookup-extra')  || {}).value || '').trim(),
      };
    }

    // subform
    const sfEl = card.querySelector('.nu-subform-config');
    if (sfEl) {
      const sfVal   = sfEl.value.trim();
      const sfParts = sfVal.split('.');
      field.subform = {
        form_code: sfParts[0] || '',
        fk_field:  sfParts[1] || '',
        view:      ((card.querySelector('.nu-subform-view') || {}).value || 'grid'),
      };
    }

    fields.push(field);
  });

  // build main payload — form_type is now included
  const payload = {
    form_id:                   formId   || null,
    form_name:                 formName,
    form_code:                 formCode || formName.toLowerCase().replace(/[^a-z0-9]+/g, '_'),
    form_table:                formTable,
    form_type:                 formType,          // ← FIX: was missing
    form_table_mode:           tableMode,
    form_pk_type:              pkType,
    form_layout:               JSON.stringify(fields),
    browse_sql:                ((document.getElementById('formBrowseSql')               || {}).value || ''),
    browse_columns:            ((document.getElementById('formBrowseColumns')           || {}).value || ''),
    browse_search_enabled:     (document.getElementById('formBrowseSearchEnabled')      || {}).checked ? 1 : 0,
    browse_search_placeholder: ((document.getElementById('formBrowseSearchPlaceholder') || {}).value || ''),
    browse_search_fields:      ((document.getElementById('formBrowseSearchFields')      || {}).value || ''),
    browse_page_size:          parseInt((document.getElementById('formBrowsePageSize')  || {}).value || '20', 10),
    browse_default_sort:       ((document.getElementById('formBrowseDefaultSort')       || {}).value || ''),
    browse_display_mode:       (document.querySelector('input[name="browseDisplayMode"]:checked') || {}).value || 'inline',
    form_custom_js:            ((document.getElementById('formCustomJs')                || {}).value || ''),
    form_js_before_save:       ((document.getElementById('formJsBeforeSave')            || {}).value || ''),
    form_js_after_save:        ((document.getElementById('formJsAfterSave')             || {}).value || ''),
    form_custom_php:           ((document.getElementById('formCustomPhp')               || {}).value || ''),
    form_custom_css:           ((document.getElementById('formCustomCss')               || {}).value || ''),
  };

  try {
    // 1. Save form meta + layout
    const saveRes = await NuApp.apiJson('api/forms.php?action=save', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify(payload),
    });
    if (!saveRes.success) { NuApp.toast(saveRes.error || 'Save failed', 'error'); return; }

    const savedId = saveRes.form_id || formId;
    if (document.getElementById('editFormId')) {
      document.getElementById('editFormId').value = savedId;
    }

    // 2. Run DB schema sync (create/alter table, pk_type, columns)
    const setupPayload = {
      form_id:    savedId,
      form_table: formTable,
      table_mode: tableMode,
      pk_type:    pkType,
      fields:     fields,
    };
    const setupRes = await NuApp.apiJson('api/form-setup.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify(setupPayload),
    });
    if (!setupRes.success) {
      NuApp.toast('Form saved but DB sync failed: ' + (setupRes.error || 'unknown error'), 'error');
      return;
    }

    NuApp.toast(formId ? 'Form updated' : 'Form created');
    NuApp.loadModule('forms');

  } catch (err) {
    console.error('saveForm error', err);
    NuApp.toast('Error: ' + err.message, 'error');
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
      html += _row('Accept', _inp('nu-field-accept