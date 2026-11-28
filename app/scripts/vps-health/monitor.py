#!/usr/bin/env python3
"""Small, read-only VPS health monitor. Python standard library only."""
import argparse
import collections
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import time
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

DEFAULTS = {
    "STATE_DIR": "/var/lib/vps-health", "ACCESS_LOG": "/var/log/nginx/access.log",
    "ERROR_LOG": "/var/log/nginx/error.log", "SLOW_LOG": "/var/log/php8.4-fpm-slow.log",
    "FPM_STATUS_HOST": "127.0.0.1", "FPM_STATUS_PORT": "9001",
    "FPM_STATUS_ENABLED": "false", "ACCESS_SCAN_BYTES": "1048576",
    "ERROR_SCAN_BYTES": "262144", "SLOW_SCAN_BYTES": "16384",
    "DISK_CRITICAL_PERCENT": "90", "RAM_CRITICAL_PERCENT": "10",
    "SWAP_CRITICAL_PERCENT": "80", "LOAD_PER_CORE_CRITICAL": "2",
    "FPM_MAX_CHILDREN": "5", "NGINX_499_CRITICAL": "5",
    "ALERT_COOLDOWN_MINUTES": "60", "HEARTBEAT_URL": "",
}
MAX_LINE = 8192

def cfg(k): return os.environ.get("VPS_HEALTH_" + k, DEFAULTS.get(k, ""))
def now(): return datetime.now(timezone.utc)
def stamp(): return now().isoformat(timespec="seconds")
def secure_dir(path):
    p = Path(path); p.mkdir(parents=True, exist_ok=True, mode=0o750)
    try: os.chmod(p, 0o750)
    except OSError: pass
    return p
def bounded_tail(path, limit):
    try:
        with open(path, "rb") as f:
            f.seek(0, 2); size = f.tell(); f.seek(max(0, size-limit))
            data = f.read(limit).decode("utf-8", "replace")
        return data.splitlines()[-5000:]
    except OSError: return []
def log_age(line):
    match=re.search(r"\[([^]]+)\]",line)
    if not match: return None
    try: return time.time()-datetime.strptime(match.group(1),"%d/%b/%Y:%H:%M:%S %z").timestamp()
    except ValueError: return None
def run(cmd, timeout=3, env=None):
    try:
        p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                           text=True, timeout=timeout, check=False, env=env)
        return p.stdout[:32768] if p.returncode == 0 else ""
    except (OSError, subprocess.TimeoutExpired): return ""
def fpm_status():
    if cfg("FPM_STATUS_ENABLED").lower() != "true": return {}
    host = cfg("FPM_STATUS_HOST")
    if host not in ("127.0.0.1", "::1"): return {}
    port = cfg("FPM_STATUS_PORT")
    if not port.isdigit() or not shutil.which("cgi-fcgi"): return {}
    # Pass only FastCGI request metadata; never forward notification credentials.
    cgi_env = {"SCRIPT_NAME":"/fpm-status", "SCRIPT_FILENAME":"/fpm-status",
               "REQUEST_METHOD":"GET", "QUERY_STRING":"", "SERVER_PROTOCOL":"HTTP/1.1",
               "GATEWAY_INTERFACE":"CGI/1.1", "FCGI_ROLE":"RESPONDER",
               "SERVER_SOFTWARE":"vps-health-monitor", "SERVER_NAME":"localhost",
               "SERVER_PORT":"80", "REMOTE_ADDR":"127.0.0.1", "REMOTE_PORT":"0",
               "CONTENT_LENGTH":"0"}
    out = run(["cgi-fcgi", "-bind", "-connect", f"{host}:{port}"], 2, env=cgi_env)
    result = {}
    for key in ("listen queue", "active processes", "idle processes", "max active processes", "max children reached"):
        m = re.search(r"^" + re.escape(key) + r":\s*(\d+)", out, re.M | re.I)
        if m: result[key] = int(m.group(1))
    return result
def log_counts(window_seconds=300):
    counts = collections.Counter()
    # Scan only the bounded tail. Query strings are never retained or emitted.
    for line in bounded_tail(cfg("ACCESS_LOG"), int(cfg("ACCESS_SCAN_BYTES"))):
        m = re.search(r'"(?:GET|POST|HEAD|PUT|DELETE|OPTIONS|PATCH)\s+([^ ?"]+)(?:\?[^ "]*)?\s+HTTP/[^" ]+"\s+(\d{3})\b', line)
        age = log_age(line)
        if m and m.group(2) in ("499", "502", "503", "504") and age is not None and 0 <= age <= window_seconds:
            counts[m.group(2)] += 1
    return dict(counts)
