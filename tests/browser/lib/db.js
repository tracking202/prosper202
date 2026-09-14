'use strict';

/*
 * Database access for a browser pass, with a guard on the destructive half.
 *
 * A browser pass asserts against stored state as well as the page, because a
 * page that says "saved" while nothing was written is the defect these passes
 * exist to catch. That means truncating tables between runs — which is fine
 * against a throwaway instance and ruinous against a real one.
 *
 * So reads go anywhere, and every write goes through a name check first: the
 * database has to look like a scratch one, or carry the explicit opt-in. The
 * failure mode this prevents is not hypothetical — the first version of this
 * pass hardcoded one database name and truncated three tables on startup.
 */

const { execFileSync } = require('child_process');

/**
 * Names that read as disposable. Deliberately conservative: a database called
 * `prosper202` is somebody's install until proven otherwise.
 */
const SCRATCH = /(^|[_-])(test|tests|scratch|tmp|temp|ci|eval|evals|fixture|sandbox|w\d+|probe|check)([_-]|$)/i;

class UnsafeDatabaseError extends Error {}

class Db {
  /**
   * @param {{name: string, user?: string, pass?: string, host?: string,
   *          allowNonScratch?: boolean}} options
   */
  constructor(options) {
    this.name = options.name;
    this.user = options.user || 'root';
    this.pass = options.pass || '';
    this.host = options.host || '';
    this.allowNonScratch = Boolean(options.allowNonScratch);
    /** @type {Array<() => void>} undo steps, newest first. */
    this.undo = [];
  }

  get looksDisposable() {
    return SCRATCH.test(this.name);
  }

  args(extra = []) {
    const args = ['-u' + this.user];
    if (this.pass) { args.push('-p' + this.pass); }
    if (this.host) { args.push('-h' + this.host, '--protocol=tcp'); }
    return args.concat(extra, [this.name]);
  }

  /** A single scalar, trimmed. Reads are never guarded. */
  value(query) {
    return execFileSync('mysql', this.args(['-N', '-e', query]), { encoding: 'utf8' }).trim();
  }

  /** Rows as arrays of column strings. */
  rows(query) {
    const out = execFileSync('mysql', this.args(['-N', '-e', query]), { encoding: 'utf8' }).trim();
    return out === '' ? [] : out.split('\n').map((line) => line.split('\t'));
  }

  count(table, where) {
    return Number(this.value('SELECT COUNT(*) FROM ' + table + (where ? ' WHERE ' + where : '')));
  }

  /**
   * Anything that changes data. Refuses a database that does not read as
   * disposable, naming what to do about it rather than just saying no.
   */
  write(query) {
    if (!this.looksDisposable && !this.allowNonScratch) {
      throw new UnsafeDatabaseError(
        'Refusing to write to "' + this.name + '": this pass truncates tables and that name does not\n'
        + 'look like a scratch database. Point P202_DB at a throwaway instance (a name containing\n'
        + 'test/scratch/tmp/ci/eval, or ending in a digit suffix like _w1), or if you really mean it:\n'
        + '  P202_DB_ALLOW_DESTRUCTIVE=yes-i-mean-it'
      );
    }
    return execFileSync('mysql', this.args(['-e', query]), { encoding: 'utf8' });
  }

  truncate(tables) {
    this.write(tables.map((t) => 'TRUNCATE ' + t + ';').join(' '));
  }

  /**
   * Make a change and register how to put it back, so a scenario that throws
   * still leaves the instance as it found it. Undo steps run newest first in
   * restore(), which the runner calls in a finally block.
   *
   * Reach for this whenever a check needs the instance configured differently
   * for a moment — a permission removed, a preference switched.
   */
  temporarily(applySql, undoSql) {
    this.write(applySql);
    this.undo.unshift(() => {
      try {
        this.write(undoSql);
      } catch (error) {
        console.error('  ! could not undo "' + undoSql + '": ' + error.message);
      }
    });
  }

  restore() {
    const steps = this.undo;
    this.undo = [];
    for (const step of steps) { step(); }
  }

  /** True when the server answers at all — a clearer failure than a stack trace. */
  reachable() {
    try {
      this.value('SELECT 1');
      return true;
    } catch (error) {
      return false;
    }
  }
}

module.exports = { Db, UnsafeDatabaseError, SCRATCH };
