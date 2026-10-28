export const initializeTemplateEditor = () => {
    const form = document.querySelector('[data-template-editor]');
    if (!form) return;
    if (location.hash === '#preview') {
        requestAnimationFrame(() => document.getElementById('preview')?.scrollIntoView({ block: 'start' }));
    }
    const frame = document.querySelector('[data-template-pdf-frame]');
    const fallback = document.querySelector('[data-template-pdf-fallback]');
    if (navigator.pdfViewerEnabled === false) {
        const link = document.querySelector('[data-template-pdf-link]');
        if (link) {
            link.textContent = 'Pobierz podgląd PDF';
            link.download = 'podglad-wzoru.pdf';
        }
    } else if (frame) {
        frame.src = frame.dataset.templatePdfSrc;
        frame.hidden = false;
        if (fallback) fallback.hidden = true;
    }
    const pending = document.querySelector('[data-template-pending]');
    const markChanged = () => {
        if (pending) pending.hidden = false;
        document.querySelectorAll('[data-template-preview], [data-template-activate]').forEach(button => { button.disabled = true; });
        const preview = document.querySelector('[data-template-saved-preview]');
        if (preview) preview.hidden = true;
    };
    form.addEventListener('input', event => {
        if (!event.target.matches('.searchable-select-input')) markChanged();
    });
    form.addEventListener('change', event => {
        if (!event.target.matches('.searchable-select-input')) markChanged();
    });
    const file = form.querySelector('input[type="file"]');
    file?.addEventListener('change', () => {
        const chosen = file.files?.[0];
        const label = form.querySelector('[data-template-file-name]');
        const picked = form.querySelector('[data-template-picked-file]');
        if (picked) picked.textContent = chosen?.name || 'Nie wybrano pliku';
        if (label) label.textContent = chosen ? `${chosen.name} · ${(chosen.size / 1024).toLocaleString('pl-PL', { maximumFractionDigits: 0 })} KB` : '';
        const name = form.querySelector('[name="name"]');
        if (chosen && name && !name.value.trim()) name.value = chosen.name.replace(/^\d+_/, '').replace(/\.(docx|pdf|txt)$/i, '');
    });
    const kind = form.querySelector('[name="kind"]');
    kind?.addEventListener('change', () => {
        const packageFields = form.querySelector('[data-template-package]');
        if (!packageFields) return;
        const primary = ['contract', 'amendment', 'termination'].includes(kind.value);
        packageFields.hidden = !primary;
        packageFields.querySelectorAll('input').forEach(input => { input.disabled = !primary; });
        const main = packageFields.querySelector('[data-template-primary]');
        if (main) main.value = kind.value;
        packageFields.querySelectorAll('input[type="checkbox"]').forEach(input => {
            input.checked = kind.value === 'contract' && ['declaration', 'attachment'].includes(input.value);
        });
    });
};
