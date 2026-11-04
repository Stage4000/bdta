const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const source = fs.readFileSync(__dirname + '/../client/clients_view.php', 'utf8');
let checks = 0;
function check(value, label) { assert.ok(value, label); ++checks; }
const checkbox = source.match(/<input[^>]*id="requestReadReceipt"[^>]*>/)[0];
check(!/\schecked(?:\s|=|>)/.test(checkbox), 'Opt-in defaults off');
check(/aria-describedby="readReceiptHelp"/.test(checkbox), 'Accessible limitations description');
check(source.includes('No receipt means unknown, not unread.'), 'No-response limitation explained');
const badge = source.slice(source.indexOf('function getReadReceiptBadge('), source.indexOf('// Get human-readable label for mail type'));
const context = {}; vm.createContext(context); vm.runInContext(badge, context);
const badgeFor = context.getReadReceiptBadge;
check(badgeFor({direction:'incoming',read_receipt_requested:1}) === '', 'Incoming mail not labeled as requested');
check(badgeFor({direction:'outgoing'}).includes('not requested'), 'Legacy mail not enrolled');
check(badgeFor({direction:'outgoing',read_receipt_requested:'1',status:'sent'}).includes('no receipt yet'), 'Unknown remains unknown');
check(badgeFor({direction:'outgoing',read_receipt_requested:1,status:'scheduled'}).includes('when sent'), 'Scheduled not falsely requested already');
check(badgeFor({direction:'outgoing',read_receipt_requested:1,status:'failed'}).includes('failed or uncertain'), 'Failure not confused with receipt');
check(badgeFor({direction:'outgoing',read_receipt_requested:1,status:'sent',read_receipt_reported_at:'2026-11-02'}).includes('displayed (unverified)'), 'Reported display not proof of reading');
const input = {checked:true}; let hide;
const document = {getElementById(id) { if(id==='requestReadReceipt') return input; return {addEventListener(event,fn) {assert.equal(event,'hidden.bs.modal');hide=fn;}}; }};
const reset = source.match(/document\.getElementById\('composeEmailModal'\)\.addEventListener\('hidden\.bs\.modal',[\s\S]*?\n\}\);/)[0];
vm.runInNewContext(reset,{document});
hide(); check(input.checked===false,'Cancel/close clears opt-in');
input.checked=true;hide();hide();check(input.checked===false,'Repeated dismissal never restores opt-in');
const reply = source.slice(source.indexOf('async function replyToEmail('), source.indexOf('// Handle template selection'));
check(reply.includes("document.getElementById('requestReadReceipt').checked = false;"),'Reply starts with opt-in off');
check(source.includes("read_receipt_requested: document.getElementById('requestReadReceipt').checked ? 1 : 0"),'Payload uses explicit per-draft boolean');
check(source.includes('form.reset();'),'Successful send resets draft');
// Execute the actual feedback lifecycle with a deterministic clock. Old draft
// feedback must not survive opening a new draft or shorten a newer alert's life.
const feedbackStart = source.indexOf('// Composer feedback belongs to the current opening, not a later draft.');
check(feedbackStart >= 0, 'Composer feedback lifecycle exists');
const feedbackSetup = source.slice(feedbackStart, source.indexOf('// Handle send email', feedbackStart));
const feedbackFunction = source.slice(source.indexOf('function showFormAlert('), source.indexOf('// Load emails when the email tab is shown'));
let opening, now = 0, nextTimer = 0;
const timers = new Map();
const feedback = {textContent:'',className:'alert d-none'};
feedback.classList = {
    add(name) { if (!this.contains(name)) feedback.className += ' ' + name; },
    remove(name) { feedback.className = feedback.className.split(/\s+/).filter(value => value !== name).join(' '); },
    contains(name) { return feedback.className.split(/\s+/).includes(name); }
};
const feedbackContext = {
    document: {getElementById(id) {
        if (id === 'emailFormAlert') return feedback;
        assert.equal(id, 'composeEmailModal');
        return {addEventListener(event,fn) {assert.equal(event,'show.bs.modal');opening=fn;}};
    }},
    setTimeout(fn,delay) { const id=++nextTimer;timers.set(id,{fn,at:now+delay});return id; },
    clearTimeout(id) { timers.delete(id); }
};
vm.runInNewContext(feedbackSetup + '\n' + feedbackFunction,feedbackContext);
function advance(milliseconds) {
    now += milliseconds;
    for (const [id,timer] of timers) if (timer.at <= now) {timers.delete(id);timer.fn();}
}
feedbackContext.showFormAlert('Old draft succeeded','success');
check(!feedback.classList.contains('d-none'), 'Feedback becomes visible');
advance(4000);
feedbackContext.showFormAlert('Current draft failed','danger');
check(timers.size === 1, 'New feedback replaces prior timer');
advance(1001);
check(feedback.textContent === 'Current draft failed' && !feedback.classList.contains('d-none'), 'Older timer cannot hide newer feedback');
advance(3999);
check(feedback.classList.contains('d-none') && timers.size === 0, 'Current feedback expires after its own five seconds');
feedbackContext.showFormAlert('Previous opening failed','danger');
advance(4000);opening();
check(feedback.textContent === '' && feedback.className === 'alert d-none', 'Opening a new draft clears feedback and styling');
check(timers.size === 0, 'Opening cancels old feedback timer');
feedbackContext.showFormAlert('New opening succeeded','success');
advance(1001);
check(feedback.textContent === 'New opening succeeded' && !feedback.classList.contains('d-none'), 'Previous opening cannot erase new feedback');
advance(3999);opening();opening();
check(feedback.textContent === '' && feedback.className === 'alert d-none' && timers.size === 0, 'Repeated opening remains clean');
console.log(`PASS: ${checks} receipt UI logic checks (not rendered browser acceptance).`);
