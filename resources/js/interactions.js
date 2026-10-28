const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const easing = 'cubic-bezier(0.22, 1, 0.36, 1)';

export const animate = (element, keyframes, duration = 180) => {
    if (!element || reducedMotion.matches || !element.animate) return Promise.resolve();
    element.getAnimations().forEach((animation) => animation.cancel());
    return element.animate(keyframes, { duration, easing }).finished.catch(() => {});
};

export const setDisclosure = (menu, open) => {
    const content = menu.querySelector(':scope > .dropdown-panel, :scope > .disclosure-content');
    const token = {};
    menu.motionToken = token;
    menu.dataset.disclosureState = open ? 'open' : 'closing';
    menu.querySelector(':scope > summary')?.setAttribute('aria-expanded', String(open));
    if (open) {
        menu.open = true;
        if (menu.classList.contains('dropdown') && content) {
            content.style.removeProperty('translate');
            const bounds = content.getBoundingClientRect();
            const shift = bounds.right > innerWidth - 12 ? innerWidth - bounds.right - 12 : Math.max(0, 12 - bounds.left);
            content.style.translate = `${shift}px 0`;
        }
        animate(content, [{ opacity: 0, transform: 'translateY(-5px) scale(0.985)' }, { opacity: 1, transform: 'none' }]);
    } else {
        animate(content, [{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(-3px)' }], 120).then(() => {
            if (menu.motionToken !== token) return;
            menu.open = false;
            menu.dataset.disclosureState = 'closed';
        });
    }
};

document.querySelectorAll('details').forEach((menu) => {
    const summary = menu.querySelector(':scope > summary');
    if (!summary) return;
    if (!menu.classList.contains('dropdown')) {
        const content = document.createElement('div');
        content.className = 'disclosure-content';
        while (summary.nextSibling) content.append(summary.nextSibling);
        menu.append(content);
    }
    summary.setAttribute('aria-expanded', String(menu.open));
    summary.addEventListener('click', (event) => {
        event.preventDefault();
        const open = !menu.open || menu.dataset.disclosureState === 'closing';
        if (open && menu.classList.contains('dropdown')) {
            document.querySelectorAll('.dropdown[open]').forEach((other) => { if (other !== menu) setDisclosure(other, false); });
        }
        setDisclosure(menu, open);
    });
    summary.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowDown' || !menu.classList.contains('dropdown')) return;
        event.preventDefault();
        setDisclosure(menu, true);
        menu.querySelector('.dropdown-panel a, .dropdown-panel button, .dropdown-panel select')?.focus();
    });
});

const sidebar = document.querySelector('.sidebar');
const mobileViewport = window.matchMedia('(max-width: 900px)');
export const setSidebarOpen = (open) => {
    document.body.classList.toggle('sidebar-open', open);
    const toggle = document.querySelector('.mobile-menu');
    toggle?.setAttribute('aria-expanded', String(open));
    toggle?.setAttribute('aria-label', open ? 'Zamknij menu' : 'Otwórz menu');
    if (sidebar) sidebar.inert = mobileViewport.matches && !open;
    const main = document.querySelector('.main-content');
    if (main) main.inert = mobileViewport.matches && open;
};
setSidebarOpen(false);
mobileViewport.addEventListener('change', () => setSidebarOpen(false));
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab' || !mobileViewport.matches || !document.body.classList.contains('sidebar-open')) return;
    const items = [document.querySelector('.mobile-menu'), ...sidebar.querySelectorAll('a[href], button, summary')].filter((item) => item && item.getClientRects().length && !item.closest('[inert]') && !item.closest('details:not([open]) .dropdown-panel'));
    const index = items.indexOf(document.activeElement);
    if ((event.shiftKey && index <= 0) || (!event.shiftKey && (index < 0 || index === items.length - 1))) {
        event.preventDefault();
        items[event.shiftKey ? items.length - 1 : 0]?.focus();
    }
});

