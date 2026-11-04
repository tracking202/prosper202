// Runs Setup › Campaigns' Link Assistant (202-js/p202-link-assistant.js)
// under node and prints what it suggests for each link, as JSON, for
// LinkAssistantTest to compare with Prosper202\Setup\LinkAssistant.
//
//   node link-assistant-harness.js <p202-link-assistant.js> <input.json>
//
// input.json is {"networks": [...], "urls": [...]}. There is no document, so
// the script defines suggest() and stops before its page wiring.
'use strict';

const fs = require('fs');
const vm = require('vm');

const [script, inputFile] = process.argv.slice(2);
const input = JSON.parse(fs.readFileSync(inputFile, 'utf8'));
const sandbox = {};
sandbox.window = sandbox;
vm.runInNewContext(fs.readFileSync(script, 'utf8'), sandbox, { filename: script });
const out = input.urls.map((url) => sandbox.P202LinkAssistant.suggest(url, input.networks));
process.stdout.write(JSON.stringify(out));
