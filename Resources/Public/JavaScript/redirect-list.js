import DocumentService from '@typo3/core/document-service.js';

export default async function (labels, filter) {
    await DocumentService.ready();
    let form = document.querySelector('form[name="demand"]');
    if (!form) {
        // The Core omits its filter form when the result is empty without native constraints.
        form = document.createElement('form');
        form.name = 'demand';
        form.className = 'form-row';
        form.method = 'post';
        form.action = filter.url;
        document.querySelector('.module-body h1')?.after(form);
    }
    const group = document.createElement('div');
    group.className = 'form-group';
    const label = document.createElement('label');
    label.className = 'form-label';
    label.htmlFor = 'redirect-lifecycle-mode';
    label.textContent = filter.label;
    const select = document.createElement('select');
    select.id = label.htmlFor;
    select.name = 'lifecycleMode';
    select.className = 'form-select';
    select.add(new Option(filter.all, ''));
    for (const [mode, text] of Object.entries(filter.modes)) {
        select.add(new Option(text, mode));
    }
    select.value = String(filter.value ?? '');
    select.addEventListener('change', () => form.requestSubmit());
    group.append(label, select);
    const row = form.querySelector('.form-row') ?? form;
    row.insertBefore(group, row.lastElementChild);

    if (filter.value !== null) {
        const withMode = (value) => {
            const url = new URL(value, document.baseURI);
            url.searchParams.set('lifecycleMode', String(filter.value));
            return url.href;
        };
        for (const link of document.querySelectorAll('.pagination a[href], thead a[href]')) {
            link.href = withMode(link.href);
        }
        for (const input of document.querySelectorAll('input[name="paginator-target-page"][data-url]')) {
            input.dataset.url = withMode(input.dataset.url);
        }
    }

    for (const [uid, label] of Object.entries(labels)) {
        // TYPO3 14 renders source host, source path, and target as path columns.
        const cell = document.querySelector(`tr[data-uid="${uid}"]`)?.querySelectorAll('td.col-path')[1];
        if (!cell) {
            continue;
        }
        const badge = document.createElement('span');
        badge.className = 'badge badge-info';
        badge.textContent = label;
        const line = document.createElement('div');
        line.append(badge);
        cell.append(line);
    }
}