def mariadb_activity():
    """Return allowlisted aggregate counters only; never capture process lists or SQL."""
    binary = shutil.which("mariadb-admin") or shutil.which("mysqladmin")
    if not binary: return {}
    # The client may read its normal private option files. Never pass credentials on argv,
    # inherit notification secrets, or retain the raw command output.
    child_env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": str(Path.home())}
    output = run([binary, "--connect-timeout=2", "status"], 3, env=child_env)
    labels = {
        "uptime": "Uptime", "threads": "Threads", "questions": "Questions",
        "slow_queries": "Slow queries", "opens": "Opens", "open_tables": "Open tables",
    }
    result = {}
    for key, label in labels.items():
        match = re.search(r"(?:^|\s)" + re.escape(label) + r":\s*(\d+)", output, re.I)
        if match: result[key] = int(match.group(1))
    return result

def disk_io_counters():
    """Read aggregate counters for the block device backing /."""
    try:
        device = os.stat("/").st_dev
        major, minor = os.major(device), os.minor(device)
        for line in Path("/proc/diskstats").read_text().splitlines():
            fields = line.split()
            if len(fields) >= 10 and int(fields[0]) == major and int(fields[1]) == minor:
                return {"read_bytes": int(fields[5]) * 512, "write_bytes": int(fields[9]) * 512}
    except (OSError, ValueError, IndexError):
        pass
    return None

def disk_io_rate(current, previous, elapsed):
    if not current or not previous or elapsed <= 0:
        return {"status": "unavailable", "read_bytes_per_second": None, "write_bytes_per_second": None}
    read_delta = current["read_bytes"] - previous.get("read_bytes", current["read_bytes"])
    write_delta = current["write_bytes"] - previous.get("write_bytes", current["write_bytes"])
    if read_delta < 0 or write_delta < 0:
        return {"status": "unavailable", "read_bytes_per_second": None, "write_bytes_per_second": None}
    return {"status": "available", "read_bytes_per_second": round(read_delta / elapsed, 1),
            "write_bytes_per_second": round(write_delta / elapsed, 1)}

def cpu_iowait_counters():
    """Return cumulative Linux CPU iowait ticks, when /proc exposes them."""
    try:
        fields = Path("/proc/stat").read_text().splitlines()[0].split()
        if fields[0] == "cpu" and len(fields) > 6:
            return {"iowait": int(fields[5]), "total": sum(map(int, fields[1:]))}
    except (OSError, ValueError, IndexError):
        pass
    return None

def iowait_rate(current, previous):
    if not current or not previous:
        return None
    total = current["total"] - previous.get("total", current["total"])
    wait = current["iowait"] - previous.get("iowait", current["iowait"])
    if total <= 0 or wait < 0:
        return None
    return round(100 * wait / total, 1)

