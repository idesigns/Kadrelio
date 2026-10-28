import { animate, setDisclosure, setSidebarOpen } from './interactions';
import { initializeLiveSearch } from './live-search';
import { initializeSearchableSelects } from './searchable-select';
import { initializeInlineEditing } from './inline-edit';
import { initializeTemplateEditor } from './template-editor';

initializeSearchableSelects();
initializeTemplateEditor();

const toast = (message) => {
    const element = document.getElementById('toast');
    if (!element) return;
    element.replaceChildren(document.createTextNode(message));
    element.classList.add('visible');
    window.clearTimeout(element.dismissTimer);
    element.dismissTimer = window.setTimeout(() => element.classList.remove('visible'), 3200);
};

initializeInlineEditing(toast);

document.querySelectorAll('[data-dismiss]').forEach((button) => {
    button.addEventListener('click', () => {
        const message = button.closest('.flash');
        animate(message, [{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(-6px)' }], 120).then(() => message?.remove());
    });
});

document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        setSidebarOpen(!document.body.classList.contains('sidebar-open'));
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        const sidebarOpen = document.body.classList.contains('sidebar-open');
        setSidebarOpen(false);
        if (sidebarOpen) document.querySelector('.mobile-menu')?.focus();
        document.querySelectorAll('.dropdown[open]').forEach((menu) => {
            if (menu.contains(document.activeElement)) menu.querySelector('summary')?.focus();
            setDisclosure(menu, false);
        });
    }
});

document.addEventListener('click', (event) => {
    document.querySelectorAll('.dropdown[open]').forEach((menu) => {
        if (!menu.contains(event.target)) setDisclosure(menu, false);
    });
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (button) {
        try {
            await navigator.clipboard.writeText(button.dataset.copy);
            toast('Link skopiowany.');
        } catch {
            const temporary = document.createElement('textarea');
            temporary.value = button.dataset.copy;
            temporary.style.position = 'fixed';
            temporary.style.opacity = '0';
            document.body.append(temporary);
            temporary.select();
            const copied = document.execCommand('copy');
            temporary.remove();
            toast(copied ? 'Link skopiowany.' : 'Nie udało się skopiować. Otwórz link i skopiuj adres z przeglądarki.');
        }
    }
});

const search = document.querySelector('input[name="q"]');
const focusSearch = document.querySelector('[data-focus-search]');
if (search) focusSearch?.addEventListener('click', () => search.focus());
else focusSearch?.remove();

document.querySelectorAll('[data-auto-submit]').forEach((select) => {
    select.addEventListener('change', () => select.form.requestSubmit());
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-toggle-group]');
    if (button) {
        const expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', String(!expanded));
        const content = document.getElementById(button.getAttribute('aria-controls'));
        content.classList.toggle('is-collapsed', expanded);
        content.inert = expanded;
        content.setAttribute('aria-hidden', String(expanded));
    }
});

const updateSelection = () => {
    const checkboxes = [...document.querySelectorAll('[data-select-row]')];
    const selected = checkboxes.filter((input) => input.checked);
    checkboxes.forEach((input) => input.closest('tr').classList.toggle('selected', input.checked));
    document.querySelectorAll('[data-select-group]').forEach((input) => {
        const items = [...input.closest('table').querySelectorAll('[data-select-row]')];
        input.checked = items.length > 0 && items.every((item) => item.checked);
        input.indeterminate = items.some((item) => item.checked) && !input.checked;
    });
    const bar = document.querySelector('.selection-bar');
    if (bar) {
        if (selected.length && bar.hidden) {
            bar.hidden = false;
            animate(bar, [{ opacity: 0, translate: '0 10px' }, { opacity: 1, translate: '0 0' }]);
        } else if (!selected.length && !bar.hidden) {
            animate(bar, [{ opacity: 1 }, { opacity: 0 }], 100).then(() => {
                if (!document.querySelector('[data-select-row]:checked')) bar.hidden = true;
            });
        }
    }
    const counter = document.querySelector('[data-selection-count]');
    if (counter) counter.textContent = `Wybrano: ${selected.length}`;
};

