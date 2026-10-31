#!/usr/bin/env python3
"""Manual, resource-aware click retention for the local MariaDB server (Python 3.9+)."""

import argparse
import datetime as dt
import fcntl
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import threading
import time
from dataclasses import dataclass
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError


MIB = 1024 ** 2
GIB = 1024 ** 3
CLICK_TABLES = (
    "202_clicks_advance", "202_clicks_record", "202_clicks_site",
    "202_clicks_spy", "202_clicks_tracking", "202_clicks_variable",
    "202_clicks_rotator", "202_cpa_trackers", "202_google", "202_bing",
    "202_facebook", "202_dataengine", "202_identity_observations",
    "202_clicks_visitor", "202_clicks_filter_reason",
    "202_click_latest_visits", "202_clicks",
)
REQUIRED_TABLES = CLICK_TABLES + (
    "202_attribution_rollup_dirty", "202_attribution_models",
    "202_attribution_journeys", "202_attribution_journey_meta",
    "202_attribution_credits",
)
STATUS_NAMES = (
    "Threads_running", "Innodb_row_lock_current_waits", "Innodb_log_waits",
    "Innodb_buffer_pool_wait_free",
)


def log(message):
    print(dt.datetime.now().astimezone().isoformat(timespec="seconds"), message, flush=True)


class DatabaseError(RuntimeError):
    def __init__(self, message):
        super().__init__(message)
        found = re.search(r"ERROR (\d+)", message)
        self.code = int(found.group(1)) if found else None


class MariaDB:
    """Each call gets a fresh local connection; errors never use --force."""

    def __init__(self, database, binary="mariadb"):
        if not re.fullmatch(r"[A-Za-z0-9_]+", database):
            raise ValueError("Database must contain only letters, digits, or underscores")
        self.command = [binary, "--batch", "--raw", "--skip-column-names",
                        "--unbuffered", "--connect-timeout=5", "--database=" + database]

    def query(self, sql):
        # Isolate the client from terminal signals. The parent finishes the
        # current transaction before honoring Ctrl+C or SIGTERM.
        result = subprocess.run(self.command, input=sql, text=True,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                start_new_session=True)
        if result.returncode:
            raise DatabaseError(result.stderr[-6000:].strip() or "MariaDB client failed")
        return result.stdout.strip()

    def preflight(self):
        quoted = ",".join("'" + name + "'" for name in REQUIRED_TABLES)
        rows = self.query(
            "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES "
            "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (" + quoted + ");"
        )
        engines = dict(line.split("\t") for line in rows.splitlines())
        bad = [name for name in REQUIRED_TABLES if engines.get(name) != "InnoDB"]
        if bad:
            raise RuntimeError("Missing or non-InnoDB tables: " + ", ".join(bad))
        version = self.query("SELECT VERSION();")
        if "mariadb" not in version.lower():
            raise RuntimeError("This script requires MariaDB, found " + version)
        paths = self.query(
            "SELECT @@datadir,@@tmpdir,COALESCE(NULLIF(@@innodb_tmpdir,''),@@tmpdir);"
        ).split("\t")
        if len(paths) != 3:
            raise RuntimeError("Cannot read MariaDB temporary/data directories")
        return version, paths

    def accounts(self, cutoff):
        rows = self.query("SELECT user_id FROM 202_clicks GROUP BY user_id "
                          "HAVING MIN(click_time)<%d ORDER BY user_id;" % cutoff)
        return [int(value) for value in rows.splitlines()]

    def status(self):
        names = ",".join("'" + name + "'" for name in STATUS_NAMES)
        rows = self.query("SHOW GLOBAL STATUS WHERE Variable_name IN (" + names + ");")
        values = {key.lower(): int(value) for key, value in
                  (line.split("\t") for line in rows.splitlines())}
        if set(values) != {name.lower() for name in STATUS_NAMES}:
            raise RuntimeError("Cannot read all required MariaDB load counters")
        # This SHOW's own connection is one running thread.
        values["threads_running"] = max(0, values["threads_running"] - 1)
        return values


def expired_selection(user, cutoff, batch):
    if not (0 <= user <= 16777215 and cutoff > 0 and 1 <= batch <= 10000):
        raise ValueError("Invalid deletion selection")
    return """SELECT c.click_id FROM 202_clicks c
        LEFT JOIN 202_clicks newer
          ON newer.click_id=c.click_id AND newer.click_time >= {cutoff}
        WHERE c.user_id={user} AND c.click_time < {cutoff}
          AND newer.click_id IS NULL
        ORDER BY c.click_time LIMIT {batch}""".format(
            user=user, cutoff=cutoff, batch=batch)


