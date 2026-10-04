# FEAT-057 offline identity evidence workbench

These commands use scratch files and public official sources. They never bootstrap
Laravel, connect to a database, start a worker, create a preview or write aliases.
The reviewed runtime registry is `app/config/nse_historical_identity_chains.json`.
Downloading a circular, matching a title, or observing a split is **not** approval
to edit that registry.

From the repository root:

```bash
# Use a scratch virtualenv with pypdf if PDF text extraction is needed.
python app/scripts/nse_identity/acquire.py /tmp/identity-evidence /tmp/official-urls.json
python app/scripts/nse_identity/discover.py /tmp/identity-evidence docs/audit/data/FEAT-057-run3-identity-classification.csv
python app/scripts/nse_identity/acquire.py /tmp/identity-evidence /tmp/identity-evidence/all-urls.json
python3 app/scripts/nse_identity/replay.py /tmp/identity-corpus --output-prefix checkpoint
```

`official-urls.json` is a JSON list of official HTTPS URLs. Seed it with the bulk
circular API URLs recorded in the audit acquisition manifest, or all URLs from that
manifest to resume the exact evidence set. `acquire.py` writes an atomic
`downloads.json` with each attempt, HTTP status, content hash and relative object
path. It verifies cached bytes before skipping them, rejects HTML error responses,
and records retryable failures. Only one writer may use a scratch root. A failed
archive mirror can be retried through the original indexed NSE URL; preserve both
attempt histories. PDF extraction failures are separate from acquisition failures;
raw bytes remain available for another extractor. ZIPs are retained intact; inspect
only named PDF members with bounded decompression, and record member hashes.

`discover.py` matches the original 146 unresolved symbols against acquired bulk
indexes. Its `all-circular-leads.json` retains the index URL/hash and original
circular URL. Name/title matches are deliberately **candidates**: FILATEX/FILATFASH,
GLOBAL/VGL and KAMDHENU/KAMOPAINTS are examples of rejected search collisions.
Company-name searches also matter: some official circular titles omit or mistype
the symbol. Reviewed extra acquisitions and amendments are in the audit manifest.

The offline corpus contains `stocks.json` (only NSE identity fields),
`manifest.json` (source id/date/source/filename/archive and content SHA256/artifact),
and immutable CSV copies under `sources/`. Obtain it through an explicitly
read-only source/master export; no production exporter or DB credentials are part
of this workbench. Replay validates every source content hash and imports the
registry through `export_registry.php`, which uses the actual PHP evidence
validator without Laravel or a DB. It preserves all eligible EQ/BE/BZ company
members, rejects duplicate canonical targets, and writes per-date CSV/JSON plus
unresolved identity/date and observed identity/date ledgers. Its mapping-only
results are **not production acceptance**. Check selected dates with the actual
provider in an isolated test DB before relying on a checkpoint.

A registry review must connect explicit existing/old and new ISINs for the same
security, action and trading effective date. Retain official URLs, SHA256, page,
short relevant excerpt, observation lower bound and day-before-change upper bound.
Every intermediate subdivision requires its own connected, strictly dated link.
Record superseded dates and the exact modifying circular rather than replacing
them silently. Consolidations, insolvency reductions, missing historical master
rows and duplicate targets remain separately documented remediation in this stage.
Never infer continuity from an issuer prefix, matching name/symbol, current ISIN,
or the ISIN column in a retrospective corporate-action listing.
