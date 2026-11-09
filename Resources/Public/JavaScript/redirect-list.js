import DocumentService from '@typo3/core/document-service.js';

export default async function (labels) {
    await DocumentService.ready();
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
