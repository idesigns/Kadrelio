let activeSelect;
let nextSelectId = 0;
const instances = new WeakMap();
const normalize = (text) => text.toLocaleLowerCase('pl-PL').normalize('NFD').replace(/\p{Diacritic}/gu, '').replace(/ł/g, 'l');

const enhanceSelect = (select) => {
    if (instances.has(select) || select.multiple) return;
    const id = `searchable-select-${++nextSelectId}`;
    const labels = [...select.labels];
    const label = labels[0]?.cloneNode(true);
    label?.querySelectorAll('select,input,button,svg').forEach(element => element.remove());
    const accessibleLabel = select.getAttribute('aria-label') || label?.textContent.replace(/\s*\*\s*$/, '').trim() || 'Opcje';
    const wrapper = document.createElement('div');
    wrapper.className = 'searchable-select';
    select.before(wrapper);
    wrapper.append(select);
    const input = document.createElement('input');
    input.type = 'text';
    input.id = `${id}-input`;
    input.className = 'searchable-select-input';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', `${id}-list`);
    input.setAttribute('aria-label', accessibleLabel);
    if (select.hasAttribute('aria-describedby')) input.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
    labels.forEach((label) => { label.htmlFor = input.id; });
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'select-toggle';
    toggle.tabIndex = -1;
    toggle.setAttribute('aria-label', 'Rozwiń listę opcji');
    const chevron = document.getElementById('select-chevron-icon')?.content.firstElementChild;
    if (chevron) toggle.append(chevron.cloneNode(true));
    const popup = document.createElement('div');
    popup.id = `${id}-list`;
    popup.className = 'select-popup';
    popup.setAttribute('role', 'listbox');
    popup.setAttribute('popover', 'manual');
    popup.setAttribute('aria-label', accessibleLabel);
    popup.hidden = true;
    wrapper.append(input, toggle, popup);
    select.hidden = true;
    select.tabIndex = -1;
    let opened = false;
    let visibleOptions = [];
    let highlighted = -1;

    const position = () => {
        if (!opened) return;
        const rect = input.getBoundingClientRect();
        if (rect.bottom < 0 || rect.top > innerHeight || !input.getClientRects().length) { close(); return; }
        const width = Math.min(Math.max(rect.width, 220), innerWidth - 16);
        const below = innerHeight - rect.bottom - 12;
        const above = rect.top - 12;
        const upwards = below < 180 && above > below;
        const height = Math.max(80, Math.min(280, upwards ? above : below));
        popup.style.width = `${width}px`;
        popup.style.maxHeight = `${height}px`;
        popup.style.left = `${Math.max(8, Math.min(rect.left, innerWidth - width - 8))}px`;
        popup.style.top = `${upwards ? rect.top - Math.min(popup.scrollHeight, height) - 6 : rect.bottom + 6}px`;
    };
    const highlight = (index, scroll = true) => {
        highlighted = index;
        [...popup.children].forEach((option, i) => option.classList.toggle('is-highlighted', i === index));
        const option = popup.children[index];
        if (option?.getAttribute('role') === 'option') {
            input.setAttribute('aria-activedescendant', option.id);
            if (scroll) option.scrollIntoView({ block: 'nearest' });
        } else input.removeAttribute('aria-activedescendant');
    };
    const render = (query = '') => {
        const needle = normalize(query);
        visibleOptions = [...select.options].filter(option => !option.disabled && !option.hidden && normalize(option.textContent).includes(needle));
        const fragment = document.createDocumentFragment();
        visibleOptions.forEach((option, index) => {
            const row = document.createElement('div');
            row.id = `${id}-option-${index}`;
            row.className = 'select-option';
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', String(option.selected));
            row.dataset.index = index;
            row.textContent = option.textContent;
            fragment.append(row);
        });
        if (!visibleOptions.length) {
            const empty = document.createElement('div');
            empty.className = 'select-empty';
            empty.textContent = 'Brak pasujących opcji';
            fragment.append(empty);
        }
        popup.replaceChildren(fragment);
        const selected = visibleOptions.findIndex(option => option.selected);
        highlight(selected >= 0 ? selected : (visibleOptions.length ? 0 : -1), false);
        position();
    };
    const sync = () => {
        input.disabled = select.disabled;
        toggle.disabled = select.disabled;
        input.placeholder = select.options[0]?.value === '' ? select.options[0].textContent : 'Wybierz opcję';
        input.value = select.value ? select.selectedOptions[0]?.textContent ?? '' : '';
        input.setCustomValidity('');
        input.setAttribute('aria-required', String(select.required));
        input.toggleAttribute('aria-invalid', select.getAttribute('aria-invalid') === 'true');
        if (select.getAttribute('aria-invalid') === 'true') input.setAttribute('aria-invalid', 'true');
        if (select.disabled && opened) close();
    };
    const close = () => {
        if (!opened) return;
        opened = false;
        if (popup.hidePopover && popup.matches(':popover-open')) popup.hidePopover();
        popup.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        wrapper.classList.remove('is-open');
        if (activeSelect?.select === select) activeSelect = null;
        input.value = select.value ? select.selectedOptions[0]?.textContent ?? '' : '';
    };
    const open = (query = '') => {
        if (select.disabled) return;
        if (activeSelect?.select !== select) activeSelect?.close();
        opened = true;
        activeSelect = { select, wrapper, close, position };
        popup.hidden = false;
        if (popup.showPopover && !popup.matches(':popover-open')) popup.showPopover();
        input.setAttribute('aria-expanded', 'true');
        wrapper.classList.add('is-open');
        render(query);
        requestAnimationFrame(() => {
            if (!opened) return;
            const footer = select.form?.querySelector('.form-footer');
            if (footer && getComputedStyle(footer).position === 'sticky' && input.getBoundingClientRect().bottom > footer.getBoundingClientRect().top - 12) {
                input.scrollIntoView({ block: 'center', inline: 'nearest' });
                position();
            }
        });
    };
    const choose = (index) => {
        const option = visibleOptions[index];
        if (!option) return;
        select.value = option.value;
        input.setCustomValidity('');
        select.removeAttribute('aria-invalid');
        sync();
        close();
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
    };
    input.addEventListener('focus', () => { open(); input.select(); });
    input.addEventListener('click', () => { if (!opened) open(); });
    input.addEventListener('input', () => open(input.value));
    input.addEventListener('blur', () => { window.setTimeout(() => { if (!wrapper.contains(document.activeElement)) close(); }, 0); });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!opened) open();
            else if (visibleOptions.length) highlight((highlighted + (event.key === 'ArrowDown' ? 1 : -1) + visibleOptions.length) % visibleOptions.length);
        } else if (event.key === 'Enter' && opened) {
            event.preventDefault();
            choose(highlighted);
        } else if (event.key === 'Escape' && opened) {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (event.key === 'Tab') close();
    });
    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        if (opened) close();
        else { input.focus(); open(); }
    });
    popup.addEventListener('pointerdown', event => event.preventDefault());
    popup.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const option = event.target.closest('[data-index]');
        if (option) choose(Number(option.dataset.index));
    });
    select.addEventListener('change', sync);
    select.addEventListener('searchable-select:sync', sync);
    select.addEventListener('invalid', (event) => {
        event.preventDefault();
        const firstInvalid = select.form?.querySelector('input:invalid,select:invalid,textarea:invalid');
        if (firstInvalid && firstInvalid !== select && firstInvalid !== input) return;
        input.setCustomValidity(select.validity.valueMissing ? 'Wybierz opcję z listy.' : select.validationMessage);
        input.focus();
        input.reportValidity();
    });
    select.form?.addEventListener('reset', () => window.setTimeout(sync, 0));
    instances.set(select, { sync });
    sync();
};

export const initializeSearchableSelects = () => {
    document.querySelectorAll('select').forEach(enhanceSelect);
    new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'attributes') instances.get(mutation.target)?.sync();
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) return;
                if (node.matches('select')) enhanceSelect(node);
                node.querySelectorAll('select').forEach(enhanceSelect);
            });
        });
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'required', 'aria-invalid'] });
    document.addEventListener('pointerdown', (event) => {
        if (activeSelect && !activeSelect.wrapper.contains(event.target)) activeSelect.close();
    });
    document.addEventListener('scroll', () => activeSelect?.position(), true);
    window.addEventListener('resize', () => activeSelect?.position());
};
