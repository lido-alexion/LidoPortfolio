import csv, json, collections, hashlib, subprocess, math
from pathlib import Path
import argparse

parser = argparse.ArgumentParser(
    description="Offline sealed-source mapping replay; no database or network access."
)
parser.add_argument(
    "corpus",
    type=Path,
    help="Scratch directory containing manifest.json, stocks.json and sources/",
)
parser.add_argument("--output-prefix", default="replay")
args = parser.parse_args()
root = args.corpus
stocks = json.loads((root / "stocks.json").read_text())
byisin = collections.defaultdict(list)
bysymbol = collections.defaultdict(list)
for s in stocks:
    if not s["is_benchmark"]:
        byisin[(s["isin"] or "").upper()].append(s)
        bysymbol[s["symbol"].upper()].append(s)
registry = json.loads(
    subprocess.check_output(
        ["php", str(Path(__file__).with_name("export_registry.php"))], text=True
    )
)


def members(path):
    with path.open(newline="", encoding="utf-8-sig") as f:
        reader = csv.reader(f)
        header = next(reader)
        index = {h.strip().lower(): i for i, h in enumerate(header)}

        def pick(keys):
            return next((index[k] for k in keys if k in index), None)

        si = pick(["symbol", "ticker", "tradingsymbol", "tckrsymb"])
        se = pick(["series", "scty srs", "sctysrs", "securityseries"])
        ii = pick(["isin", "isin number", "isinno"])
        assert si is not None and se is not None
        dd = {}
        prio = {"EQ": 1, "BE": 2, "BZ": 3}
        for row in reader:
            if len(row) <= max(si, se, ii or 0):
                continue
            sym = row[si].strip().upper()
            series = row[se].strip().upper()
            isin = row[ii].strip().upper() if ii is not None else ""
            if not sym or series not in prio or isin.startswith("INF"):
                continue
            key = "I:" + isin if isin else "S:" + sym
            if key not in dd or prio[series] < prio[dd[key]["series"]]:
                dd[key] = {"symbol": sym, "isin": isin, "series": series}
        return list(dd.values())


def resolve(m, date):
    exact = byisin[m["isin"]] if m["isin"] else []
    if len(exact) > 1:
        return None, "ambiguous_current_isin"
    if len(exact) == 1:
        return exact[0], None
    aliases = [
        a
        for a in registry["identities"]
        if a["historical_isin"] == m["isin"]
        and a["symbol"] == m["symbol"]
        and a["valid_from"] <= date <= a["valid_until"]
    ]
    if len(aliases) == 1:
        targets = byisin[aliases[0]["canonical_isin"]]
        return (
            (targets[0], "dated_identity")
            if len(targets) == 1
            else (None, "historical_target_missing_or_ambiguous")
        )
    syms = bysymbol[m["symbol"]]
    if len(syms) > 1:
        return None, "ambiguous_current_symbol"
    if not syms:
        return None, "no_current_identity_candidate"
    s = syms[0]
    if (
        m["isin"]
        and (s["isin"] or "").strip()
        and s["isin"].strip().upper() != m["isin"]
    ):
        return None, "conflicting_symbol_isin_without_dated_evidence"
    return s, None


if __name__ == "__main__":
    output = []
    unresolved = collections.defaultdict(lambda: {"dates": []})
    observations = collections.defaultdict(list)
    for src in json.loads((root / "manifest.json").read_text()):
        path = (root / src["artifact"]).resolve()
        assert path.is_relative_to(root.resolve())
        assert hashlib.sha256(path.read_bytes()).hexdigest() == src["content_sha256"]
        rows = members(path)
        seen = set()
        counts = collections.Counter()
        mapped = 0
        aliases = 0
        for m in rows:
            observations[m["symbol"] + "|" + m["isin"]].append(src["date"])
            s, reason = resolve(m, src["date"])
            if s is not None and s["id"] in seen:
                s = None
                reason = "duplicate_canonical_target"
            if s is None:
                counts[reason] += 1
                k = m["symbol"] + "|" + m["isin"] + "|" + reason
                unresolved[k]["dates"].append(src["date"])
            else:
                seen.add(s["id"])
                mapped += 1
                aliases += reason == "dated_identity"
        output.append(
            {
                "date": src["date"],
                "source_id": src["id"],
                "source_sha256": src["content_sha256"],
                "source_count": len(rows),
                "mapped_count": mapped,
                "unmapped_count": len(rows) - mapped,
                "mapping_percentage": round(mapped / len(rows) * 100, 4),
                "needed_to_90": max(0, math.ceil(len(rows) * 0.9) - mapped),
                "historical_identity_mapped_count": aliases,
                "identity_evidence_sha256": registry["sha256"],
                **dict(counts),
            }
        )
    (root / (args.output_prefix + ".json")).write_text(json.dumps(output, indent=2))
    (root / "unresolved-by-identity.json").write_text(json.dumps(unresolved))
    (root / "observations.json").write_text(json.dumps(observations))
    keys = list(dict.fromkeys(k for r in output for k in r))
    with (root / (args.output_prefix + ".csv")).open("w") as f:
        w = csv.DictWriter(f, fieldnames=keys, lineterminator="\n")
        w.writeheader()
        w.writerows(output)
    print(
        json.dumps(
            {
                "dates": len(output),
                "passed_mapping_dates": sum(r["needed_to_90"] == 0 for r in output),
                "failed_mapping_dates": sum(r["needed_to_90"] > 0 for r in output),
                "minimum_mapping": min(r["mapping_percentage"] for r in output),
                "maximum_needed": max(r["needed_to_90"] for r in output),
                "first": output[0],
            },
            indent=2,
        )
    )
