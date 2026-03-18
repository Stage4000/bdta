<?php
/**
 * Unmatched Emails - Manage emails from unknown senders
 */

require_once __DIR__ . '/../backend/includes/config.php';
requireLogin();

$page_title = 'Unmatched Emails';
include __DIR__ . '/../backend/includes/header.php';
?>

<style>
    .unmatched-email-toolbar {
        gap: 0.75rem;
    }
    .unmatched-email-table .subject-link {
        display: inline-flex;
        max-width: 100%;
        color: inherit;
        font-weight: 600;
        text-align: left;
        text-decoration: none;
    }
    .unmatched-email-table .subject-link:hover,
    .unmatched-email-table .subject-link:focus {
        color: var(--bs-primary);
    }
    .unmatched-email-table .subject-text {
        display: inline-block;
        max-width: min(32rem, 45vw);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .unmatched-email-table .email-contact {
        display: block;
        max-width: 24rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .unmatched-email-status {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 0.25rem;
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-inbox"></i> Unmatched Emails</h2>
            <p class="text-muted">Manage emails from senders not in your client database</p>
        </div>
        <div class="d-flex align-items-center flex-wrap unmatched-email-toolbar">
            <button type="button" class="btn btn-outline-secondary" id="cleanupEmailsBtn" onclick="cleanupMissingTimestampEmails()">
                <i class="fas fa-broom"></i> Clean Missing Timestamps
            </button>
            <button type="button" class="btn btn-primary" onclick="openComposeModal()">
                <i class="fas fa-pen"></i> Compose Email
            </button>
            <div class="d-flex align-items-center">
                <span class="badge bg-warning fs-5" id="unassignedCount">0</span>
                <span class="text-muted ms-2">Unassigned</span>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="unassigned-tab" data-bs-toggle="tab" href="#unassigned" role="tab">
                Unassigned
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="assigned-tab" data-bs-toggle="tab" href="#assigned" role="tab">
                Assigned
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="archived-tab" data-bs-toggle="tab" href="#archived" role="tab">
                Archived
            </a>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content">
        <div class="tab-pane fade show active" id="unassigned" role="tabpanel">
            <div id="unassignedEmails"></div>
        </div>
        <div class="tab-pane fade" id="assigned" role="tabpanel">
            <div id="assignedEmails"></div>
        </div>
        <div class="tab-pane fade" id="archived" role="tabpanel">
            <div id="archivedEmails"></div>
        </div>
    </div>
</div>

<!-- Email Details Modal -->
<div class="modal fade" id="emailDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Email Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="emailDetailsBody"></div>
            <div class="modal-footer" id="emailDetailsFooter"></div>
        </div>
    </div>
</div>

<!-- Reply Modal -->
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-reply"></i> Reply</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="replyForm">
                    <input type="hidden" id="replyEmailId">
                    <div class="mb-3">
                        <label for="replyTo" class="form-label">To</label>
                        <input type="email" class="form-control" id="replyTo" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="replySubject" class="form-label">Subject <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="replySubject" required>
                    </div>
                    <div class="mb-3">
                        <label for="replyBody" class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="replyBody" rows="10" required></textarea>
                        <small class="form-text text-muted">HTML is supported</small>
                    </div>
                    <div id="replyFormAlert" class="alert d-none"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="sendReplyBtn" onclick="sendReply()">
                    <i class="fas fa-paper-plane"></i> Send Reply
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Assign to Client Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Email to Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="assignForm">
                    <input type="hidden" id="assignEmailId">
                    <div class="mb-3">
                        <label for="assignClientId" class="form-label">Select Client</label>
                        <select class="form-select" id="assignClientId" required>
                            <option value="">Choose a client...</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="createClientEmail" checked>
                        <label class="form-check-label" for="createClientEmail">
                            Add to client's email history
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="assignEmail()">Assign Email</button>
            </div>
        </div>
    </div>
</div>

<!-- Compose Email Modal -->
<div class="modal fade" id="composeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen"></i> Compose Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="composeForm">
                    <div class="mb-3">
                        <label for="composeTo" class="form-label">To <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="composeTo" required placeholder="recipient@example.com">
                    </div>
                    <div class="mb-3">
                        <label for="composeCC" class="form-label">CC</label>
                        <input type="text" class="form-control" id="composeCC" placeholder="cc@example.com, another@example.com">
                        <small class="form-text text-muted">Separate multiple addresses with commas</small>
                    </div>
                    <div class="mb-3">
                        <label for="composeBCC" class="form-label">BCC</label>
                        <input type="text" class="form-control" id="composeBCC" placeholder="bcc@example.com">
                        <small class="form-text text-muted">Separate multiple addresses with commas</small>
                    </div>
                    <div class="mb-3">
                        <label for="composeSubject" class="form-label">Subject <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="composeSubject" required>
                    </div>
                    <div class="mb-3">
                        <label for="composeBody" class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea id="composeBody" required></textarea>
                        <small class="form-text text-muted">HTML is supported</small>
                    </div>
                    <div id="composeFormAlert" class="alert d-none"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="sendComposeBtn" onclick="sendComposedEmail()">
                    <i class="fas fa-paper-plane"></i> Send Email
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentEmailId = null;
let currentEmailData = null;
let currentEmailFilter = 'unassigned';

// Load emails on page load
document.addEventListener('DOMContentLoaded', function() {
    loadEmails('unassigned');
    loadClients();
    
    // Tab change event
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('shown.bs.tab', function(e) {
            const target = e.target.getAttribute('href').substring(1);
            loadEmails(target);
        });
    });
});

const platformTimeZone = <?= json_encode(getSystemTimezone()) ?>;
const cleanupCsrfToken = <?= json_encode(scalar_string($_SESSION['csrf_token'] ?? '')) ?>;
const platformDateTimeFormatter = new Intl.DateTimeFormat('en-US', {
    timeZone: platformTimeZone,
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
});

function parseUtcDate(dateStr) {
    if (!dateStr) {
        return null;
    }

    const normalized = String(dateStr).trim().replace(' ', 'T');
    const utcValue = /(?:Z|[+-]\d{2}:\d{2})$/i.test(normalized) ? normalized : `${normalized}Z`;
    const date = new Date(utcValue);
    return Number.isNaN(date.getTime()) ? null : date;
}

function formatDateTime(dateStr) {
    const date = parseUtcDate(dateStr);
    return date ? platformDateTimeFormatter.format(date) : '';
}

function htmlToPlainText(html) {
    if (!html) {
        return '';
    }

    // Parse into a detached document and read only textContent; nothing is inserted into the live DOM.
    const doc = new DOMParser().parseFromString(String(html), 'text/html');
    return doc.body.textContent || '';
}

function getEmailMessageText(email) {
    if (email && email.body_text) {
        return email.body_text;
    }

    if (email && email.body_html) {
        return htmlToPlainText(email.body_html);
    }

    return '';
}

// Load emails based on filter
async function loadEmails(filter, preserveCurrentFilter = false) {
    if (!preserveCurrentFilter) {
        currentEmailFilter = filter;
    }
    let url = 'unmatched_emails_api.php?';
    
    if (filter === 'assigned') {
        url += 'assigned=1';
    } else if (filter === 'archived') {
        url += 'archived=1';
    }
    
    const containerId = filter + 'Emails';
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-secondary" role="status"></div> Loading...</div>';

    try {
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success) {
            displayEmails(data.emails, filter);
            document.getElementById('unassignedCount').textContent = data.unassigned_count || 0;
        } else {
            container.innerHTML = '<div class="alert alert-danger">Failed to load emails.</div>';
        }
    } catch (error) {
        console.error('Error loading emails:', error);
        container.innerHTML = '<div class="alert alert-danger">Error loading emails. Please refresh the page.</div>';
    }
}

