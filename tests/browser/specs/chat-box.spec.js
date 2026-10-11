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
  ],
};
