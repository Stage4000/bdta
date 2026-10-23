"""Static wiring/syntax checks only. Does not substitute for browser or HTTP tests."""
from pathlib import Path
import re
import subprocess
ROOT=Path(__file__).resolve().parents[1]
for page in ['client/clients_view.php','client/unmatched_emails_list.php']:
    source=(ROOT/page).read_text()
    scripts=re.findall(r'<script\b[^>]*>(.*?)</script>',source,flags=re.S|re.I)
    for number,script in enumerate(scripts):
        code=re.sub(r'<\?.*?\?>','null',script,flags=re.S)
        result=subprocess.run(['node','--input-type=module','--check'],input=code,text=True,capture_output=True)
        assert result.returncode==0,f'{page} inline script {number}: {result.stderr}'
    assert 'BdtaEmailUI.renderAttachments' in source
    assert 'BdtaEmailUI.renderBody' in source
    assert 'BdtaEmailUI.formData' in source
    assert 'messageContainer.innerHTML = email.body_html' not in source
    assert 'tmp.innerHTML = email.body_html' not in source
    print('PASS: '+page+' inline JS parses and safe shared helpers wired')
helper=(ROOT/'assets/js/email-attachments.js').read_text()
assert 'container.textContent = messageText(email)' in helper
assert "document.createElement('iframe')" not in helper
assert 'link.textContent =' in helper
assert 'Number.isSafeInteger(id)' in helper
endpoint=(ROOT/'client/email_attachments_download.php').read_text()
for required in ['requireLogin();','EXISTS (SELECT 1 FROM client_emails','EXISTS (SELECT 1 FROM unmatched_emails','application/octet-stream','Content-Disposition: attachment','X-Content-Type-Options: nosniff','private, no-store','bdta_email_verified_attachment_path']:
    assert required in endpoint,required
for api in ['client/client_emails_api.php','client/unmatched_emails_api.php']:
    assert "['POST', 'DELETE']" in (ROOT/api).read_text()
    assert 'isValidCsrfToken' in (ROOT/api).read_text()
assert 'bdta_email_attachments' in (ROOT/'backend/cron/tasks/scheduled_email_sender.php').read_text()
assert 'FT_PEEK' in (ROOT/'backend/includes/imap_receiver.php').read_text()
print('PASS: strict download headers/auth/parent checks, CSRF, scheduled attachments, and IMAP PEEK wired')