def bounded_directory_breakdown(budget_entries=500, budget_seconds=0.25):
    """Bounded visible category samples and identifiable cleanup candidates; never deletes or follows links."""
    started = time.monotonic()
    entries = 0
    roots = ("/var/log", "/var/lib", "/var/cache", "/var/tmp", "/tmp", "/home", "/opt")
    totals = {root: 0 for root in roots}
    states = {root: "available" for root in roots}
    candidate_bytes = {"cache": 0, "rotated_logs": 0}
    candidate_states = {"cache": "available", "rotated_logs": "available"}
    # The service unit intentionally hides home and gives the process a private /tmp.
    # Those namespaces cannot be reported as host-wide measurements from this service.
    states["/home"] = "unavailable"
    states["/tmp"] = "unavailable"
    for root in roots:
        if root in ("/home", "/tmp"):
            continue
        if entries >= budget_entries or time.monotonic() - started >= budget_seconds:
            for pending in roots[roots.index(root):]: states[pending] = "partial"
            break
        try:
            with os.scandir(root) as children:
                for child in children:
                    if entries >= budget_entries or time.monotonic() - started >= budget_seconds:
                        states[root] = "partial"
                        break
                    entries += 1
                    try:
                        if child.is_symlink():
                            continue
                        if child.is_file(follow_symlinks=False):
                            size = child.stat(follow_symlinks=False).st_size
                            totals[root] = totals.get(root, 0) + size
                            name = child.name
                            if root == "/var/cache":
                                candidate_bytes["cache"] += size
                            if root == "/var/log" and re.search(r"(?:\.gz|\.[0-9]+|\.old)$", name):
                                candidate_bytes["rotated_logs"] += size
                        elif child.is_dir(follow_symlinks=False):
                            subtotal = 0
                            try:
                                with os.scandir(child.path) as files:
                                    for file in files:
                                        if entries >= budget_entries or time.monotonic() - started >= budget_seconds:
                                            states[root] = "partial"
                                            break
                                        entries += 1
                                        if file.is_file(follow_symlinks=False):
                                            size = file.stat(follow_symlinks=False).st_size
                                            subtotal += size
                                            if root == "/var/cache":
                                                candidate_bytes["cache"] += size
                                            if root == "/var/log" and re.search(r"(?:\.gz|\.[0-9]+|\.old)$", file.name):
                                                candidate_bytes["rotated_logs"] += size
                            except OSError:
                                states[root] = "partial"
                            totals[root] += subtotal
                    except OSError:
                        states[root] = "partial"
        except OSError:
            states[root] = "unavailable"
    results = [{"path": path, "shallow_file_bytes": totals[path] if states[path] != "unavailable" else None, "status": states[path]} for path in roots]
    candidate_states["cache"] = states["/var/cache"]
    candidate_states["rotated_logs"] = states["/var/log"]
    overall = "unavailable" if all(value == "unavailable" for value in states.values()) else ("partial" if any(value != "available" for value in states.values()) else "available")
    return {"status": overall, "scanned_at": stamp(), "entries_scanned": entries, "paths": results,
            "reclaimable_candidates": [
                {"kind": "cache_files", "bytes": candidate_bytes["cache"] if candidate_states["cache"] != "unavailable" else None, "status": candidate_states["cache"], "guaranteed_safe_to_delete": False},
                {"kind": "rotated_log_files", "bytes": candidate_bytes["rotated_logs"] if candidate_states["rotated_logs"] != "unavailable" else None, "status": candidate_states["rotated_logs"], "guaranteed_safe_to_delete": False},
            ],
            "visibility": {"home": "unavailable: ProtectHome=yes", "tmp": "unavailable: PrivateTmp=yes; service-private namespace"}}

def collect():
    load1, load5, load15 = os.getloadavg()
    cpus = max(1, os.cpu_count() or 1)
    mem = {}
    try:
        for line in Path("/proc/meminfo").read_text().splitlines():
            m = re.match(r"(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+)", line)
            if m: mem[m.group(1)] = int(m.group(2)) * 1024
    except OSError: pass
    total, avail = mem.get("MemTotal", 0), mem.get("MemAvailable", 0)
    swap, swapfree = mem.get("SwapTotal", 0), mem.get("SwapFree", 0)
    fs = os.statvfs("/")
    disk_total = fs.f_blocks * fs.f_frsize
    disk_free = fs.f_bfree * fs.f_frsize
    disk_available = fs.f_bavail * fs.f_frsize
    disk_used = disk_total - disk_free
    inode = None
    try:
        stat = os.statvfs("/")
        inode = {"total": stat.f_files, "used": stat.f_files - stat.f_ffree, "available": stat.f_favail,
                 "used_percent": round(100 * (stat.f_files - stat.f_ffree) / stat.f_files, 1) if stat.f_files else None}
    except OSError:
        pass
    return {"time": stamp(), "load1": round(load1, 2), "load5": round(load5, 2), "load15": round(load15, 2),
            "cpus": cpus, "load_per_core": round(load1/cpus, 3),
            "ram_available_percent": round(100*avail/total, 1) if total else None,
            "ram_available_bytes": avail, "swap_used_percent": round(100*(swap-swapfree)/swap, 1) if swap else 0,
            "root_total_bytes": disk_total, "root_used_bytes": disk_used, "root_available_bytes": disk_available,
            "root_used_percent": round(100*disk_used/(disk_used+disk_available), 1) if disk_used+disk_available else None,
            "root_available_percent": round(100*disk_available/disk_total, 1) if disk_total else None,
            "root_disk_critical_percent": float(cfg("DISK_CRITICAL_PERCENT")),
            "root_inode": inode, "_disk_io_counters": disk_io_counters(),
            "_cpu_iowait_counters": cpu_iowait_counters(), "fpm": fpm_status(),
            "nginx": log_counts(300), "nginx_minute": log_counts(60)}
