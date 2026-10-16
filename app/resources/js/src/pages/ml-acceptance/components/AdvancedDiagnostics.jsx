import React, { useState } from 'react';

export default function AdvancedDiagnostics({ data, report, onCopy }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        const safe = { wizard: data, report };
        try { await navigator.clipboard.writeText(JSON.stringify(safe, null, 2)); setCopied(true); onCopy?.(); }
        catch { setCopied(false); }
    };
    return <details id="acceptance-advanced-diagnostics" className="mt-3 acceptance-diagnostics">
        <summary>Advanced diagnostics</summary>
        <p className="mt-2">Technical status and reason codes are read-only. StoX does not include private file paths or raw provider payloads here.</p>
        <button type="button" className="btn btn-outline-secondary btn-sm mb-2" onClick={copy}>Copy safe diagnostics</button>
        {copied && <span role="status" className="ms-2">Diagnostics copied.</span>}
        <pre className="small">{JSON.stringify({ wizard: data, report }, null, 2)}</pre>
    </details>;
}
