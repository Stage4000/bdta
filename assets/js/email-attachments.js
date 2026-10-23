/* Shared, no-provider email display/upload helpers. Untrusted mail never enters the active document. */
(function () {
    'use strict';
    const maxFile = 10 * 1024 * 1024;
    const maxTotal = 15 * 1024 * 1024;

    function messageText(email) {
        if (email.body_text) return String(email.body_text);
        const template = document.createElement('template');
        template.innerHTML = String(email.body_html || '');
        template.content.querySelectorAll('script,style,template').forEach(node => node.remove());
        template.content.querySelectorAll('br').forEach(node => node.replaceWith(document.createTextNode('\n')));
        template.content.querySelectorAll('p,div,li,tr,h1,h2,h3,h4,h5,h6,blockquote').forEach(node => node.appendChild(document.createTextNode('\n')));
        return template.content.textContent || '';
    }

    function renderBody(container, email) {
        // Plain-text-first intentionally matches the inbox's existing safe display.
        // No untrusted HTML, iframe, navigation control or remote resource enters the page.
        container.replaceChildren();
        container.textContent = messageText(email);
        container.style.whiteSpace = 'pre-wrap';
    }

    function renderAttachments(container, email) {
        const section = document.createElement('section');
        section.className = 'mt-3';
        const heading = document.createElement('h6');
        heading.textContent = 'Attachments';
        section.appendChild(heading);
        const files = Array.isArray(email.attachments) ? email.attachments : [];
        if (!files.length) {
            const note = document.createElement('p');
            note.className = 'text-muted small';
            note.textContent = Number(email.attachments_processed) === 1 ? 'No attachments.' :
                'This message predates attachment support. Check the original mailbox for any files.';
            section.appendChild(note);
        }
        files.forEach(file => {
            const row = document.createElement('div');
            row.className = 'mb-2';
            const id = Number(file.id);
            if (file.status === 'available' && Number.isSafeInteger(id) && id > 0) {
                const link = document.createElement('a');
                link.href = 'email_attachments_download.php?id=' + id;
                link.textContent = String(file.name || 'Attachment') + ' (' + Math.ceil(Number(file.size || 0) / 1024) + ' KB)';
                row.appendChild(link);
            } else {
                row.textContent = String(file.name || 'Attachment') + ': ' + String(file.message || 'Unavailable');
            }
            section.appendChild(row);
        });
        if (files.length) {
            const warning = document.createElement('p');
            warning.className = 'text-muted small';
            warning.textContent = 'Only open files from senders you trust. Files download rather than execute inside BDTA.';
            section.appendChild(warning);
        }
        container.appendChild(section);
    }

    function formData(fields, input) {
        const files = Array.from(input.files || []);
        if (files.length > 10 || files.some(file => file.size > maxFile) || files.reduce((size, file) => size + file.size, 0) > maxTotal) {
            throw new Error('Choose at most 10 files, 10 MB each and 15 MB combined.');
        }
        const data = new FormData();
        Object.entries(fields).forEach(([key, value]) => {
            if (value !== null && value !== undefined) data.append(key, String(value));
        });
        data.set('attachment_count', String(files.length));
        files.forEach(file => data.append('attachments[]', file));
        return data;
    }

    function attachSelection(input, modal, button) {
        const listing = document.createElement('div');
        listing.className = 'small text-muted mt-1';
        input.after(listing);
        input.addEventListener('change', () => {
            listing.textContent = Array.from(input.files || []).map(file => file.name).join(', ');
        });
        modal.addEventListener('hide.bs.modal', event => {
            if (button.disabled) event.preventDefault();
        });
        modal.addEventListener('hidden.bs.modal', () => {
            input.value = '';
            listing.textContent = '';
        });
    }

    window.BdtaEmailUI = {messageText, renderBody, renderAttachments, formData, attachSelection};
}());
