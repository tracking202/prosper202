#!/usr/bin/env python3
"""Focused controller tests; live SQL checks are separately opt-in."""
import contextlib
import dataclasses
import importlib.util
import io
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest.mock import Mock, patch

sys.dont_write_bytecode = True

spec = importlib.util.spec_from_file_location(
    "adaptive_delete", Path(__file__).resolve().parents[1] / "p202-adaptive-delete.py")
m = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = m
spec.loader.exec_module(m)


def healthy(**changes):
    values = dict(cpu=20, iowait=0, available=2*m.GIB, memory_total=8*m.GIB,
                  swap_rate=0, load=1, cores=4, threads=1, row_waits=0,
                  new_log_waits=0, new_buffer_waits=0)
    values.update(changes)
    return m.Snapshot(**values)


class NoSleepEvent:
    def __init__(self):
        self.flag = False
        self.waits = []

    def wait(self, seconds):
        self.waits.append(seconds)
        return self.flag

    def is_set(self):
        return self.flag

    def set(self):
        self.flag = True


class AdaptiveDeletionTest(unittest.TestCase):
    def test_target_and_cutoff_must_be_explicit(self):
        required = ["--database", "example_database", "--before", "2025-01-01", "--timezone", "UTC"]
        for args in ([], required[2:], required[:2] + required[4:], required[:4]):
            with self.subTest(args=args), contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
                m.parse_args(args + ["--execute"])
        parsed = m.parse_args(required)
        self.assertFalse(parsed.execute)
        self.assertEqual("example_database", parsed.database)

    def test_cutoff_is_pacific_midnight(self):
        self.assertEqual(1735718400, m.cutoff_time("2025-01-01", "America/Los_Angeles"))
        self.assertEqual(1735689600, m.cutoff_time("2025-01-01", "UTC"))

    def test_bad_dates_and_future_dates_are_refused(self):
        for value in ("2025-02-30", "25-01-01", "2099-01-01", "1960-01-01"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                m.cutoff_time(value, "America/Los_Angeles")

    def test_growth_requires_three_fast_full_batches(self):
        c = m.Controller()
        c.finished(.2, "healthy", False)
        self.assertEqual(500, c.batch)
        for _ in range(2):
            c.finished(.2, "healthy", True)
        self.assertEqual(500, c.batch)
        c.finished(.2, "healthy", True)
        self.assertEqual(625, c.batch)

    def test_growth_and_reductions_stay_bounded(self):
        c = m.Controller()
        for _ in range(100):
            c.finished(.2, "healthy", True)
        self.assertEqual(5000, c.batch)
        for _ in range(100):
            c.backoff(True)
        self.assertEqual(100, c.batch)
        self.assertEqual(5, c.delay)

    def test_partial_batch_breaks_growth_streak(self):
        c = m.Controller()
        c.finished(.2, "healthy", True)
        c.finished(.2, "healthy", True)
        c.finished(.2, "healthy", False)
        c.finished(.2, "healthy", True)
        self.assertEqual(500, c.batch)

    def test_slow_batch_halves_size(self):
        c = m.Controller()
        c.finished(3, "healthy", True)
        self.assertEqual(250, c.batch)
        self.assertGreaterEqual(c.delay, 1)

    def test_warm_resources_never_grow_size(self):
        c = m.Controller()
        c.backoff()
        for _ in range(10):
            c.finished(.2, "slow", True)
        self.assertEqual(375, c.batch)

    def test_invalid_batch_bounds_are_refused(self):
        for args in ((0, 0, 1), (500, 600, 1000), (500, 100, 10001)):
            with self.subTest(args=args), self.assertRaises(ValueError):
                m.Controller(*args)

    def test_every_high_pressure_signal_pauses(self):
        cases = [dict(cpu=90), dict(iowait=20), dict(available=500*m.MIB),
                 dict(swap_rate=2*m.MIB), dict(load=6), dict(threads=4),
                 dict(row_waits=1), dict(new_log_waits=1),
                 dict(new_buffer_waits=1), dict(low_disks=("/tmp",))]
        for change in cases:
            with self.subTest(change=change):
                state, reasons = m.pressure(healthy(**change))
                self.assertEqual("pause", state)
                self.assertTrue(reasons)

    def test_moderate_pressure_slows_and_healthy_is_allowed(self):
        for change in (dict(cpu=70), dict(iowait=8), dict(available=1100*m.MIB),
                       dict(swap_rate=.2*m.MIB), dict(load=3.5), dict(threads=2)):
            with self.subTest(change=change):
                self.assertEqual("slow", m.pressure(healthy(**change))[0])
        self.assertEqual(("healthy", []), m.pressure(healthy()))

    def test_sampler_uses_interval_cpu_and_swap_not_boot_average(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "stat").write_text("cpu 100 0 0 800 100 0 0 0 0 0\n")
            (root / "meminfo").write_text("MemTotal: 8388608 kB\nMemAvailable: 2097152 kB\n")
            (root / "vmstat").write_text("pswpin 100000\npswpout 100000\n")
            db = Mock()
            status = dict(threads_running=1, innodb_row_lock_current_waits=0,
                          innodb_log_waits=2, innodb_buffer_pool_wait_free=0)
            db.status.side_effect = [status, dict(status, innodb_log_waits=3)]
            sampler = m.Sampler(db, [], root)
            with patch.object(m.time, "monotonic", side_effect=[1, 3]):
                self.assertIsNone(sampler.sample())
                (root / "stat").write_text("cpu 140 0 0 850 110 0 0 0 0 0\n")
                (root / "vmstat").write_text("pswpin 100010\npswpout 100000\n")
                s = sampler.sample()
            self.assertEqual(40, s.cpu)
            self.assertEqual(10, s.iowait)
            self.assertEqual(5 * m.os.sysconf("SC_PAGE_SIZE"), s.swap_rate)
            self.assertEqual(1, s.new_log_waits)

    def test_mariadb_client_failure_is_not_an_empty_batch(self):
        result = subprocess.CompletedProcess([], 1, "", "ERROR 1205 (HY000): timed out")
        with patch.object(m.subprocess, "run", return_value=result) as call:
            with self.assertRaises(m.DatabaseError) as error:
                m.MariaDB("example_database").query("SELECT 1;")
        self.assertEqual(1205, error.exception.code)
        self.assertNotIn("--force", call.call_args.args[0])
        self.assertTrue(call.call_args.kwargs["start_new_session"])

    def test_database_name_and_selection_cannot_inject_sql(self):
        for value in ("x;DROP DATABASE y", "x`", "--help", "x/y"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                m.MariaDB(value)
        for args in ((-1, 1, 1), (1, 0, 1), (1, 1, 10001)):
            with self.subTest(args=args), self.assertRaises(ValueError):
                m.expired_selection(*args)

    def test_preflight_handles_empty_innodb_tmpdir_fallback(self):
        db = m.MariaDB("example_database")
        db.query = Mock(side_effect=[
            "\n".join(t+"\tInnoDB" for t in m.REQUIRED_TABLES),
            "11.4.13-MariaDB", "/var/lib/mysql/\t/tmp\t/tmp"])
        self.assertEqual(("11.4.13-MariaDB", ["/var/lib/mysql/", "/tmp", "/tmp"]),
                         db.preflight())

    def test_missing_or_nontransactional_tables_are_refused(self):
        for rows in ("", "\n".join(t+"\tMyISAM" for t in m.REQUIRED_TABLES)):
            db = m.MariaDB("example_database")
            db.query = Mock(return_value=rows)
            with self.assertRaisesRegex(RuntimeError, "Missing or non-InnoDB"):
                db.preflight()

    def test_missing_db_counters_are_not_assumed_zero(self):
        db = m.MariaDB("example_database")
        db.query = Mock(return_value="Threads_running\t1")
        with self.assertRaisesRegex(RuntimeError, "required MariaDB"):
            db.status()

    def run_controller(self, arguments, results, samples, stop=None):
        db = Mock()
        db.accounts.return_value = [1]
        db.query.side_effect = results
        sampler = Mock()
        sampler.sample.side_effect = [None] + samples
        event = stop or NoSleepEvent()
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            code = m.run(m.parse_args(["--database", "example_database", "--before", "2025-01-01", "--timezone", "UTC"] + arguments), db, sampler, event)
        return code, db, event, output.getvalue()

    def test_preview_never_submits_a_deletion(self):
        code, db, _, out = self.run_controller([], ["3"], [healthy()])
        self.assertEqual(0, code)
        self.assertIn("nothing deleted", out)
        self.assertTrue(db.query.call_args.args[0].startswith("SELECT COUNT"))
        self.assertNotIn("DELETE ", db.query.call_args.args[0])

    def test_pressure_pauses_without_starting_a_transaction(self):
        code, db, event, out = self.run_controller(
            ["--execute"], ["500", "0"], [healthy(cpu=90), healthy(), healthy()])
        self.assertEqual(0, code)
        self.assertEqual(2, db.query.call_count)
        self.assertIn(5, event.waits)
        self.assertIn("PAUSE", out)
        self.assertIn("LIMIT 250", db.query.call_args_list[0].args[0])

    def test_timeout_retries_smaller_batch_after_rollback(self):
        code, db, _, out = self.run_controller(
            ["--execute"], [m.DatabaseError("ERROR 1205: timeout"), "250", "0"],
            [healthy(), healthy(), healthy()])
        self.assertEqual(0, code)
        self.assertIn("rolled back", out)
        self.assertIn("LIMIT 250", db.query.call_args_list[1].args[0])

    def test_unknown_database_error_stops_instead_of_retrying(self):
        with self.assertRaises(m.DatabaseError):
            self.run_controller(["--execute"], [m.DatabaseError("ERROR 1054: bad column")],
                                [healthy()])

    def test_five_consecutive_failures_stop(self):
        with self.assertRaisesRegex(RuntimeError, "Five consecutive"):
            self.run_controller(["--execute"], [m.DatabaseError("ERROR 1213: deadlock")]*5,
                                [healthy()]*5)

    def test_max_batches_stops_after_committed_limit(self):
        code, db, _, out = self.run_controller(
            ["--execute", "--max-batches", "1"], ["500"], [healthy()])
        self.assertEqual(0, code)
        self.assertEqual(1, db.query.call_count)
        self.assertIn("Batch limit reached", out)

    def test_interrupt_waits_for_current_batch_and_starts_no_more(self):
        stop = NoSleepEvent()
        def finish_batch(sql):
            stop.set()
            return "500"
        code, db, _, out = self.run_controller(["--execute"], finish_batch, [healthy()], stop)
        self.assertEqual(130, code)
        self.assertEqual(1, db.query.call_count)
        self.assertIn("Stopped between transactions", out)


if __name__ == "__main__":
    unittest.main(verbosity=2)
