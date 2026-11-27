/**
 * Prosper202's one-time usage-data question.
 *
 * Mounted by 202-config/template.php only when ConsentPolicy::takePrompt()
 * says so: the user has made no choice and has not been asked before. It is
 * asked once and never again, answered or not; the choice stays in Account
 * Settings. Nothing is sent until the user says yes, so "No thanks" and
 * leaving the question alone both keep usage data off. The two answers are
 * the same kind of button, side by side.
 *
 * Posts the choice to 202-account/ajax/messaging/consent.php with
 * source=prompt and removes itself only after the choice is saved.
 */
(function () {
    'use strict';

    var mount = document.getElementById('p202-consent-banner');
    if (!mount) { return; }

    var base = mount.getAttribute('data-base') || '/';
    var token = mount.getAttribute('data-token') || '';

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text) { node.textContent = text; }
        return node;
    }

    var wrap = el('div', 'position-fixed bottom-0 start-0 p-3');
    wrap.style.zIndex = '1080';
    wrap.style.maxWidth = '440px';

    var card = el('section', 'card shadow');
    card.setAttribute('aria-labelledby', 'p202-consent-title');
    var body = el('div', 'card-body');

    var title = el('h2', 'h6 card-title', 'Share how you use Prosper202?');
    title.id = 'p202-consent-title';

    var why = el('p', 'small mb-2',
        'If you say yes, the tips, guides and announcements Tracking202 sends you are picked for how ' +
        'you use Prosper202, instead of being the same for everyone.');

    var sent = el('p', 'small mb-2');
    sent.appendChild(el('strong', '', 'Sent: '));
    sent.appendChild(document.createTextNode(
        'your account email and sign-up date; 30-day totals of clicks, conversions, revenue and cost; ' +
        'your network, traffic source and campaign names, payouts and the shape of their links; ' +
        'Customer LTV account totals; and which pages and features you use.'));

    var never = el('p', 'small mb-2');
    never.appendChild(el('strong', '', 'Never sent: '));
    never.appendChild(document.createTextNode('your visitors’ or your customers’ details.'));

    var change = el('p', 'small text-body-secondary mb-3',
        'Nothing is sent unless you say yes. You can change this any time in Account Settings. ');
    var learnMore = el('a', '', 'Full details');
    learnMore.href = base + '202-account/disclosure.php';
    learnMore.target = '_blank';
    learnMore.rel = 'noopener';
    change.appendChild(learnMore);

    var actions = el('div', 'd-flex gap-2');
    var accept = el('button', 'btn btn-sm btn-outline-primary', 'Yes, share usage');
    accept.type = 'button';
    var decline = el('button', 'btn btn-sm btn-outline-primary', 'No thanks');
    decline.type = 'button';

    var error = el('p', 'small text-danger mt-2 mb-0');
    error.setAttribute('role', 'alert');

    function remove() {
        if (mount.parentNode) { mount.parentNode.removeChild(mount); }
    }

    function send(state) {
        if (accept.disabled || decline.disabled) { return; }
        accept.disabled = true;
        decline.disabled = true;
        error.textContent = '';
        var params = new URLSearchParams();
        params.append('flag', 'analytics');
        params.append('state', state);
        params.append('source', 'prompt');
        params.append('token', token);
        fetch(base + '202-account/ajax/messaging/consent.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(function (response) {
            if (!response.ok) { throw new Error('Could not save choice'); }
            return response.json();
        }).then(function (result) {
            if (!result || result.ok !== true) { throw new Error('Could not save choice'); }
            remove();
        }).catch(function () {
            error.textContent = 'Your choice could not be saved. Please try again, or set it in Account Settings.';
            accept.disabled = false;
            decline.disabled = false;
        });
    }

    accept.addEventListener('click', function () { send('granted'); });
    decline.addEventListener('click', function () { send('denied'); });

    actions.appendChild(accept);
    actions.appendChild(decline);
    body.appendChild(title);
    body.appendChild(why);
    body.appendChild(sent);
    body.appendChild(never);
    body.appendChild(change);
    body.appendChild(actions);
    body.appendChild(error);
    card.appendChild(body);
    wrap.appendChild(card);
    mount.appendChild(wrap);
})();
