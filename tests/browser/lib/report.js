'use strict';

/*
 * The scoreboard.
 *
 * Every check prints as it happens, because a pass that only prints a total
 * at the end tells you nothing about where it got to when it hangs. A failure
 * records what was expected and what was there — a bare "FAIL" sends you back
 * to the page to find out what it meant.
 */

const useColour = process.stdout.isTTY && !process.env.NO_COLOR;
const ESC = String.fromCharCode(27);
const paint = (code, text) => useColour ? ESC + '[' + code + 'm' + text + ESC + '[0m' : text;
const green = (t) => paint('32', t);
const red = (t) => paint('31', t);
const yellow = (t) => paint('33', t);
const bold = (t) => paint('1', t);
const dim = (t) => paint('2', t);

class Report {
  constructor() {
    this.passed = 0;
    this.failed = 0;
    this.skipped = 0;
    /** @type {Array<{scenario: string, name: string, detail: string}>} */
    this.failures = [];
    this.scenario = '(none)';
    this.started = Date.now();
  }

  spec(title) {
    console.log('\n' + bold('# ' + title));
  }

  section(title) {
    this.scenario = title;
    console.log('\n  ' + bold(title));
  }

  pass(name) {
    this.passed++;
    console.log('    ' + green('PASS') + ' ' + name);
  }

  /** @param {string} [detail] what was actually there, when that helps. */
  fail(name, detail) {
    this.failed++;
    this.failures.push({ scenario: this.scenario, name, detail: detail || '' });
    console.log('    ' + red('FAIL') + ' ' + name + (detail ? dim(' - ' + detail) : ''));
  }

  skip(name, why) {
    this.skipped++;
    console.log('    ' + yellow('SKIP') + ' ' + name + (why ? dim(' - ' + why) : ''));
  }

  note(text) {
    console.log('    ' + dim(text));
  }

  /** A scenario that threw: one failure carrying the error, not a dead run. */
  crashed(scenarioName, error) {
    this.scenario = scenarioName;
    const detail = (error && error.message ? error.message : String(error)).split('\n')[0];
    this.fail('the scenario ran to completion', detail);
  }

  summary() {
    const seconds = ((Date.now() - this.started) / 1000).toFixed(1);
    const verdict = this.failed === 0 ? green(this.passed + ' passed') : red(this.failed + ' failed') + ', ' + this.passed + ' passed';
    const skipped = this.skipped ? ', ' + yellow(this.skipped + ' skipped') : '';
    console.log('\n' + bold(verdict + skipped) + dim('  (' + seconds + 's)'));

    if (this.failures.length) {
      console.log('\n' + bold('Failures'));
      let current = null;
      for (const failure of this.failures) {
        if (failure.scenario !== current) {
          current = failure.scenario;
          console.log('  ' + current);
        }
        console.log('    - ' + failure.name + (failure.detail ? dim(' (' + failure.detail + ')') : ''));
      }
    }
    return this.failed === 0;
  }
}

/**
 * Assertions, bound to a report.
 *
 * Deliberately few: a check is a boolean with a sentence, and the sentence is
 * what a reader sees, so it reads as a claim about the app rather than about
 * the selector that happened to express it.
 */
class Expect {
  constructor(report) {
    this.report = report;
  }

  ok(condition, name, detail) {
    if (condition) {
      this.report.pass(name);
    } else {
      this.report.fail(name, detail);
    }
    return Boolean(condition);
  }

  notOk(condition, name, detail) {
    return this.ok(!condition, name, detail);
  }

  eq(got, want, name) {
    const same = String(got) === String(want);
    return this.ok(same, name, same ? '' : 'got ' + JSON.stringify(String(got)) + ' want ' + JSON.stringify(String(want)));
  }

  ne(got, unwanted, name) {
    const different = String(got) !== String(unwanted);
    return this.ok(different, name, different ? '' : 'both are ' + JSON.stringify(String(got)));
  }

  match(value, pattern, name) {
    const text = String(value === undefined || value === null ? '' : value);
    const matched = pattern.test(text);
    return this.ok(matched, name, matched ? '' : 'in ' + JSON.stringify(text.slice(0, 160)));
  }

  notMatch(value, pattern, name) {
    const text = String(value === undefined || value === null ? '' : value);
    const matched = pattern.test(text);
    return this.ok(!matched, name, matched ? 'found in ' + JSON.stringify(text.slice(0, 160)) : '');
  }

  /** For a number that must sit in a range — layout measurements, mostly. */
  between(value, low, high, name) {
    const n = Number(value);
    const inside = Number.isFinite(n) && n >= low && n <= high;
    return this.ok(inside, name, inside ? '' : n + ' is outside ' + low + '..' + high);
  }

  includes(haystack, needle, name) {
    const list = Array.isArray(haystack) ? haystack : [haystack];
    const found = list.some((item) => String(item) === String(needle));
    return this.ok(found, name, found ? '' : 'have ' + JSON.stringify(list.slice(0, 8)));
  }

  fail(name, detail) {
    this.report.fail(name, detail);
    return false;
  }

  skip(name, why) {
    this.report.skip(name, why);
  }

  section(title) {
    this.report.section(title);
  }

  note(text) {
    this.report.note(text);
  }
}

module.exports = { Report, Expect };
