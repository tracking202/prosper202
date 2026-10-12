'use strict';

/*
 * The chat box (202-js/p202-chat.js): the parts a request cannot reach.
 *
 * Stood in for: chat-pass.php's answer. This pass runs with no portal and no
 * chat service, so the real endpoint answers 409 or 502 and the box would
 * build in its unavailable state or not at all. Each scenario serves the
 * answer it needs in place of that one response; the script, its idle start,
 * its command queue and the page around it are real.
 */

const PASS_URL = '**/202-account/ajax/chat-pass.php';

function unavailable(route) {
  return route.fulfill({ status: 502, contentType: 'application/json', body: '{"ok":false,"error":"chat_unavailable"}' });
}

// A pass for a chat service that is not there: the box keeps it and shows
// itself unavailable, which is all these scenarios need.
function aPass(route) {
  return route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      ok: true,
      pass: 'cht1.e30.c3RhbmQtaW4',
      chat_url: 'http://127.0.0.1:9',
      expires_at_s: Math.floor(Date.now() / 1000) + 86400,
      user: { name: 'Stand-in' },
    }),
  });
}

// The chat service aPass names, stood in for: answers its HTTP calls (CORS
// preflight included) with what `answer` returns (or resolves to) for each.
async function standInService(page, answer) {
  await page.route('http://127.0.0.1:9/**', async (route) => {
    const cors = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Headers': 'Authorization, Content-Type',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    };
    if (route.request().method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers: cors });
    }
    const [status, body] = await answer(route.request());
    if (status === 204) {
      return route.fulfill({ status, headers: cors });
    }
    return route.fulfill({ status, headers: cors, contentType: 'application/json', body: JSON.stringify(body) });
  });
}

const EMPTY_STATE = { conversations: [], messages: [], seq: 0 };

function chatConv(over) {
  const now = Date.now();
  return { id: 7, type: 'chat', subject: null, status: 'open', created_at: now - 60000, last_message_at: now, team_read_upto: 0, unread: 1, rev: 1, ...over };
}

function teamMessage(seq, body) {
  return { kind: 'message', seq, conv: 7, author: 'team', name: 'Sam', body, at: Date.now() };
}

function customerMessage(seq, body) {
  return { kind: 'message', seq, conv: 7, author: 'customer', name: '', body, at: Date.now() };
}