async function fetchEmailDetails(emailId, filter = currentEmailFilter) {
    currentEmailId = emailId;
    currentEmailFilter = filter;

    const response = await fetch(`unmatched_emails_api.php?id=${emailId}`);
    const data = await response.json();

    if (!data.success) {
        throw new Error(data.error || 'Failed to load email details');
    }

    currentEmailData = data.email;
    return data.email;
}

function reloadCurrentEmailFilter() {
    const activeFilter = currentEmailFilter;
    loadEmails(activeFilter);
    if (activeFilter !== 'unassigned') {
        loadEmails('unassigned', true);
    }
}

// Display emails in the list
function displayEmails(emails, filter) {
    const containerId = filter + 'Emails';
    const container = document.getElementById(containerId);
    const safeFilterArg = JSON.stringify(String(filter)).replace(/"/g, '&quot;');
    
    if (emails.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No emails found.</div>';
        return;
    }
    
    let html = `
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 unmatched-email-table">
                        <thead class="table-light">
                            <tr>
                                <th>Subject</th>
                                <th>Contact</th>
                                <th>Direction</th>
                                <th>Status</th>
                                <th>Timestamp</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
    `;
    
    emails.forEach(email => {
        const date = formatDateTime(email.received_at);
        const assignedBadge = email.is_assigned ? `<span class="badge bg-success">Assigned to ${escapeHtml(email.assigned_client_name)}</span>` : '';
        const archivedBadge = email.is_archived ? '<span class="badge bg-secondary">Archived</span>' : '';
        const isSent = email.direction === 'outgoing';
        const directionBadge = isSent
            ? '<span class="badge bg-primary"><i class="fas fa-paper-plane"></i> Sent</span>'
            : '<span class="badge bg-info text-dark"><i class="fas fa-inbox"></i> Received</span>';
        const contactLine = isSent
            ? `To: ${escapeHtml(email.to_email)}`
            : `From: ${escapeHtml(email.from_name || email.from_email)}${email.from_name ? ` &lt;${escapeHtml(email.from_email)}&gt;` : ''}`;
        const replyAction = isSent
            ? `<button type="button" class="btn btn-sm btn-outline-info table-action-btn" title="Compose to recipient" aria-label="Compose to recipient" onclick="quickCompose(${email.id}, ${safeFilterArg})"><i class="fas fa-pen"></i></button>`
            : `<button type="button" class="btn btn-sm btn-outline-primary table-action-btn" title="Reply" aria-label="Reply" onclick="quickReply(${email.id}, ${safeFilterArg})"><i class="fas fa-reply"></i></button>`;
        const assignAction = !email.is_assigned
            ? `<button type="button" class="btn btn-sm btn-outline-success table-action-btn" title="Assign to client" aria-label="Assign to client" onclick="quickAssign(${email.id}, ${safeFilterArg})"><i class="fas fa-user-check"></i></button>`
            : '';
        const archiveAction = email.is_archived
            ? `<button type="button" class="btn btn-sm btn-outline-info table-action-btn" title="Unarchive" aria-label="Unarchive" onclick="quickUnarchive(${email.id}, ${safeFilterArg})"><i class="fas fa-box-open"></i></button>`
            : `<button type="button" class="btn btn-sm btn-outline-warning table-action-btn" title="Archive" aria-label="Archive" onclick="quickArchive(${email.id}, ${safeFilterArg})"><i class="fas fa-box-archive"></i></button>`;
        const replyActionMobile = isSent
            ? `<button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="quickCompose(${email.id}, ${safeFilterArg})"><i class="fas fa-pen me-2 text-info"></i>Compose to recipient</button>`
            : `<button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="quickReply(${email.id}, ${safeFilterArg})"><i class="fas fa-reply me-2 text-primary"></i>Reply</button>`;
        const assignActionMobile = !email.is_assigned
            ? `<li><button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="quickAssign(${email.id}, ${safeFilterArg})"><i class="fas fa-user-check me-2 text-success"></i>Assign to client</button></li>`
            : '';
        const archiveActionMobile = email.is_archived
            ? `<button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="quickUnarchive(${email.id}, ${safeFilterArg})"><i class="fas fa-box-open me-2 text-info"></i>Unarchive</button>`
            : `<button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="quickArchive(${email.id}, ${safeFilterArg})"><i class="fas fa-box-archive me-2 text-warning"></i>Archive</button>`;
        
        html += `
            <tr>
                <td>
                    <button type="button" class="btn btn-link btn-sm p-0 subject-link" title="${escapeHtml(email.subject)}" onclick="showEmailDetails(${email.id}, ${safeFilterArg})">
                        <span class="subject-text">${escapeHtml(email.subject)}</span>
                    </button>
                </td>
                <td><small class="text-muted email-contact">${contactLine}</small></td>
                <td>${directionBadge}</td>
                <td><div class="unmatched-email-status">${assignedBadge}${archivedBadge || '<span class="badge bg-secondary-subtle text-body-secondary border">Open</span>'}</div></td>
                <td><small class="text-muted">${date || 'Missing timestamp'}</small></td>
                <td>
                    <div class="d-none d-md-inline-flex table-action-buttons table-action-buttons-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary table-action-btn" title="View details" aria-label="View details" onclick="showEmailDetails(${email.id}, ${safeFilterArg})"><i class="fas fa-eye"></i></button>
                        ${replyAction}
                        ${assignAction}
                        ${archiveAction}
                        <button type="button" class="btn btn-sm btn-outline-danger table-action-btn" title="Delete" aria-label="Delete" onclick="quickDelete(${email.id}, ${safeFilterArg})"><i class="fas fa-trash"></i></button>
                    </div>
                    <div class="d-md-none table-action-dropdown">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="showEmailDetails(${email.id}, ${safeFilterArg})"><i class="fas fa-eye me-2 text-secondary"></i>View details</button></li>
                                <li>${replyActionMobile}</li>
                                ${assignActionMobile}
                                <li>${archiveActionMobile}</li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item text-danger w-100 text-start border-0 bg-transparent" onclick="quickDelete(${email.id}, ${safeFilterArg})"><i class="fas fa-trash me-2"></i>Delete</button></li>
                            </ul>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += `
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
    container.innerHTML = html;
}

// Show email details in modal
async function showEmailDetails(emailId, filter = currentEmailFilter) {
    try {
        const email = await fetchEmailDetails(emailId, filter);
        const modal = new bootstrap.Modal(document.getElementById('emailDetailsModal'));

        const isSent = email.direction === 'outgoing';
        const directionBadge = isSent
            ? '<span class="badge bg-primary"><i class="fas fa-paper-plane"></i> Sent</span>'
            : '<span class="badge bg-info text-dark"><i class="fas fa-inbox"></i> Received</span>';
        const dateLabel = isSent ? 'Sent:' : 'Received:';

        const body = document.getElementById('emailDetailsBody');
        body.innerHTML = `
            <dl class="row">
                <dt class="col-sm-3">Direction:</dt>
                <dd class="col-sm-9">${directionBadge}</dd>

                <dt class="col-sm-3">From:</dt>
                <dd class="col-sm-9">${escapeHtml(email.from_name || email.from_email)} ${email.from_name ? `&lt;${escapeHtml(email.from_email)}&gt;` : ''}</dd>

                <dt class="col-sm-3">To:</dt>
                <dd class="col-sm-9">${escapeHtml(email.to_email)}</dd>

                <dt class="col-sm-3">Subject:</dt>
                <dd class="col-sm-9">${escapeHtml(email.subject)}</dd>

                <dt class="col-sm-3">${dateLabel}</dt>
                <dd class="col-sm-9">${formatDateTime(email.received_at)}</dd>

                ${email.is_assigned ? `
                    <dt class="col-sm-3">Assigned to:</dt>
                    <dd class="col-sm-9">${escapeHtml(email.assigned_client_name)} (${formatDateTime(email.assigned_at)})</dd>
                ` : ''}
            </dl>

            <hr>

            <h6>Message</h6>
            <div class="border p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                ${escapeHtml(getEmailMessageText(email))}
            </div>
        `;

        // Show action buttons
        const footer = document.getElementById('emailDetailsFooter');
        footer.innerHTML = '';

        if (isSent) {
            // For sent emails: offer composing a new email to same recipient
            const recipientEmail = email.to_email || '';
            footer.innerHTML += `<button type="button" class="btn btn-info" id="composeToRecipientBtn"><i class="fas fa-pen"></i> Compose to Recipient</button>`;
            document.getElementById('composeToRecipientBtn').addEventListener('click', function() {
                openComposeModal({ to: recipientEmail });
            });
        } else {
            // For received emails: offer reply and compose
            if (email.from_email) {
                footer.innerHTML += `<button type="button" class="btn btn-primary" onclick="openReplyModal()"><i class="fas fa-reply"></i> Reply</button>`;
                footer.innerHTML += `<button type="button" class="btn btn-info" onclick="openComposeFromEmail()"><i class="fas fa-pen"></i> Compose</button>`;
            }
        }

        if (!email.is_assigned) {
            footer.innerHTML += '<button type="button" class="btn btn-success" onclick="openAssignModal()">Assign to Client</button>';
        }

        if (!email.is_archived) {
            footer.innerHTML += '<button type="button" class="btn btn-warning" onclick="archiveEmail()">Archive</button>';
        } else {
            footer.innerHTML += '<button type="button" class="btn btn-info" onclick="unarchiveEmail()">Unarchive</button>';
        }

        footer.innerHTML += '<button type="button" class="btn btn-danger" onclick="deleteEmail()">Delete</button>';
        footer.innerHTML += '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';

        modal.show();
    } catch (error) {
        console.error('Error loading email details:', error);
        alert('Error loading email details');
    }
}

async function quickAssign(emailId, filter) {
    try {
        await fetchEmailDetails(emailId, filter);
        openAssignModal();
    } catch (error) {
        console.error('Error opening assign modal:', error);
        alert('Error loading email details');
    }
}

async function quickReply(emailId, filter) {
    try {
        await fetchEmailDetails(emailId, filter);
        openReplyModal();
    } catch (error) {
        console.error('Error opening reply modal:', error);
        alert('Error loading email details');
    }
}

async function quickCompose(emailId, filter) {
    try {
        const email = await fetchEmailDetails(emailId, filter);
        openComposeModal({ to: email.to_email || '' });
    } catch (error) {
        console.error('Error opening compose modal:', error);
        alert('Error loading email details');
    }
}

function quickArchive(emailId, filter) {
    currentEmailId = emailId;
    currentEmailFilter = filter;
    archiveEmail();
}

function quickUnarchive(emailId, filter) {
    currentEmailId = emailId;
    currentEmailFilter = filter;
    unarchiveEmail();
}

function quickDelete(emailId, filter) {
    currentEmailId = emailId;
    currentEmailFilter = filter;
    deleteEmail();
}

// Load clients for assignment dropdown
async function loadClients() {
    try {
        const response = await fetch('../backend/api/clients.php');
        const data = await response.json();
        
        if (data.success) {
            const select = document.getElementById('assignClientId');
            data.clients.forEach(client => {
                const option = document.createElement('option');
                option.value = client.id;
                option.textContent = `${client.name} (${client.email})`;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading clients:', error);
    }
}

// Open assign modal
function openAssignModal() {
    document.getElementById('assignEmailId').value = currentEmailId;
    const assignModal = new bootstrap.Modal(document.getElementById('assignModal'));
    const detailsModal = bootstrap.Modal.getInstance(document.getElementById('emailDetailsModal'));
    if (detailsModal) {
        detailsModal.hide();
    }
    assignModal.show();
}

// Open reply modal
function openReplyModal() {
    if (!currentEmailData) return;

    document.getElementById('replyEmailId').value = currentEmailId;
    document.getElementById('replyTo').value = currentEmailData.from_email || '';

    const subject = currentEmailData.subject || '';
    document.getElementById('replySubject').value = subject.startsWith('Re: ') ? subject : 'Re: ' + subject;

    // Quote original message
    const originalText = getEmailMessageText(currentEmailData);
    const date = currentEmailData.received_at ? formatDateTime(currentEmailData.received_at) : '';
    const quoted = '\n\n---\nOn ' + date + ', ' + (currentEmailData.from_email || '') + ' wrote:\n' +
        originalText.split('\n').map(l => '> ' + l).join('\n');
    document.getElementById('replyBody').value = quoted;

    document.getElementById('replyFormAlert').className = 'alert d-none';

    const detailsModal = bootstrap.Modal.getInstance(document.getElementById('emailDetailsModal'));
    if (detailsModal) {
        detailsModal.hide();
    }
    const replyModal = new bootstrap.Modal(document.getElementById('replyModal'));
    replyModal.show();
}

// Send reply to unmatched email sender
async function sendReply() {
    const emailId = document.getElementById('replyEmailId').value;
    const subject = document.getElementById('replySubject').value.trim();
    const bodyHtml = document.getElementById('replyBody').value.trim();

    if (!subject || !bodyHtml) {
        showReplyAlert('Subject and message body are required.', 'danger');
        return;
    }

    const btn = document.getElementById('sendReplyBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    try {
        const response = await fetch('unmatched_emails_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: parseInt(emailId),
                action: 'reply',
                subject: subject,
                body_html: bodyHtml
            })
        });

        const data = await response.json();

        if (data.success) {
            showReplyAlert('Reply sent successfully!', 'success');
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('replyModal')).hide();
            }, 1500);
        } else {
            showReplyAlert('Error: ' + (data.error || 'Failed to send reply'), 'danger');
        }
    } catch (error) {
        console.error('Error sending reply:', error);
        showReplyAlert('Error sending reply: ' + error.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reply';
    }
}

// Show alert in reply form
function showReplyAlert(message, type) {
    const alertDiv = document.getElementById('replyFormAlert');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.textContent = message;
}

// Assign email to client
async function assignEmail() {
    const emailId = document.getElementById('assignEmailId').value;
    const clientId = document.getElementById('assignClientId').value;
    const createClientEmail = document.getElementById('createClientEmail').checked;
    
    if (!clientId) {
        alert('Please select a client');
        return;
    }
    
    try {
        const response = await fetch('unmatched_emails_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: emailId,
                action: 'assign',
                client_id: clientId,
                create_client_email: createClientEmail
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Email assigned successfully!');
            const assignModal = bootstrap.Modal.getInstance(document.getElementById('assignModal'));
            assignModal.hide();
            reloadCurrentEmailFilter();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error assigning email:', error);
        alert('Error assigning email');
    }
}

// Archive email
async function archiveEmail() {
    if (!confirm('Archive this email?')) return;
    
    try {
        const response = await fetch('unmatched_emails_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: currentEmailId,
                action: 'archive'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Email archived');
            const modal = bootstrap.Modal.getInstance(document.getElementById('emailDetailsModal'));
            if (modal) {
                modal.hide();
            }
            reloadCurrentEmailFilter();
        }
    } catch (error) {
        console.error('Error archiving email:', error);
        alert('Error archiving email');
    }
}

// Unarchive email
async function unarchiveEmail() {
    try {
        const response = await fetch('unmatched_emails_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: currentEmailId,
                action: 'unarchive'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Email unarchived');
            const modal = bootstrap.Modal.getInstance(document.getElementById('emailDetailsModal'));
            if (modal) {
                modal.hide();
            }
            reloadCurrentEmailFilter();
        }
    } catch (error) {
        console.error('Error unarchiving email:', error);
        alert('Error unarchiving email');
    }
}

// Delete email
async function deleteEmail() {
    if (!confirm('Permanently delete this email? This cannot be undone.')) return;
    
    try {
        const response = await fetch(`unmatched_emails_api.php?id=${currentEmailId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Email deleted');
            const modal = bootstrap.Modal.getInstance(document.getElementById('emailDetailsModal'));
            if (modal) {
                modal.hide();
            }
            reloadCurrentEmailFilter();
        }
    } catch (error) {
        console.error('Error deleting email:', error);
        alert('Error deleting email');
    }
}

async function cleanupMissingTimestampEmails() {
    if (!confirm('Permanently delete all unmatched emails that are missing timestamps? This action cannot be undone.')) return;

    const btn = document.getElementById('cleanupEmailsBtn');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cleaning...';

    try {
        const response = await fetch('unmatched_emails_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'cleanup_missing_timestamps',
                csrf_token: cleanupCsrfToken
            })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || data.error || 'Failed to clean unmatched emails');
        }

        alert(data.message || 'Cleanup completed.');
        reloadCurrentEmailFilter();
    } catch (error) {
        console.error('Error cleaning unmatched emails:', error);
        alert('Error cleaning unmatched emails: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

// Open compose modal with optional prefill data
function openComposeModal(prefillData) {
    document.getElementById('composeTo').value = (prefillData && prefillData.to) ? prefillData.to : '';
    document.getElementById('composeCC').value = (prefillData && prefillData.cc) ? prefillData.cc : '';
    document.getElementById('composeBCC').value = (prefillData && prefillData.bcc) ? prefillData.bcc : '';
    document.getElementById('composeSubject').value = (prefillData && prefillData.subject) ? prefillData.subject : '';
    if (window.composeEditor) {
        window.composeEditor.setData('');
    } else {
        document.getElementById('composeBody').value = '';
    }
    document.getElementById('composeFormAlert').className = 'alert d-none';

    const detailsModal = bootstrap.Modal.getInstance(document.getElementById('emailDetailsModal'));
    if (detailsModal) {
        detailsModal.hide();
    }

    const composeModal = new bootstrap.Modal(document.getElementById('composeModal'));
    composeModal.show();
}

// Open compose modal prefilled from currently viewed unmatched email
function openComposeFromEmail() {
    if (!currentEmailData) return;
    openComposeModal({ to: currentEmailData.from_email || '' });
}

// Send composed email
async function sendComposedEmail() {
    const to = document.getElementById('composeTo').value.trim();
    const cc = document.getElementById('composeCC').value.trim();
    const bcc = document.getElementById('composeBCC').value.trim();
    const subject = document.getElementById('composeSubject').value.trim();
    const bodyHtml = window.composeEditor
        ? window.composeEditor.getData().trim()
        : document.getElementById('composeBody').value.trim();

    if (!to || !subject || !bodyHtml) {
        showComposeAlert('To, Subject, and Message Body are required.', 'danger');
        return;
    }

    const btn = document.getElementById('sendComposeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    try {
        const response = await fetch('unmatched_emails_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'compose',
                to: to,
                cc: cc,
                bcc: bcc,
                subject: subject,
                body_html: bodyHtml
            })
        });

        const data = await response.json();

        if (data.success) {
            showComposeAlert('Email sent successfully!', 'success');
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('composeModal')).hide();
            }, 1500);
        } else {
            showComposeAlert('Error: ' + (data.error || 'Failed to send email'), 'danger');
        }
    } catch (error) {
        console.error('Error sending email:', error);
        showComposeAlert('Error sending email: ' + error.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Email';
    }
}

// Show alert in compose form
function showComposeAlert(message, type) {
    const alertDiv = document.getElementById('composeFormAlert');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.textContent = message;
}

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>

<!-- CKEditor 5 Rich Text Editor (Self-Hosted, GPL License) -->
<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css" />
<script type="module">
import {
    ClassicEditor,
    Essentials,
    Bold,
    Italic,
    Underline,
    Paragraph,
    Heading,
    Link,
    List,
    Alignment,
    SourceEditing,
    GeneralHtmlSupport
} from './js/ckeditor5/ckeditor5.js';

// Initialize CKEditor 5 for compose email modal (email-optimized preset)
// Lazy-initialize on first modal show so the element is visible
const composeModal = document.getElementById('composeModal');
let editorInitialized = false;

composeModal.addEventListener('shown.bs.modal', function () {
    if (editorInitialized) return;
    editorInitialized = true;

    ClassicEditor
        .create(document.querySelector('#composeBody'), {
            licenseKey: 'GPL',
            plugins: [
                Essentials, Bold, Italic, Underline,
                Paragraph, Heading, Link, List,
                Alignment, SourceEditing, GeneralHtmlSupport
            ],
            toolbar: [
                'undo', 'redo', '|',
                'heading', '|',
                'bold', 'italic', 'underline', '|',
                'link', '|',
                'bulletedList', 'numberedList', '|',
                'alignment', '|',
                'sourceEditing'
            ],
            heading: {
                options: [
                    { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                    { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                    { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
                ]
            },
            htmlSupport: {
                allow: [
                    {
                        name: /.*/,
                        attributes: true,
                        classes: true,
                        styles: true
                    }
                ]
            }
        })
        .then(editor => {
            window.composeEditor = editor;
            // Sync with textarea on change
            editor.model.document.on('change:data', () => {
                document.querySelector('#composeBody').value = editor.getData();
            });
        })
        .catch(error => {
            console.error('CKEditor initialization error (composeBody):', error);
        });
});
</script>