def read_state(directory):
    try: return json.loads((directory/"state.json").read_text())
    except (OSError, ValueError): return {}
def save_state(directory, state):
    tmp = directory/"state.json.tmp"; tmp.write_text(json.dumps(state, indent=2)); os.chmod(tmp, 0o640); tmp.replace(directory/"state.json")
def criticals(m, old):
    history = old.get("history", [])
    def streak(key, condition): return condition and bool(history) and bool(history[-1].get(key))
    f = m["fpm"]
    issues=[]
    if f.get("listen queue", 0) > 0: issues.append("FPM listen queue is nonzero")
    if sum(m["nginx"].get(x,0) for x in ("502","504")) >= 3: issues.append("at least 3 Nginx 502/504 responses in scanned recent log")
    if m["nginx"].get("499",0) >= int(cfg("NGINX_499_CRITICAL")): issues.append("Nginx 499 client-closed responses reached the configured five-minute threshold")
    disk_percent = m.get("root_used_percent")
    if disk_percent is not None:
        for threshold in sorted({75.0, 85.0, float(cfg("DISK_CRITICAL_PERCENT"))}):
            if disk_percent >= threshold:
                issues.append(f"root filesystem usage reached {threshold:g}% threshold")
    if m["ram_available_percent"] is not None and m["ram_available_percent"] < float(cfg("RAM_CRITICAL_PERCENT")): issues.append("available RAM is critical")
    if m["swap_used_percent"] > float(cfg("SWAP_CRITICAL_PERCENT")): issues.append("swap usage is critical")
    if m["load_per_core"] >= float(cfg("LOAD_PER_CORE_CRITICAL")) and streak("high_load", True): issues.append("1-minute load per core is critical for two checks")
    # FPM saturation inferred from active=max configured children. Parse the status field when available.
    active=f.get("active processes"); maxchildren=int(cfg("FPM_MAX_CHILDREN") or 0) or f.get("max children")
    if active is not None and maxchildren and active >= maxchildren and streak("fpm_saturated", True): issues.append("FPM active processes at max children for two checks")
    flags={"high_load":m["load_per_core"] >= float(cfg("LOAD_PER_CORE_CRITICAL")),
           "fpm_saturated":active is not None and maxchildren is not None and active >= maxchildren}
    return issues, flags
def parse_fpm_totals(f):
    configured=int(cfg("FPM_MAX_CHILDREN") or 0)
    if configured > 0: f["max children"] = configured
    return f
def top_paths():
    counts=collections.Counter()
    for line in bounded_tail(cfg("ACCESS_LOG"), int(cfg("ACCESS_SCAN_BYTES"))):
        m=re.search(r'"(?:GET|POST|HEAD|PUT|DELETE|OPTIONS|PATCH)\s+([^ ?"]+)(?:\?[^ "]*)?\s+HTTP/[^" ]+"\s+(?:499|5\d\d)\b',line)
        age=log_age(line)
        if m and age is not None and 0 <= age <= 300: counts[m.group(1)[:200]]+=1
    return counts.most_common(5)
def sanitize_log_line(line):
    line=re.sub(r"\?[^\s\"'<>]*", "?[redacted]", line)
    return re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "[ip]", line)[:240]
def diagnose(m=None):
    m=m or collect(); errors=bounded_tail(cfg("ERROR_LOG"),int(cfg("ERROR_SCAN_BYTES")))
    diagnostic_metrics={key:value for key,value in m.items() if not key.startswith("_")}
    slow=bounded_tail(cfg("SLOW_LOG"),int(cfg("SLOW_SCAN_BYTES")))
    procs=run(["ps","-eo","pid,comm,%cpu,%mem,etime","--sort=-%cpu"],2).splitlines()[:8]
    sysstat={}
    for name,command in (("vmstat",["vmstat","1","2"]),("iostat",["iostat","-xz","1","2"]),
                         ("pidstat",["pidstat","-dru","1","1"])):
        if shutil.which(command[0]):
            sysstat[name]=run(command,4).splitlines()[-30:]
    return {"time":stamp(),"metrics":diagnostic_metrics,"top_nginx_paths":top_paths(),
            "nginx_error_summaries":[sanitize_log_line(x) for x in errors[-5:]],
            "php_fpm_slow_log_excerpt":[sanitize_log_line(x) for x in slow[-20:]],
            "process_top":procs,"mariadb_activity":mariadb_activity(),"sysstat":sysstat}
