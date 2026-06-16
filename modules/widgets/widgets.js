/* modules/widgets/widgets.js */
(function () {
  'use strict';

  var API       = 'modules/dashboard/widget_api.php';
  var ROLES_API = 'api/roles.php';
  var chartInstances = {};
  var LS_KEY = 'nuDash_groupCollapsed';
  var WIDGET_DATA = window.NUDASH_WIDGET_DATA || {};

  function initCharts() {
    document.querySelectorAll('[data-chartjs]').forEach(function (canvas) {
      var id = canvas.id;
      if (chartInstances[id]) chartInstances[id].destroy();
      try { chartInstances[id] = new Chart(canvas, JSON.parse(canvas.dataset.chartjs)); }
      catch (e) { console.warn('[nuDash chart]', e); }
    });
  }

  function getCollapsedSet() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]'); } catch (e) { return []; }
  }
  function saveCollapsedSet(arr) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(arr)); } catch (e) {}
  }
  function setGroupCollapsed(roleCode, collapsed) {
    var arr = getCollapsedSet();
    var idx = arr.indexOf(roleCode);
    if (collapsed && idx === -1) arr.push(roleCode);
    if (!collapsed && idx !== -1) arr.splice(idx, 1);
    saveCollapsedSet(arr);
  }

  function restoreGroupStates() {
    getCollapsedSet().forEach(function (roleCode) {
      var bodyId  = 'nuRoleGroup_' + roleCode.replace(/[^a-z0-9_]/gi, '_');
      var body    = document.getElementById(bodyId);
      var chevron = document.getElementById(bodyId + '_chevron');
      if (body) { body.style.maxHeight = '0px'; body.classList.add('nu-group-collapsed'); }
      if (chevron) chevron.classList.add('nu-group-collapsed');
    });
    document.querySelectorAll('.nu-role-group-body:not(.nu-group-collapsed)').forEach(function (body) {
      body.style.maxHeight = body.scrollHeight + 'px';
    });
  }

  function loadRolesIntoDropdown(selectedValue) {
    var sel = document.getElementById('nuWTargetRole');
    if (!sel) return;
    fetch(ROLES_API + '?action=list')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success || !Array.isArray(d.roles)) return;
        while (sel.options.length > 1) sel.remove(1);
        d.roles.forEach(function (r) {
          var opt = document.createElement('option');
          opt.value = r.role_code;
          opt.textContent = r.role_name + ' (' + r.role_code + ')';
          sel.appendChild(opt);
        });
        if (selectedValue) sel.value = selectedValue;
      })
      .catch(function (e) { console.warn('[nuDash] roles fetch error', e); });
  }

  var TYPE_CONFIGS = {
    stat: [
      '<div class="nu-field" style="margin-bottom:12px;">',
      '<label class="nu-label">SQL <small style="color:#888">must return a <code>value</code> column</small></label>',
      '<textarea class="nu-input" id="nuWSql" rows="3" placeholder="SELECT COUNT(*) as value FROM my_table"></textarea></div>',
      '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">Subtitle (optional)</label>',
      '<input class="nu-input" id="nuWSubtitle" placeholder="Pending tasks"></div>',
      '<div class="nu-field"><label class="nu-label">Accent colour</label>',
      '<select class="nu-input" id="nuWColor">',
      '<option value="primary">Teal</option><option value="success">Green</option>',
      '<option value="warning">Orange</option><option value="error">Red</option>',
      '</select></div>'
    ].join(''),
    chart_bar:  '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT status AS label, COUNT(*) AS value FROM my_table GROUP BY status"></textarea></div>',
    chart_line: '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT DATE(created_at) AS label, COUNT(*) AS value FROM my_table GROUP BY DATE(created_at) ORDER BY label"></textarea></div>',
    chart_pie:  '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT category AS label, COUNT(*) AS value FROM my_table GROUP BY category"></textarea></div>',
    table:      '<div class="nu-field"><label class="nu-label">SQL <small style="color:#888">use <code>{{user_id}}</code> to filter by current user</small></label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT title AS Task, status AS Status FROM my_tasks LIMIT 10"></textarea></div>',
    list:       '<div class="nu-field"><label class="nu-label">Links (one per line: <code>Label|module_name</code> or <code>Label|https://url</code>)</label><textarea class="nu-input" id="nuWLinks" rows="5" placeholder="Open Forms|forms\nMy Reports|reports"></textarea></div>',
    progress:   '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">SQL (columns: <code>done</code>, <code>total</code>)</label><textarea class="nu-input" id="nuWSql" rows="3"></textarea></div><div class="nu-field"><label class="nu-label">Label</label><input class="nu-input" id="nuWSubtitle" placeholder="Tasks completed"></div>',
    custom:     '<div class="nu-field"><label class="nu-label">HTML Content</label><textarea class="nu-input" id="nuWHtml" rows="6" placeholder="<p>Any HTML here...</p>"></textarea></div>'
  };

  // Accent hex per type (mirrors PHP wu_type_accent)
  var TYPE_ACCENTS = {
    stat:'#01696f', chart_bar:'#006494', chart_line:'#7a39bb',
    chart_pie:'#da7101', table:'#437a22', list:'#006494',
    progress:'#964219', custom:'#a12c7b'
  };

  window.nuDash = {
    editMode:  false,
    editingId: null,

    toggleGroup: function (bodyId, roleCode) {
      var body    = document.getElementById(bodyId);
      var chevron = document.getElementById(bodyId + '_chevron');
      if (!body) return;
      var isCollapsed = body.classList.contains('nu-group-collapsed');
      if (isCollapsed) {
        body.style.maxHeight = body.scrollHeight + 'px';
        body.classList.remove('nu-group-collapsed');
        if (chevron) chevron.classList.remove('nu-group-collapsed');
        setGroupCollapsed(roleCode, false);
        body.addEventListener('transitionend', function handler() {
          if (!body.classList.contains('nu-group-collapsed')) body.style.maxHeight = 'none';
          body.removeEventListener('transitionend', handler);
        });
      } else {
        body.style.maxHeight = body.scrollHeight + 'px';
        body.offsetHeight;
        body.classList.add('nu-group-collapsed');
        if (chevron) chevron.classList.add('nu-group-collapsed');
        setGroupCollapsed(roleCode, true);
      }
    },

    previewIcon: function (val) {
      var wrap   = document.getElementById('nuWIconPreview');
      var badge  = document.getElementById('nuWIconPreviewBadge');
      var title  = (document.getElementById('nuWTitle') || {}).value || 'Widget Title';
      var type   = (document.getElementById('nuWType') || {}).value || 'stat';
      var accent = TYPE_ACCENTS[type] || '#01696f';
      if (!wrap || !badge) return;
      if (val.trim()) {
        wrap.style.display = 'block';
        badge.style.background = accent;
        badge.innerHTML = '<span style="font-size:1rem;line-height:1;">' + val + '</span>' + title;
      } else {
        wrap.style.display = 'none';
      }
    },

    openBuilder: function (id) {
      this.editingId = id || null;
      var sid = id ? String(id) : null;
      document.getElementById('nuWid').value = id || '';
      document.getElementById('nuWPreviewWrap').style.display = 'none';
      var iconPreview = document.getElementById('nuWIconPreview');
      if (iconPreview) iconPreview.style.display = 'none';

      if (sid && WIDGET_DATA[sid]) {
        var w = WIDGET_DATA[sid];
        var cfg = {};
        try { cfg = JSON.parse(w.widget_config || '{}'); } catch (e) {}
        document.getElementById('nuWType').value   = w.widget_type   || 'stat';
        document.getElementById('nuWTitle').value  = w.widget_title  || '';
        document.getElementById('nuWWidth').value  = String(w.widget_width  || 2);
        document.getElementById('nuWHeight').value = String(w.widget_height || 1);
        var iconEl = document.getElementById('nuWIcon');
        if (iconEl) { iconEl.value = w.widget_icon || ''; this.previewIcon(iconEl.value); }
        this.onTypeChange();
        var sqlEl = document.getElementById('nuWSql');
        var subEl = document.getElementById('nuWSubtitle');
        var colEl = document.getElementById('nuWColor');
        var lnkEl = document.getElementById('nuWLinks');
        var htmEl = document.getElementById('nuWHtml');
        if (sqlEl) sqlEl.value = cfg.sql      || '';
        if (subEl) subEl.value = cfg.subtitle || cfg.label || '';
        if (colEl) colEl.value = cfg.color    || 'primary';
        if (htmEl) htmEl.value = cfg.html     || '';
        if (lnkEl && cfg.items) {
          lnkEl.value = cfg.items.map(function (i) {
            return i.label + '|' + (i.module || i.url || '');
          }).join('\n');
        }
        loadRolesIntoDropdown(w.widget_role || '');
      } else {
        document.getElementById('nuWType').value   = 'stat';
        document.getElementById('nuWTitle').value  = '';
        document.getElementById('nuWWidth').value  = '2';
        document.getElementById('nuWHeight').value = '1';
        var iconEl2 = document.getElementById('nuWIcon');
        if (iconEl2) iconEl2.value = '';
        this.onTypeChange();
        loadRolesIntoDropdown('');
      }
      document.getElementById('nuBuilderModal').style.display = 'block';
    },

    openBuilderForRole: function (roleCode) {
      this.openBuilder();
      loadRolesIntoDropdown(roleCode);
    },

    closeBuilder: function () {
      document.getElementById('nuBuilderModal').style.display = 'none';
      document.getElementById('nuWPreviewWrap').style.display = 'none';
    },

    onTypeChange: function () {
      var area = document.getElementById('nuWConfigArea');
      if (area) area.innerHTML = TYPE_CONFIGS[document.getElementById('nuWType').value] || '';
      // Re-render icon preview with new accent color
      var iconEl = document.getElementById('nuWIcon');
      if (iconEl && iconEl.value) this.previewIcon(iconEl.value);
    },

    buildConfig: function () {
      var type  = document.getElementById('nuWType').value;
      var sqlEl = document.getElementById('nuWSql');
      var sql   = sqlEl ? sqlEl.value.trim() : '';
      switch (type) {
        case 'stat':
          return { sql: sql, subtitle: (document.getElementById('nuWSubtitle')||{}).value||'', color: (document.getElementById('nuWColor')||{}).value||'primary' };
        case 'chart_bar': case 'chart_line': case 'chart_pie': case 'table':
          return { sql: sql };
        case 'progress':
          return { sql: sql, label: (document.getElementById('nuWSubtitle')||{}).value||'' };
        case 'list': {
          var lines = ((document.getElementById('nuWLinks')||{}).value||'').split('\n').filter(Boolean);
          return { items: lines.map(function (l) {
            var p = l.split('|'); var t = (p[1]||'').trim();
            return t.indexOf('http') === 0 ? { label:(p[0]||'').trim(), url:t } : { label:(p[0]||'').trim(), module:t };
          })};
        }
        case 'custom':
          return { html: (document.getElementById('nuWHtml')||{}).value||'' };
        default: return {};
      }
    },

    validateConfig: function (type, cfg) {
      var sqlTypes = ['stat','chart_bar','chart_line','chart_pie','table','progress'];
      if (sqlTypes.indexOf(type) !== -1 && !cfg.sql)              return 'Please enter a SQL query.';
      if (type === 'list'   && (!cfg.items || !cfg.items.length)) return 'Please add at least one link.';
      if (type === 'custom' && !cfg.html)                         return 'Please enter HTML content.';
      return null;
    },

    runPreview: function () {
      var cfg  = this.buildConfig();
      var wrap = document.getElementById('nuWPreviewWrap');
      var prev = document.getElementById('nuWPreview');
      wrap.style.display = 'block';
      prev.innerHTML = '<span style="color:#888">Loading...</span>';
      if (!cfg.sql) { prev.innerHTML = '<em>No SQL to preview.</em>'; return; }
      fetch(API + '?action=run_sql', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({sql:cfg.sql}) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          prev.innerHTML = d.error
            ? '<span style="color:#a12c7b">' + d.error + '</span>'
            : '<pre style="font-size:12px;white-space:pre-wrap;">' + JSON.stringify((d.rows||[]).slice(0,3),null,2) + '</pre>';
        })
        .catch(function () { prev.innerHTML = '<span style="color:#a12c7b">Request failed</span>'; });
    },

    saveWidget: function () {
      var self       = this;
      var id         = document.getElementById('nuWid').value;
      var type       = document.getElementById('nuWType').value;
      var title      = (document.getElementById('nuWTitle').value||'').trim();
      var icon       = (document.getElementById('nuWIcon')||{}).value || '';
      var width      = parseInt(document.getElementById('nuWWidth').value,  10) || 2;
      var height     = parseInt(document.getElementById('nuWHeight').value, 10) || 1;
      var cfg        = this.buildConfig();
      var rEl        = document.getElementById('nuWTargetRole');
      var targetRole = rEl ? rEl.value : null;
      if (!title) { alert('Please enter a title.'); document.getElementById('nuWTitle').focus(); return; }
      var err = this.validateConfig(type, cfg);
      if (err) { alert(err); return; }
      var payload = { type:type, title:title, icon:icon, width:width, height:height, config:cfg };
      if (targetRole) payload.target_role = targetRole;
      if (id) payload.id = id;
      fetch(API + '?action=' + (id ? 'update' : 'add'), {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
      })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) { self.closeBuilder(); location.reload(); } else alert('Error: ' + (d.error||'Unknown')); })
        .catch(function (e) { alert('Request failed: ' + e.message); });
    },

    removeWidget: function (id) {
      if (!confirm('Remove this widget?')) return;
      fetch(API + '?action=remove', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id:id}) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) location.reload(); else alert('Error: '+(d.error||'')); })
        .catch(function () { alert('Request failed'); });
    },

    editWidget: function (id) { this.openBuilder(id); },

    toggleEditMode: function () {
      this.editMode = !this.editMode;
      var self = this;
      var btn  = document.getElementById('nuDashEditBtn');
      document.querySelectorAll('.nu-widget-card').forEach(function (el) {
        el.style.outline = self.editMode ? '2px dashed #01696f' : '';
        el.draggable     = self.editMode;
      });
      if (btn) btn.textContent = this.editMode ? '\u2705 Done Editing' : '\u270F\uFE0F Edit Layout';
      if (this.editMode) this.initDrag();
    },

    initDrag: function () {
      var self = this;
      var grid = document.getElementById('nuWidgetGrid');
      var dragSrc = null;
      grid.querySelectorAll('.nu-widget-card').forEach(function (card) {
        card.addEventListener('dragstart', function () { dragSrc = card; card.style.opacity = '.4'; });
        card.addEventListener('dragend',   function () { card.style.opacity = ''; });
        card.addEventListener('dragover',  function (e) { e.preventDefault(); });
        card.addEventListener('drop', function (e) {
          e.preventDefault();
          if (dragSrc && dragSrc !== card) {
            var cards = Array.prototype.slice.call(grid.querySelectorAll('.nu-widget-card'));
            if (cards.indexOf(dragSrc) < cards.indexOf(card)) card.after(dragSrc);
            else card.before(dragSrc);
            self.persistOrder();
          }
        });
      });
    },

    persistOrder: function () {
      var order = Array.prototype.slice.call(document.querySelectorAll('.nu-widget-card')).map(function (c, i) {
        return { id: parseInt(c.dataset.widgetId, 10), position: (i+1)*10 };
      });
      fetch(API + '?action=reorder', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({order:order}) });
    },

    resetLayout: function () {
      if (!confirm('Reset to role default? Personal widgets will be removed.')) return;
      fetch(API + '?action=reset', { method:'POST' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) location.reload(); })
        .catch(function () { alert('Request failed'); });
    },

    openRoleDesigner: function () {
      this.openBuilder();
      var rEl = document.getElementById('nuWTargetRole');
      if (rEl) setTimeout(function () { rEl.focus(); }, 150);
    }
  };

  function onReady() { restoreGroupStates(); initCharts(); }
  document.addEventListener('DOMContentLoaded', onReady);
  if (document.readyState !== 'loading') onReady();

}());