document.addEventListener('change', (event) => {
    const input = event.target;
    if (input.matches('[data-select-group]')) {
        input.closest('table').querySelectorAll('[data-select-row]').forEach((checkbox) => { checkbox.checked = input.checked; });
    }
    if (input.matches('[data-select-row], [data-select-group]')) updateSelection();
});
document.querySelector('[data-clear-selection]')?.addEventListener('click', () => {
    document.querySelectorAll('[data-select-row]').forEach((input) => { input.checked = false; });
    updateSelection();
});

const pendingPages = new Map();
const fitBoardTables = () => {
    document.querySelectorAll('.monday-table, .simple-table').forEach((table) => {
        const headers = [...(table.tHead?.rows[0]?.cells ?? [])].map(cell => cell.textContent.replace(/\s+/g, ' ').trim());
        [...table.tBodies].forEach(body => [...body.rows].forEach((row) => {
            [...row.cells].forEach((cell, index) => { cell.dataset.label = cell.colSpan > 1 ? '' : headers[index] ?? ''; });
        }));
        table.classList.toggle('is-stacked', table.parentElement.clientWidth < 860 || headers.length > 12);
    });
};
fitBoardTables();
const tableContainer = document.querySelector('.main-content');
if (tableContainer) new ResizeObserver(fitBoardTables).observe(tableContainer);

document.addEventListener('scroll', (event) => {
    const scroller = event.target;
    if (!scroller.matches?.('[data-board-results] .table-scroll')) return;
    scroller.closest('[data-board-results]').querySelectorAll('.table-scroll').forEach((other) => {
        if (other.scrollLeft !== scroller.scrollLeft) other.scrollLeft = scroller.scrollLeft;
    });
}, true);

const syncPaginationLinks = () => {
    document.querySelectorAll('[data-paginated-group]').forEach((group) => {
        group.querySelectorAll('[data-group-pagination] a').forEach((link) => {
            const url = new URL(location.href);
            url.searchParams.set(group.dataset.pageName, new URL(link.href).searchParams.get(group.dataset.pageName));
            link.href = url.href;
        });
    });
};

const loadGroupPage = async (group, url, updateHistory = true) => {
    const key = group.dataset.paginatedGroup;
    pendingPages.get(key)?.abort();
    const controller = new AbortController();
    pendingPages.set(key, controller);
    const timeout = window.setTimeout(() => controller.abort('timeout'), 20000);
    group.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(url, {
            headers: { 'X-Board-Group': key, 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            signal: controller.signal,
        });
        if (response.redirected && new URL(response.url).pathname === '/login') {
            location.assign(response.url);
            return;
        }
        if (!response.ok || response.redirected) throw new Error('page');
        const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');
        const nextGroup = documentFragment.querySelector(`[data-paginated-group="${CSS.escape(key)}"]`);
        if (!nextGroup || controller.signal.aborted || !group.isConnected) throw new Error('page');
        const collapsed = group.querySelector('[data-toggle-group]')?.getAttribute('aria-expanded') === 'false';
        const horizontalScroll = group.querySelector('.table-scroll')?.scrollLeft ?? 0;
        group.replaceChildren(...nextGroup.childNodes);
        group.dataset.currentPage = nextGroup.dataset.currentPage;
        if (collapsed) {
            group.querySelector('[data-toggle-group]').setAttribute('aria-expanded', 'false');
            const content = group.querySelector('.group-content');
            content.classList.add('is-collapsed');
            content.inert = true;
            content.setAttribute('aria-hidden', 'true');
        }
        const table = group.querySelector('.table-scroll');
        if (table) table.scrollLeft = horizontalScroll;
        if (updateHistory) {
            const currentUrl = new URL(location.href);
            currentUrl.searchParams.delete('page');
            currentUrl.searchParams.set(group.dataset.pageName, group.dataset.currentPage);
            history.pushState(null, '', currentUrl);
        }
        syncPaginationLinks();
        fitBoardTables();
        updateSelection();
        if (updateHistory && !collapsed) {
            const main = document.querySelector('.main-content');
            const offset = (document.querySelector('.board-toolbar')?.offsetHeight ?? 0) + 12;
            if (main && group.getBoundingClientRect().top < main.getBoundingClientRect().top + offset) {
                main.scrollBy({ top: group.getBoundingClientRect().top - main.getBoundingClientRect().top - offset });
            }
            group.querySelector('[aria-current="page"], .group-page-summary')?.focus({ preventScroll: true });
        }
        animate(group.querySelector('.group-body, .table-scroll'), [{ opacity: 0.4 }, { opacity: 1 }], 160);
    } catch {
        if (!controller.signal.aborted || controller.signal.reason === 'timeout') {
            toast('Nie udało się wczytać strony. Spróbuj ponownie.');
        }
    } finally {
        window.clearTimeout(timeout);
        if (pendingPages.get(key) === controller) {
            group.removeAttribute('aria-busy');
            pendingPages.delete(key);
        }
    }
};