def prune_diagnostics(directory):
    cutoff=time.time()-14*86400
    paths=sorted(directory.glob("diagnostic-*.json"), key=lambda p: p.stat().st_mtime if p.exists() else 0)
    for p in paths[:-500]:
        try: p.unlink()
        except OSError: pass
    for p in paths:
        try:
            if p.stat().st_mtime < cutoff: p.unlink()
        except OSError: pass
def save_diagnostic(d, directory):
    prune_diagnostics(directory)
    path=directory/("diagnostic-"+now().strftime("%Y%m%dT%H%M%SZ")+".json")
    path.write_text(json.dumps(d,indent=2)); os.chmod(path,0o640)
    return path
def channel_status(status=None):
    status = status or {}
    return {"telegram": int(status.get("telegram_recipients", 0)) > 0,
            "email": int(status.get("email_recipients", 0)) > 0,
            "cache_used": bool(status.get("cache_used", False))}
def sample_status(metrics, issues):
    disk_percent = metrics.get("root_used_percent")
    critical_threshold = float(cfg("DISK_CRITICAL_PERCENT"))
    disk_only = {f"root filesystem usage reached {threshold:g}% threshold" for threshold in (75.0, 85.0, critical_threshold)}
    return "critical" if (disk_percent is not None and disk_percent >= critical_threshold) or any(issue not in disk_only for issue in issues) else ("warning" if disk_percent is not None and disk_percent >= 75 else "ok")

def record_sample(metrics, issues):
    artisan = Path(__file__).resolve().parents[2] / "artisan"
    if not artisan.is_file() or not shutil.which("php"):
        return False
    status = sample_status(metrics, issues)
    payload = {"time": metrics.get("time", stamp()), "status": status,
               "issues": issues, "metrics": metrics}
    child_env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": str(Path.home()),
                 "VPS_HEALTH_STATE_DIR": cfg("STATE_DIR")}
    try:
        result = subprocess.run(
            ["php", str(artisan), "vps-health:record"], cwd=str(artisan.parent),
            input=json.dumps(payload), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            text=True, timeout=8, check=False, env=child_env,
        )
        return result.returncode == 0
    except (OSError, subprocess.TimeoutExpired):
        return False
def notify(text, urgent=False):
    artisan = Path(__file__).resolve().parents[2] / "artisan"
    if not artisan.is_file() or not shutil.which("php"):
        print("notification failure: StoX CLI is unavailable", file=sys.stderr)
        return {"success": False, "email_recipients": 0, "telegram_recipients": 0, "cache_used": False}
    payload = {"title": "VPS health alert" if urgent else "VPS health 30-minute digest",
               "message": text[:8000], "urgent": urgent}
    child_env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": str(Path.home()),
                 "VPS_HEALTH_STATE_DIR": cfg("STATE_DIR")}
    try:
        process = subprocess.run(
            ["php", str(artisan), "vps-health:notify"], cwd=str(artisan.parent),
            input=json.dumps(payload), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            text=True, timeout=20, check=False, env=child_env,
        )
        result = json.loads(process.stdout.strip()) if process.stdout.strip() else {}
        if isinstance(result, dict) and result:
            result["success"] = bool(result.get("success")) and process.returncode == 0
            return result
    except (OSError, subprocess.TimeoutExpired, ValueError):
        pass
    print("notification failure: StoX could not deliver to admin channels", file=sys.stderr)
    return {"success": False, "email_recipients": 0, "telegram_recipients": 0, "cache_used": False}
def heartbeat():
    url=cfg("HEARTBEAT_URL")
    if url:
        try: urllib.request.urlopen(url,timeout=4).read(256)
        except Exception: pass
def summary(directory):
    state=read_state(directory); hist=state.get("history",[]); latest=hist[-1].get("metrics",{}) if hist else {}
    ago=time.time()-1800
    rows=[r for r in hist if r.get("epoch",0)>=ago]
    totals=log_counts(1800)
    return {"window_minutes":30,"samples":len(rows),"latest":latest,"nginx_counts":totals,
            "channels":channel_status(state.get("last_notification_status"))}
