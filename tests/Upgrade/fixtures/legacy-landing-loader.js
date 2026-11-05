// Runs tracking202/static/landing.js as a paid 1.9.56 page loads it and prints
// the script URL it inserts, for Paid1956ContractsServedIntegrationTest.
//
//   node legacy-landing-loader.js <landing.js> <script src> <page url>
'use strict';

const fs = require('fs');
const vm = require('vm');

const [file, src, page] = process.argv.slice(2);
const inserted = [];
const first = { parentNode: { insertBefore: (el) => inserted.push(el) } };
const document = {
  currentScript: { src: src, id: 't202js' },
  getElementById: () => null,
  createElement: () => ({}),
  getElementsByTagName: () => [first],
};
const window = { location: new URL(page) };
vm.runInNewContext(fs.readFileSync(file, 'utf8'), { document, window, URLSearchParams }, { filename: file });
process.stdout.write(JSON.stringify(inserted.map((el) => ({ src: el.src, id: el.id }))));
