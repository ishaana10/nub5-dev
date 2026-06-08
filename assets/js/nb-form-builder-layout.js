/**
 * nb-form-builder-layout.js
 * Patches the nbFormBuilder canvas to support:
 *  1. Multi-field rows — drop a field into an *existing* row body
 *  2. nuToggleContainer registered on window (preview collapse fix)
 *  3. Correct col-span applied inline on field cards inside rows
 *  4. _nbSfAugmentLayout hook — called just before getLayout() returns
 *     so subform panel values (child form, FK field, flags) are always
 *     baked into the serialised JSON without any intercept layer.
 *     Registered by nb-subform-fk-builder.js as window._nbSfAugmentLayout.
 *
 * Load order: nubuilder-next.js → nb-sf-data.js → nb-subform-fk-builder.js
 *             → nb-form-builder-layout.js
 */
(function () {
  'use strict';

  // ── 1. Register nuToggleContainer on window ─────────────────────────────
  if (!window.nuToggleContainer) {
    window.nuToggleContainer = function (btn) {
      if (!btn) return;
      var tid  = btn.getAttribute('data-target');
      if (!tid) return;
      var body = document.getElementById(tid);
      if (!body) return;
      var hidden = body.style.display === 'none' || body.style.display === '';
      body.style.display = hidden ? 'block' : 'none';
      btn.innerHTML      = hidden ? '&#9660;' : '&#9654;';
    };
  }

  // ── 2. Wait for nbFormBuilder, then patch ────────────────────────────
  function patchBuilder() {
    var fb = window.nbFormBuilder;
    if (!fb) return;

    // ── 2a. _applyColSpan ──────────────────────────────────────────────
    fb._applyColSpan = function (card, col) {
      var c = parseInt(col, 10) || 12;
      if (c < 1 || c > 12) c = 12;
      card.style.gridColumn = 'span ' + c;
      card.dataset.col = String(c);
      var badge = card.querySelector('.nb-cfield-span-badge');
      if (badge) badge.textContent = c + '/12';
      card.querySelectorAll('.nb-span-btn').forEach(function (btn) {
        btn.classList.toggle('active', parseInt(btn.dataset.span, 10) === c);
      });
    };

    // ── 2b. Patch _addField to wire span buttons ─────────────────────────
    var _origAddField = fb._addField;
    fb._addField = function (type, label, name, required, extraData) {
      var card = _origAddField.call(this, type, label, name, required, extraData);
      if (!card) return card;
      card.querySelectorAll('.nb-span-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          fb._applyColSpan(card, parseInt(btn.dataset.span, 10) || 12);
        });
      });
      return card;
    };

    // ── 2c. Row-body drop ───────────────────────────────────────────────
    function attachRowBodyDrop(rowBody) {
      if (rowBody._nuDropPatched) return;
      rowBody._nuDropPatched = true;

      rowBody.addEventListener('dragover', function (e) {
        e.preventDefault(); e.stopPropagation();
        rowBody.classList.add('drag-col-over');
      });
      rowBody.addEventListener('dragleave', function (e) {
        if (!rowBody.contains(e.relatedTarget)) rowBody.classList.remove('drag-col-over');
      });
      rowBody.addEventListener('drop', function (e) {
        e.preventDefault(); e.stopPropagation();
        rowBody.classList.remove('drag-col-over');

        var cardId = e.dataTransfer.getData('text/nb-card-id');
        if (cardId) {
          var existingCard = document.getElementById(cardId);
          if (existingCard && existingCard !== rowBody.parentElement) {
            var oldRow = existingCard.closest('.nb-row');
            existingCard.parentNode.removeChild(existingCard);
            rowBody.appendChild(existingCard);
            fb._applyColSpan(existingCard, existingCard.dataset.col || 12);
            if (oldRow && !oldRow.querySelector('.nb-cfield')) oldRow.parentNode && oldRow.parentNode.removeChild(oldRow);
            fb._updateEmptyState();
            return;
          }
        }

        var dtype = e.dataTransfer.getData('text/nb-type') || e.dataTransfer.getData('text/plain');
        if (dtype) {
          var card = fb._addFieldToRow(dtype, rowBody);
          if (card) fb._applyColSpan(card, 6);
          fb._updateEmptyState();
        }
      });
    }

    // ── 2d. _addFieldToRow ─────────────────────────────────────────────
    fb._addFieldToRow = function (type, rowBody) {
      var label = type.charAt(0).toUpperCase() + type.slice(1) + ' Field';
      var name  = type + '_' + Date.now();
      var card  = fb._buildFieldCard(type, label, name, false, {});
      if (!card) return null;
      if (!card.id) card.id = 'nb-card-' + Date.now();
      card.setAttribute('draggable', 'true');
      card.addEventListener('dragstart', function (ev) {
        ev.dataTransfer.setData('text/nb-card-id', card.id);
        card.classList.add('drag-source');
      });
      card.addEventListener('dragend', function () { card.classList.remove('drag-source'); });
      rowBody.appendChild(card);
      fb._applyColSpan(card, 6);
      return card;
    };

    // ── 2e. _buildFieldCard ─────────────────────────────────────────────
    if (!fb._buildFieldCard) {
      fb._buildFieldCard = function (type, label, name, required, extra) {
        var tmpHolder = document.createElement('div');
        var card = fb._makeFieldCard ? fb._makeFieldCard(type, label, name, required, extra) : null;
        if (!card) {
          var prevCanvas = fb._canvas;
          fb._canvas = tmpHolder;
          card = _origAddField.call(fb, type, label, name, required, extra);
          fb._canvas = prevCanvas;
          if (tmpHolder.firstElementChild && tmpHolder.firstElementChild !== card)
            card = tmpHolder.firstElementChild;
        }
        return card;
      };
    }

    // ── 2f. Patch addRow to attach drop listeners ──────────────────────
    var _origAddRow = fb.addRow;
    fb.addRow = function () {
      var row = _origAddRow ? _origAddRow.call(this) : null;
      if (row) {
        var body = row.querySelector('.nb-row-body');
        if (body) attachRowBodyDrop(body);
      }
      return row;
    };

    // ── 2g. Patch _rebuildCanvas ─────────────────────────────────────────
    var _origRebuild = fb._rebuildCanvas;
    fb._rebuildCanvas = function (layout) {
      var result = _origRebuild ? _origRebuild.call(this, layout) : undefined;
      var canvas = document.getElementById('formCanvas');
      if (canvas) {
        canvas.querySelectorAll('.nb-row-body').forEach(attachRowBodyDrop);
        canvas.querySelectorAll('.nb-cfield').forEach(function (card) {
          card.querySelectorAll('.nb-span-btn').forEach(function (btn) {
            var freshBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(freshBtn, btn);
            freshBtn.addEventListener('click', function (e) {
              e.stopPropagation();
              fb._applyColSpan(card, parseInt(freshBtn.dataset.span, 10) || 12);
            });
          });
        });
      }
      return result;
    };

    // ── 2h. Toolbox drag data-transfer types ──────────────────────────
    document.addEventListener('dragstart', function (e) {
      var tool = e.target.closest('.nb-tool[data-type]');
      if (tool) e.dataTransfer.setData('text/nb-type', tool.dataset.type);
      var card = e.target.closest('.nb-cfield[id]');
      if (card) {
        if (!card.id) card.id = 'nb-card-' + Date.now();
        e.dataTransfer.setData('text/nb-card-id', card.id);
      }
    }, true);

    // ── 2i. Patch getLayout() to call _nbSfAugmentLayout hook ───────────
    // nb-subform-fk-builder.js registers window._nbSfAugmentLayout.
    // We wrap getLayout (and common aliases) so subform panel values
    // are always baked into the JSON at the point of serialisation—
    // no saveForm/fetch intercept needed.
    var _layoutMethods = ['getLayout', 'collectLayout', 'serializeLayout', 'buildLayout', 'exportLayout'];
    _layoutMethods.forEach(function (methodName) {
      if (typeof fb[methodName] !== 'function') return;
      var _orig = fb[methodName].bind(fb);
      fb[methodName] = function () {
        var layout = _orig.apply(fb, arguments);
        if (typeof window._nbSfAugmentLayout === 'function') {
          layout = window._nbSfAugmentLayout(layout);
        }
        return layout;
      };
    });

  } // end patchBuilder

  // ── 3. Init ───────────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', patchBuilder);
  } else {
    patchBuilder();
  }
  document.addEventListener('nu:form:opened', patchBuilder);
  window._nuPatchBuilderLayout = patchBuilder;

})();
