<?php
/**
 * Unmatched Emails - Manage emails from unknown senders
 */

require_once __DIR__ . '/../backend/includes/config.php';
requireLogin();

$page_title = 'Unmatched Emails';
include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-inbox"></i> Unmatched Emails</h2>
            <p class="text-muted">Manage emails from senders not in your client database</p>
        </div>
        <div>
            <span class="badge bg-warning fs-5" id="unassignedCount">0</span>
            <span class="text-muted ms-2">Unassigned</span>
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

<script>
let currentEmailId = null;
let currentEmailData = null;

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

// Load emails based on filter
async function loadEmails(filter) {
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

// Display emails in the list
function displayEmails(emails, filter) {
    const containerId = filter + 'Emails';
    const container = document.getElementById(containerId);
    
    if (emails.length === 0) {
        container.innerHTML = '<div class="alert alert-info">No emails found.</div>';
        return;
    }
    
    let html = '<div class="list-group">';
    
    emails.forEach(email => {
        const date = new Date(email.received_at).toLocaleString();
        const assignedBadge = email.is_assigned ? `<span class="badge bg-success">Assigned to ${escapeHtml(email.assigned_client_name)}</span>` : '';
        const archivedBadge = email.is_archived ? '<span class="badge bg-secondary">Archived</span>' : '';
        
        html += `
            <div class="list-group-item list-group-item-action" onclick="showEmailDetails(${email.id})">
                <div class="d-flex w-100 justify-content-between">
                    <div>
                        <h6 class="mb-1">${escapeHtml(email.subject)}</h6>
                        <p class="mb-1 text-muted small">
                            From: ${escapeHtml(email.from_name || email.from_email)}
                            ${email.from_name ? `&lt;${escapeHtml(email.from_email)}&gt;` : ''}
                        </p>
                        ${assignedBadge} ${archivedBadge}
                    </div>
                    <small class="text-muted">${date}</small>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

// Show email details in modal
async function showEmailDetails(emailId) {
    currentEmailId = emailId;
    
    try {
        const response = await fetch(`unmatched_emails_api.php?id=${emailId}`);
        const data = await response.json();
        
        if (data.success) {
            const email = data.email;
            currentEmailData = email;
            const modal = new bootstrap.Modal(document.getElementById('emailDetailsModal'));
            
            const body = document.getElementById('emailDetailsBody');
            body.innerHTML = `
                <dl class="row">
                    <dt class="col-sm-3">From:</dt>
                    <dd class="col-sm-9">${escapeHtml(email.from_name || email.from_email)} ${email.from_name ? `&lt;${escapeHtml(email.from_email)}&gt;` : ''}</dd>
                    
                    <dt class="col-sm-3">To:</dt>
                    <dd class="col-sm-9">${escapeHtml(email.to_email)}</dd>
                    
                    <dt class="col-sm-3">Subject:</dt>
                    <dd class="col-sm-9">${escapeHtml(email.subject)}</dd>
                    
                    <dt class="col-sm-3">Received:</dt>
                    <dd class="col-sm-9">${new Date(email.received_at).toLocaleString()}</dd>
                    
                    ${email.is_assigned ? `
                        <dt class="col-sm-3">Assigned to:</dt>
                        <dd class="col-sm-9">${escapeHtml(email.assigned_client_name)} (${new Date(email.assigned_at).toLocaleString()})</dd>
                    ` : ''}
                </dl>
                
                <hr>
                
                <h6>Message</h6>
                <div class="border p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                    ${email.body_html || escapeHtml(email.body_text)}
                </div>
            `;
            
            // Show action buttons
            const footer = document.getElementById('emailDetailsFooter');
            footer.innerHTML = '';
            
            if (email.from_email) {
                footer.innerHTML += `<button type="button" class="btn btn-primary" onclick="openReplyModal()"><i class="fas fa-reply"></i> Reply</button>`;
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
        }
    } catch (error) {
        console.error('Error loading email details:', error);
        alert('Error loading email details');
    }
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
    detailsModal.hide();
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
    let originalText = currentEmailData.body_text || '';
    if (!originalText && currentEmailData.body_html) {
        const tmp = document.createElement('div');
        tmp.innerHTML = currentEmailData.body_html;
        originalText = tmp.textContent || tmp.innerText || '';
    }
    const date = currentEmailData.received_at ? new Date(currentEmailData.received_at).toLocaleString() : '';
    const quoted = '\n\n---\nOn ' + date + ', ' + (currentEmailData.from_email || '') + ' wrote:\n' +
        originalText.split('\n').map(l => '> ' + l).join('\n');
    document.getElementById('replyBody').value = quoted;

    document.getElementById('replyFormAlert').className = 'alert d-none';

    const detailsModal = bootstrap.Modal.getInstance(document.getElementById('emailDetailsModal'));
    detailsModal.hide();
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
            loadEmails('unassigned');
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
            modal.hide();
            loadEmails('unassigned');
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
            modal.hide();
            loadEmails('archived');
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
            modal.hide();
            loadEmails('unassigned');
        }
    } catch (error) {
        console.error('Error deleting email:', error);
        alert('Error deleting email');
    }
}

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
