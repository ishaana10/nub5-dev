<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db = NuDatabase::getInstance();
?>

<div class="nu-ai">
    <div class="nu-card" style="margin-bottom: 24px;">
        <div class="nu-card-header">
            <h3 class="nu-card-title">AI Assistant</h3>
            <span style="font-size:12px;color:var(--text-tertiary);">Powered by OpenAI / Claude</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:16px;">
            <div id="aiChat" style="background:var(--bg-secondary);border-radius:var(--radius-lg);padding:16px;height:400px;overflow-y:auto;display:flex;flex-direction:column;gap:12px;">
                <div style="align-self:flex-start;background:var(--bg-elevated);padding:12px 16px;border-radius:var(--radius-md);max-width:80%;font-size:14px;">
                    <strong>AI:</strong> Hello! I can help you build forms, write SQL queries, analyze data, or answer questions about your application. What would you like to do?
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <input type="text" class="nu-input" id="aiPrompt" placeholder="Ask me to build a form for customer orders..." style="flex:1;" onkeypress="if(event.key==='Enter')sendAiPrompt()">
                <button class="nu-btn nu-btn-primary" onclick="sendAiPrompt()">Send</button>
            </div>
        </div>
    </div>

    <div class="nu-grid">
        <div class="nu-card">
            <div class="nu-card-header">
                <h3 class="nu-card-title">Auto-Generate Form</h3>
            </div>
            <div class="nu-modal-body">
                <div class="nu-field">
                    <label>Describe the form you need</label>
                    <textarea class="nu-input" id="aiFormPrompt" rows="3" placeholder="A customer order form with name, email, product selection, quantity, and delivery date"></textarea>
                </div>
                <button class="nu-btn nu-btn-primary" onclick="generateForm()" style="margin-top:8px;">Generate Form</button>
            </div>
        </div>

        <div class="nu-card">
            <div class="nu-card-header">
                <h3 class="nu-card-title">Auto-Generate SQL Report</h3>
            </div>
            <div class="nu-modal-body">
                <div class="nu-field">
                    <label>Describe the report you need</label>
                    <textarea class="nu-input" id="aiReportPrompt" rows="3" placeholder="Show monthly sales by product category with totals and averages"></textarea>
                </div>
                <button class="nu-btn nu-btn-primary" onclick="generateReport()" style="margin-top:8px;">Generate Report</button>
            </div>
        </div>
    </div>
</div>
