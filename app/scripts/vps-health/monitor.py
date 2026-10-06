#!/usr/bin/env python3
"""Small, read-only VPS health monitor. Python standard library only."""
import argparse
import collections
import email.message
import json
import os
import re
import shutil
import smtplib
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
    "ALERT_COOLDOWN_MINUTES": "60",
    "HEARTBEAT_URL": "", "SMTP_HOST": "", "SMTP_PORT": "587", "SMTP_USER": "",
    "SMTP_PASSWORD": "", "SMTP_FROM": "", "SMTP_TO": "", "SMTP_STARTTLS": "true",
    "TELEGRAM_BOT_TOKEN": "", "TELEGRAM_CHAT_ID": "",
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
    disk = shutil.disk_usage("/")
    return {"time": stamp(), "load1": round(load1, 2), "load5": round(load5, 2), "load15": round(load15, 2),
            "cpus": cpus, "load_per_core": round(load1/cpus, 3),
            "ram_available_percent": round(100*avail/total, 1) if total else None,
            "ram_available_bytes": avail, "swap_used_percent": round(100*(swap-swapfree)/swap, 1) if swap else 0,
            "root_used_percent": round(100*disk.used/disk.total, 1), "fpm": fpm_status(),
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
    if m["root_used_percent"] >= float(cfg("DISK_CRITICAL_PERCENT")): issues.append("root filesystem usage is critical")
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
    slow=bounded_tail(cfg("SLOW_LOG"),int(cfg("SLOW_SCAN_BYTES")))
    procs=run(["ps","-eo","pid,comm,%cpu,%mem,etime","--sort=-%cpu"],2).splitlines()[:8]
    sysstat={}
    for name,command in (("vmstat",["vmstat","1","2"]),("iostat",["iostat","-xz","1","2"]),
                         ("pidstat",["pidstat","-dru","1","1"])):
        if shutil.which(command[0]):
            sysstat[name]=run(command,4).splitlines()[-30:]
    return {"time":stamp(),"metrics":m,"top_nginx_paths":top_paths(),
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
def channel_status():
    return {"telegram": bool(cfg("TELEGRAM_BOT_TOKEN") and cfg("TELEGRAM_CHAT_ID")),
            "email": bool(cfg("SMTP_HOST") and cfg("SMTP_FROM") and cfg("SMTP_TO"))}
def notify(text, urgent=False):
    ready=channel_status()
    attempted= False
    failed=False
    if ready["telegram"] and urgent:
        attempted=True
        import urllib.parse
        url="https://api.telegram.org/bot"+cfg("TELEGRAM_BOT_TOKEN")+"/sendMessage"
        data=urllib.parse.urlencode({"chat_id":cfg("TELEGRAM_CHAT_ID"),"text":text[:3500]}).encode()
        try: urllib.request.urlopen(urllib.request.Request(url,data=data),timeout=5).read(1024)
        except Exception: failed=True
    if ready["email"]:
        attempted=True
        msg=email.message.EmailMessage(); msg["Subject"]="VPS health " + ("alert" if urgent else "30-minute digest")
        msg["From"]=cfg("SMTP_FROM"); msg["To"]=cfg("SMTP_TO"); msg.set_content(text[:8000])
        try:
            with smtplib.SMTP(cfg("SMTP_HOST"),int(cfg("SMTP_PORT")),timeout=7) as s:
                if cfg("SMTP_STARTTLS").lower()!="false": s.starttls()
                if cfg("SMTP_USER"): s.login(cfg("SMTP_USER"),cfg("SMTP_PASSWORD"))
                s.send_message(msg)
        except Exception: failed=True
    if not attempted:
        print("notification failure: no channels configured", file=sys.stderr)
    elif failed:
        print("notification failure: configured channel delivery failed", file=sys.stderr)
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
    return {"window_minutes":30,"samples":len(rows),"latest":latest,"nginx_counts":totals,"channels":channel_status()}
def check(directory):
    prune_diagnostics(directory)
    m=collect(); state=read_state(directory); f=parse_fpm_totals(m["fpm"]); m["fpm"]=f
    issues,flags=criticals(m,state); hist=state.get("history",[]); hist.append({"epoch":time.time(),"metrics":m,**flags}); hist=hist[-1440:]
    state["history"]=hist; state.setdefault("active_issues",[])
    previous=set(state["active_issues"]); current=set(issues)
    if current:
        cooldown=float(cfg("ALERT_COOLDOWN_MINUTES"))*60
        if time.time()-state.get("last_alert",0) >= cooldown:
            d=diagnose(m); path=save_diagnostic(d,directory)
            notify("Evidence suggests a VPS bottleneck; this snapshot does not prove root cause.\n"+"\n".join("- "+x for x in issues)+"\nSnapshot: "+str(path)+"\nFirst steps: inspect FPM queue/workers, Nginx/PHP errors, disk/RAM pressure, and recent deploys; avoid automatic restarts.",True)
            state["last_alert"]=time.time()
        state["active_issues"]=issues
    elif previous:
        notify("VPS health recovered at "+stamp()+". Current critical thresholds are clear.",True); state["active_issues"]=[]
    # Periodic digest independently of alert activity.
    if time.time()-state.get("last_digest",0)>=1800:
        sm=summary_from(hist); notify(json.dumps(sm,sort_keys=True),False); state["last_digest"]=time.time()
    state["history"]=hist; save_state(directory,state); heartbeat()
    print(json.dumps({"status":"critical" if issues else "ok","issues":issues,"metrics":m,"channels":channel_status()},sort_keys=True))
def summary_from(hist):
    rows=[x for x in hist if x.get("epoch",0)>=time.time()-1800]
    return {"window_minutes":30,"samples":len(rows),"latest":rows[-1].get("metrics",{}) if rows else {},
            "nginx_counts":log_counts(1800),"channels":channel_status()}
def main():
    ap=argparse.ArgumentParser(); ap.add_argument("--state-dir",default=cfg("STATE_DIR")); sub=ap.add_subparsers(dest="command",required=True)
    sub.add_parser("check"); sub.add_parser("diagnose"); sub.add_parser("summary")
    a=ap.parse_args(); directory=secure_dir(a.state_dir)
    if a.command=="check": check(directory)
    elif a.command=="summary": print(json.dumps(summary(directory),indent=2,sort_keys=True))
    else:
        d=diagnose(); p=save_diagnostic(d,directory); d["saved_to"]=str(p); print(json.dumps(d,indent=2,sort_keys=True))
if __name__=="__main__": main()
