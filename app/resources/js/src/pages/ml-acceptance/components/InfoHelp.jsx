import React, { useId } from 'react';

export default function InfoHelp({ label, children }) {
    const reactId = useId();
    const id = `help-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${reactId.replace(/:/g, '')}`;
    return <span className="ms-1 d-inline-flex align-items-center">
        <button type="button" className="btn btn-link p-0 acceptance-help" aria-label={`More information about ${label}`} aria-describedby={id}>ⓘ</button>
        <span id={id} role="tooltip" className="acceptance-help-text">{children}</span>
    </span>;
}
