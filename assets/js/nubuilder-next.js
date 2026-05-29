window.NuApp = {
  currentModule: 'dashboard',

  init() {
    this.bindEvents();
    this.loadTheme();

    if (document.querySelector('.nu-app')) {
      const moduleFromHash = (window.location.hash || '').replace('#', '');
      this.loadModule(moduleFromHash || 'dashboard');
    }
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
    try {
      saved = localStorage.getItem('nu-theme') || 'auto';
    } catch (e) {}
    document.documentElement.setAttribute('data-theme', saved);
  },

  toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'light' ? 'dark' : current === 'dark' ? 'auto' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    try {
      localStorage.setItem('nu-theme', next);
    } catch (e) {}
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

  async loadModule(module) {
    this.currentModule = module;
    const container = document.getElementById('contentArea');
    const pageTitle = document.getElementById('pageTitle');

    if (!container) {
      console.error('contentArea not found');
      return;
    }

    if (pageTitle) {
      pageTitle.textContent = module.charAt(0).toUpperCase() + module.slice(1);
    }

    this.setActiveNavByModule(module);
    container.innerHTML = '<div class="nu-spinner" style="margin:40px auto;"></div>';

    try {
      const res = await fetch('modules/' + module + '/' + module + '.php', {
        credentials: 'same-origin'
      });

      const html = await res.text();

      if (!res.ok) {
        container.innerHTML =
          '<div style="padding:24px;border:2px solid red;background:#fee;">' +
          '<h3>Module load failed</h3>' +
          '<p>Status: ' + res.status + '</p>' +
          '</div>';
        return;
      }

      container.innerHTML = html;
      container.style.display = 'block';
      container.style.visibility = 'visible';
      container.style.opacity = '1';

      this.initModuleScripts(module);
    } catch (err) {
      console.error('loadModule error', err);
      container.innerHTML =
        '<div style="padding:24px;border:2px solid red;background:#fee;">' +
        '<h3>Error</h3>' +
        '<p>' + String(err.message || err) + '</p>' +
        '</div>';
    }
  },

  initModuleScripts(module) {
    if (module === 'forms' && typeof window.initFormBuilder === 'function') {
      window.initFormBuilder();
    }
  },

  async apiJson(url, options) {
    const res = await fetch(url, options || {});
    const text = await res.text();
    console.log('api raw response:', url, text);

    let json = null;
    try {
      json = JSON.parse(text);
    } catch (e) {
      throw new Error('Invalid JSON response');
    }

    return json;
  },

  async previewForm(code) {
    try {
      const json = await this.apiJson(
        'api/form.php?action=render&code=' + encodeURIComponent(code),
        { credentials: 'same-origin' }
      );

      if (!json.success) {
        this.toast(json.error || 'Preview failed', 'error');
        return;
      }

      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay';
      overlay.style.cssText =
        'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText =
        'background:#fff;border-radius:12px;padding:24px;max-width:900px;max-height:90vh;overflow-y:auto;width:92%;';

      box.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">' +
        '<h3 style="margin:0;">Preview</h3>' +
        '<button type="button" onclick="this.closest(\'.nu-form-overlay\').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;">×</button>' +
        '</div>' +
        json.html;

      overlay.appendChild(box);
      document.body.appendChild(overlay);

      if (window.nuForm && typeof window.nuForm.init === 'function') {
        const formEl = overlay.querySelector('.nu-generated-form');
        if (formEl) {
          window.nuForm.init(formEl.dataset.formCode || code, {}, true);
        }
      }
    } catch (err) {
      console.error('previewForm error', err);
      this.toast('Preview error: ' + err.message, 'error');
    }
  },

  async editRecord(code, id) {
    try {
      const json = await this.apiJson(
        'api/form.php?action=render&code=' + encodeURIComponent(code) + '&id=' + encodeURIComponent(id),
        { credentials: 'same-origin' }
      );

      if (!json.success) {
        this.toast(json.error || 'Failed', 'error');
        return;
      }

      const overlay = document.createElement('div');
      overlay.className = 'nu-form-overlay';
      overlay.style.cssText =
        'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText =
        'background:#fff;border-radius:12px;padding:24px;max-width:900px;max-height:90vh;overflow-y:auto;width:92%;';

      box.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">' +
        '<h3 style="margin:0;">Edit Record</h3>' +
        '<button type="button" onclick="this.closest(\'.nu-form-overlay\').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;">×</button>' +
        '</div>' +
        json.html;

      overlay.appendChild(box);
      document.body.appendChild(overlay);

      if (window.nuForm && typeof window.nuForm.init === 'function') {
        const formEl = overlay.querySelector('.nu-generated-form');
        if (formEl) {
          window.nuForm.init(formEl.dataset.formCode || code, {}, false);
        }
      }
    } catch (err) {
      console.error('editRecord error', err);
      this.toast('Error: ' + err.message, 'error');
    }
  },

  addRecord(code) {
    return this.previewForm(code);
  },

  async browseForm(code, page, query) {
    try {
      page = page || 1;
      query = query || '';

      const json = await this.apiJson(
        'api/form.php?action=list&code=' +
          encodeURIComponent(code) +
          '&page=' + encodeURIComponent(page) +
          '&q=' + encodeURIComponent(query),
        { credentials: 'same-origin' }
      );

      if (!json.success) {
        this.toast(json.error || 'Browse failed', 'error');
        return;
      }

      const data = json.data || {};
      const layout = Array.isArray(data.layout) ? data.layout : [];
      const records = Array.isArray(data.records) ? data.records : [];
      const currentQuery = data.query || query;
      const searchEnabled = String(data.browsesearchenabled || 0) === '1';
      const searchPlaceholder = data.browsesearchplaceholder || 'Search...';

      const container = document.getElementById('contentArea');
      if (!container) {
        this.toast('Content area not found', 'error');
        return;
      }

      container.innerHTML = '';

      const header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;';
      header.innerHTML = '<h3 style="margin:0;">Browse ' + code + '</h3>';

      const btnGroup = document.createElement('div');
      btnGroup.style.cssText = 'display:flex;gap:8px;';

      const addBtn = document.createElement('button');
      addBtn.className = 'nu-btn nu-btn-primary nu-btn-sm';
      addBtn.textContent = 'Add';
      addBtn.onclick = () => this.addRecord(code);
      btnGroup.appendChild(addBtn);

      const backBtn = document.createElement('button');
      backBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
      backBtn.textContent = 'Back';
      backBtn.onclick = () => this.loadModule('forms');
      btnGroup.appendChild(backBtn);

      header.appendChild(btnGroup);
      container.appendChild(header);

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
        searchBtn.onclick = () => this.browseForm(code, 1, searchInput.value.trim());

        const clearBtn = document.createElement('button');
        clearBtn.className = 'nu-btn nu-btn-ghost';
        clearBtn.textContent = 'Clear';
        clearBtn.onclick = () => this.browseForm(code, 1, '');

        searchInput.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            this.browseForm(code, 1, searchInput.value.trim());
          }
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
      headRow.style.cssText = 'border-bottom:1px solid var(--border-color, #ddd);';

      layout.forEach((f) => {
        const th = document.createElement('th');
        th.style.cssText = 'padding:12px;text-align:left;font-size:13px;';
        th.textContent = f.fieldlabel || f.label || f.fieldname || f.name || '';
        headRow.appendChild(th);
      });

      const actionTh = document.createElement('th');
      actionTh.textContent = 'Actions';
      actionTh.style.cssText = 'padding:12px;text-align:left;font-size:13px;';
      headRow.appendChild(actionTh);

      thead.appendChild(headRow);
      table.appendChild(thead);

      const tbody = document.createElement('tbody');

      if (!records.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = layout.length + 1;
        td.style.cssText = 'padding:40px;text-align:center;color:#666;';
        td.textContent = currentQuery ? 'No matching records' : 'No records';
        tr.appendChild(td);
        tbody.appendChild(tr);
      } else {
        records.forEach((row) => {
          const tr = document.createElement('tr');
          tr.style.cssText = 'border-bottom:1px solid var(--border-color, #ddd);';

          layout.forEach((f) => {
            const td = document.createElement('td');
            td.style.cssText = 'padding:12px;';

            const fieldName = f.fieldname || f.name;
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
          editBtn.onclick = () => this.editRecord(code, row.id);
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
        prevBtn.textContent = 'Prev';
        prevBtn.disabled = (data.page || 1) <= 1;
        prevBtn.onclick = () => this.browseForm(code, (data.page || 1) - 1, currentQuery);
        pagination.appendChild(prevBtn);

        for (let i = 1; i <= (data.pages || 1); i++) {
          const pageBtn = document.createElement('button');
          pageBtn.className = 'nu-btn ' + (i === (data.page || 1) ? 'nu-btn-primary' : 'nu-btn-ghost') + ' nu-btn-sm';
          pageBtn.textContent = i;
          pageBtn.onclick = () => this.browseForm(code, i, currentQuery);
          pagination.appendChild(pageBtn);
        }

        const nextBtn = document.createElement('button');
        nextBtn.className = 'nu-btn nu-btn-ghost nu-btn-sm';
        nextBtn.textContent = 'Next';
        nextBtn.disabled = (data.page || 1) >= (data.pages || 1);
        nextBtn.onclick = () => this.browseForm(code, (data.page || 1) + 1, currentQuery);
        pagination.appendChild(nextBtn);

        const meta = document.createElement('span');
        meta.style.cssText = 'margin-left:8px;color:#666;font-size:13px;';
        meta.textContent = 'Total ' + (data.total || 0);
        pagination.appendChild(meta);

        container.appendChild(pagination);
      }
    } catch (err) {
      console.error('browseForm error', err);
      this.toast('Error: ' + err.message, 'error');
    }
  }
};

document.addEventListener('DOMContentLoaded', function () {
  NuApp.init();
});

window.closeNuForm = function (btn) {
  const overlay = btn ? btn.closest('.nu-form-overlay') : null;
  if (overlay) {
    overlay.remove();
    return;
  }
  NuApp.loadModule('forms');
};

window.submitNuForm = async function (formElement) {
  if (!formElement) {
    NuApp.toast('Form element not found', 'error');
    return;
  }

  const formCode = formElement.dataset.formCode;
  const recordId = formElement.dataset.recordId;
  const url =
    'api/form.php?action=save&code=' + encodeURIComponent(formCode) +
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
    if (!Object.prototype.hasOwnProperty.call(data, el.name)) {
      data[el.name] = '';
    }
  });

  try {
    const json = await NuApp.apiJson(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });

    if (!json.success) {
      NuApp.toast(json.error || 'Save failed', 'error');
      return;
    }

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

window.openFormBuilder = function () {
  const card = document.getElementById('formBuilderCard');
  if (!card) return;

  const editId = document.getElementById('editFormId');
  const title = document.getElementById('builderTitle');
  const formName = document.getElementById('builderFormName');
  const formTable = document.getElementById('builderFormTable');
  const formCanvas = document.getElementById('formCanvas');

  if (editId) editId.value = '';
  if (title) title.textContent = 'New Form';
  if (formName) formName.value = '';
  if (formTable) formTable.value = '';
  if (formCanvas) formCanvas.innerHTML = '<p style="color:#777;text-align:center;padding:40px;">Drag fields here...</p>';

  const customJs = document.getElementById('formCustomJs');
  const customPhp = document.getElementById('formCustomPhp');
  const customCss = document.getElementById('formCustomCss');
  const browseSql = document.getElementById('formBrowseSql');
  const browseColumns = document.getElementById('formBrowseColumns');
  const browseSearchEnabled = document.getElementById('formBrowseSearchEnabled');
  const browseSearchPlaceholder = document.getElementById('formBrowseSearchPlaceholder');
  const browseSearchFields = document.getElementById('formBrowseSearchFields');
  const browsePageSize = document.getElementById('formBrowsePageSize');
  const browseDefaultSort = document.getElementById('formBrowseDefaultSort');

  if (customJs) customJs.value = '';
  if (customPhp) customPhp.value = '';
  if (customCss) customCss.value = '';
  if (browseSql) browseSql.value = '';
  if (browseColumns) browseColumns.value = '';
  if (browseSearchEnabled) browseSearchEnabled.checked = false;
  if (browseSearchPlaceholder) browseSearchPlaceholder.value = '';
  if (browseSearchFields) browseSearchFields.value = '';
  if (browsePageSize) browsePageSize.value = '20';
  if (browseDefaultSort) browseDefaultSort.value = '';

  card.style.display = 'block';
  card.scrollIntoView({ behavior: 'smooth' });

  if (typeof window.initFormBuilder === 'function') {
    window.initFormBuilder();
  }
};

window.saveForm = async function () {
  const id = document.getElementById('editFormId') ? document.getElementById('editFormId').value : '';
  const formName = document.getElementById('builderFormName') ? document.getElementById('builderFormName').value.trim() : '';
  const formTable = document.getElementById('builderFormTable') ? document.getElementById('builderFormTable').value.trim() : '';
  const formCode = formName.toLowerCase().replace(/[^a-z0-9]+/g, '_');

  if (!formName) {
    NuApp.toast('Form name required', 'error');
    return;
  }

  const fields = [];
  document.querySelectorAll('.nu-builder-field').forEach(function (el, index) {
    const labelInput = el.querySelector('.nu-builder-label') || el.querySelectorAll('input[type="text"]')[0];
    const nameInput = el.querySelector('.nu-builder-name') || el.querySelectorAll('input[type="text"]')[1];
    const requiredBox = el.querySelector('.nu-field-required');

    const field = {
      type: el.dataset.type || 'text',
      label: labelInput ? labelInput.value : '',
      name: nameInput ? nameInput.value : '',
      required: requiredBox ? requiredBox.checked : false,
      width: el.dataset.width || '100%',
      default_value: el.dataset.default || '',
      placeholder: el.dataset.placeholder || '',
      help_text: el.dataset.help || '',
      css_class: el.dataset.cssClass || '',
      sort_order: parseInt(el.dataset.sortOrder || (index + 1), 10),
      tab: el.dataset.tab || '',
      section: el.dataset.section || '',
      visibility_rule: el.dataset.visibilityRule || '',
      readonly_rule: el.dataset.readonlyRule || '',
      css: el.dataset.css || '',
      js_onchange: el.dataset.onchange || '',
      rows: parseInt(el.dataset.rows || '3', 10),
      min: el.dataset.min || '',
      max: el.dataset.max || '',
      step: el.dataset.step || '',
      accept: el.dataset.accept || '',
      multiple_upload: el.dataset.multipleUpload === '1' ? 1 : 0,
      html_content: el.dataset.htmlContent || '',
      button_action: el.dataset.buttonAction || '',
      legend: el.dataset.legend || '',
      select2: el.dataset.select2 === '1' ? 1 : 0
    };

    if (field.type === 'select' || field.type === 'radio' || field.type === 'checkbox_group') {
      const sourceType = el.querySelector('.nu-select-source-type');
      const sqlInput = el.querySelector('.nu-select-sql');
      const multiBox = el.querySelector('.nu-field-multiple');
      const select2Box = el.querySelector('.nu-field-select2');
      const txt = el.querySelector('.nu-select-static, textarea');

      if (multiBox) field.multiple = multiBox.checked;
      if (select2Box) field.select2 = select2Box.checked ? 1 : 0;

      if (sourceType && sourceType.value === 'sql' && sqlInput && sqlInput.value.trim()) {
        field.source_type = 'sql';
        field.sql_source = sqlInput.value.trim();
      } else {
        field.source_type = 'static';
        if (txt) {
          field.options = txt.value.split('\n').filter(Boolean).map(function (v) {
            const parts = v.split('|');
            return {
              value: (parts[0] || '').trim(),
              label: (parts[1] || parts[0] || '').trim()
            };
          });
        }
      }
    }

    if (field.type === 'lookup') {
      const txt = el.querySelector('.nu-lookup-source');
      const idCol = el.querySelector('.nu-lookup-id');
      const filterInput = el.querySelector('.nu-lookup-filter');
      const extraInput = el.querySelector('.nu-lookup-extra');

      // Accept both table.column and table:column formats
      const rawVal = txt ? txt.value.trim() : '';
      const sep = rawVal.indexOf(':') > -1 ? ':' : '.';
      if (rawVal && rawVal.indexOf(sep) > -1) {
        const parts = rawVal.split(sep);
        field.lookup = {
          table: parts[0].trim(),
          id_column: idCol ? idCol.value.trim() || 'id' : 'id',
          display_column: (parts[1] || 'name').trim(),
          filter: filterInput ? filterInput.value : '',
          extra: extraInput ? extraInput.value : ''
        };
      }
    }

    if (field.type === 'subform') {
      const txt = el.querySelector('.nu-subform-config');
      const view = el.querySelector('.nu-subform-view');
      if (txt && txt.value.indexOf('.') > -1) {
        const parts = txt.value.split('.');
        field.subform = {
          form_code: parts[0],
          fk_field: parts[1],
          view: view ? view.value : (el.dataset.subformView || 'grid')
        };
      }
    }

    if (field.type === 'calculated') {
      const txt = el.querySelector('.nu-calc-expression');
      if (txt) field.calculated = txt.value;
    }

    fields.push(field);
  });

  const payload = {
    form_name: formName,
    form_code: formCode,
    form_table: formTable,
    form_layout: JSON.stringify(fields),
    form_active: 1
  };

  const customJs = document.getElementById('formCustomJs');
  const customPhp = document.getElementById('formCustomPhp');
  const customCss = document.getElementById('formCustomCss');
  const browseSql = document.getElementById('formBrowseSql');
  const browseColumns = document.getElementById('formBrowseColumns');
  const browseSearchEnabled = document.getElementById('formBrowseSearchEnabled');
  const browseSearchPlaceholder = document.getElementById('formBrowseSearchPlaceholder');
  const browseSearchFields = document.getElementById('formBrowseSearchFields');
  const browsePageSize = document.getElementById('formBrowsePageSize');
  const browseDefaultSort = document.getElementById('formBrowseDefaultSort');

  if (customJs) payload.form_custom_js = customJs.value;
  if (customPhp) payload.form_custom_php = customPhp.value;
  if (customCss) payload.form_custom_css = customCss.value;
  if (browseSql) payload.browse_sql = browseSql.value;
  if (browseColumns) payload.browse_columns = browseColumns.value;
  if (browseSearchEnabled) payload.browse_search_enabled = browseSearchEnabled.checked ? 1 : 0;
  if (browseSearchPlaceholder) payload.browse_search_placeholder = browseSearchPlaceholder.value;
  if (browseSearchFields) payload.browse_search_fields = browseSearchFields.value;
  if (browsePageSize) payload.browse_page_size = browsePageSize.value || 20;
  if (browseDefaultSort) payload.browse_default_sort = browseDefaultSort.value;

  try {
    const endpoint = id
      ? 'api/crud.php?table=nu_forms&id=' + encodeURIComponent(id)
      : 'api/crud.php?table=nu_forms';

    const json = await NuApp.apiJson(endpoint, {
      method: id ? 'PUT' : 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    if (!json.success) {
      NuApp.toast(json.error || 'Save failed', 'error');
      return;
    }

    const formId = id || json.id;

    if (formTable) {
      try {
        const setupJson = await NuApp.apiJson('api/form-setup.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            form_id: formId,
            form_code: formCode,
            form_table: formTable,
            fields: fields
          })
        });

        if (!setupJson.success) {
          NuApp.toast('Table setup: ' + setupJson.error, 'error');
        }
      } catch (setupErr) {
        console.error('Table setup error', setupErr);
      }
    }

    NuApp.toast('Form saved');
    const card = document.getElementById('formBuilderCard');
    if (card) card.style.display = 'none';
    NuApp.loadModule('forms');
  } catch (err) {
    console.error('saveForm error', err);
    NuApp.toast('Error: ' + err.message, 'error');
  }
};

window.deleteForm = function (id, name) {
  if (!confirm('Delete form "' + name + '"?')) return;

  NuApp.apiJson('api/crud.php?table=nu_forms&id=' + encodeURIComponent(id), {
    method: 'DELETE',
    credentials: 'same-origin'
  })
    .then(function (json) {
      if (json.success) {
        NuApp.toast('Deleted');
        NuApp.loadModule('forms');
      } else {
        NuApp.toast(json.error || 'Failed', 'error');
      }
    })
    .catch(function (e) {
      NuApp.toast('Error: ' + e.message, 'error');
    });
};

window.editForm = async function (id) {
  try {
    const json = await NuApp.apiJson('api/crud.php?table=nu_forms&id=' + encodeURIComponent(id), {
      credentials: 'same-origin'
    });

    const form = json.data || json.record;
    if (!json.success || !form) {
      NuApp.toast('Could not load', 'error');
      return;
    }

    const card = document.getElementById('formBuilderCard');
    const canvas = document.getElementById('formCanvas');

    if (document.getElementById('editFormId')) document.getElementById('editFormId').value = id;
    if (document.getElementById('builderTitle')) document.getElementById('builderTitle').textContent = 'Edit Form';
    if (document.getElementById('builderFormName')) document.getElementById('builderFormName').value = form.form_name || '';
    if (document.getElementById('builderFormTable')) document.getElementById('builderFormTable').value = form.form_table || '';

    const customJs = document.getElementById('formCustomJs');
    const customPhp = document.getElementById('formCustomPhp');
    const customCss = document.getElementById('formCustomCss');
    const browseSql = document.getElementById('formBrowseSql');
    const browseColumns = document.getElementById('formBrowseColumns');
    const browseSearchEnabled = document.getElementById('formBrowseSearchEnabled');
    const browseSearchPlaceholder = document.getElementById('formBrowseSearchPlaceholder');
    const browseSearchFields = document.getElementById('formBrowseSearchFields');
    const browsePageSize = document.getElementById('formBrowsePageSize');
    const browseDefaultSort = document.getElementById('formBrowseDefaultSort');

    if (customJs) customJs.value = form.form_custom_js || '';
    if (customPhp) customPhp.value = form.form_custom_php || '';
    if (customCss) customCss.value = form.form_custom_css || '';
    if (browseSql) browseSql.value = form.browse_sql || '';
    if (browseColumns) browseColumns.value = form.browse_columns || '';
    if (browseSearchEnabled) browseSearchEnabled.checked = parseInt(form.browse_search_enabled || 0, 10) === 1;
    if (browseSearchPlaceholder) browseSearchPlaceholder.value = form.browse_search_placeholder || '';
    if (browseSearchFields) browseSearchFields.value = form.browse_search_fields || '';
    if (browsePageSize) browsePageSize.value = form.browse_page_size || 20;
    if (browseDefaultSort) browseDefaultSort.value = form.browse_default_sort || '';

    if (canvas) {
      canvas.innerHTML = '';
      try {
        const layout = JSON.parse(form.form_layout || '[]');
        if (Array.isArray(layout) && layout.length) {
          layout.forEach(function (f) {
            window.addFieldToCanvas(
              f.type || 'text',
              f.label || '',
              f.name || '',
              !!f.required,
              f
            );
          });
        } else {
          canvas.innerHTML = '<p style="color:#777;text-align:center;padding:40px;">Drag fields here...</p>';
        }
      } catch (e) {
        console.error('Could not parse layout', e);
        canvas.innerHTML = '<p style="color:#777;text-align:center;padding:40px;">Could not parse layout</p>';
      }
    }

    if (card) {
      card.style.display = 'block';
      card.scrollIntoView({ behavior: 'smooth' });
    }

    if (typeof window.initFormBuilder === 'function') {
      window.initFormBuilder();
    }
  } catch (e) {
    console.error('editForm error', e);
    NuApp.toast('Error: ' + e.message, 'error');
  }
};

window.initFormBuilder = function () {
  const canvas = document.getElementById('formCanvas');
  if (!canvas) return;

  document.querySelectorAll('.nu-builder-tool').forEach(function (tool) {
    const newTool = tool.cloneNode(true);
    tool.parentNode.replaceChild(newTool, tool);

    newTool.setAttribute('draggable', 'true');

    newTool.addEventListener('dragstart', function (e) {
      window.draggedTool = newTool.dataset.type;
      e.dataTransfer.effectAllowed = 'copy';
    });

    newTool.addEventListener('click', function () {
      if (canvas.querySelector('p')) canvas.innerHTML = '';
      window.addFieldToCanvas(newTool.dataset.type);
    });
  });

  canvas.addEventListener('dragover', function (e) {
    e.preventDefault();
  });

  canvas.addEventListener('drop', function (e) {
    e.preventDefault();
    if (window.draggedTool) {
      if (canvas.querySelector('p')) canvas.innerHTML = '';
      window.addFieldToCanvas(window.draggedTool);
      window.draggedTool = null;
    }
  });
};

window.addFieldToCanvas = function (type, label, name, required, extraData) {
  const canvas = document.getElementById('formCanvas');
  if (!canvas) return;

  if (!label) label = type.charAt(0).toUpperCase() + type.slice(1) + ' Field';
  if (!name) name = type + '_field_' + Date.now();

  const field = document.createElement('div');
  field.className = 'nu-builder-field';
  field.dataset.type = type;
  field.style.cssText = 'border:1px solid #ddd;padding:12px;border-radius:8px;margin-bottom:8px;background:#fff;';

  const extra = extraData || {};
  field.dataset.width = extra.width || '100%';
  field.dataset.default = extra.default_value || '';
  field.dataset.placeholder = extra.placeholder || '';
  field.dataset.help = extra.help_text || '';
  field.dataset.cssClass = extra.css_class || '';
  field.dataset.sortOrder = extra.sort_order || '';
  field.dataset.rows = extra.rows || '3';
  field.dataset.min = extra.min || '';
  field.dataset.max = extra.max || '';
  field.dataset.step = extra.step || '';
  field.dataset.accept = extra.accept || '';
  field.dataset.multipleUpload = String(extra.multiple_upload || 0);
  field.dataset.legend = extra.legend || '';
  field.dataset.select2 = String(extra.select2 || 0);
  field.dataset.tab = extra.tab || '';
  field.dataset.section = extra.section || '';
  field.dataset.visibilityRule = extra.visibility_rule || '';
  field.dataset.readonlyRule = extra.readonly_rule || '';
  field.dataset.css = extra.css || '';
  field.dataset.onchange = extra.js_onchange || '';
  field.dataset.htmlContent = extra.html_content || '';
  field.dataset.buttonAction = extra.button_action || '';

  const wrapper = document.createElement('div');
  wrapper.style.cssText = 'display:flex;align-items:flex-start;gap:8px;';

  const dragHandle = document.createElement('div');
  dragHandle.style.cssText = 'cursor:move;padding-top:6px;color:#666;';
  dragHandle.textContent = '⋮⋮';
  wrapper.appendChild(dragHandle);

  const inputsDiv = document.createElement('div');
  inputsDiv.style.cssText = 'flex:1;';

  const labelInput = document.createElement('input');
  labelInput.type = 'text';
  labelInput.className = 'nu-input nu-builder-label';
  labelInput.value = label;
  labelInput.placeholder = 'Field Label';
  labelInput.style.cssText = 'width:100%;margin-bottom:6px;';
  inputsDiv.appendChild(labelInput);

  const nameInput = document.createElement('input');
  nameInput.type = 'text';
  nameInput.className = 'nu-input nu-builder-name';
  nameInput.value = name;
  nameInput.placeholder = 'Field Name';
  nameInput.style.cssText = 'width:100%;margin-bottom:6px;';
  inputsDiv.appendChild(nameInput);

  const reqLabel = document.createElement('label');
  reqLabel.style.cssText = 'font-size:12px;display:flex;align-items:center;gap:6px;';
  const reqBox = document.createElement('input');
  reqBox.type = 'checkbox';
  reqBox.className = 'nu-field-required';
  if (required) reqBox.checked = true;
  reqLabel.appendChild(reqBox);
  reqLabel.appendChild(document.createTextNode('Required'));
  inputsDiv.appendChild(reqLabel);

  if (type === 'select' || type === 'radio' || type === 'checkbox_group') {
    const srcLabel = document.createElement('label');
    srcLabel.style.cssText = 'font-size:12px;margin-top:6px;display:block;';
    srcLabel.textContent = 'Source';
    inputsDiv.appendChild(srcLabel);

    const srcSelect = document.createElement('select');
    srcSelect.className = 'nu-input nu-select-source-type';
    srcSelect.style.cssText = 'width:100%;margin-bottom:6px;font-size:12px;';
    srcSelect.innerHTML = '<option value="static">Static Options</option><option value="sql">SQL Table</option>';
    srcSelect.value = extra.source_type || extra.sourcetype || 'static';
    inputsDiv.appendChild(srcSelect);

    const txt = document.createElement('textarea');
    txt.className = 'nu-input nu-select-static';
    txt.style.cssText = 'width:100%;margin-top:4px;font-size:12px;';
    txt.rows = 3;
    txt.placeholder = 'value|label per line';

    if (extra.options && Array.isArray(extra.options)) {
      txt.value = extra.options.map(function (o) {
        return (o.value || '') + '|' + (o.label || o.value || '');
      }).join('\n');
    }
    inputsDiv.appendChild(txt);

    const sqlInput = document.createElement('input');
    sqlInput.type = 'text';
    sqlInput.className = 'nu-input nu-select-sql';
    sqlInput.style.cssText = 'width:100%;margin-top:4px;font-size:12px;display:none;';
    sqlInput.placeholder = 'SELECT id, name FROM customers WHERE active=1';
    sqlInput.value = extra.sql_source || extra.sqlsource || '';
    inputsDiv.appendChild(sqlInput);

    const multiLabel = document.createElement('label');
    multiLabel.style.cssText = 'font-size:12px;display:flex;align-items:center;gap:6px;margin-top:6px;';
    const multiBox = document.createElement('input');
    multiBox.type = 'checkbox';
    multiBox.className = 'nu-field-multiple';
    multiBox.checked = !!extra.multiple;
    multiLabel.appendChild(multiBox);
    multiLabel.appendChild(document.createTextNode('Multi-select'));
    inputsDiv.appendChild(multiLabel);

    const select2Label = document.createElement('label');
    select2Label.style.cssText = 'font-size:12px;display:flex;align-items:center;gap:6px;margin-top:6px;';
    const select2Box = document.createElement('input');
    select2Box.type = 'checkbox';
    select2Box.className = 'nu-field-select2';
    select2Box.checked = parseInt(extra.select2 || 0, 10) === 1;
    select2Label.appendChild(select2Box);
    select2Label.appendChild(document.createTextNode('Select2'));
    inputsDiv.appendChild(select2Label);

    const toggleSelectSource = function () {
      txt.style.display = srcSelect.value === 'static' ? 'block' : 'none';
      sqlInput.style.display = srcSelect.value === 'sql' ? 'block' : 'none';
    };

    srcSelect.addEventListener('change', toggleSelectSource);
    toggleSelectSource();
  }

  if (type === 'lookup') {
    const txt = document.createElement('input');
    txt.type = 'text';
    txt.className = 'nu-input nu-lookup-source';
    txt.style.cssText = 'width:100%;margin-top:6px;font-size:12px;';
    // Updated placeholder to show both accepted formats
    txt.placeholder = 'table.column  or  table:column';
    if (extra.lookup) {
      txt.value = (extra.lookup.table || '') + '.' + (extra.lookup.display_column || extra.lookup.displaycolumn || 'name');
    }
    inputsDiv.appendChild(txt);

    const idCol = document.createElement('input');
    idCol.type = 'text';
    idCol.className = 'nu-input nu-lookup-id';
    idCol.style.cssText = 'width:100%;margin-top:4px;font-size:12px;';
    idCol.placeholder = 'ID column (default: id)';
    idCol.value = (extra.lookup && (extra.lookup.id_column || extra.lookup.idcolumn)) || 'id';
    inputsDiv.appendChild(idCol);

    const filterInput = document.createElement('input');
    filterInput.type = 'text';
    filterInput.className = 'nu-input nu-lookup-filter';
    filterInput.style.cssText = 'width:100%;margin-top:4px;font-size:12px;';
    filterInput.placeholder = 'Filter SQL (e.g. active=1)';
    filterInput.value = (extra.lookup && extra.lookup.filter) || '';
    inputsDiv.appendChild(filterInput);

    const extraInput = document.createElement('input');
    extraInput.type = 'text';
    extraInput.className = 'nu-input nu-lookup-extra';
    extraInput.style.cssText = 'width:100%;margin-top:4px;font-size:12px;';
    extraInput.placeholder = 'Extra mapping: lookupcol:formfield';
    extraInput.value = (extra.lookup && extra.lookup.extra) || '';
    inputsDiv.appendChild(extraInput);
  }

  if (type === 'subform') {
    const txt = document.createElement('input');
    txt.type = 'text';
    txt.className = 'nu-input nu-subform-config';
    txt.style.cssText = 'width:100%;margin-top:6px;font-size:12px;';
    txt.placeholder = 'form_code.fk_field';
    if (extra.subform) {
      txt.value = (extra.subform.form_code || '') + '.' + (extra.subform.fk_field || '');
    }
    inputsDiv.appendChild(txt);

    const view = document.createElement('select');
    view.className = 'nu-input nu-subform-view';
    view.style.cssText = 'width:100%;margin-top:4px;font-size:12px;';
    view.innerHTML = '<option value="grid">Grid</option><option value="form">Form</option>';
    view.value = (extra.subform && extra.subform.view) || 'grid';
    inputsDiv.appendChild(view);
  }

  if (type === 'calculated') {
    const txt = document.createElement('input');
    txt.type = 'text';
    txt.className = 'nu-input nu-calc-expression';
    txt.style.cssText = 'width:100%;margin-top:6px;font-size:12px;';
    txt.placeholder = 'Calculated expression';
    txt.value = extra.calculated || '';
    inputsDiv.appendChild(txt);
  }

  const delBtn = document.createElement('button');
  delBtn.type = 'button';
  delBtn.className = 'nu-btn nu-btn-danger nu-btn-sm';
  delBtn.textContent = '×';
  delBtn.onclick = function () {
    field.remove();
  };

  wrapper.appendChild(inputsDiv);
  wrapper.appendChild(delBtn);
  field.appendChild(wrapper);
  canvas.appendChild(field);
};

window.nuForm = {
  data: {},
  hashCookies: {},
  fields: {},
  isNew: true,
  formId: null,
  formCode: null,

  async init(formCode, recordData, isNew) {
    this.formCode = formCode;
    this.data = recordData || {};
    this.isNew = isNew !== false;
    this.hashCookies = {};
    await this.loadFields(formCode);
    await this.runEvent('js_onload');
  },

  async loadFields(formCode) {
    try {
      const json = await NuApp.apiJson('api/form.php?action=fields&code=' + encodeURIComponent(formCode), {
        credentials: 'same-origin'
      });

      if (json.success && Array.isArray(json.data)) {
        this.fields = {};
        json.data.forEach((f) => {
          this.fields[f.fieldname] = f;
        });
      }
    } catch (e) {
      console.error('loadFields error', e);
    }
  },

  getValue(fieldName) {
    const wrapper = document.querySelector('.nu-field-wrapper[data-field="' + fieldName + '"]');
    let el = null;
    if (wrapper) {
      el = wrapper.querySelector('[data-field="' + fieldName + '"]');
    }
    if (!el) {
      el = document.querySelector('[data-field="' + fieldName + '"]');
    }
    if (!el) return null;
    if (el.type === 'checkbox') return el.checked ? 1 : 0;
    return el.value;
  },

  setValue(fieldName, value) {
    const wrapper = document.querySelector('.nu-field-wrapper[data-field="' + fieldName + '"]');
    let el = null;
    if (wrapper) {
      el = wrapper.querySelector('[data-field="' + fieldName + '"]');
    }
    if (!el) {
      el = document.querySelector('[data-field="' + fieldName + '"]');
    }
    if (!el) return;

    if (el.type === 'checkbox') {
      el.checked = !!value;
    } else {
      el.value = value == null ? '' : value;
    }

    el.dispatchEvent(new Event('change', { bubbles: true }));
    this.recalculate(fieldName);
  },

  getText(fieldName) {
    const el = document.querySelector('[data-field="' + fieldName + '"]');
    if (!el) return null;
    if (el.options && el.selectedIndex >= 0) return el.options[el.selectedIndex].text;
    return el.value;
  },

  show(fieldName) {
    const wrapper = document.querySelector('.nu-field-wrapper[data-field="' + fieldName + '"]');
    if (wrapper) wrapper.style.display = '';
  },

  hide(fieldName) {
    const wrapper = document.querySelector('.nu-field-wrapper[data-field="' + fieldName + '"]');
    if (wrapper) wrapper.style.display = 'none';
  },

  enable(fieldName) {
    const el = document.querySelector('[data-field="' + fieldName + '"]');
    if (el) el.disabled = false;
  },

  disable(fieldName) {
    const el = document.querySelector('[data-field="' + fieldName + '"]');
    if (el) el.disabled = true;
  },

  setProperty(name, value) {
    this.hashCookies[name] = value;
  },

  getProperty(name) {
    return this.hashCookies[name];
  },

  recalculate() {
    document.querySelectorAll('[data-calculated="true"]').forEach((el) => {
      const expr = el.dataset.expression || '';
      if (!expr) return;
      try {
        const fn = new Function('nu', 'with(nu){ return ' + expr + '; }');
        const result = fn(this);
        el.value = result == null ? '' : result;
      } catch (e) {
        console.error('Calc error', e);
      }
    });
  },

  async runEvent(eventType) {
    try {
      const json = await NuApp.apiJson(
        'api/form.php?action=events&code=' +
          encodeURIComponent(this.formCode) +
          '&event=' + encodeURIComponent(eventType),
        { credentials: 'same-origin' }
      );

      if (json.success && json.code && eventType.indexOf('js_') === 0) {
        const fn = new Function('nu', json.code);
        fn(this);
      }
    } catch (e) {
      console.error('Event error', eventType, e);
    }
  },

  async runProcedure(procedureCode, params) {
    try {
      return await NuApp.apiJson('api/procedure.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          code: procedureCode,
          params: params || {},
          hashCookies: this.hashCookies
        })
      });
    } catch (e) {
      NuApp.toast('Procedure error: ' + e.message, 'error');
      return { success: false };
    }
  }
};

