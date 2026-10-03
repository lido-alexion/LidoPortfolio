import csv, json, re, collections
from pathlib import Path
import argparse

parser = argparse.ArgumentParser(
    description="Discover candidate circular URLs only; title matches are not identity proof."
)
parser.add_argument("root", type=Path)
parser.add_argument("classification", type=Path)
args = parser.parse_args()
r = args.root
idx = json.loads((r / "downloads.json").read_text())
targets = [
    x
    for x in csv.DictReader(args.classification.open())
    if x["v3_rejection"] == "conflicting_symbol_isin" and x["symbol"] != "NESTLEIND"
]
selected = []
for url, d in idx.items():
    if "/api/circulars?" not in url or d.get("status") != "acquired":
        continue
    obj = json.loads((r / d["artifact"]).read_text())
    for row in obj.get("data", []):
        title = row.get("sub", "")
        syms = [
            t["symbol"]
            for t in targets
            if re.search(
                r"(?<![A-Z0-9])" + re.escape(t["symbol"]) + r"(?![A-Z0-9])", title, re.I
            )
        ]
        if syms and any(
            x in title.lower()
            for x in [
                "change in isin",
                "early pay",
                "corrigendum",
                "revision in ex",
                "revised ex",
            ]
        ):
            selected.append(
                {
                    "symbols": syms,
                    "date": row["cirDate"],
                    "title": title,
                    "url": row["circFilelink"],
                    "index_url": url,
                    "index_sha256": d["sha256"],
                }
            )
(r / "all-circular-leads.json").write_text(json.dumps(selected, indent=2))
urls = [
    x["url"].replace("nsearchives.nseindia.com", "archives.nseindia.com")
    for x in selected
    if x["url"].endswith(".pdf")
]
(r / "all-urls.json").write_text(json.dumps(list(dict.fromkeys(urls))))
counts = collections.Counter(sym for x in selected for sym in x["symbols"])
print(
    json.dumps(
        {
            "targets": len(targets),
            "circular_leads": len(selected),
            "unique_urls": len(set(urls)),
            "targets_without_title_match": [
                t["symbol"] for t in targets if not counts[t["symbol"]]
            ],
        },
        indent=2,
    )
)