const drawer = document.getElementById('detail-drawer');
if (drawer && drawer.showModal) {
    const frame = drawer.querySelector('iframe');
    const expand = drawer.querySelector('[data-drawer-expand]');
    let opener;
    let changed = false;
    let closing = false;
    const closeDrawer = () => {
        if (!drawer.open || closing) return;
        closing = true;
        drawer.classList.add('is-closing');
        animate(drawer, [{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateX(32px)' }], 150).then(() => {
            drawer.close();
            drawer.classList.remove('is-loaded', 'is-closing');
            frame.removeAttribute('src');
            closing = false;
            opener?.focus();
            if (changed) location.reload();
        });
    };
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-open-drawer]');
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const url = new URL(link.href);
        if (url.origin !== location.origin) return;
        event.preventDefault();
        document.querySelectorAll('.dropdown[open]').forEach((menu) => setDisclosure(menu, false));
        opener = link;
        changed = false;
        expand.href = link.href;
        const employee = /\/employees\//.test(url.pathname);
        drawer.querySelector('#drawer-title').textContent = employee ? 'Profil pracownika' : 'Szczegóły dokumentu';
        frame.title = employee ? 'Profil pracownika' : 'Szczegóły dokumentu';
        url.searchParams.set('panel', '1');
        drawer.classList.remove('is-loaded');
        drawer.showModal();
        animate(drawer, [{ opacity: 0, transform: 'translateX(48px)' }, { opacity: 1, transform: 'none' }], 240);
        frame.src = url.href;
    });
    frame.addEventListener('load', () => {
        if (!drawer.open || !frame.getAttribute('src')) return;
        const currentUrl = frame.contentDocument?.URL;
        if (!currentUrl || currentUrl === 'about:blank') return;
        const url = new URL(currentUrl);
        if (url.pathname === '/login') { location.assign(url.href); return; }
        url.searchParams.delete('panel');
        expand.href = url.href;
        const title = frame.contentDocument.querySelector('[data-detail-title]')?.dataset.detailTitle ?? (/\/employees\//.test(url.pathname) ? 'Profil pracownika' : 'Szczegóły dokumentu');
        drawer.querySelector('#drawer-title').textContent = title;
        frame.title = title;
        drawer.classList.add('is-loaded');
        if (frame.contentDocument?.querySelector('.flash-success')) changed = true;
    });
    drawer.querySelector('[data-drawer-close]').addEventListener('click', closeDrawer);
    drawer.addEventListener('cancel', (event) => { event.preventDefault(); closeDrawer(); });
    drawer.addEventListener('click', (event) => {
        if (event.target !== drawer) return;
        const bounds = drawer.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeDrawer();
    });
    window.addEventListener('message', (event) => {
        if (event.origin !== location.origin || event.source !== frame.contentWindow) return;
        if (event.data?.type === 'kadrelio:close-details') closeDrawer();
        if (event.data?.type === 'kadrelio:details-updated') changed = true;
    });
}

if (document.body.classList.contains('panel-body') && window.parent !== window) {
    document.querySelectorAll('form[method="get"]').forEach((form) => {
        if (form.elements.namedItem('panel')) return;
        const panel = document.createElement('input');
        panel.type = 'hidden';
        panel.name = 'panel';
        panel.value = '1';
        form.append(panel);
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || link.target === '_blank' || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (link.classList.contains('back-link')) {
            event.preventDefault();
            window.parent.postMessage({ type: 'kadrelio:close-details' }, location.origin);
            return;
        }
        const url = new URL(link.href);
        if (url.origin !== location.origin || !/^\/(companies|monday)\//.test(url.pathname)) return;
        if (/\/(records|employees)\/\d+$/.test(url.pathname) || /^\/monday\/boards\/\d+\/items\/\d+$/.test(url.pathname)) {
            url.searchParams.set('panel', '1');
            link.href = url.href;
        } else if (!/\/document$|\/assets\/\d+$/.test(url.pathname)) {
            link.target = '_top';
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !document.querySelector('.dropdown[open]')) window.parent.postMessage({ type: 'kadrelio:close-details' }, location.origin);
    });
}

document.querySelectorAll('[data-form-progress]').forEach((form) => {
    const progress = document.createElement('div');
    progress.className = 'form-progress';
    progress.innerHTML = '<div class="form-progress-label"><span><strong data-progress-complete>0</strong> z <span data-progress-total>0</span> wymaganych pól</span><span data-progress-percent>0%</span></div><div class="form-progress-track" role="progressbar" aria-label="Postęp uzupełniania formularza" aria-valuemin="0" aria-valuemax="100"><span></span></div>';
    form.prepend(progress);
    const update = () => {
        const fields = [...form.querySelectorAll('input,select,textarea')].filter((input) => input.required && !input.disabled && input.type !== 'hidden');
        const complete = fields.filter((input) => input.validity.valid).length;
        const percent = fields.length ? Math.round(complete / fields.length * 100) : 0;
        progress.querySelector('[data-progress-complete]').textContent = complete;
        progress.querySelector('[data-progress-total]').textContent = fields.length;
        progress.querySelector('[data-progress-percent]').textContent = `${percent}%`;
        progress.querySelector('[role="progressbar"]').setAttribute('aria-valuenow', percent);
        progress.querySelector('.form-progress-track > span').style.transform = `scaleX(${percent / 100})`;
    };
    form.addEventListener('input', update);
    form.addEventListener('change', () => requestAnimationFrame(update));
    form.addEventListener('fields-updated', update);
    requestAnimationFrame(update);
});

const openHashSection = () => {
    const section = document.getElementById(location.hash.slice(1));
    if (section instanceof HTMLDetailsElement) setDisclosure(section, true);
};
openHashSection();
window.addEventListener('hashchange', openHashSection);

document.querySelectorAll('.icon-button[aria-label], summary[aria-label]').forEach((element) => {
    if (!element.hasAttribute('title')) element.title = element.getAttribute('aria-label');
});

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download') || link.closest('[data-group-pagination]')) return;
    const url = new URL(link.href);
    if (url.origin !== location.origin || url.pathname.endsWith('/document') || (url.pathname === location.pathname && url.search === location.search)) return;
    document.body.classList.add('is-navigating');
});
window.addEventListener('pageshow', () => {
    document.body.classList.remove('is-navigating');
    document.querySelectorAll('button[data-original-content]').forEach((button) => {
        button.innerHTML = button.dataset.originalContent;
        delete button.dataset.originalContent;
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.classList.remove('is-loading');
    });
});
