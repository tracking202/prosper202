#!/usr/bin/env node
'use strict';

/*
 * Entry point for the browser pass.
 *
 *   node tests/browser/run.js                        every spec
 *   node tests/browser/run.js --spec mobile-apps     one spec
 *   node tests/browser/run.js --grep 'schema token'  one scenario
 *   node tests/browser/run.js --headed --slow 120    watch it happen
 *
 * Requires a live instance you stood up yourself and a SCRATCH database:
 * the pass truncates tables. See tests/browser/README.md.
 */

const { load, ConfigError } = require('./lib/config');
const { discover, run } = require('./lib/runner');
const { UnsafeDatabaseError } = require('./lib/db');

const USAGE = [
  'Usage: node tests/browser/run.js [options]',
  '',
  '  --spec <name>     run specs whose name contains this (repeatable)',
  '  --grep <pattern>  run scenarios whose name matches this regular expression',
  '  --list            list the specs and scenarios, run nothing',
  '  --base <url>      the instance to drive        (P202_BASE)',
  '  --user <name>     the login to sign in as      (P202_USER)',
  '  --pass <secret>   that login\'s password        (P202_PASS)',
  '  --db <name>       the SCRATCH database         (P202_DB)',
  '  --cdn <dir>       mirror of the pinned CDN files (P202_CDN_MIRROR)',
  '  --shots <dir>     where screenshots are written  (P202_SHOTS)',
  '  --headed          show the browser',
  '  --slow <ms>       slow each action down, to watch it',
  '  --timeout <ms>    how long any single wait may take (default 15000)',
  '  --keep-data       do not reset the instance first (specs may then fail)',
  '',
  'Exit: 0 all checks passed, 1 a check failed, 2 could not run.',
].join('\n');

function parseArgs(argv) {
  const out = { specs: [] };
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    const next = () => argv[++i];
    switch (arg) {
      case '--spec': out.specs.push(next()); break;
      case '--grep': out.grep = next(); break;
      case '--list': out.list = true; break;
      case '--base': out.base = next(); break;
      case '--user': out.user = next(); break;
      case '--pass': out.pass = next(); break;
      case '--db': out.db = next(); break;
      case '--cdn': out.cdnMirror = next(); break;
      case '--shots': out.shots = next(); break;
      case '--headed': out.headed = true; break;
      case '--slow': out.slowMo = Number(next()); break;
      case '--timeout': out.timeout = Number(next()); break;
      case '--keep-data': out.keepData = true; break;
      case '-h':
      case '--help': out.help = true; break;
      default:
        throw new ConfigError('Unknown option "' + arg + '"\n\n' + USAGE);
    }
  }
  return out;
}

(async () => {
  let args;
  try {
    args = parseArgs(process.argv.slice(2));
  } catch (error) {
    console.error(error.message);
    process.exit(2);
  }

  if (args.help) {
    console.log(USAGE);
    process.exit(0);
  }

  let specs = discover();
  if (args.specs.length) {
    specs = specs.filter((spec) => args.specs.some((wanted) => spec.name.includes(wanted)));
  }

  if (args.list) {
    for (const spec of specs) {
      console.log(spec.name + '  (' + spec.file + ')');
      for (const scenario of spec.scenarios || []) {
        console.log('    ' + scenario.name);
      }
    }
    process.exit(0);
  }

  if (!specs.length) {
    console.error('No specs matched. Try --list.');
    process.exit(2);
  }

  let config;
  try {
    config = load(args);
  } catch (error) {
    if (error instanceof ConfigError) {
      console.error(error.message);
      process.exit(2);
    }
    throw error;
  }

  // Fail on an instance that is not there with a sentence, not a timeout
  // three layers into Playwright.
  try {
    const response = await fetch(config.base + '/202-login.php', { redirect: 'manual' });
    if (!response.status) { throw new Error('no status'); }
  } catch (error) {
    console.error('Cannot reach ' + config.base + ' — start the instance, or pass --base.');
    process.exit(2);
  }

  let code;
  try {
    code = await run({
      config,
      specs,
      grep: args.grep ? new RegExp(args.grep, 'i') : undefined,
    });
  } catch (error) {
    if (error instanceof UnsafeDatabaseError) {
      console.error('\n' + error.message);
      process.exit(2);
    }
    throw error;
  }
  process.exit(code);
})().catch((error) => {
  console.error('\nThe run itself failed: ' + (error && error.stack ? error.stack : error));
  process.exit(2);
});
