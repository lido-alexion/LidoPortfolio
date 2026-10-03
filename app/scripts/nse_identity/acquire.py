"""Resumable official evidence downloads. Never writes a runtime alias registry.

Usage: python acquire.py SCRATCH_ROOT URLS_JSON
Optional PDF text extraction needs pypdf in a scratch virtualenv.
One writer per scratch root, bounded downloads, no credentials, no redirects.
"""

import argparse
import datetime
import fcntl
import hashlib
import json
import logging
from pathlib import Path
import subprocess
from urllib.parse import urlparse


def allowed_url(url):
    parsed = urlparse(url)
    return (
        parsed.scheme == "https"
        and parsed.hostname
        in {"archives.nseindia.com", "nsearchives.nseindia.com", "www.nseindia.com"}
        and parsed.username is None
        and parsed.password is None
        and parsed.port in (None, 443)
    )


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("root", type=Path)
    parser.add_argument("urls", type=Path)
    args = parser.parse_args()
    urls = list(dict.fromkeys(json.loads(args.urls.read_text())))
    if not all(allowed_url(url) for url in urls):
        parser.error("Only public HTTPS NSE URLs are allowed")
    args.root.mkdir(parents=True, exist_ok=True)
    (args.root / "objects").mkdir(exist_ok=True)
    with (args.root / ".acquire.lock").open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        ledger = args.root / "downloads.json"
        index = json.loads(ledger.read_text()) if ledger.exists() else {}
        for url in urls:
            key = hashlib.sha256(url.encode()).hexdigest()
            path = args.root / "objects" / key
            record = index.get(url, {"url": url, "attempts": []})
            if (
                record.get("status") == "acquired"
                and path.exists()
                and hashlib.sha256(path.read_bytes()).hexdigest() == record["sha256"]
            ):
                print(json.dumps({"url": url, "status": "cached"}), flush=True)
                continue
            partial = path.with_suffix(".part")
            run = subprocess.run(
                [
                    "curl",
                    "--http1.1",
                    "--silent",
                    "--proto",
                    "=https",
                    "--max-time",
                    "25",
                    "--max-filesize",
                    "20000000",
                    "--user-agent",
                    "Mozilla/5.0",
                    "--referer",
                    "https://www.nseindia.com/",
                    "--output",
                    str(partial),
                    "--write-out",
                    "%{http_code}",
                    url,
                ],
                capture_output=True,
                text=True,
            )
            data = partial.read_bytes() if partial.exists() else b""
            record["attempts"].append(
                {
                    "at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
                    "http_status": run.stdout.strip(),
                    "curl_exit": run.returncode,
                }
            )
            kind = (
                "pdf"
                if data.startswith(b"%PDF")
                else "zip" if data.startswith(b"PK") else None
            )
            if kind is None:
                try:
                    json.loads(data)
                    kind = "json"
                except (ValueError, UnicodeDecodeError):
                    pass
            if run.returncode == 0 and run.stdout.strip() == "200" and kind:
                partial.replace(path)
                record.update(
                    status="acquired",
                    sha256=hashlib.sha256(data).hexdigest(),
                    bytes=len(data),
                    artifact="objects/" + key,
                    content_kind=kind,
                )
                if kind == "pdf":
                    try:
                        from pypdf import PdfReader

                        logging.getLogger("pypdf").setLevel(logging.ERROR)
                        pages = [
                            page.extract_text() or "" for page in PdfReader(path).pages
                        ]
                        path.with_suffix(".text.json").write_text(json.dumps(pages))
                        record.update(pages=len(pages), extraction_status="extracted")
                    except Exception as error:
                        record["extraction_status"] = type(error).__name__
            else:
                record["status"] = "retryable_public_acquisition_failure"
                partial.unlink(missing_ok=True)
            index[url] = record
            temporary = ledger.with_suffix(".tmp")
            temporary.write_text(json.dumps(index, indent=2) + "\n")
            temporary.replace(ledger)
            print(json.dumps({"url": url, "status": record["status"]}), flush=True)


if __name__ == "__main__":
    main()
