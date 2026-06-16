/* modules/widgets/widgets.js */
(function () {
  'use strict';

  var API       = 'modules/dashboard/widget_api.php';
  var ROLES_API = 'api/roles.php';
  var chartInstances = {};
  var LS_KEY = 'nuDash_groupCollapsed';
  var WIDGET_DATA = window.NUDASH_WIDGET_DATA || {};

  // ── Font Awesome icon list (solid set) ────────────────────────────────────
  var FA_ICONS = [
    ['fa-house','home'],['fa-user','user'],['fa-users','users'],['fa-gear','settings'],
    ['fa-bell','bell'],['fa-bookmark','bookmark'],['fa-calendar','calendar'],
    ['fa-calendar-days','calendar-days'],['fa-chart-bar','chart-bar'],
    ['fa-chart-line','chart-line'],['fa-chart-pie','chart-pie'],
    ['fa-check','check'],['fa-check-circle','check-circle'],['fa-circle-xmark','x-circle'],
    ['fa-clock','clock'],['fa-code','code'],['fa-comment','comment'],
    ['fa-comments','comments'],['fa-credit-card','card'],['fa-database','database'],
    ['fa-envelope','email'],['fa-file','file'],['fa-file-lines','file-lines'],
    ['fa-flag','flag'],['fa-folder','folder'],['fa-folder-open','folder-open'],
    ['fa-globe','globe'],['fa-graduation-cap','education'],['fa-heart','heart'],
    ['fa-house-chimney','house'],['fa-id-card','id-card'],['fa-image','image'],
    ['fa-inbox','inbox'],['fa-key','key'],['fa-layer-group','layers'],
    ['fa-link','link'],['fa-list','list'],['fa-list-check','checklist'],
    ['fa-lock','lock'],['fa-magnifying-glass','search'],['fa-map','map'],
    ['fa-map-pin','pin'],['fa-message','message'],['fa-money-bill','money'],
    ['fa-paper-plane','send'],['fa-paperclip','attach'],['fa-pen','edit'],
    ['fa-people-group','team'],['fa-phone','phone'],['fa-plus','plus'],
    ['fa-print','print'],['fa-receipt','receipt'],['fa-rotate','refresh'],
    ['fa-shield','shield'],['fa-sliders','sliders'],['fa-star','star'],
    ['fa-table','table'],['fa-tag','tag'],['fa-tags','tags'],
    ['fa-thumbs-up','thumbs-up'],['fa-ticket','ticket'],['fa-toolbox','toolbox'],
    ['fa-trash','trash'],['fa-triangle-exclamation','warning'],['fa-truck','truck'],
    ['fa-upload','upload'],['fa-user-check','user-check'],['fa-user-gear','user-gear'],
    ['fa-user-group','group'],['fa-user-tie','manager'],['fa-vault','vault'],
    ['fa-wallet','wallet'],['fa-warehouse','warehouse'],['fa-wifi','wifi'],
    ['fa-wrench','wrench'],['fa-xmark','close'],['fa-border-all','grid'],
    ['fa-building','building'],['fa-bullhorn','announce'],['fa-bus','bus'],
    ['fa-car','car'],['fa-cart-shopping','cart'],['fa-circle-info','info'],
    ['fa-clipboard','clipboard'],['fa-clipboard-list','clipboard-list'],
    ['fa-coins','coins'],['fa-compass','compass'],['fa-copy','copy'],
    ['fa-crown','crown'],['fa-desktop','desktop'],['fa-diagram-project','diagram'],
    ['fa-download','download'],['fa-droplet','water'],['fa-earth-americas','earth'],
    ['fa-ellipsis','more'],['fa-eye','view'],['fa-filter','filter'],
    ['fa-fire','fire'],['fa-floppy-disk','save'],['fa-gauge','gauge'],
    ['fa-hand','hand'],['fa-hashtag','hash'],['fa-headset','support'],
    ['fa-hourglass','hourglass'],['fa-laptop','laptop'],['fa-leaf','leaf'],
    ['fa-lightbulb','idea'],['fa-location-dot','location'],['fa-mobile','mobile'],
    ['fa-moon','night'],['fa-network-wired','network'],['fa-newspaper','news'],
    ['fa-palette','palette'],['fa-pencil','pencil'],['fa-percent','percent'],
    ['fa-person','person'],['fa-phone-flip','phone-flip'],['fa-plug','plug'],
    ['fa-puzzle-piece','puzzle'],['fa-qrcode','qr'],['fa-rocket','rocket'],
    ['fa-route','route'],['fa-school','school'],['fa-screwdriver-wrench','tools'],
    ['fa-share','share'],['fa-signal','signal'],['fa-sitemap','sitemap'],
    ['fa-sort','sort'],['fa-spinner','loading'],['fa-square-check','sq-check'],
    ['fa-store','store'],['fa-suitcase','suitcase'],['fa-sun','sun'],
    ['fa-table-columns','columns'],['fa-timeline','timeline'],
    ['fa-toggle-on','toggle'],['fa-trophy','trophy'],['fa-truck-fast','delivery'],
    ['fa-umbrella','umbrella'],['fa-unlock','unlock'],['fa-video','video'],
    ['fa-volume-high','volume'],['fa-wind','wind']
  ];

  var _faFilterVal = '';

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
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">',
      '<div class="nu-field"><label class="nu-label">SQL <small style="color:#888">&rarr; <code>value</code> column</small></label>',
      '<textarea class="nu-input" id="nuWSql" rows="3" placeholder="SELECT COUNT(*) as value FROM my_table"></textarea></div>',
      '<div class="nu-field"><label class="nu-label">Subtitle (optional)</label>',
      '<input class="nu-input" id="nuWSubtitle" placeholder="e.g. Pending tasks"></div></div>',
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">',
      '<div class="nu-field"><label class="nu-label">Accent colour</label>',
      '<select class="nu-input" id="nuWColor">',
      '<option value="primary">Teal</option><option value="success">Green</option>',
      '<option value="warning">Orange</option><option value="error">Red</option>',
      '</select></div></div>',
      '<div style="padding:10px;background:var(--color-surface-offset,#f5f5f5);border-radius:.5rem;border-left:3px solid #01696f;margin-bottom:4px;">',
      '<div class="nu-label" style="margin-bottom:8px;color:#01696f;">&#10148; Drill-down (optional)</div>',
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">',
      '<div class="nu-field"><label class="nu-label">Module name</label>',
      '<input class="nu-input" id="nuWLinkModule" placeholder="e.g. pending_tasks"></div>',
      '<div class="nu-field"><label class="nu-label">&mdash; or &mdash; External URL</label>',
      '<input class="nu-input" id="nuWLinkUrl" placeholder="https://..."></div>',
      '</div>',
      '<small style="color:#888;font-size:11px;">When filled, a &rsaquo; arrow button appears on the stat card.</small>',
      '</div>'
    ].join(''),
    chart_bar:  '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT status AS label, COUNT(*) AS value FROM my_table GROUP BY status"></textarea></div>',
    chart_line: '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT DATE(created_at) AS label, COUNT(*) AS value FROM my_table GROUP BY DATE(created_at) ORDER BY label"></textarea></div>',
    chart_pie:  '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT category AS label, COUNT(*) AS value FROM my_table GROUP BY category"></textarea></div>',
    table:      '<div class="nu-field"><label class="nu-label">SQL <small style="color:#888">use <code>{{user_id}}</code> for current user</small></label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT title AS Task, status AS Status FROM my_tasks LIMIT 10"></textarea></div>',
    list:       '<div class="nu-field"><label class="nu-label">Links (one per line: <code>Label|module_name</code> or <code>Label|https://url</code>)</label><textarea class="nu-input" id="nuWLinks" rows="5" placeholder="Open Forms|forms\nMy Reports|reports"></textarea></div>',
    progress:   '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">SQL (columns: <code>done</code>, <code>total</code>)</label><textarea class="nu-input" id="nuWSql" rows="3"></textarea></div><div class="nu-field"><label class="nu-label">Label</label><input class="nu-input" id="nuWSubtitle" placeholder="Tasks completed"></div>',
    custom:     '<div class="nu-field"><label class="nu-label">HTML Content</label><textarea class="nu-input" id="nuWHtml" rows="6" placeholder="<p>Any HTML here...</p>"></textarea></div>'
  };

  var TYPE_ACCENTS = {
    stat:'#01696f', chart_bar:'#006494', chart_line:'#7a39bb',
    chart_pie:'#da7101', table:'#437a22', list:'#006494',
    progress:'#964219', custom:'#a12c7b'
  };

  window.nuDash = {
    editMode:  false,
    editingId: null,

    // ── FA Picker ────────────────────────────────────────────────────────
    openFaPicker: function () {
      var modal = document.getElementById('nuFaPickerModal');
      if (!modal) return;
      _faFilterVal = '';
      var search = document.getElementById('nuFaPickerSearch');
      if (search) search.value = '';
      this._renderFaGrid('');
      modal.style.display = 'flex';
      if (search) setTimeout(function () { search.focus(); }, 80);
    },

    closeFaPicker: function () {
      var modal = document.getElementById('nuFaPickerModal');
      if (modal) modal.style.display = 'none';
    },

    filterFaPicker: function (val) {
      _faFilterVal = val.toLowerCase();
      this._renderFaGrid(_faFilterVal);
    },

    _renderFaGrid: function (filter) {
      var grid = document.getElementById('nuFaPickerGrid');
      if (!grid) return;
      var current = (document.getElementById('nuWIcon') || {}).value || '';
      var html = '';
      FA_ICONS.forEach(function (item) {
        var cls   = item[0];
        var label = item[1];
        if (filter && cls.indexOf(filter) === -1 && label.indexOf(filter) === -1) return;
        var sel = (current === cls) ? ' nu-selected' : '';
        html += '<button class="nu-fa-picker-btn' + sel + '" data-fa="' + cls + '" title="' + cls + '" onclick="nuDash.selectFaIcon(\'' + cls + '\')">' +
                '<i class="fas ' + cls + '"></i><span>' + label + '</span></button>';
      });
      if (!html) html = '<div style="grid-column:1/-1;text-align:center;color:#888;padding:24px;">No icons match.</div>';
      grid.innerHTML = html;
    },

    selectFaIcon: function (cls) {
      var iconEl = document.getElementById('nuWIcon');
      if (iconEl) iconEl.value = cls;
      this.closeFaPicker();
      this.updateIconPreview();
    },

    clearIcon: function () {
      var iconEl = document.getElementById('nuWIcon');
      if (iconEl) iconEl.value = '';
      this.updateIconPreview();
    },

    updateIconPreview: function () {
      var wrap  = document.getElementById('nuWIconPreview');
      var badge = document.getElementById('nuWIconPreviewBadge');
      if (!wrap || !badge) return;
      var iconVal = (document.getElementById('nuWIcon') || {}).value || '';
      var title   = (document.getElementById('nuWTitle') || {}).value || 'Widget Title';
      var type    = (document.getElementById('nuWType') || {}).value || 'stat';
      var accent  = TYPE_ACCENTS[type] || '#01696f';
      badge.style.background = accent;
      var iconHtml = '';
      if (iconVal) {
        var isFa = iconVal.indexOf('fa-') !== -1;
        iconHtml = isFa
          ? '<i class="fas ' + iconVal + '" style="font-size:.9rem;"></i>'
          : '<span style="font-size:1rem;">' + iconVal + '</span>';
      }
      badge.innerHTML = (iconHtml ? iconHtml + ' ' : '') + title;
      wrap.style.display = (iconVal || title) ? 'block' : 'none';
    },

    // ── Group toggle ─────────────────────────────────────────────────────
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
        body.offsetHeight; // force reflow
        body.classList.add('nu-group-collapsed');
        if (chevron) chevron.classList.add('nu-group-collapsed');
        setGroupCollapsed(roleCode, true);
      }
    },

    // ── Builder open/close ───────────────────────────────────────────────
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
        if (iconEl) iconEl.value = w.widget_icon || '';
        this.onTypeChange();
        var sqlEl = document.getElementById('nuWSql');
        var subEl = document.getElementById('nuWSubtitle');
        var colEl = document.getElementById('nuWColor');
        var lnkEl = document.getElementById('nuWLinks');
        var htmEl = document.getElementById('nuWHtml');
        var lmEl  = document.getElementById('nuWLinkModule');
        var luEl  = document.getElementById('nuWLinkUrl');
        if (sqlEl) sqlEl.value = cfg.sql          || '';
        if (subEl) subEl.value = cfg.subtitle     || cfg.label || '';
        if (colEl) colEl.value = cfg.color        || 'primary';
        if (htmEl) htmEl.value = cfg.html         || '';
        if (lmEl)  lmEl.value  = cfg.link_module  || '';
        if (luEl)  luEl.value  = cfg.link_url     || '';
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
      this.updateIconPreview();
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
      this.updateIconPreview();
    },

    // ── Config building ──────────────────────────────────────────────────
    buildConfig: function () {
      var type  = document.getElementById('nuWType').value;
      var sqlEl = document.getElementById('nuWSql');
      var sql   = sqlEl ? sqlEl.value.trim() : '';
      switch (type) {
        case 'stat': {
          var cfg = {
            sql:      sql,
            subtitle: (document.getElementById('nuWSubtitle')||{}).value||'',
            color:    (document.getElementById('nuWColor')   ||{}).value||'primary'
          };
          var lm = ((document.getElementById('nuWLinkModule')||{}).value||'').trim();
          var lu = ((document.getElementById('nuWLinkUrl')   ||{}).value||'').trim();
          if (lm) cfg.link_module = lm;
          if (lu) cfg.link_url    = lu;
          return cfg;
        }
        case 'chart_bar': case 'chart_line': case 'chart_pie': case 'table':
          return { sql: sql };
        case 'progress':
          return { sql: sql, label: (document.getElementById('nuWSubtitle')||{}).value||'' };
        case 'list': {
          var lines = ((document.getElementById('nuWLinks')||{}).value||'').split('\n').filter(Boolean);
          return { items: lines.map(function (l) {
            var p = l.split('|'); var t = (p[1]||'').trim();
            return t.indexOf('http') === 0
              ? { label:(p[0]||'').trim(), url:t }
              : { label:(p[0]||'').trim(), module:t };
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
      fetch(API + '?action=run_sql', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({sql: cfg.sql})
      })
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
      var icon       = ((document.getElementById('nuWIcon')||{}).value||'').trim();
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
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok) { self.closeBuilder(); location.reload(); }
          else alert('Error: ' + (d.error||'Unknown'));
        })
        .catch(function (e) { alert('Request failed: ' + e.message); });
    },

    removeWidget: function (id) {
      if (!confirm('Remove this widget?')) return;
      fetch(API + '?action=remove', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id: id})
      })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) location.reload(); else alert('Error: '+(d.error||'')); })
        .catch(function () { alert('Request failed'); });
    },

    editWidget: function (id) { this.openBuilder(id); },

    drillDown: function (moduleName) {
      if (window.NuApp && NuApp.loadModule) { NuApp.loadModule(moduleName); }
      else if (window.loadModule) { loadModule(moduleName); }
    },

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
      fetch(API + '?action=reorder', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({order: order})
      });
    },

    resetLayout: function () {
      if (!confirm('Reset to role default? Personal widgets will be removed.')) return;
      fetch(API + '?action=reset', { method: 'POST' })
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
