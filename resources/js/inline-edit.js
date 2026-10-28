import { animate } from './interactions';

export const initializeInlineEditing = (notify) => {
    let activeForm;
    let saving = false;
    const focusField = (form) => {
        const field = form.querySelector('[data-field-input]');
        const input = field.closest('.searchable-select')?.querySelector('.searchable-select-input') ?? field;
        input.focus({ preventScroll: true });
        input.select?.();
    };
    const dirty = (form) => [...form.querySelectorAll('textarea[name], input[name]:not([type="hidden"]), select[name]')].some(input => input.tagName === 'SELECT' ? input.value !== ([...input.options].find(option => option.defaultSelected)?.value ?? input.options[0]?.value ?? '') : input.value !== input.defaultValue);
    const close = (form) => {
        if (!form || saving) return;
        form.reset();
        form.hidden = true;
        const row = form.closest('[data-field-column]');
        row.querySelector('[data-field-display]').hidden = false;
        const button = row.querySelector('[data-edit-field]');
        button.setAttribute('aria-expanded', 'false');
        button.focus({ preventScroll: true });
        activeForm = null;
    };

    document.addEventListener('click', (event) => {
        const cancel = event.target.closest('[data-cancel-field]');
        if (cancel) { close(cancel.closest('form')); return; }
        const button = event.target.closest('[data-edit-field]');
        if (!button || saving) return;
        if ((activeForm && dirty(activeForm)) || (document.querySelector('[data-full-edit]') && dirty(document.querySelector('[data-full-edit]')))) {
            notify('Najpierw zapisz lub anuluj rozpoczętą edycję.');
            if (activeForm) focusField(activeForm);
            return;
        }
        close(activeForm);
        const row = button.closest('[data-field-column]');
        const form = row.querySelector('[data-inline-edit]');
        form.querySelector('[data-field-error]').hidden = true;
        row.querySelector('[data-field-display]').hidden = true;
        form.hidden = false;
        activeForm = form;
        button.setAttribute('aria-expanded', 'true');
        animate(form, [{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }], 140);
        focusField(form);
    });

    document.addEventListener('keydown', (event) => {
        if (!activeForm) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(activeForm);
        } else if (activeForm.contains(event.target) && event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            activeForm.requestSubmit();
        }
    }, true);

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form.matches('[data-inline-edit]')) return;
        event.preventDefault();
        if (saving) return;
        saving = true;
        const column = form.closest('[data-field-column]').dataset.fieldColumn;
        const payload = new FormData(form);
        const button = form.querySelector('[type="submit"]');
        const spinner = document.createElement('span');
        spinner.className = 'button-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        button.append(spinner);
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button, textarea, select, input:not([type="hidden"])').forEach(input => { input.disabled = true; });
        const error = form.querySelector('[data-field-error]');
        error.hidden = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: payload, signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });
            if (response.redirected && new URL(response.url).pathname === '/login') {
                location.assign(response.url);
                return;
            }
            if (!response.ok) {
                const data = response.headers.get('Content-Type')?.includes('application/json') ? await response.json() : {};
                if (response.status === 419 || response.status === 401) throw new Error('Sesja wygasła. Odśwież stronę i spróbuj ponownie.');
                throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Nie udało się zapisać zmiany. Spróbuj ponownie.');
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextRow = page.querySelector(`[data-field-column="${CSS.escape(column)}"]`);
            if (!nextRow) throw new Error('Nie udało się potwierdzić zapisu. Odśwież stronę przed kolejną zmianą.');
            const revision = page.querySelector('[data-inline-edit] input[name="revision"]')?.value;
            document.querySelectorAll('input[name="revision"]').forEach(input => { input.value = revision; input.defaultValue = revision; });
            document.querySelectorAll('[data-field-column]').forEach((row) => {
                const next = page.querySelector(`[data-field-column="${CSS.escape(row.dataset.fieldColumn)}"]`);
                if (next) row.replaceWith(next);
            });
            const fullEdit = document.querySelector('[data-full-edit]');
            const nextFullEdit = page.querySelector('[data-full-edit]');
            if (fullEdit && nextFullEdit) {
                [...fullEdit.elements].forEach((input) => {
                    const next = nextFullEdit.elements.namedItem(input.name);
                    if (!next || !input.name) return;
                    input.value = next.value;
                    if ('defaultValue' in input) input.defaultValue = next.value;
                    if (input.tagName === 'SELECT') {
                        [...input.options].forEach(option => { option.defaultSelected = option.selected; });
                        input.dispatchEvent(new Event('searchable-select:sync'));
                    }
                });
            }
            for (const selector of ['.board-header', '[data-item-history]', '[data-item-review]', '[data-item-employee-name]', '[data-document-actions]']) {
                const current = document.querySelector(selector);
                const next = page.querySelector(selector);
                if (current && next) current.replaceWith(next);
            }
            document.title = page.title;
            activeForm = null;
            const updatedRow = document.querySelector(`[data-field-column="${CSS.escape(column)}"]`);
            updatedRow.querySelector('[data-edit-field]')?.focus({ preventScroll: true });
            animate(updatedRow, [{ backgroundColor: '#e7f7ef' }, { backgroundColor: 'transparent' }], 500);
            notify('Zapisano dane.');
            if (window.parent !== window) window.parent.postMessage({ type: 'kadrelio:details-updated' }, location.origin);
        } catch (failure) {
            error.textContent = failure.name === 'AbortError' ? 'Zapis trwał zbyt długo. Odśwież stronę, aby sprawdzić, czy zmiana została zapisana.' : failure.message;
            error.hidden = false;
        } finally {
            saving = false;
            window.clearTimeout(timeout);
            spinner.remove();
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button, textarea, select, input:not([type="hidden"])').forEach(input => { input.disabled = false; });
        }
    });
};