document.addEventListener('click', (event) => {
    const link = event.target.closest('[data-group-pagination] a');
    if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const group = link.closest('[data-paginated-group]');
    if (!group) return;
    event.preventDefault();
    if (group.closest('[data-board-results]')?.getAttribute('aria-busy') === 'true') return;
    const url = new URL(location.href);
    url.searchParams.set(group.dataset.pageName, new URL(link.href).searchParams.get(group.dataset.pageName));
    loadGroupPage(group, url);
});

const liveSearch = initializeLiveSearch({
    onUpdate: () => { fitBoardTables(); updateSelection(); syncPaginationLinks(); },
    beforeSearch: () => pendingPages.forEach(controller => controller.abort()),
    notify: toast,
});

window.addEventListener('popstate', () => {
    const url = new URL(location.href);
    if (liveSearch.restore(url)) return;
    document.querySelectorAll('[data-paginated-group]').forEach((group) => {
        const page = url.searchParams.get(group.dataset.pageName) ?? '1';
        if (page !== group.dataset.currentPage || pendingPages.has(group.dataset.paginatedGroup)) loadGroupPage(group, url, false);
    });
});

document.querySelectorAll('[data-export]').forEach((button) => {
    button.addEventListener('click', () => {
        const selected = [...document.querySelectorAll('[data-select-row]:checked')];
        const rows = selected.length ? selected.map((input) => input.closest('tr')) : [...document.querySelectorAll('[data-export-row]')];
        const table = document.querySelector('[data-export-table]');
        if (!rows.length || !table) { toast('Nie ma danych do eksportu.'); return; }
        const escapeCell = (value) => {
            let text = value.replace(/\s+/g, ' ').trim();
            if (/^[=+@\-\t\r]/.test(text)) text = `'${text}`;
            return `"${text.replaceAll('"', '""')}"`;
        };
        const cells = (row) => [...row.children].slice(1, -1).map((cell) => {
            const clone = cell.cloneNode(true);
            clone.querySelectorAll('.avatar, svg, .row-open').forEach((element) => element.remove());
            return escapeCell(clone.textContent);
        }).join(';');
        const csv = '\uFEFF' + [cells(table.querySelector('thead tr')), ...rows.map(cells)].join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `kadrelio-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        toast(`Plik CSV gotowy. Liczba wierszy: ${rows.length}.`);
    });
});

document.querySelectorAll('[data-conditional-form]').forEach((form) => {
    const updateConditions = () => {
        form.querySelectorAll('[data-condition]').forEach((field) => {
            const parent = form.elements.namedItem(field.dataset.condition);
            const visible = parent && field.dataset.values.split('|').includes(String(parent.value));
            const wasHidden = field.hidden;
            field.hidden = !visible;
            field.querySelectorAll('input, select, textarea').forEach((input) => { input.disabled = !visible; });
            if (wasHidden && visible) animate(field, [{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }]);
        });
        form.dispatchEvent(new Event('fields-updated'));
    };
    updateConditions();
    form.addEventListener('change', updateConditions);
});

document.querySelectorAll('[data-insert-variable]').forEach((button) => {
    button.addEventListener('click', () => {
        const textarea = document.querySelector('textarea[name="body"]');
        if (!textarea) return;
        textarea.setRangeText(`{{${button.dataset.insertVariable}}}`, textarea.selectionStart, textarea.selectionEnd, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        animate(button, [{ backgroundColor: '#cce5ff' }, { backgroundColor: '#edf2fb' }], 240);
        textarea.focus();
    });
});

document.querySelectorAll('[data-signature]').forEach((section) => {
    const canvas = section.querySelector('canvas');
    const context = canvas.getContext('2d');
    const hidden = section.querySelector('input[name="signature_image"]');
    const status = section.querySelector('.signature-status');
    const placeholder = section.querySelector('.signature-placeholder');
    const useButton = section.querySelector('[data-signature-use]');
    let drawing = false;
    let drawn = false;
    let moved = false;
    const point = (event) => {
        const bounds = canvas.getBoundingClientRect();
        return { x: (event.clientX - bounds.left) * canvas.width / bounds.width, y: (event.clientY - bounds.top) * canvas.height / bounds.height };
    };
    context.lineWidth = 2.3;
    context.strokeStyle = '#292d42';
    context.lineCap = 'round';
    context.lineJoin = 'round';
    canvas.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;
        drawing = true;
        moved = false;
        hidden.value = '';
        section.classList.remove('signature-ready');
        status.textContent = '';
        placeholder.hidden = true;
        canvas.setPointerCapture(event.pointerId);
        const start = point(event);
        context.beginPath();
        context.moveTo(start.x, start.y);
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!drawing) return;
        const next = point(event);
        context.lineTo(next.x, next.y);
        context.stroke();
        moved = true;
    });
    const finish = () => { if (drawing && moved) drawn = true; drawing = false; };
    canvas.addEventListener('pointerup', finish);
    canvas.addEventListener('pointercancel', finish);
    section.querySelector('[data-signature-clear]').addEventListener('click', () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        drawn = false;
        hidden.value = '';
        section.classList.remove('signature-ready');
        status.textContent = '';
        placeholder.hidden = false;
    });
    useButton.addEventListener('click', () => {
        if (!drawn) { toast('Najpierw narysuj podpis w polu.'); canvas.focus(); return; }
        hidden.value = canvas.toDataURL('image/png');
        status.textContent = 'Podpis gotowy do wysłania';
        section.classList.add('signature-ready');
        animate(status, [{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }]);
    });
    section.closest('form').addEventListener('submit', (event) => {
        if (!hidden.value) {
            event.preventDefault();
            toast('Narysuj podpis i kliknij „Użyj podpisu”.');
            useButton.focus();
        }
    });
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.matches('form[method="post"]')) {
        if (event.defaultPrevented) return;
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            if (button.disabled) return;
            button.dataset.originalContent = button.innerHTML;
            const indicator = document.createElement('span');
            indicator.className = 'button-spinner';
            indicator.setAttribute('aria-hidden', 'true');
            indicator.style.color = getComputedStyle(button).color;
            button.append(indicator);
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
            button.disabled = true;
            window.setTimeout(() => {
                if (!button.dataset.originalContent) return;
                button.innerHTML = button.dataset.originalContent;
                delete button.dataset.originalContent;
                button.disabled = false;
                button.classList.remove('is-loading');
                button.removeAttribute('aria-busy');
            }, 15000);
        });
    }
});


document.querySelectorAll('[data-profile-upload]').forEach(input => {
    input.addEventListener('change', () => {
        const label = input.closest('.profile-file-picker')?.querySelector('[data-upload-label]');
        if (label) label.textContent = input.files.length ? [...input.files].map(file => file.name).join(', ') : 'Nie wybrano plików';
    });
});
