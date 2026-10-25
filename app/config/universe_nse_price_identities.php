<?php

// Official source hashes and trade-date cutoffs reviewed under GH #282.
// Clearing EPI old-ISIN instructions corroborate identity, not a later trade cutoff.
// This catalogue cannot authorize writing unadjusted pre-action price bars.
return json_decode(<<<'JSON'
{
    "version": 1,
    "purpose": "Daily bhavcopy identity acknowledgement only; not price adjustment or ML membership.",
    "transitions": {
        "CML77034": {
            "action": "subdivision",
            "symbol": "AZADIND",
            "old_isin": "INE566M01017",
            "new_isin": "INE566M01025",
            "effective_on": "2026-10-23",
            "evidence": [
                {
                    "role": "old_isin_event",
                    "document": "CMPT77060",
                    "url": "https://nsearchives.nseindia.com/content/circulars/CMPT77060.pdf",
                    "date": "2026-10-22",
                    "sha256": "c1d6cef683fb879485ae21168a38f8720ca7b82dff7042ac2e8d616321deaadc",
                    "isin": "INE566M01017",
                    "effective_on": "2026-10-23"
                },
                {
                    "role": "new_isin_event",
                    "document": "CML77034",
                    "url": "https://nsearchives.nseindia.com/content/circulars/CML77034.pdf",
                    "date": "2026-10-21",
                    "sha256": "d9e7cd4cab87530221919ee383109840f089e97d60fe5ee6f89613c37a1849d5",
                    "isin": "INE566M01025",
                    "effective_on": "2026-10-23"
                }
            ]
        },
        "CML76879": {
            "action": "subdivision",
            "symbol": "BANSALWIRE",
            "old_isin": "INE0B9K01025",
            "new_isin": "INE0B9K01033",
            "effective_on": "2026-10-16",
            "evidence": [
                {
                    "role": "old_isin_event",
                    "document": "CMPT76887",
                    "url": "https://nsearchives.nseindia.com/content/circulars/CMPT76887.pdf",
                    "date": "2026-10-14",
                    "sha256": "cb5bf611f7a020f0bcc042cded6d1a08bc93a5896caf78abc59e6986546d54dd",
                    "isin": "INE0B9K01025",
                    "effective_on": "2026-10-16"
                },
                {
                    "role": "new_isin_event",
                    "document": "CML76879",
                    "url": "https://nsearchives.nseindia.com/content/circulars/CML76879.pdf",
                    "date": "2026-10-13",
                    "sha256": "a30e4bb5ae0cfba52d0fa83c98469229552356f2ce3f0a720c02b4a33de6d4c2",
                    "isin": "INE0B9K01033",
                    "effective_on": "2026-10-16"
                }
            ]
        },
        "CML77001": {
            "action": "subdivision",
            "symbol": "JSWDULUX",
            "old_isin": "INE133A01011",
            "new_isin": "INE133A01029",
            "effective_on": "2026-10-22",
            "evidence": [
                {
                    "role": "old_isin_event",
                    "document": "CMPT77031",
                    "url": "https://nsearchives.nseindia.com/content/circulars/CMPT77031.pdf",
                    "date": "2026-10-21",
                    "sha256": "9d9ff8452ba06f273e114c96c0d4f8d875e62bc8e3d39ccd7f92cfd18fd26994",
                    "isin": "INE133A01011",
                    "effective_on": "2026-10-22"
                },
                {
                    "role": "new_isin_event",
                    "document": "CML77001",
                    "url": "https://nsearchives.nseindia.com/content/circulars/CML77001.pdf",
                    "date": "2026-10-19",
                    "sha256": "eb9b435bdd25be1d4615be761ce5d58f0a87438d2d709629f5f922e46ff3b588",
                    "isin": "INE133A01029",
                    "effective_on": "2026-10-22"
                }
            ]
        }
    },
    "identities": [
        {
            "symbol": "AZADIND",
            "historical_isin": "INE566M01017",
            "canonical_isin": "INE566M01025",
            "valid_from": "2026-10-15",
            "valid_until": "2026-10-22",
            "transition_ids": [
                "CML77034"
            ],
            "evidence": [
                {
                    "role": "historical_observation",
                    "url": "https://nsearchives.nseindia.com/content/cm/BhavCopy_NSE_CM_0_0_0_20261015_F_0000.csv.zip",
                    "sha256": "3d1c7039e2c70868cc44b51d1f9e4b0f942afc0575fe8c4a524aed9e01eac3e8",
                    "date": "2026-10-15",
                    "isin": "INE566M01017",
                    "symbol": "AZADIND"
                }
            ]
        },
        {
            "symbol": "BANSALWIRE",
            "historical_isin": "INE0B9K01025",
            "canonical_isin": "INE0B9K01033",
            "valid_from": "2026-10-15",
            "valid_until": "2026-10-15",
            "transition_ids": [
                "CML76879"
            ],
            "evidence": [
                {
                    "role": "historical_observation",
                    "url": "https://nsearchives.nseindia.com/content/cm/BhavCopy_NSE_CM_0_0_0_20261015_F_0000.csv.zip",
                    "sha256": "3d1c7039e2c70868cc44b51d1f9e4b0f942afc0575fe8c4a524aed9e01eac3e8",
                    "date": "2026-10-15",
                    "isin": "INE0B9K01025",
                    "symbol": "BANSALWIRE"
                }
            ]
        },
        {
            "symbol": "JSWDULUX",
            "historical_isin": "INE133A01011",
            "canonical_isin": "INE133A01029",
            "valid_from": "2026-10-15",
            "valid_until": "2026-10-21",
            "transition_ids": [
                "CML77001"
            ],
            "evidence": [
                {
                    "role": "historical_observation",
                    "url": "https://nsearchives.nseindia.com/content/cm/BhavCopy_NSE_CM_0_0_0_20261015_F_0000.csv.zip",
                    "sha256": "3d1c7039e2c70868cc44b51d1f9e4b0f942afc0575fe8c4a524aed9e01eac3e8",
                    "date": "2026-10-15",
                    "isin": "INE133A01011",
                    "symbol": "JSWDULUX"
                }
            ]
        }
    ]
}
JSON, true, 512, JSON_THROW_ON_ERROR);