// Message text, and the parts its bubble must be built from, in order: a
// text node ({text}) or a link whose href and label are exactly the address
// ({url}). The cases for linkParts() in 202-js/p202-chat.js, run through the
// bubble it builds rather than on their own: the function is private to the
// script, and the bubble is the only thing it is for.
const T = (text) => ({ text });
const U = (url) => ({ url });
const LONGEST = 'https://x.test/' + 'a'.repeat(2000 - 'https://x.test/'.length);
// Characters that are invisible or turn the text's direction, by code point
// (U+00AD, U+061C, U+200B-U+200F, U+202A-U+202E, U+2060-U+2064,
// U+2066-U+2069, U+FEFF): an address stops at each, as at whitespace.
const INVISIBLE = [
  0x00ad, 0x061c, 0x200b, 0x200c, 0x200d, 0x200e, 0x200f, 0x202a, 0x202b, 0x202c, 0x202d,
  0x202e, 0x2060, 0x2061, 0x2062, 0x2063, 0x2064, 0x2066, 0x2067, 0x2068, 0x2069, 0xfeff,
];
const codePoint = (cp) => 'U+' + cp.toString(16).toUpperCase().padStart(4, '0');
const RLO = String.fromCharCode(0x202e);
const FORBIDDEN = [' ', '\t', '\n', '\u00a0', '"', '\'', '<', '>', '\\', '^', '`', '{', '|', '}'];
const LINK_CASES = [
  ['plain text', 'No address here, just words.', [T('No address here, just words.')]],
  ['one address', 'The guide: https://x.test/docs/install', [T('The guide: '), U('https://x.test/docs/install')]],
  ['several addresses', 'Try https://a.test/one or http://b.test/two, then https://c.test',
    [T('Try '), U('https://a.test/one'), T(' or '), U('http://b.test/two'), T(', then '), U('https://c.test')]],
  ['an address at the start', 'https://x.test/start is the place', [U('https://x.test/start'), T(' is the place')]],
  ['an address at the end', 'It is at https://x.test/end', [T('It is at '), U('https://x.test/end')]],
  ['only an address', 'https://x.test/', [U('https://x.test/')]],
  ...['.', ',', ';', ':', '!', '?'].map((p) => [
    'a trailing ' + p, 'See https://x.test/p' + p + ' next', [T('See '), U('https://x.test/p'), T(p + ' next')],
  ]),
  ['a run of trailing punctuation', 'https://x.test/q?a=1!?;:,.', [U('https://x.test/q?a=1'), T('!?;:,.')]],
  ['punctuation inside an address', 'https://x.test/a.b,c;d:e!f?g=h', [U('https://x.test/a.b,c;d:e!f?g=h')]],
  ['a balanced )', 'https://en.wikipedia.org/wiki/Foo_(bar)', [U('https://en.wikipedia.org/wiki/Foo_(bar)')]],
  ['an unbalanced )', '(see https://x.test/a)', [T('(see '), U('https://x.test/a'), T(')')]],
  ['a balanced ) inside an unbalanced one', '(https://en.wikipedia.org/wiki/Foo_(bar))',
    [T('('), U('https://en.wikipedia.org/wiki/Foo_(bar)'), T(')')]],
  ['an unbalanced ) then a full stop', '(at https://x.test/a).', [T('(at '), U('https://x.test/a'), T(').')]],
  ['a balanced ]', 'https://x.test/list[1]', [U('https://x.test/list[1]')]],
  ['an unbalanced ]', '[https://x.test/a]', [T('['), U('https://x.test/a'), T(']')]],
  ['a } (never in an address)', '{https://x.test/a}', [T('{'), U('https://x.test/a'), T('}')]],
  ...FORBIDDEN.map((c) => [
    'stops at ' + (c === '\u00a0' ? 'a no-break space' : JSON.stringify(c)), 'https://x.test/a' + c + 'b', [U('https://x.test/a'), T(c + 'b')],
  ]),
  ...INVISIBLE.map((cp) => [
    'stops at ' + codePoint(cp), 'https://x.test/a' + String.fromCharCode(cp) + 'b',
    [U('https://x.test/a'), T(String.fromCharCode(cp) + 'b')],
  ]),
  ['a right-to-left override cannot disguise the address', 'https://bank.com/' + RLO + 'txt.exe',
    [U('https://bank.com/'), T(RLO + 'txt.exe')]],
  ['javascript: is not linked', 'javascript:alert(1)', [T('javascript:alert(1)')]],
  ['data: is not linked', 'data:text/html,<b>x</b>', [T('data:text/html,<b>x</b>')]],
  ['mailto: is not linked', 'mailto:help@x.test', [T('mailto:help@x.test')]],
  ['a bare www. is not linked', 'www.x.test/a', [T('www.x.test/a')]],
  ['ftp:// is not linked', 'ftp://x.test/file', [T('ftp://x.test/file')]],
  ['a scheme with nothing after it', 'https:// and https://. and http://)', [T('https:// and https://. and http://)')]],
  ['an uppercase scheme', 'HTTPS://X.TEST/Path and Http://x.test/b', [U('HTTPS://X.TEST/Path'), T(' and '), U('Http://x.test/b')]],
  ['2000 characters', LONGEST, [U(LONGEST)]],
  ['2000 characters and a full stop (2001 matched, so all text)', LONGEST + '.', [T(LONGEST + '.')]],
  ['2001 characters', LONGEST + 'a', [T(LONGEST + 'a')]],
  ['markup stays text', '<img src=x onerror="window.__p202Xss=1">', [T('<img src=x onerror="window.__p202Xss=1">')]],
  ['markup after an address stays text', 'https://x.test/"><img src=x onerror="window.__p202Xss=1">',
    [U('https://x.test/'), T('"><img src=x onerror="window.__p202Xss=1">')]],
  ['line breaks and spaces are kept', 'one https://x.test/a\n  two\tthree\n\nhttps://x.test/b',
    [T('one '), U('https://x.test/a'), T('\n  two\tthree\n\n'), U('https://x.test/b')]],
];