// ─── Shorthand globals for nuForm ────────────────────────────────────────────
window.nuGetValue    = function (f)    { return window.nuForm.getValue(f); };
window.nuSetValue    = function (f, v) { return window.nuForm.setValue(f, v); };
window.nuGetText     = function (f)    { return window.nuForm.getText(f); };
window.nuShow        = function (f)    { return window.nuForm.show(f); };
window.nuHide        = function (f)    { return window.nuForm.hide(f); };
window.nuEnable      = function (f)    { return window.nuForm.enable(f); };
window.nuDisable     = function (f)    { return window.nuForm.disable(f); };
window.nuSetProperty = function (n, v) { return window.nuForm.setProperty(n, v); };
window.nuGetProperty = function (n)    { return window.nuForm.getProperty(n); };
window.nuRunPHPHidden = function (c, p) { return window.nuForm.runProcedure(c, p); };

// ─── Global shims so inline onclick="" attributes work ───────────────────────
// previewForm/browseForm/addRecord/editRecord live on NuApp but HTML buttons
// call them as bare globals – these shims bridge that gap.
window.previewForm = function (code)              { return NuApp.previewForm(code); };
window.browseForm  = function (code, page, query) { return NuApp.browseForm(code, page, query); };
window.addRecord   = function (code)              { return NuApp.addRecord(code); };
window.editRecord  = function (code, id)          { return NuApp.editRecord(code, id); };

