const querySignature = (url) => {
    const params = new URL(url).searchParams;
    [...params.keys()].forEach((key) => {
        if (/^page(?:_|$)/.test(key) || params.get(key) === '') params.delete(key);
    });
    params.sort();
    return params.toString();
};

export const initializeLiveSearch = ({ onUpdate, beforeSearch, notify }) => {
    const form = document.querySelector('[data-filter-form]');
    const input = form?.querySelector('input[name="q"]');
    if (!input || !document.querySelector('[data-board-results]')) return { restore: () => false };
    let signature = querySignature(location.href);
    let controller;
    let timer;
    const status = document.createElement('span');
    status.className = 'sr-only';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    form.append(status);

    const restoreForm = (url) => {
        form.querySelectorAll('input[name], select[name]').forEach((field) => {
            if (field.type === 'checkbox') {
                field.checked = url.searchParams.has(field.name) ? url.searchParams.getAll(field.name).includes(field.value) : field.defaultChecked;
            } else if (field.tagName === 'SELECT') {
                field.value = url.searchParams.get(field.name) ?? field.options[0]?.value ?? '';
                field.dispatchEvent(new Event('searchable-select:sync'));
            } else {
                field.value = url.searchParams.get(field.name) ?? '';
            }
        });
    };

    const load = async (url, restore = false) => {
        controller?.abort();
        beforeSearch();
        const current = new AbortController();
        controller = current;
        const results = document.querySelector('[data-board-results]');
        results.setAttribute('aria-busy', 'true');
        const timeout = window.setTimeout(() => current.abort('timeout'), 20000);
        try {
            const response = await fetch(url, { headers: { 'X-Board-Search': '1', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, signal: current.signal });
            if (response.redirected && new URL(response.url).pathname === '/login') {
                location.assign(response.url);
                return;
            }
            if (!response.ok || response.redirected) throw new Error('search');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = page.querySelector('[data-board-results]');
            if (!next || current.signal.aborted) return;
            const collapsed = new Set([...results.querySelectorAll('[data-toggle-group][aria-expanded="false"]')].map(button => button.closest('[data-paginated-group]').dataset.paginatedGroup));
            next.querySelectorAll('[data-paginated-group]').forEach((group) => {
                if (!collapsed.has(group.dataset.paginatedGroup)) return;
                group.querySelector('[data-toggle-group]').setAttribute('aria-expanded', 'false');
                const content = group.querySelector('.group-content');
                content.classList.add('is-collapsed');
                content.inert = true;
                content.setAttribute('aria-hidden', 'true');
            });
            beforeSearch();
            results.replaceWith(next);
            const total = Number(next.dataset.total);
            const counter = document.querySelector('[data-board-total]');
            if (counter) counter.textContent = `${counter.dataset.totalLabel}: ${total.toLocaleString('pl-PL')}`;
            document.querySelector('.export-button')?.toggleAttribute('disabled', total === 0);
            signature = querySignature(url);
            if (restore) restoreForm(url);
            else history.replaceState(null, '', url);
            status.textContent = `Liczba wyników: ${total}.`;
            const filtered = [...form.querySelectorAll('select[name="status"],select[name="department"],select[name="kind"]')].some(select => select.value);
            form.querySelector('.filter-dropdown summary')?.classList.toggle('is-filtered', filtered);
            onUpdate();
        } catch {
            if (!current.signal.aborted || current.signal.reason === 'timeout') notify('Nie udało się wczytać wyników. Spróbuj ponownie.');
        } finally {
            window.clearTimeout(timeout);
            if (controller === current) document.querySelector('[data-board-results]')?.removeAttribute('aria-busy');
        }
    };

    const formUrl = () => {
        const url = new URL(form.getAttribute('action') || location.pathname, location.origin);
        url.search = new URLSearchParams(new FormData(form)).toString();
        return url;
    };
    const schedule = () => {
        controller?.abort();
        beforeSearch();
        window.clearTimeout(timer);
        timer = window.setTimeout(() => load(formUrl()), 300);
    };
    input.addEventListener('input', (event) => { if (!event.isComposing) schedule(); });
    input.addEventListener('compositionend', schedule);
    form.addEventListener('submit', (event) => {
        if (event.submitter?.name === 'columns_custom') return;
        event.preventDefault();
        window.clearTimeout(timer);
        load(formUrl());
    });

    return {
        restore(url) {
            window.clearTimeout(timer);
            controller?.abort();
            if (querySignature(url) === signature) {
                restoreForm(new URL(url));
                return false;
            }
            load(new URL(url), true);
            return true;
        },
    };
};