// Bodies at the chat service's cap (10,000 characters, as the composer's
// MAX_BODY; the first is 7 over) built so a slow splitter shows: closers a
// naive trim recounts at every step, a balanced opener ahead of them, full
// stops alone and after an address, addresses just inside MAX_URL that each
// have to be trimmed (the only ones the trim ever sees), a near miss of the
// scheme at every position (the regex itself), and as many addresses as fit
// (the loop and the DOM). Each is the only message in its conversation, and
// opening that conversation, which builds the thread and lays it out, must
// take under RENDER_MS every time.
const RENDER_MS = 50;
const RENDER_RUNS = 5;
const HOSTILE = [
  ['"http://" then 10,000 )', 'http://' + ')'.repeat(10000), 0],
  ['"(http://a)" then 9,990 )', '(http://a)' + ')'.repeat(9990), 0],
  ['10,000 full stops', '.'.repeat(10000), 0],
  ['"http://a" then 9,992 full stops', 'http://a' + '.'.repeat(9992), 0],
  ['five addresses, each followed by 1,991 )', ('http://a' + ')'.repeat(1991) + ' ').repeat(5), 5],
  ['"http:/" over and over, a near miss at every h', 'http:/'.repeat(1667).slice(0, 10000), 0],
  ['1,111 addresses', 'http://a '.repeat(1111), 1111],
];

// In the page: open the conversation titled `name` `runs` times, timing each
// click (the box renders the thread and reads its height before the click
// returns), and report what the last one showed.
function timeOpening({ name, runs }) {
  const times = [];
  let shown = null;
  for (let i = 0; i < runs; i++) {
    const row = Array.from(document.querySelectorAll('.p202-chat__row'))
      .find((r) => r.querySelector('.p202-chat__row-title').textContent === name);
    if (!row) {
      return { error: 'no conversation titled ' + name };
    }
    const started = performance.now();
    row.click();
    times.push(performance.now() - started);
    const bubbles = document.querySelectorAll('.p202-chat__bubble');
    shown = {
      bubbles: bubbles.length,
      text: bubbles[0] ? bubbles[0].textContent : null,
      links: bubbles[0] ? bubbles[0].querySelectorAll('a').length : -1,
    };
    document.querySelector('.p202-chat__back').click();
  }
  return { times, shown };
}

// In the page: each bubble as the parts it was built from, its text, and
// its text as rendered (innerText follows white-space, textContent does not).
function readBubbles(bubbles) {
  return bubbles.map((bubble) => ({
    text: bubble.textContent,
    rendered: bubble.innerText,
    parts: Array.from(bubble.childNodes).map((node) => {
      if (node.nodeType === Node.TEXT_NODE) {
        return { text: node.nodeValue };
      }
      if (node.nodeName === 'A' && node.childNodes.length === 1 && node.firstChild.nodeType === Node.TEXT_NODE
        && node.textContent === node.getAttribute('href')) {
        return { url: node.getAttribute('href') };
      }
      return { other: node.nodeName + ' ' + node.textContent };
    }),
  }));
}

// Parts, readable in a failure: a long string shows its start and length.
function showParts(parts) {
  return JSON.stringify(parts.map((part) => {
    const [kind, value] = Object.entries(part)[0];
    return kind + ':' + (value.length > 48 ? JSON.stringify(value.slice(0, 32)) + '...(' + value.length + ')' : JSON.stringify(value));
  }));
}

// A conversation of these messages, served by the stand-in service, opened.
async function openThreadOf(page, app, ui, messages) {
  await page.route(PASS_URL, aPass);
  const answer = { conversations: [chatConv({ unread: 0 })], messages, seq: messages.length };
  await standInService(page, () => [200, answer]);
  await page.routeWebSocket('ws://127.0.0.1:9/**', (ws) => {
    setTimeout(() => ws.send(JSON.stringify({ t: 'ready', seq: messages.length })), 50);
  });
  await app.goto('/202-account/');
  await ui.until(async () => ui.exists('.p202-chat__launcher'), { describe: 'the chat box to be built' });
  await page.click('.p202-chat__launcher');
  await ui.until(async () => ui.exists('.p202-chat__row'), { describe: 'the conversation to be listed' });
  await page.click('.p202-chat__row');
  await ui.until(async () => (await page.$$('.p202-chat__bubble')).length === messages.length, { describe: 'every message to be shown' });
}