window.openLookupModal = function (fieldName, table, idCol, displayCol, filter, extraData) {
  NuApp.apiJson(
    'api/lookup.php?table=' + encodeURIComponent(table) +
    '&id=' + encodeURIComponent(idCol) +
    '&display=' + encodeURIComponent(displayCol) +
    '&filter=' + encodeURIComponent(filter || '') +
    '&extra=' + encodeURIComponent(extraData || ''),
    { credentials: 'same-origin' }
  )
    .then(function (json) {
      if (!json.success) {
        NuApp.toast(json.error || 'Lookup failed', 'error');
        return;
      }

      const existing = document.querySelector('.nu-lookup-overlay');
      if (existing) existing.remove();

      const overlay = document.createElement('div');
      overlay.className = 'nu-lookup-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

      const box = document.createElement('div');
      box.style.cssText = 'background:#fff;border-radius:12px;padding:24px;max-width:600px;width:90%;max-height:80vh;overflow-y:auto;';

      let rows = '';
      if (json.data && json.data.length) {
        json.data.forEach(function (row) {
          const rowJson = JSON.stringify(row).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
          const displayText = String(row[displayCol] || '');
          rows += '<tr style="cursor:pointer;" data-row="' + rowJson + '" onclick="selectLookup(\'' +
            String(fieldName).replace(/'/g, "\\'") + '\', \'' +
            String(row[idCol]).replace(/'/g, "\\'") + '\', \'' +
            String(displayText).replace(/'/g, "\\'") + '\', this)">' +
            '<td style="padding:10px;border-bottom:1px solid #ddd;">' + displayText + '</td></tr>';
        });
      } else {
        rows = '<tr><td style="padding:20px;text-align:center;color:#666;">No records found</td></tr>';
      }

      box.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">' +
        '<h3 style="margin:0;">Select ' + displayCol + '</h3>' +
        '<button type="button" onclick="this.closest(\'.nu-lookup-overlay\').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;">×</button>' +
        '</div>' +
        '<input type="text" class="nu-input" placeholder="Search..." style="margin-bottom:12px;" onkeyup="filterLookupTable(this.value,this.nextElementSibling)">' +
        '<table style="width:100%;border-collapse:collapse;">' + rows + '</table>';

      overlay.appendChild(box);
      document.body.appendChild(overlay);

      const hidden = document.querySelector('input[type="hidden"][data-field="' + fieldName + '"]');
      if (hidden) hidden.dataset.lookupMapping = extraData || '';
    })
    .catch(function (e) {
      NuApp.toast('Lookup error: ' + e.message, 'error');
    });
};

window.filterLookupTable = function (value, table) {
  if (!table) return;
  const rows = table.querySelectorAll('tr');
  const lower = String(value).toLowerCase();
  rows.forEach(function (row) {
    const text = row.textContent.toLowerCase();
    row.style.display = text.indexOf(lower) === -1 ? 'none' : '';
  });
};

window.selectLookup = function (fieldName, id, display, rowElement) {
  const hidden = document.querySelector('input[type="hidden"][data-field="' + fieldName + '"]');
  const text = hidden ? hidden.nextElementSibling : null;

  if (hidden) hidden.value = id;
  if (text) text.value = display;

  if (rowElement && rowElement.dataset.row && hidden && hidden.dataset.lookupMapping) {
    try {
      const rowData = JSON.parse(rowElement.dataset.row.replace(/&quot;/g, '"').replace(/&#39;/g, "'"));
      const mappingText = hidden.dataset.lookupMapping;
      const pairs = mappingText.split(',');

      pairs.forEach(function (pair) {
        const parts = pair.split(':');
        if (parts.length !== 2) return;

        const sourceCol = parts[0].trim();
        const targetField = parts[1].trim();

        if (rowData[sourceCol] !== undefined) {
          window.nuSetValue(targetField, rowData[sourceCol]);
        }
      });
    } catch (e) {
      console.error('Lookup mapping error', e);
    }
  }

  const overlay = document.querySelector('.nu-lookup-overlay');
  if (overlay) overlay.remove();

  if (hidden) hidden.dispatchEvent(new Event('change', { bubbles: true }));
};

window.clearLookup = function (fieldName) {
  const hidden = document.querySelector('input[type="hidden"][data-field="' + fieldName + '"]');
  const text = hidden ? hidden.nextElementSibling : null;

  if (hidden) hidden.value = '';
  if (text) text.value = '';

  if (hidden && hidden.dataset.lookupMapping) {
    hidden.dataset.lookupMapping.split(',').forEach(function (pair) {
      const parts = pair.split(':');
      if (parts.length !== 2) return;
      window.nuSetValue(parts[1].trim(), '');
    });
  }

  if (hidden) hidden.dispatchEvent(new Event('change', { bubbles: true }));
};
