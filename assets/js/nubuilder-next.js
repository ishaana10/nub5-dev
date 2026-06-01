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

// ─── SAVE FORM ────────────────────────────────────────────────────────────────
window.saveForm = async function () {
  const formId    = (document.getElementById('editFormId')    || {}).value || '';
  const formName  = ((document.getElementById('builderFormName')  || {}).value || '').trim();
  const formCode  = ((document.getElementById('builderFormCode')  || {}).value || '').trim();
  const tableMode = (document.querySelector('input[name="formTableMode"]:checked') || {}).value || 'new';
  const pkType    = (document.querySelector('input[name="formPkType"]:checked')    || {}).value || 'autoincrement';
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

  // build main payload
  const payload = {
    form_id:                   formId   || null,
    form_name:                 formName,
    form_code:                 formCode || formName.toLowerCase().replace(/[^a-z0-9]+/g, '_'),
    form_table:                formTable,
    form_type:                 formType,
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

// ─── nbFormBuilder ────────────────────────────────────────────────────────────
window.nbFormBuilder = (function () {

  function _esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
  function _val(obj, k, def) { return (obj && obj[k] !== undefined && obj[k] !== null) ? obj[k] : (def !== undefined ? def : ''); }
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
      _row('Label', '<input type="text" class="nu-input nu-builder-label" value="' + _esc(_val(extra, 'label', _val(extra, 'fieldlabel', ''))) + '" placeholder="Field label">') +
      _row('Field Name (DB column)', '<input type="text" class="nu-input nu-builder-name" value="' + _esc(_val(extra, 'name', _val(extra, 'fieldname', ''))) + '" placeholder="field_name">') +
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
    if (type === 'fieldset') {
      html += _row('Legend', _inp('nu-field-legend', extra, 'legend', 'Group title'));
    }
    if (type === 'html') {
      html += '<div class="nb-fp nb-fp-full"><label>HTML Content</label>' +
        '<textarea class="nu-input nu-html-content" rows="4" placeholder="<p>Static HTML here</p>">' + _esc(_val(extra, 'html_content')) + '</textarea></div>';
    }
    if (type === 'button') {
      html += _row('Button Action (JS)', _inp('nu-field-button-action', extra, 'button_action', 'submitForm()'));
    }

    html += '</div>'; // close nb-fp-grid
    return html;
  }

  // ─── FIELD CARD HTML ──────────────────────────────────────────────────────
  function _fieldCard(type, extra) {
    extra = extra || {};
    type = String(type || 'text');
    var icons = {
      text:'T', textarea:'¶', number:'#', email:'@', phone:'☏', date:'📅',
      select:'▾', radio:'◉', checkbox:'☑', checkbox_group:'☑☑',
      lookup:'🔍', file:'📎', image:'🖼', subform:'⊞', calculated:'∑',
      fieldset:'▭', html:'<>', button:'⬛', range:'⇔', hidden:'👁', password:'🔒',
    };
    var icon  = icons[type] || 'F';
    var label = _val(extra, 'label', _val(extra, 'fieldlabel', type.charAt(0).toUpperCase() + type.slice(1)));
    var name  = _val(extra, 'name',  _val(extra, 'fieldname', ''));
    return '<div class="nb-cfield" data-type="' + _esc(type) + '" draggable="true">' +
      '<div class="nb-cf-header">' +
        '<span class="nb-cf-icon">' + icon + '</span>' +
        '<span class="nb-cf-type">' + _esc(type) + '</span>' +
        '<div class="nb-cf-actions">' +
          '<button type="button" class="nb-cf-btn nb-cf-toggle" onclick="nbFormBuilder.togglePanel(this)" title="Settings">⚙</button>' +
          '<button type="button" class="nb-cf-btn nb-cf-del"    onclick="nbFormBuilder.removeField(this)"  title="Remove">✕</button>' +
        '</div>' +
      '</div>' +
      '<div class="nb-cf-summary"><span class="nb-cf-label-text">' + _esc(label) + '</span>' + (name ? ' <code>' + _esc(name) + '</code>' : '') + '</div>' +
      '<div class="nb-cf-panel" style="display:none;">' + _fieldPanel(type, extra) + '</div>' +
    '</div>';
  }

  // ─── DRAG & DROP ─────────────────────────────────────────────────────────
  var _dragSrc = null;

  function _initDrag(canvas) {
    canvas.addEventListener('dragstart', function (e) {
      var card = e.target.closest('.nb-cfield');
      if (!card) return;
      _dragSrc = card;
      e.dataTransfer.effectAllowed = 'move';
      setTimeout(function () { if (_dragSrc) _dragSrc.style.opacity = '0.4'; }, 0);
    });
    canvas.addEventListener('dragend', function () {
      canvas.querySelectorAll('.nb-cfield').forEach(function (c) { c.style.opacity = ''; c.classList.remove('nb-drag-over'); });
      _dragSrc = null;
    });
    canvas.addEventListener('dragover', function (e) {
      e.preventDefault();
      var card = e.target.closest('.nb-cfield');
      if (card && card !== _dragSrc) {
        canvas.querySelectorAll('.nb-cfield').forEach(function (c) { c.classList.remove('nb-drag-over'); });
        card.classList.add('nb-drag-over');
      }
    });
    canvas.addEventListener('drop', function (e) {
      e.preventDefault();
      var target = e.target.closest('.nb-cfield');
      if (!target || !_dragSrc || target === _dragSrc) return;
      var rect = target.getBoundingClientRect();
      if (e.clientY < rect.top + rect.height / 2) {
        canvas.insertBefore(_dragSrc, target);
      } else {
        canvas.insertBefore(_dragSrc, target.nextSibling);
      }
      target.classList.remove('nb-drag-over');
      _canvasEmpty();
    });
  }

  // ─── PUBLIC API ───────────────────────────────────────────────────────────
  return {

    _initAfterLoad: function () {
      var canvas = _el('formCanvas');
      if (canvas && !canvas.dataset.nbInit) {
        canvas.dataset.nbInit = '1';
        _initDrag(canvas);
      }
    },

    // ─── open() — show the builder panel in "new form" state ─────────────
    open: function () {
      var listSection  = _el('formsListSection');
      var builderCard  = _el('formBuilderCard');
      var builderTitle = _el('builderTitle');
      var editFormId   = _el('editFormId');
      var canvas       = _el('formCanvas');

      if (listSection)  listSection.style.display  = 'none';
      if (builderCard)  builderCard.style.display   = '';
      if (builderTitle) builderTitle.textContent    = 'New Form';
      if (editFormId)   editFormId.value            = '';

      // reset all meta inputs
      ['builderFormName','builderFormCode','builderFormTable',
       'formBrowseSql','formBrowseColumns','formBrowseSearchPlaceholder',
       'formBrowseSearchFields','formBrowseDefaultSort',
       'formCustomJs','formJsBeforeSave','formJsAfterSave',
       'formCustomPhp','formCustomCss'].forEach(function(id) {
        var el = _el(id);
        if (el) el.value = '';
      });
      var pageSizeEl = _el('formBrowsePageSize');
      if (pageSizeEl) pageSizeEl.value = '20';
      var searchEnabled = _el('formBrowseSearchEnabled');
      if (searchEnabled) searchEnabled.checked = false;

      // clear canvas
      if (canvas) {
        canvas.innerHTML = '<div class="nb-canvas-empty" id="canvasEmpty">&#x2B06; Drag or click a field type to add it here</div>';
      }

      // reset to first tab
      document.querySelectorAll('.nb-tab').forEach(function(t) { t.classList.remove('active'); });
      document.querySelectorAll('.nb-tab-panel').forEach(function(p) { p.classList.remove('active'); });
      var firstTab   = document.querySelector('.nb-tab[data-panel="panelFields"]');
      var firstPanel = _el('panelFields');
      if (firstTab)   firstTab.classList.add('active');
      if (firstPanel) firstPanel.classList.add('active');

      this._initAfterLoad();
    },

    // ─── close() — hide builder, show list ───────────────────────────────
    close: function () {
      var listSection = _el('formsListSection');
      var builderCard = _el('formBuilderCard');
      if (listSection) listSection.style.display = '';
      if (builderCard) builderCard.style.display  = 'none';
    },

    addField: function (type, extra) {
      var canvas = _el('formCanvas');
      if (!canvas) return;
      var safeType = String(type || 'text');
      canvas.insertAdjacentHTML('beforeend', _fieldCard(safeType, extra || {}));
      _canvasEmpty();
      var cards = canvas.querySelectorAll('.nb-cfield');
      var last  = cards[cards.length - 1];
      if (last) {
        var panel = last.querySelector('.nb-cf-panel');
        if (panel) panel.style.display = 'block';
        last.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    },

    togglePanel: function (btn) {
      var card  = btn.closest('.nb-cfield');
      var panel = card ? card.querySelector('.nb-cf-panel') : null;
      if (!panel) return;
      panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    },

    removeField: function (btn) {
      var card = btn.closest('.nb-cfield');
      if (card) { card.remove(); _canvasEmpty(); }
    },

    toggleSelectSource: function (sel) {
      var card = sel.closest('.nb-cfield');
      if (!card) return;
      var staticBlock = card.querySelector('.nu-static-block');
      var sqlBlock    = card.querySelector('.nu-sql-block');
      if (staticBlock) staticBlock.style.display = sel.value === 'static' ? '' : 'none';
      if (sqlBlock)    sqlBlock.style.display    = sel.value === 'sql'    ? '' : 'none';
    },

    switchTab: function (btn) {
      var panelId = btn.dataset.panel;
      document.querySelectorAll('.nb-tab').forEach(function(t) { t.classList.remove('active'); });
      document.querySelectorAll('.nb-tab-panel').forEach(function(p) { p.classList.remove('active'); });
      btn.classList.add('active');
      var panel = _el(panelId);
      if (panel) panel.classList.add('active');
    },

    // ─── selectTableMode — highlight chosen card, show/hide table inputs ──
    selectTableMode: function (mode, card) {
      document.querySelectorAll('.nb-tmode-card').forEach(function (c) {
        c.classList.toggle('nb-tmode-selected', c === card);
      });
      var newWrap      = _el('tableNewWrap');
      var existingWrap = _el('tableExistingWrap');
      if (newWrap)      newWrap.style.display      = mode === 'new'      ? '' : 'none';
      if (existingWrap) existingWrap.style.display = mode === 'existing' ? '' : 'none';
    },

    // ─── selectPkType — highlight chosen card ────────────────────────────
    selectPkType: function (type, card) {
      document.querySelectorAll('.nb-pk-card').forEach(function (c) {
        c.classList.toggle('nb-pk-selected', c === card);
      });
    },

    // ─── edit — load a saved form into the builder ───────────────────────
    edit: function (form) {
      if (!form) return;

      // if passed a numeric ID, fetch the form data first
      if (typeof form === 'number' || (typeof form === 'string' && !isNaN(form))) {
        var self = this;
        NuApp.apiJson('api/forms.php?action=get&id=' + encodeURIComponent(form), { credentials: 'same-origin' })
          .then(function(json) {
            if (json && json.success && json.form) {
              self.edit(json.form);
            } else {
              NuApp.toast((json && json.error) || 'Could not load form', 'error');
            }
          })
          .catch(function(err) {
            NuApp.toast('Error loading form: ' + err.message, 'error');
          });
        return;
      }

      // open the builder panel first
      this.open();

      var builderTitle = _el('builderTitle');
      if (builderTitle) builderTitle.textContent = 'Edit Form: ' + (form.form_name || '');

      // basic meta
      if (_el('editFormId'))       _el('editFormId').value       = form.form_id   || '';
      if (_el('builderFormName'))  _el('builderFormName').value  = form.form_name  || '';
      if (_el('builderFormCode'))  _el('builderFormCode').value  = form.form_code  || '';
      if (_el('builderFormTable')) _el('builderFormTable').value = form.form_table || '';

      // table mode
      var tableMode     = form.form_table_mode || 'new';
      var tmRadio       = document.querySelector('input[name="formTableMode"][value="' + tableMode + '"]');
      if (tmRadio) {
        tmRadio.checked = true;
        this.selectTableMode(tableMode, tmRadio.closest('.nb-tmode-card'));
      }
      if (tableMode === 'existing') {
        var existSel = _el('builderFormTableExisting');
        if (existSel) existSel.value = form.form_table || '';
      }

      // pk type
      var pkType   = form.form_pk_type || 'autoincrement';
      var pkRadio  = document.querySelector('input[name="formPkType"][value="' + pkType + '"]');
      if (pkRadio) {
        pkRadio.checked = true;
        this.selectPkType(pkType, pkRadio.closest('.nb-pk-card'));
      }

      // form type
      var fType   = form.form_type || 'main';
      var ftRadio = document.querySelector('input[name="formType"][value="' + fType + '"]');
      if (ftRadio) ftRadio.checked = true;

      // browse settings
      if (_el('formBrowseSql'))               _el('formBrowseSql').value               = form.browse_sql                || '';
      if (_el('formBrowseColumns'))           _el('formBrowseColumns').value           = form.browse_columns            || '';
      if (_el('formBrowseSearchEnabled'))     _el('formBrowseSearchEnabled').checked   = String(form.browse_search_enabled) === '1';
      if (_el('formBrowseSearchPlaceholder')) _el('formBrowseSearchPlaceholder').value = form.browse_search_placeholder || '';
      if (_el('formBrowseSearchFields'))      _el('formBrowseSearchFields').value      = form.browse_search_fields      || '';
      if (_el('formBrowsePageSize'))          _el('formBrowsePageSize').value          = form.browse_page_size          || 20;
      if (_el('formBrowseDefaultSort'))       _el('formBrowseDefaultSort').value       = form.browse_default_sort       || '';

      var bdm = form.browse_display_mode || 'inline';
      var bdmRadio = document.querySelector('input[name="browseDisplayMode"][value="' + bdm + '"]');
      if (bdmRadio) bdmRadio.checked = true;

      // advanced
      if (_el('formCustomJs'))     _el('formCustomJs').value     = form.form_custom_js     || '';
      if (_el('formJsBeforeSave')) _el('formJsBeforeSave').value = form.form_js_before_save|| '';
      if (_el('formJsAfterSave'))  _el('formJsAfterSave').value  = form.form_js_after_save || '';
      if (_el('formCustomPhp'))    _el('formCustomPhp').value    = form.form_custom_php    || '';
      if (_el('formCustomCss'))    _el('formCustomCss').value    = form.form_custom_css    || '';

      // rebuild canvas from saved layout
      var canvas = _el('formCanvas');
      if (canvas) {
        canvas.innerHTML = '';
        var layout = [];
        try {
          layout = typeof form.form_layout === 'string'
            ? JSON.parse(form.form_layout)
            : (Array.isArray(form.form_layout) ? form.form_layout : []);
        } catch (e) { layout = []; }
        var self = this;
        layout.forEach(function (f) {
          self.addField(f.fieldtype || f.type || 'text', f);
        });
      }
      _canvasEmpty();
    },

  };

})();