def check(directory):
    prune_diagnostics(directory)
    m=collect(); state=read_state(directory); hist=state.get("history", [])
    elapsed = time.time() - hist[-1].get("epoch", time.time()) if hist else 0
    current_io = m.pop("_disk_io_counters", None)
    m["disk_io"] = disk_io_rate(current_io, state.get("_disk_io_counters"), elapsed)
    current_cpu = m.pop("_cpu_iowait_counters", None)
    m["disk_iowait_percent"] = iowait_rate(current_cpu, state.get("_cpu_iowait_counters"))
    state["_cpu_iowait_counters"] = current_cpu
    previous_breakdown = state.get("directory_breakdown_at", 0)
    if time.time() - previous_breakdown >= 3600:
        state["directory_breakdown"] = bounded_directory_breakdown()
        state["directory_breakdown_at"] = time.time()
    m["directory_breakdown"] = state.get("directory_breakdown", {"status": "unavailable", "entries_scanned": 0, "paths": []})
    f=parse_fpm_totals(m["fpm"]); m["fpm"]=f
    state["_disk_io_counters"] = current_io
    issues,flags=criticals(m,state); hist.append({"epoch":time.time(),"metrics":m,**flags}); hist=hist[-1440:]
    state["history"]=hist; state.setdefault("active_issues",[])
    previous=set(state["active_issues"]); current=set(issues)
    critical_threshold = float(cfg("DISK_CRITICAL_PERCENT"))
    disk_thresholds = {f"root filesystem usage reached {threshold:g}% threshold" for threshold in (75.0, 85.0, critical_threshold)}
    active_disk_thresholds = current & disk_thresholds
    notified_disk_thresholds = set(state.get("disk_thresholds_notified", [])) & active_disk_thresholds
    pending_disk_thresholds = active_disk_thresholds - notified_disk_thresholds
    if current:
        cooldown=float(cfg("ALERT_COOLDOWN_MINUTES"))*60
        if time.time()-state.get("last_alert",0) >= cooldown:
            notification_issues = (current - disk_thresholds) | pending_disk_thresholds
            if notification_issues:
                d=diagnose(m); path=save_diagnostic(d,directory)
                delivery = notify("Evidence suggests a VPS bottleneck; this snapshot does not prove root cause.\n"+"\n".join("- "+x for x in sorted(notification_issues))+"\nSnapshot: "+str(path)+"\nFirst steps: inspect FPM queue/workers, Nginx/PHP errors, disk/RAM pressure, and recent deploys; avoid automatic restarts.",True)
                state["last_notification_status"] = delivery
                if delivery.get("success"):
                    state["last_alert"]=time.time()
                    notified_disk_thresholds |= pending_disk_thresholds
        state["disk_thresholds_notified"] = sorted(notified_disk_thresholds)
        state["active_issues"]=issues
    elif previous:
        recovery_delivery = notify("VPS health recovered at "+stamp()+". Current critical thresholds are clear.",True)
        state["last_notification_status"] = recovery_delivery
        if recovery_delivery.get("success"):
            state["active_issues"]=[]
            state["disk_thresholds_notified"] = []
    # Periodic digest independently of alert activity.
    if time.time()-state.get("last_digest",0)>=1800:
        sm=summary_from(hist, state.get("last_notification_status"))
        digest_delivery = notify(json.dumps(sm,sort_keys=True),False)
        state["last_notification_status"] = digest_delivery
        if digest_delivery.get("success"):
            state["last_digest"]=time.time()
    state["history"]=hist; save_state(directory,state)
    sample_recorded = record_sample(m, issues)
    heartbeat()
    print(json.dumps({"status":sample_status(m, issues),"issues":issues,"metrics":m,
                      "channels":channel_status(state.get("last_notification_status")),
                      "sample_recorded":sample_recorded},sort_keys=True))
def summary_from(hist, notification_status=None):
    rows=[x for x in hist if x.get("epoch",0)>=time.time()-1800]
    return {"window_minutes":30,"samples":len(rows),"latest":rows[-1].get("metrics",{}) if rows else {},
            "nginx_counts":log_counts(1800),"channels":channel_status(notification_status)}
def main():
    ap=argparse.ArgumentParser(); ap.add_argument("--state-dir",default=cfg("STATE_DIR")); sub=ap.add_subparsers(dest="command",required=True)
    sub.add_parser("check"); sub.add_parser("diagnose"); sub.add_parser("summary")
    a=ap.parse_args(); directory=secure_dir(a.state_dir)
    if a.command=="check": check(directory)
    elif a.command=="summary": print(json.dumps(summary(directory),indent=2,sort_keys=True))
    else:
        d=diagnose(); p=save_diagnostic(d,directory); d["saved_to"]=str(p); print(json.dumps(d,indent=2,sort_keys=True))
if __name__=="__main__": main()