def batch_sql(user, cutoff, batch, preview=False):
    selection = expired_selection(user, cutoff, batch)
    if preview:
        return "SELECT COUNT(DISTINCT click_id) FROM (" + selection + ") p;"
    deletes = "\n".join(
        "DELETE x FROM p202_delete_batch b STRAIGHT_JOIN `{}` x "
        "ON x.click_id=b.click_id;".format(table) for table in CLICK_TABLES)
    return """
SET SESSION innodb_lock_wait_timeout=3;
SET SESSION lock_wait_timeout=5;
SET SESSION max_statement_time=15;
DELIMITER //
BEGIN NOT ATOMIC
    DECLARE v_count INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    CREATE TEMPORARY TABLE p202_delete_batch (
        click_id BIGINT UNSIGNED PRIMARY KEY
    ) ENGINE=MEMORY;
    CREATE TEMPORARY TABLE p202_delete_hours (
        user_id MEDIUMINT UNSIGNED, h INT UNSIGNED,
        PRIMARY KEY (user_id,h)
    ) ENGINE=MEMORY;
    START TRANSACTION;
    INSERT IGNORE INTO p202_delete_batch (click_id) {selection};
    SELECT COUNT(*) INTO v_count FROM p202_delete_batch;
    INSERT IGNORE INTO p202_delete_hours
        SELECT c.user_id,c.click_time DIV 3600
        FROM p202_delete_batch b
        STRAIGHT_JOIN 202_clicks c ON c.click_id=b.click_id
        STRAIGHT_JOIN 202_attribution_models m ON m.user_id=c.user_id;
    INSERT IGNORE INTO p202_delete_hours
        SELECT jm.user_id,jm.conv_time DIV 3600
        FROM p202_delete_batch b
        STRAIGHT_JOIN 202_attribution_journeys j ON j.click_id=b.click_id
        STRAIGHT_JOIN 202_attribution_journey_meta jm ON jm.conv_id=j.conv_id
        STRAIGHT_JOIN 202_attribution_models m ON m.user_id=jm.user_id;
    INSERT IGNORE INTO p202_delete_hours
        SELECT c.user_id,cr.conv_time DIV 3600
        FROM p202_delete_batch b
        STRAIGHT_JOIN 202_clicks c ON c.click_id=b.click_id
        STRAIGHT_JOIN 202_attribution_models m ON m.user_id=c.user_id
        STRAIGHT_JOIN 202_attribution_credits cr
          ON cr.model_id=m.model_id AND cr.click_id=b.click_id;
    INSERT INTO 202_attribution_rollup_dirty (user_id,hour_from,hour_to)
        SELECT user_id,h,h FROM p202_delete_hours;
    {deletes}
    COMMIT;
    SELECT v_count;
END//
DELIMITER ;
""".format(selection=selection, deletes=deletes)


@dataclass
class Snapshot:
    cpu: float
    iowait: float
    available: int
    memory_total: int
    swap_rate: float
    load: float
    cores: int
    threads: int
    row_waits: int
    new_log_waits: int
    new_buffer_waits: int
    low_disks: tuple = ()

    def summary(self):
        return ("CPU=%.0f%% IOwait=%.0f%% RAMavail=%.2fGiB "
                "swap=%.2fMiB/s load=%.2f DBactive=%d" %
                (self.cpu, self.iowait, self.available / GIB,
                 self.swap_rate / MIB, self.load, self.threads))


