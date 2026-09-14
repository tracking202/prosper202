'use strict';

/*
 * Where everything lives, resolved rather than assumed.
 *
 * The browser pass runs against a live instance you stood up yourself, so
 * nothing here has a useful compile-time default — the URL, the login and the
 * database all differ per machine. Every value can come from the environment,
 * and anything that cannot be resolved fails with a sentence saying what to
 * set rather than a stack trace three layers down.
 */

const fs = require('fs');
const path = require('path');

/** Places playwright-core turns up, most specific first. */
const PLAYWRIGHT_CANDIDATES = [
  process.env.P202_PLAYWRIGHT,
  path.join(__dirname, '..', 'node_modules', 'playwright-core'),
  path.join(__dirname, '..', '..', '..', 'node_modules', 'playwright-core'),
  '/tmp/pwdrv/node_modules/playwright-core',
  'playwright-core',
  'playwright',
].filter(Boolean);

/** Chromium binaries, most specific first. */
const CHROMIUM_CANDIDATES = [
  process.env.P202_CHROMIUM,
  process.env.PLAYWRIGHT_BROWSERS_PATH && path.join(process.env.PLAYWRIGHT_BROWSERS_PATH, 'chromium'),
  '/opt/pw-browsers/chromium',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
  '/usr/bin/google-chrome',
].filter(Boolean);

class ConfigError extends Error {}

function resolvePlaywright() {
  const tried = [];
  for (const candidate of PLAYWRIGHT_CANDIDATES) {
    try {
      return require(candidate);
    } catch (error) {
      tried.push(candidate);
    }
  }
  throw new ConfigError(
    'Could not load playwright-core. Set P202_PLAYWRIGHT to its directory, or\n'
    + '  npm install playwright-core --prefix tests/browser\n'
    + 'Tried: ' + tried.join(', ')
  );
}

/**
 * The chromium to drive. Playwright can find its own download when the
 * package was installed with browsers, so an unresolved path is not fatal —
 * undefined lets playwright decide.
 */
function resolveChromium() {
  for (const candidate of CHROMIUM_CANDIDATES) {
    try {
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    } catch (error) {
      // An unreadable candidate is simply not the one.
    }
  }
  return undefined;
}

function required(name, value, hint) {
  if (value === undefined || value === null || value === '') {
    throw new ConfigError('Set ' + name + ': ' + hint);
  }
  return value;
}

/**
 * @param {object} overrides values from the command line, which win over the
 *   environment so a one-off run does not need exports.
 */
function load(overrides = {}) {
  const env = process.env;
  const base = overrides.base || env.P202_BASE || 'http://127.0.0.1:8097';

  return {
    base: base.replace(/\/+$/, ''),
    host: new URL(base).host,

    user: required('P202_USER', overrides.user || env.P202_USER,
      'the Prosper202 login the pass signs in as'),
    pass: required('P202_PASS', overrides.pass || env.P202_PASS,
      'that login\'s password'),

    // Reads use this; writes go through lib/db.js, which refuses a database
    // that does not look like a scratch one.
    db: {
      name: required('P202_DB', overrides.db || env.P202_DB,
        'the SCRATCH database the instance uses — this pass truncates tables'),
      user: env.P202_DB_USER || 'root',
      pass: env.P202_DB_PASS || '',
      host: env.P202_DB_HOST || '',
      allowNonScratch: env.P202_DB_ALLOW_DESTRUCTIVE === 'yes-i-mean-it',
    },

    browser: {
      playwright: resolvePlaywright(),
      chromium: resolveChromium(),
      headed: Boolean(overrides.headed || env.P202_HEADED),
      slowMo: Number(overrides.slowMo || env.P202_SLOWMO || 0),
      // A generous default: a cold instance on a shared machine is slow, and
      // every wait here is a real condition rather than a sleep, so a high
      // ceiling costs nothing on a healthy run.
      timeout: Number(overrides.timeout || env.P202_TIMEOUT || 15000),
    },

    // Third-party files the shell pins. Requests to anything else are aborted
    // so a pass never depends on the network; point this at a mirror to keep
    // the pinned CDN files loading.
    cdnMirror: overrides.cdnMirror || env.P202_CDN_MIRROR || '',

    shots: overrides.shots || env.P202_SHOTS || path.join(__dirname, '..', 'shots'),
    keepData: Boolean(overrides.keepData || env.P202_KEEP_DATA),
  };
}

module.exports = { load, ConfigError };
