const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require('playwright');
(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox']});
    try {
        const page = await browser.newPage();
        let requests = 0;
        await page.route('**/*', route => { requests++; return route.abort(); });
        await page.setContent('<div id="body"></div><div id="attachments"></div><input id="files" type="file" multiple><div id="modal"></div><button id="send"></button>');
        await page.addScriptTag({path: path.resolve(__dirname, '../assets/js/email-attachments.js')});
        const result = await page.evaluate(() => {
            const payload = '<img src="https://example.invalid/leak" onerror="window.attachmentXss=1"><script>window.attachmentXss=2</script><svg><a><animate attributeName="href" values="https://example.invalid/"></animate>X</a></svg><template shadowrootmode="open"><a href="https://example.invalid/">Bad</a></template><meta http-equiv="refresh" content="0;url=https://example.invalid/"><p>Hello &amp; safe</p>';
            BdtaEmailUI.renderBody(document.getElementById('body'), {body_html: payload});
            BdtaEmailUI.renderAttachments(document.getElementById('attachments'), {attachments_processed: 1, attachments: [
                {id: 4, name: '<img src=x onerror=alert(1)>.pdf', size: 10, status: 'available'},
                {id: '1&path=/etc/passwd', name: 'bad', size: 0, status: 'available'},
                {id: 5, name: 'Large file', status: 'unavailable', message: '<script>bad</script>'}
            ]});
            BdtaEmailUI.attachSelection(document.getElementById('files'), document.getElementById('modal'), document.getElementById('send'));
            const data = new DataTransfer();data.items.add(new File(['safe'], '<x>.txt'));
            document.getElementById('files').files=data.files;
            document.getElementById('files').dispatchEvent(new Event('change'));
            const fd=BdtaEmailUI.formData({subject:'Test'},document.getElementById('files'));
            const attached=fd.get('attachments[]').name;
            document.getElementById('send').disabled=true;
            const close=new Event('hide.bs.modal',{cancelable:true});document.getElementById('modal').dispatchEvent(close);
            document.getElementById('send').disabled=false;
            document.getElementById('modal').dispatchEvent(new Event('hidden.bs.modal'));
            return {text:document.getElementById('body').textContent,children:document.getElementById('body').children.length,
                xss:window.attachmentXss||0,links:Array.from(document.querySelectorAll('#attachments a')).map(a=>a.getAttribute('href')),
                maliciousNodes:document.querySelectorAll('#attachments img,#attachments script').length,attached,
                blockedClose:close.defaultPrevented,cleared:document.getElementById('files').files.length===0};
        });
        assert.equal(result.children,0);assert.equal(result.xss,0);assert.match(result.text,/Hello & safe/);
        assert.deepEqual(result.links,['email_attachments_download.php?id=4']);assert.equal(result.maliciousNodes,0);
        assert.equal(result.attached,'<x>.txt');assert.equal(result.blockedClose,true);assert.equal(result.cleared,true);
        await page.waitForTimeout(250);assert.equal(requests,0,'Untrusted email must not cause network requests');
        console.log('PASS: actual Chromium inert body, hostile filenames, safe attachment IDs, upload, pending-send close protection and cancel reset.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
