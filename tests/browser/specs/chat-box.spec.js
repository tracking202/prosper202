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
  ],
};
