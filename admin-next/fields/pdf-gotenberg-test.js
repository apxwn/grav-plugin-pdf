/** Admin2 button that probes the configured Gotenberg service. */
const TAG = window.__GRAV_FIELD_TAG || 'grav-pdf--pdf-gotenberg-test';

class PdfGotenbergTestField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = null;
        this._busy = false;
    }

    set field(value) { this._field = value; if (this.isConnected) this._render(); }
    get field() { return this._field; }
    set value(value) { this._value = value; }
    get value() { return this._value; }

    connectedCallback() { this._render(); }

    _render() {
        this.innerHTML = `
            <style>
                .pdf-test-row { display:flex; align-items:center; flex-wrap:wrap; gap:12px; }
                .pdf-test-button { padding:7px 13px; border:1px solid var(--border,#cbd5e1); border-radius:6px; background:var(--muted,#f1f5f9); color:var(--foreground,#0f172a); cursor:pointer; font:inherit; }
                .pdf-test-button:disabled { opacity:.6; cursor:wait; }
                .pdf-test-result { font-size:13px; }
            </style>
            <div class="pdf-test-row">
                <button class="pdf-test-button" type="button"></button>
                <span class="pdf-test-result" role="status" aria-live="polite"></span>
            </div>`;
        this._button = this.querySelector('button');
        this._result = this.querySelector('[role="status"]');
        this._button.textContent = (this._field && this._field.label) || 'Test connection';
        this._button.disabled = this._busy;
        this._button.addEventListener('click', () => this._run());
    }

    async _run() {
        this._busy = true;
        this._button.disabled = true;
        this._result.textContent = 'Testing…';
        try {
            const response = await window.__GRAV_API.post('/pdf/gotenberg/test');
            const data = response && (response.data || response);
            const message = data && (data.hint || data.error) || 'No response details.';
            this._result.textContent = data && data.ok
                ? `Connected: Gotenberg ${data.version} (${data.ms} ms).`
                : `${message}${data && data.status ? ` HTTP ${data.status}.` : ''}`;
            this._result.dataset.state = data && data.ok ? 'success' : 'error';
        } catch (error) {
            this._result.textContent = (error && error.message) || 'Connection test failed.';
            this._result.dataset.state = 'error';
        } finally {
            this._busy = false;
            this._button.disabled = false;
        }
    }
}

customElements.define(TAG, PdfGotenbergTestField);
