/**
 * email-manager.js
 * Frontend JS for the Email Settings & Templates admin UI.
 * Uses the /api/email.php endpoint.
 */

const EmailManager = {

    // -------------------------------------------------------------------------
    // Send an email programmatically from another module
    // -------------------------------------------------------------------------
    async send(to, subject, body, options = {}) {
        const res = await fetch('/api/email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send', to, subject, body, ...options })
        });
        return res.json();
    },

    // Send using a DB template with variable substitution
    async sendTemplate(to, templateSlug, variables = {}, options = {}) {
        const res = await fetch('/api/email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send', to, template_slug: templateSlug, variables, ...options })
        });
        return res.json();
    },

    // Send a test email
    async sendTest(to) {
        const res = await fetch('/api/email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'test', to })
        });
        const data = await res.json();
        alert(data.success ? '✅ Test email sent successfully!' : '❌ Failed: ' + data.message);
        return data;
    },

    // -------------------------------------------------------------------------
    // Templates CRUD
    // -------------------------------------------------------------------------
    async loadTemplates() {
        const res  = await fetch('/api/email.php?action=templates');
        const data = await res.json();
        return data.success ? data.data : [];
    },

    async saveTemplate(templateData) {
        const res = await fetch('/api/email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save_template', ...templateData })
        });
        return res.json();
    },

    async deleteTemplate(id) {
        if (!confirm('Delete this email template?')) return;
        const res = await fetch('/api/email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_template', id })
        });
        return res.json();
    },

    // -------------------------------------------------------------------------
    // Logs
    // -------------------------------------------------------------------------
    async loadLogs(limit = 50, offset = 0) {
        const res  = await fetch(`/api/email.php?action=logs&limit=${limit}&offset=${offset}`);
        const data = await res.json();
        return data.success ? data : { data: [], total: 0 };
    },

    // -------------------------------------------------------------------------
    // Render templates table into a container element
    // -------------------------------------------------------------------------
    async renderTemplatesTable(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const templates = await this.loadTemplates();
        let html = `
        <div style="margin-bottom:12px">
            <button onclick="EmailManager.openTemplateModal()" class="btn btn-primary btn-sm">+ New Template</button>
            <button onclick="EmailManager.sendTest('')" class="btn btn-outline-secondary btn-sm ms-2">Send Test Email</button>
        </div>
        <table class="table table-sm table-bordered">
          <thead><tr><th>Name</th><th>Slug</th><th>Subject</th><th>Active</th><th>Actions</th></tr></thead>
          <tbody>`;

        if (!templates.length) {
            html += '<tr><td colspan="5" class="text-center text-muted">No templates yet.</td></tr>';
        } else {
            for (const t of templates) {
                html += `<tr>
                  <td>${this._esc(t.name)}</td>
                  <td><code>${this._esc(t.slug)}</code></td>
                  <td>${this._esc(t.subject)}</td>
                  <td>${t.is_active ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
                  <td>
                    <button onclick="EmailManager.openTemplateModal(${JSON.stringify(t).replace(/"/g,'&quot;')})" class="btn btn-xs btn-outline-primary">Edit</button>
                    <button onclick="EmailManager.deleteTemplate(${t.id}).then(()=>EmailManager.renderTemplatesTable('${containerId}'))" class="btn btn-xs btn-outline-danger ms-1">Delete</button>
                  </td>
                </tr>`;
            }
        }
        html += '</tbody></table>';
        container.innerHTML = html;
    },

    // -------------------------------------------------------------------------
    // Simple modal for create/edit template
    // -------------------------------------------------------------------------
    openTemplateModal(tpl = null) {
        const existing = document.getElementById('_emailTplModal');
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id = '_emailTplModal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center';
        modal.innerHTML = `
          <div style="background:#fff;border-radius:8px;padding:24px;width:640px;max-height:90vh;overflow-y:auto">
            <h5>${tpl ? 'Edit' : 'New'} Email Template</h5>
            <input  id="_etId"      type="hidden" value="${tpl?.id ?? ''}">
            <div class="mb-2"><label>Name</label><input id="_etName"    class="form-control form-control-sm" value="${this._esc(tpl?.name ?? '')}"></div>
            <div class="mb-2"><label>Slug <small class="text-muted">(unique key, e.g. form_submission)</small></label><input id="_etSlug" class="form-control form-control-sm" value="${this._esc(tpl?.slug ?? '')}"></div>
            <div class="mb-2"><label>Subject <small class="text-muted">(supports {{variables}})</small></label><input id="_etSubject" class="form-control form-control-sm" value="${this._esc(tpl?.subject ?? '')}"></div>
            <div class="mb-2"><label>Description</label><input id="_etDesc" class="form-control form-control-sm" value="${this._esc(tpl?.description ?? '')}"></div>
            <div class="mb-2"><label>Body (HTML, supports {{variables}})</label><textarea id="_etBody" class="form-control form-control-sm" rows="10">${this._esc(tpl?.body ?? '')}</textarea></div>
            <div class="mb-3"><label><input id="_etActive" type="checkbox" ${(tpl?.is_active ?? 1) == 1 ? 'checked' : ''}> Active</label></div>
            <div style="text-align:right">
              <button onclick="document.getElementById('_emailTplModal').remove()" class="btn btn-secondary btn-sm me-2">Cancel</button>
              <button onclick="EmailManager._saveTemplateFromModal()" class="btn btn-primary btn-sm">Save Template</button>
            </div>
          </div>`;
        document.body.appendChild(modal);
    },

    async _saveTemplateFromModal() {
        const data = {
            id:          document.getElementById('_etId').value || 0,
            name:        document.getElementById('_etName').value,
            slug:        document.getElementById('_etSlug').value,
            subject:     document.getElementById('_etSubject').value,
            description: document.getElementById('_etDesc').value,
            body:        document.getElementById('_etBody').value,
            is_active:   document.getElementById('_etActive').checked ? 1 : 0
        };
        const result = await this.saveTemplate(data);
        if (result.success) {
            document.getElementById('_emailTplModal').remove();
            // Refresh whichever table is on screen
            const containers = ['emailTemplatesContainer', 'email-templates-container'];
            containers.forEach(id => { if (document.getElementById(id)) this.renderTemplatesTable(id); });
        } else {
            alert('Error: ' + result.message);
        }
    },

    _esc(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
};
