# Acceptance PIT evidence journal storage

Acceptance retains every canonical row's fundamental availability and PIT fact IDs, plus the stock fact inventories used by the audit. The feature calculations, coverage counters, missing-value rules and readiness gates are unchanged.

New journals use lossless gzip JSON Lines in private `pit-evidence.jsonl.gz` files. Campaign evidence declares `pit_evidence_format=jsonl-gzip-v1`; `pit_evidence_sha256` hashes the exact stored artifact, and `pit_evidence_content_sha256` hashes the complete decoded JSON Lines. Record and stored-byte counts are persisted only after successful compression finalization. Older campaign journals remain in their original JSON Lines format and are not rewritten.

The frozen **512 MiB per-horizon storage quota remains enforced**. Every compressed output chunk, including final bytes and the gzip footer, is checked before it is written. An artifact never crosses the quota. No evidence rows are sampled, dropped or silently pruned to fit. Compression uses bounded streaming state rather than buffering the complete journal. An incomplete or oversized journal cannot qualify a campaign; a quota failure records the allowlisted `pit_evidence_quota_exceeded` blocking reason without exposing paths or exception details.

An authorized operator can stream a new journal with `gzip -dc` and verify the decoded SHA-256 against its declared content digest. Successful gzip finalization and both hashes are required evidence; a retained partial file from an interrupted or rejected build is diagnostic material, not qualification. Existing failed campaigns remain immutable; retry qualification through a fresh campaign on the reviewed deployed build.
