#!/usr/bin/env python3
import argparse
import fcntl
import ipaddress
import json
import re
import sys
import syslog
from collections import defaultdict
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parent.parent
LOG_DIR = ROOT / "logs"
STATE_DIR = Path("/var/lib/seefish-security-monitor")
STATE_FILE = STATE_DIR / "state.json"
LOCK_FILE = STATE_DIR / "monitor.lock"
ALERT_FILE = Path("/var/log/seefish-security-monitor.log")

TIMEZONE = ZoneInfo("Asia/Makassar")
WINDOW_MINUTES = 10
THRESHOLD = 5
COOLDOWN_MINUTES = 30
MAX_READ_BYTES = 2 * 1024 * 1024

PATTERN = re.compile(
    r'^\[(?P<time>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]'
    r'\s+\[LOGIN_FAILED\]\s+user_id=(?P<uid>\d+)'
    r'\s+ip=(?P<ip>\S+)'
)

def read_recent_lines(path):
    with path.open("rb") as handle:
        handle.seek(0, 2)
        size = handle.tell()
        handle.seek(max(0, size - MAX_READ_BYTES))
        if size > MAX_READ_BYTES:
            handle.readline()
        data = handle.read()
    return data.decode("utf-8", errors="replace").splitlines()

def collect_failures(now):
    cutoff = now - timedelta(minutes=WINDOW_MINUTES)
    failures = defaultdict(list)

    for path in LOG_DIR.glob("activity_*.log"):
        try:
            for line in read_recent_lines(path):
                match = PATTERN.match(line)
                if not match:
                    continue

                try:
                    event_time = datetime.strptime(
                        match.group("time"), "%Y-%m-%d %H:%M:%S"
                    ).replace(tzinfo=TIMEZONE)
                    ip = str(ipaddress.ip_address(match.group("ip")))
                except ValueError:
                    continue

                if cutoff <= event_time <= now:
                    failures[ip].append(event_time)
        except (OSError, PermissionError) as error:
            print(f"MONITOR_ERROR file={path.name} type={type(error).__name__}")
    return failures

def load_state():
    try:
        state = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        return state if isinstance(state, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}

def save_state(state):
    temp = STATE_FILE.with_suffix(".tmp")
    temp.write_text(json.dumps(state), encoding="utf-8")
    temp.chmod(0o600)
    temp.replace(STATE_FILE)

def emit_alert(ip, count, now):
    message = (
        f"SECURITY_ALERT event=REPEATED_LOGIN_FAILURE "
        f"ip={ip} count={count} window_minutes={WINDOW_MINUTES}"
    )
    syslog.syslog(syslog.LOG_WARNING, message)
    with ALERT_FILE.open("a", encoding="utf-8") as handle:
        handle.write(f"[{now.isoformat()}] {message}\n")
    print(message)

def run_monitor():
    STATE_DIR.mkdir(mode=0o700, parents=True, exist_ok=True)

    with LOCK_FILE.open("a+") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        now = datetime.now(TIMEZONE)
        failures = collect_failures(now)
        state = load_state()
        alerts = 0

        for ip, events in failures.items():
            if len(events) < THRESHOLD:
                continue

            previous = state.get(ip)
            if previous:
                try:
                    last_alert = datetime.fromisoformat(previous)
                    if now - last_alert < timedelta(minutes=COOLDOWN_MINUTES):
                        continue
                except (ValueError, TypeError):
                    pass

            emit_alert(ip, len(events), now)
            state[ip] = now.isoformat()
            alerts += 1

        # Hapus status cooldown yang sudah kedaluwarsa.
        state = {
            ip: timestamp for ip, timestamp in state.items()
            if isinstance(timestamp, str)
            and _is_recent(timestamp, now)
        }
        save_state(state)

        if alerts == 0:
            print(f"MONITOR_OK checked_ips={len(failures)} alerts=0")

def _is_recent(timestamp, now):
    try:
        return now - datetime.fromisoformat(timestamp) < timedelta(hours=24)
    except (ValueError, TypeError):
        return False

def self_test():
    now = datetime.now(TIMEZONE)
    sample = []
    stamp = now.strftime("%Y-%m-%d %H:%M:%S")

    for number in range(5):
        sample.append(
            f"[{stamp}] [LOGIN_FAILED] user_id=0 "
            f"ip=203.0.113.10 info=username=test{number}"
        )

    valid = sum(1 for line in sample if PATTERN.match(line))
    invalid_ip = "999.999.999.999"

    try:
        ipaddress.ip_address(invalid_ip)
        raise AssertionError("IP tidak valid justru diterima")
    except ValueError:
        pass

    assert valid == THRESHOLD
    print("PASS: 5 event LOGIN_FAILED berhasil dikenali.")
    print("PASS: IP tidak valid ditolak.")
    print("PASS: ambang deteksi sesuai konfigurasi.")
    print("SELF_TEST_OK")

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    try:
        if args.self_test:
            self_test()
        else:
            run_monitor()
    except BlockingIOError:
        print("MONITOR_SKIP another_instance_running")
    except Exception as error:
        print(f"MONITOR_ERROR type={type(error).__name__}")
        sys.exit(1)