class Sampler:
    """Use interval deltas, never total swap allocation or boot CPU averages."""

    def __init__(self, db, paths, proc=Path("/proc")):
        self.db, self.paths, self.proc = db, paths, proc
        self.previous = None

    def sample(self):
        now = time.monotonic()
        if self.previous is not None and now - self.previous[0] < 0.5:
            time.sleep(0.5 - (now - self.previous[0]))
            now = time.monotonic()
        cpu = list(map(int, (self.proc / "stat").read_text().splitlines()[0].split()[1:9]))
        if len(cpu) != 8:
            raise RuntimeError("Cannot read Linux CPU counters")
        mem = {line.split()[0].rstrip(":"): int(line.split()[1]) * 1024
               for line in (self.proc / "meminfo").read_text().splitlines()
               if line.startswith(("MemTotal:", "MemAvailable:"))}
        vm = dict(line.split() for line in (self.proc / "vmstat").read_text().splitlines())
        swap = (int(vm["pswpin"]) + int(vm["pswpout"])) * os.sysconf("SC_PAGE_SIZE")
        status = self.db.status()
        low = []
        for path in self.paths:
            disk = shutil.disk_usage(path)
            minimum = min(5 * GIB, max(512 * MIB, disk.total * 0.05))
            if disk.free < minimum:
                low.append(path)
        current = (now, cpu, swap, status)
        if self.previous is None:
            self.previous = current
            return None
        old_time, old_cpu, old_swap, old_db = self.previous
        elapsed = now - old_time
        delta = [max(0, a - b) for a, b in zip(cpu, old_cpu)]
        total = sum(delta)
        if elapsed <= 0 or total == 0:
            raise RuntimeError("No fresh CPU sample; stopping instead of guessing load")
        # A reset cannot masquerade as a harmless zero-wait interval.
        if swap < old_swap or any(status[k] < old_db[k] for k in
                                 ("innodb_log_waits", "innodb_buffer_pool_wait_free")):
            raise RuntimeError("Resource counters reset; restart the script to resample")
        self.previous = current
        return Snapshot(
            100 * (total - delta[3] - delta[4]) / total,
            100 * delta[4] / total, mem["MemAvailable"], mem["MemTotal"],
            max(0, swap - old_swap) / elapsed, os.getloadavg()[0],
            os.cpu_count() or 1, status["threads_running"],
            status["innodb_row_lock_current_waits"],
            status["innodb_log_waits"] - old_db["innodb_log_waits"],
            status["innodb_buffer_pool_wait_free"] - old_db["innodb_buffer_pool_wait_free"],
            tuple(low))