// WCAG contrast of a link's colour against the first opaque background under it.
function linkContrast(links) {
  const channels = (value) => (value.match(/[\d.]+/g) || []).map(Number);
  const luminance = ([r, g, b]) => {
    const lin = (c) => {
      const s = c / 255;
      return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  };
  return links.map((link) => {
    let under = link;
    let background = null;
    while (under && background === null) {
      const c = channels(getComputedStyle(under).backgroundColor);
      if (c.length >= 3 && (c.length < 4 || c[3] === 1)) {
        background = c;
      }
      under = under.parentElement;
    }
    const fg = luminance(channels(getComputedStyle(link).color));
    const bg = luminance(background || [255, 255, 255]);
    return {
      who: link.closest('.p202-chat__msg').className,
      ratio: (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05),
      underline: getComputedStyle(link).textDecorationLine,
      color: getComputedStyle(link).color,
      background: background ? background.join(',') : 'none',
    };
  });
}

// What show/hide/toggle called before the box exists must leave it as, in order.
const SEQUENCES = [
  [['show'], true],
  [['show', 'toggle'], false],
  [['toggle', 'toggle'], false],
  [['hide', 'toggle'], true],
  [['toggle'], true],
  [['show', 'hide'], false],
];

module.exports = {
  name: 'chat-box',
  title: 'The chat box',

  async setup(ctx) {
    await ctx.app.login();
  },

  scenarios: [
    {
      name: 'Show, hide and toggle asked before the box exists replay in order',
      async run(ctx) {
        for (const [commands, open] of SEQUENCES) {
          await ctx.withSession({}, async ({ page, app, ui }) => {
            await page.route(PASS_URL, unavailable);
            await page.addInitScript((cmds) => {
              window.Prosper202Messenger = function () {
                (window.Prosper202Messenger.q = window.Prosper202Messenger.q || []).push(arguments);
              };
              cmds.forEach((c) => window.Prosper202Messenger(c));
            }, commands);
            await app.goto('/202-account/');
            await ui.until(async () => ui.exists('.p202-chat__panel'), { describe: 'the chat box to be built' });
            const isOpen = await page.evaluate(() => !document.querySelector('.p202-chat__panel').hidden);
            ctx.expect.eq(isOpen, open, commands.join(', ') + ' leaves the box ' + (open ? 'open' : 'closed'));
          });
        }
      },
    },
    {
      name: 'Every page asks the install for the pass, and the browser keeps none',
      async run(ctx) {
        const { page, app, ui, expect } = ctx;
        let asks = 0;
        await page.route(PASS_URL, (route) => {
          asks += 1;
          return aPass(route);
        });
        try {
          for (const n of [1, 2]) {
            asks = 0;
            await app.goto('/202-account/');
            await ui.until(async () => asks >= 1, { describe: 'page ' + n + ' to ask chat-pass.php' });
            await ui.until(async () => ui.exists('.p202-chat__panel'), { describe: 'the chat box to be built' });
            expect.eq(asks, 1, 'page ' + n + ' asks chat-pass.php once');
          }
          const keys = await page.evaluate(() => Object.keys(window.sessionStorage).concat(Object.keys(window.localStorage)));
          expect.ok(!keys.some((k) => /chat|pass/i.test(k)), 'nothing of the chat is kept in browser storage', JSON.stringify(keys));
        } finally {
          await page.unroute(PASS_URL);
        }
      },
    },
    {
      // The service opens every socket and closes one whose pass it refuses
      // with 4001. Counting the open as success reset the one-renewal guard
      // and the backoff each time: measured against the real service with a
      // mismatched secret, one tab opened 32 sockets and asked for 33 passes
      // in 20 s, and would have gone on.
      name: 'A socket refused for its pass is renewed once, then left to polling',
      async run(ctx) {
        await ctx.withSession({}, async ({ page, app, expect }) => {
          let refreshes = 0;
          let sockets = 0;
          await page.route(PASS_URL, (route) => {
            if ((route.request().postData() || '').includes('refresh=1')) {
              refreshes += 1;
            }
            return aPass(route);
          });
          await standInService(page, () => [200, EMPTY_STATE]);
          await page.routeWebSocket('ws://127.0.0.1:9/**', (ws) => {
            sockets += 1;
            // Open first, as the service does, then refuse.
            setTimeout(() => ws.close({ code: 4001, reason: 'Pass invalid.' }), 100);
          });
          await app.goto('/202-account/');
          await page.waitForTimeout(12000);
          expect.ok(sockets >= 1 && sockets <= 3, 'the box tried the socket, then stopped', sockets + ' sockets in 12 s');
          expect.ok(refreshes >= 1 && refreshes <= 2, 'and asked for a new pass once, not on every refusal', refreshes + ' refreshes in 12 s');
        });
      },
    },
    {
      // Codex on #207: a retry that succeeded after a renewal did not clear
      // the guard, so a refusal of the very next request could not renew.
      // The stand-in answers 401, 200 (the retry), 401, then 200s.
      name: 'Polling renews a refused pass again after a renewal that worked',
      async run(ctx) {
        await ctx.withSession({}, async ({ page, app, ui, expect }) => {
          let refreshes = 0;
          let answers = 0;
          await page.addInitScript(() => {
            delete window.WebSocket; // the polling fallback
          });
          await page.route(PASS_URL, (route) => {
            if ((route.request().postData() || '').includes('refresh=1')) {
              refreshes += 1;
            }
            return aPass(route);
          });
          await standInService(page, () => {
            answers += 1;
            return answers === 1 || answers === 3
              ? [401, { error: { code: 'unauthorized', message: 'Pass invalid.' } }]
              : [200, EMPTY_STATE];
          });
          await app.goto('/202-account/');
          await ui.until(async () => answers >= 3, { describe: 'the second refusal', timeout: 20000 });
          await ui.until(async () => refreshes === 2, { describe: 'the second refusal to be renewed', timeout: 3000 });
          expect.eq(refreshes, 2, 'a refusal right after a renewal that worked gets its own renewal');
        });
      },
    },
    {
      // Codex on #207: a read receipt answered after a newer team message
      // arrived cleared the count for both, and the badge went dark.
      name: 'A team message newer than a read receipt keeps the badge',
      async run(ctx) {
        await ctx.withSession({}, async ({ page, app, ui, expect }) => {
          let readAsked = false;
          let readAnswered = false;
          let server = null;
          await page.route(PASS_URL, aPass);
          await standInService(page, async (request) => {
            if (request.method() === 'POST' && request.url().includes('/v1/customer/read')) {
              readAsked = true;
              await new Promise((resolve) => setTimeout(resolve, 2000));
              readAnswered = true;
              return [204, null];
            }
            return [200, { conversations: [chatConv()], messages: [teamMessage(1, 'Hi Dana')], seq: 1 }];
          });
          await page.routeWebSocket('ws://127.0.0.1:9/**', (ws) => {
            server = ws;
            setTimeout(() => ws.send(JSON.stringify({ t: 'ready', seq: 1 })), 50);
          });
          await app.goto('/202-account/');
          await ui.until(async () => ui.exists('.p202-chat__launcher'), { describe: 'the chat box to be built' });
          await page.click('.p202-chat__launcher');
          await ui.until(async () => ui.exists('.p202-chat__row'), { describe: 'the conversation to be listed' });
          await page.click('.p202-chat__row');
          await ui.until(async () => readAsked, { describe: 'opening the thread to post a read receipt' });
          await page.click('.p202-chat__launcher'); // closed: the next message is not seen
          await ui.until(async () => server !== null, { describe: 'the socket' });
          server.send(JSON.stringify({ t: 'message', message: teamMessage(2, 'One more thing'), conversation: chatConv({ unread: 1, rev: 2 }) }));
          await ui.until(async () => readAnswered, { describe: 'the receipt to be answered' });
          await page.waitForTimeout(300);
          expect.ok(await ui.exists('.p202-chat__launcher--unread'), 'the newer message still shows on the launcher');
        });
      },
    },
    {
      // Codex on #207: the gap fill a socket starts with can answer after a
      // newer conversation frame, and replaced it with the older snapshot.
      name: 'An older snapshot never replaces a newer one',
      async run(ctx) {
        await ctx.withSession({}, async ({ page, app, ui, expect }) => {
          let stateCalls = 0;
          let staleAnswered = false;
          await page.route(PASS_URL, aPass);
          await standInService(page, async (request) => {
            if (request.method() === 'GET' && request.url().includes('/v1/customer/state')) {
              stateCalls += 1;
              if (stateCalls === 2) {
                // The socket's gap fill: held back, then answered with rev 1.
                await new Promise((resolve) => setTimeout(resolve, 1500));
                staleAnswered = true;
              }
              return [200, { conversations: [chatConv({ unread: 1, rev: 1 })], messages: [teamMessage(1, 'Hi Dana')], seq: 1 }];
            }
            return [204, null];
          });
          await page.routeWebSocket('ws://127.0.0.1:9/**', (ws) => {
            setTimeout(() => ws.send(JSON.stringify({ t: 'ready', seq: 1 })), 50);
            // Read in another tab meanwhile: rev 2, nothing unread.
            setTimeout(() => ws.send(JSON.stringify({ t: 'conversation', conversation: chatConv({ unread: 0, team_read_upto: 0, rev: 2 }) })), 300);
          });
          await app.goto('/202-account/');
          await ui.until(async () => staleAnswered, { describe: 'the gap fill to be answered', timeout: 20000 });
          await page.waitForTimeout(300);
          expect.ok(!(await ui.exists('.p202-chat__launcher--unread')), 'the launcher keeps the newer count (nothing unread)');
        });
      },
    },
    {
      // Codex on #207: a socket a proxy upgrades and then starves never gets
      // a ready frame; the box waited on it for good, with nothing polling.
      name: 'A socket that never says ready is given up and tried again',
      async run(ctx) {
        await ctx.withSession({}, async ({ page, app, expect }) => {
          let sockets = 0;
          await page.route(PASS_URL, aPass);
          await standInService(page, () => [200, EMPTY_STATE]);
          await page.routeWebSocket('ws://127.0.0.1:9/**', () => {
            sockets += 1; // open, and silent
          });
          await app.goto('/202-account/');
          await page.waitForTimeout(15000);
          expect.ok(sockets >= 2, 'the silent socket was given up and a new one opened', sockets + ' sockets in 15 s');
        });
      },
    },
    {
      // Codex on #207: two requests refused at once (a pass expiring under
      // both an HTTP call and the socket) each asked for the one renewal; the
      // second was told no, and a socket told no fell back to polling for
      // good.
      name: 'Requests refused together share one renewal, and the socket comes back',
      async run(ctx) {
        await ctx.withSession({}, async ({ page, app, ui, expect }) => {
          let sockets = 0;
          let refreshes = 0;
          let gets = 0;
          await page.route(PASS_URL, async (route) => {
            if ((route.request().postData() || '').includes('refresh=1')) {
              refreshes += 1;
              await new Promise((resolve) => setTimeout(resolve, 1000));
            }
            return aPass(route);
          });
          await standInService(page, (request) => {
            if (request.method() === 'GET') {
              gets += 1;
              // The socket's gap fill (the second GET) is refused.
              return gets === 2 ? [401, { error: { code: 'unauthorized', message: 'Pass expired.' } }] : [200, EMPTY_STATE];
            }
            return [204, null];
          });
          await page.routeWebSocket('ws://127.0.0.1:9/**', (ws) => {
            sockets += 1;
            setTimeout(() => ws.send(JSON.stringify({ t: 'ready', seq: 0 })), 50);
            if (sockets === 1) {
              // Refused too, while the gap fill's renewal is in flight.
              setTimeout(() => ws.close({ code: 4001, reason: 'Pass expired.' }), 300);
            }
          });
          await app.goto('/202-account/');
          await ui.until(async () => sockets >= 2, { describe: 'the socket to reconnect after the shared renewal', timeout: 15000 });
          await page.waitForTimeout(500);
          expect.eq(refreshes, 1, 'one renewal served both refusals');
          expect.ok(gets >= 3, 'the refused gap fill was retried with the new pass', gets + ' GETs');
        });
      },
    },
    {
      name: 'Web addresses in a message are links, and nothing else is',
      async run(ctx) {
        await ctx.withSession({}, async ({ page, app, ui, expect }) => {
          await openThreadOf(page, app, ui, LINK_CASES.map(([, body], i) => teamMessage(i + 1, body)));
          const bubbles = await page.$$eval('.p202-chat__bubble', readBubbles);
          expect.eq(bubbles.length, LINK_CASES.length, 'one bubble a message');
          expect.eq(new Set(INVISIBLE).size, 22, 'all 22 invisible and direction characters are cases');
          LINK_CASES.forEach(([name, body, want], i) => {
            const got = bubbles[i] || { parts: [], text: '' };
            const same = JSON.stringify(got.parts) === JSON.stringify(want);
            expect.ok(same, name, same ? '' : 'got ' + showParts(got.parts) + ' want ' + showParts(want));
            expect.ok(got.text === body, name + ': the text reads back unchanged');
          });
          const spaced = LINK_CASES.findIndex(([name]) => name === 'line breaks and spaces are kept');
          expect.eq(JSON.stringify((bubbles[spaced] || {}).rendered), JSON.stringify(LINK_CASES[spaced][1]),
            'line breaks, runs of spaces and tabs still render as written');
          expect.eq(await page.$eval('.p202-chat__bubble', (b) => getComputedStyle(b).whiteSpace), 'pre-wrap', 'the bubble still keeps white space');
          const markup = await page.evaluate(() => ({ elements: document.querySelectorAll('.p202-chat__bubble *:not(a)').length, ran: window.__p202Xss === 1 }));
          expect.eq(markup.elements, 0, 'no bubble holds an element other than a link');
          expect.notOk(markup.ran, 'and no markup in a message ran');
        });
      },
    },
    {
      name: 'A link in a team reply opens in a new tab, with the text around it as text',
      async run(ctx) {
        await ctx.withSession({}, async ({ page, app, ui, expect }) => {
          await openThreadOf(page, app, ui, [teamMessage(1, 'Hi Dana, the guide is at https://x.test/guide?step=2. Shout if not.')]);
          const bubble = await page.$eval('.p202-chat__msg--team .p202-chat__bubble', (node) => Array.from(node.childNodes).map((n) => (
            n.nodeType === Node.TEXT_NODE
              ? { node: '#text', text: n.nodeValue }
              : {
                node: n.nodeName,
                className: n.className,
                href: n.getAttribute('href'),
                target: n.getAttribute('target'),
                rel: n.getAttribute('rel'),
                label: n.textContent,
                children: n.children.length,
              }
          )));
          expect.eq(bubble.map((n) => n.node).join(' '), '#text A #text', 'text, a link, text');
          expect.eq(bubble[0] && bubble[0].text, 'Hi Dana, the guide is at ', 'the text before the link is a text node');
          expect.eq(bubble[2] && bubble[2].text, '. Shout if not.', 'the text after it, full stop included, is a text node');
          const link = bubble[1] || {};
          expect.eq(link.href, 'https://x.test/guide?step=2', 'the href is the address exactly as written');
          expect.eq(link.label, 'https://x.test/guide?step=2', 'and so is its label');
          expect.eq(link.children, 0, 'the label is text alone');
          expect.eq(link.target, '_blank', 'it opens in a new tab');
          expect.eq(link.rel, 'noopener noreferrer nofollow', 'without a handle on this page, a referrer or a vote');
          expect.eq(link.className, 'p202-chat__bubble-link', 'styled as a bubble link');
        });
      },
    },
    {
      name: 'A link reads on the customer bubble and the team bubble, light and dark',
      async run(ctx) {
        for (const scheme of ['light', 'dark']) {
          await ctx.withSession({ colorScheme: scheme }, async ({ page, app, ui, expect }) => {
            await openThreadOf(page, app, ui, [
              customerMessage(1, 'Mine: https://x.test/mine'),
              teamMessage(2, 'Team: https://x.test/team'),
            ]);
            expect.eq(await page.evaluate(() => document.documentElement.getAttribute('data-bs-theme')), scheme, scheme + ': the page is in that theme');
            const links = await page.$$eval('.p202-chat__bubble-link', linkContrast);
            expect.eq(links.length, 2, scheme + ': both messages have a link');
            for (const link of links) {
              const who = /--mine/.test(link.who) ? 'customer' : 'team';
              expect.ok(link.ratio >= 4.5, scheme + ', ' + who + ': the link has 4.5:1 contrast on its bubble',
                link.ratio.toFixed(2) + ':1, ' + link.color + ' on rgb(' + link.background + ')');
              expect.ok(/underline/.test(link.underline), scheme + ', ' + who + ': and is underlined', link.underline);
            }
          });
        }
      },
    },
    {
      name: 'A hostile message at the length cap renders in under 50 ms',
      async run(ctx) {
        await ctx.withSession({}, async ({ page, app, ui, expect }) => {
          await page.route(PASS_URL, aPass);
          const answer = {
            conversations: HOSTILE.map(([name], i) => chatConv({ id: 100 + i, type: 'broadcast', subject: name, unread: 0 })),
            messages: HOSTILE.map(([, body], i) => ({ ...teamMessage(i + 1, body), conv: 100 + i })),
            seq: HOSTILE.length,
          };
          await standInService(page, () => [200, answer]);
          await page.routeWebSocket('ws://127.0.0.1:9/**', (ws) => {
            setTimeout(() => ws.send(JSON.stringify({ t: 'ready', seq: HOSTILE.length })), 50);
          });
          await app.goto('/202-account/');
          await ui.until(async () => ui.exists('.p202-chat__launcher'), { describe: 'the chat box to be built' });
          await page.click('.p202-chat__launcher');
          await ui.until(async () => (await page.$$('.p202-chat__row')).length === HOSTILE.length, { describe: 'every conversation to be listed' });
          for (const [name, body, links] of HOSTILE) {
            const got = await page.evaluate(timeOpening, { name, runs: RENDER_RUNS });
            if (got.error) {
              expect.fail(name, got.error);
              continue;
            }
            const times = got.times.map((t) => t.toFixed(1)).join(', ') + ' ms';
            expect.note(name + ' (' + body.length + ' characters): ' + times);
            expect.ok(Math.max(...got.times) < RENDER_MS, name + ': every render under ' + RENDER_MS + ' ms', times);
            expect.ok(got.shown.bubbles === 1 && got.shown.text === body, name + ': shown whole, as written');
            expect.eq(got.shown.links, links, name + ': ' + links + ' links');
          }
        });
      },
    },
    {
      // track.php sends one batch a request: "throttled" held the rows back
      // and "more" left some pending, and the page asks again for both. A
      // page closed first leaves them to the hourly cron.
      name: 'Analytics held back or left pending are asked for again',
      async run(ctx) {
        for (const [delivery, again] of [['throttled', true], ['more', true], ['sent', false]]) {
          await ctx.withSession({}, async ({ page, app, ui, expect }) => {
            const posts = [];
            await page.route(PASS_URL, unavailable);
            await page.route('**/202-account/ajax/messaging/track.php', (route) => {
              posts.push(route.request().postData() || '');
              const answer = posts.length === 1 ? { ok: true, delivery, retry_after_s: 1 } : { ok: true, delivery: 'sent' };
              return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(answer) });
            });
            await app.goto('/202-account/');
            await ui.until(async () => ui.exists('.p202-chat__panel'), { describe: 'the chat box to be built' });
            await page.evaluate(() => window.Prosper202Messenger('trackEvent', 'spec_event'));
            await ui.until(async () => posts.length >= 1, { describe: 'the event to be posted' });
            await page.waitForTimeout(3000);
            expect.eq(posts.length, again ? 2 : 1, '"' + delivery + '" ' + (again ? 'asks once more' : 'asks nothing more'));
            if (again) {
              expect.ok(/(^|&)flush=1(&|$)/.test(posts[1]), 'the second request asks for the delivery alone', posts[1]);
            }
          });
        }
      },
    },
  ],
};
