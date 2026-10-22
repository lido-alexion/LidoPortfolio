import React from 'react';

export default function StepCard({ number, title, what, learn, children }) {
    return <section className="card acceptance-step-card" aria-labelledby={`acceptance-step-${number}`}>
        <div className="card-body">
            <h3 className="h5" id={`acceptance-step-${number}`}>Step {number}: {title}</h3>
            <p><strong>What this does:</strong> {what}</p>
            <details className="mb-3"><summary>Learn more</summary><p className="mt-2 mb-0">{learn}</p></details>
            {children}
        </div>
    </section>;
}