def pressure(s):
    """Return pause/slow/healthy and concrete reasons; thresholds are defaults."""
    hard, warm = [], []
    for value, soft, high, label in (
        (s.cpu, 65, 85, "CPU"), (s.iowait, 5, 15, "disk I/O wait"),
        (s.swap_rate, 0.1 * MIB, MIB, "active swapping"),
        (s.load, s.cores * 0.8, s.cores * 1.25, "system load"),
        (s.threads, max(2, s.cores // 2), max(4, s.cores), "MariaDB activity"),
    ):
        if value >= high:
            hard.append(label)
        elif value >= soft:
            warm.append(label)
    if s.available < max(768 * MIB, s.memory_total * 0.10):
        hard.append("low available RAM")
    elif s.available < max(GIB, s.memory_total * 0.15):
        warm.append("available RAM")
    if s.row_waits:
        hard.append("InnoDB row-lock waits")
    if s.new_log_waits or s.new_buffer_waits:
        hard.append("InnoDB log/buffer waits")
    if s.low_disks:
        hard.append("low disk space: " + ",".join(s.low_disks))
    return ("pause", hard) if hard else (("slow", warm) if warm else ("healthy", []))


class Controller:
    def __init__(self, initial=500, minimum=100, maximum=5000):
        if not 1 <= minimum <= initial <= maximum <= 10000:
            raise ValueError("Require 1 <= min-batch <= batch <= max-batch <= 10000")
        self.batch, self.minimum, self.maximum = initial, minimum, maximum
        self.calm = 0
        self.delay = 0.5

    def backoff(self, severe=False):
        self.batch = max(self.minimum, self.batch // 2 if severe else self.batch * 3 // 4)
        self.delay = min(5.0, max(1.0, self.delay * 2))
        self.calm = 0

    def finished(self, seconds, state, full_batch):
        if seconds > 2:
            self.backoff(severe=True)
        elif state != "healthy" or seconds > 1:
            self.calm = 0
        elif full_batch:
            self.calm += 1
            if self.calm >= 3:
                self.batch = min(self.maximum, self.batch + max(25, self.batch // 4))
                self.delay = max(0.5, self.delay * 0.8)
                self.calm = 0
        else:
            self.calm = 0


def cutoff_time(before, timezone):
    if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", before):
        raise ValueError("--before must be YYYY-MM-DD")
    zone = ZoneInfo(timezone)
    date = dt.date.fromisoformat(before)
    if date > dt.datetime.now(zone).date():
        raise ValueError("Refusing a future deletion cutoff")
    cutoff = int(dt.datetime.combine(date, dt.time(), zone).timestamp())
    if cutoff <= 0:
        raise ValueError("Cutoff must be after the Unix epoch")
    return cutoff


def parse_args(argv=None):
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--database", required=True, help="database to inspect or modify")
    p.add_argument("--before", required=True, help="delete clicks before this date (YYYY-MM-DD)")
    p.add_argument("--timezone", required=True, help="IANA time zone for the cutoff date")
    p.add_argument("--batch", type=int, default=500)
    p.add_argument("--min-batch", type=int, default=100)
    p.add_argument("--max-batch", type=int, default=5000)
    p.add_argument("--max-batches", type=int, default=0,
                   help="stop after this many committed batches; 0 means no limit")
    p.add_argument("--execute", action="store_true", help="delete; default only previews")
    return p.parse_args(argv)


def run(args, db, sampler, stop):
    policy = Controller(args.batch, args.min_batch, args.max_batch)
    cutoff = cutoff_time(args.before, args.timezone)
    if args.max_batches < 0:
        raise ValueError("--max-batches cannot be negative")
    log("%s database=%s cutoff=%s midnight %s (%d), batches=%d..%d, start=%d" %
        ("DELETE" if args.execute else "PREVIEW ONLY", args.database, args.before,
         args.timezone, cutoff, policy.minimum, policy.maximum, policy.batch))
    accounts = db.accounts(cutoff)
    if not accounts:
        log("Complete: no accounts have clicks older than the cutoff")
        return 0
    sampler.sample()
    if stop.wait(1):
        return 130
    batches = total = failures = 0
    for user in accounts:
        while not stop.is_set():
            sample = sampler.sample()
            state, reasons = pressure(sample)
            if not args.execute:
                count = int(db.query(batch_sql(user, cutoff, policy.batch, preview=True)))
                log("user=%d next-batch-IDs=%d %s; state=%s %s" %
                    (user, count, sample.summary(), state, ", ".join(reasons)))
                break
            if state == "pause":
                policy.backoff(severe=True)
                log("PAUSE 5s: %s; %s; next-batch=%d" %
                    (", ".join(reasons), sample.summary(), policy.batch))
                stop.wait(5)
                continue
            if state == "slow":
                policy.backoff()
            requested = policy.batch
            start = time.monotonic()
            try:
                count = int(db.query(batch_sql(user, cutoff, requested)))
            except DatabaseError as error:
                if error.code not in (1205, 1213, 1969):
                    raise
                failures += 1
                if failures >= 5:
                    raise RuntimeError("Five consecutive timeout/deadlock failures; stopped. "
                                       "Inspect the database before rerunning. " + str(error)) from error
                policy.backoff(severe=True)
                log("Batch rolled back: error=%d; pause 5s; next-batch=%d" %
                    (error.code, policy.batch))
                stop.wait(5)
                continue
            failures = 0
            elapsed = time.monotonic() - start
            if count == 0:
                break
            batches += 1
            total += count
            policy.finished(elapsed, state, count == requested)
            log("COMMITTED batch=%d IDs-this-run=%d user=%d IDs=%d/%d took=%.2fs "
                "next=%d pause=%.1fs %s%s" %
                (batches, total, user, count, requested, elapsed, policy.batch,
                 policy.delay, sample.summary(), " slow=" + ",".join(reasons) if reasons else ""))
            if args.max_batches and batches >= args.max_batches:
                log("Batch limit reached; rerun the same command to continue")
                return 0
            stop.wait(policy.delay)
        if stop.is_set():
            log("Stopped between transactions; committed batches remain. Rerun to continue")
            return 130
    log("%s; committed-batches=%d click-IDs-processed-this-run=%d" %
        ("Complete" if args.execute else "Preview finished; nothing deleted", batches, total))
    return 0


def main(argv=None):
    args = parse_args(argv)
    stop = threading.Event()

    def request_stop(signum, frame):
        log("Stop requested; waiting for the current database call to finish")
        stop.set()

    signal.signal(signal.SIGINT, request_stop)
    signal.signal(signal.SIGTERM, request_stop)
    try:
        # Validate before even opening a connection.
        Controller(args.batch, args.min_batch, args.max_batch)
        cutoff_time(args.before, args.timezone)
        if args.max_batches < 0:
            raise ValueError("--max-batches cannot be negative")
        db = MariaDB(args.database)
        if not Path("/proc/meminfo").is_file():
            raise RuntimeError("Run on the Linux database server; /proc is required")
        with open("/run/lock/p202-adaptive-delete-" + args.database + ".lock", "a") as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as error:
                raise RuntimeError("Another adaptive cleanup for this database is running") from error
            version, raw_paths = db.preflight()
            paths = sorted({p for raw in raw_paths for p in raw.split(":") if p})
            if not paths:
                raise RuntimeError("No database filesystem paths available")
            log("Server=" + version + "; disk checks=" + ",".join(paths))
            return run(args, db, Sampler(db, paths), stop)
    except (OSError, ValueError, RuntimeError, KeyError, ZoneInfoNotFoundError) as error:
        log("STOPPED: " + str(error))
        log("No further batches started. Earlier commits remain; rerun after fixing the error")
        return 1


if __name__ == "__main__":
    sys.exit(main())
