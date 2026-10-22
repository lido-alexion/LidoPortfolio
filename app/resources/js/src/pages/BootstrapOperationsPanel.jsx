import React, { useState } from 'react';
import OperationsSummary from './bootstrap-operations/OperationsSummary';
import RunOperations from './bootstrap-operations/RunOperations';
import CoverageOperations from './bootstrap-operations/CoverageOperations';
import SchedulePresetOperations from './bootstrap-operations/SchedulePresetOperations';
import useBootstrapOperations from './bootstrap-operations/useBootstrapOperations';
import './bootstrap-operations/bootstrap-operations.css';

const tabs = [{ id: 'runs', label: 'Backfills and history' }, { id: 'coverage', label: 'Coverage and review' }, { id: 'schedules', label: 'Presets and schedules' }];
export default function BootstrapOperationsPanel() {
    const ops = useBootstrapOperations();
    const [tab, setTab] = useState('runs');
    return <section className="bootstrap-operations" id="bootstrap-operations" aria-labelledby="bootstrap-operations-title">
        <header className="bo-page-heading"><div><span className="bo-eyebrow">ADMIN OPERATIONS</span><h2 id="bootstrap-operations-title">Historical fundamentals</h2><p>Manage governed backfills, review coverage, and maintain recurring schedules.</p></div><a className="btn btn-outline-secondary" href="/settings/fundamentals">Fundamental settings</a></header>
        {ops.error && <div className={`alert ${ops.stale ? 'alert-warning' : 'alert-danger'}`} role="status"><span>{ops.error}</span><button className="btn btn-sm btn-outline-secondary ms-2" disabled={ops.busy} onClick={()=>ops.load()}>Refresh</button></div>}
        <OperationsSummary health={ops.health} coverage={ops.coverage} />
        <p className="bo-notification-note">Meaningful outcomes appear in the existing Admin notification center, including failures, completed runs with failures, and scheduled run delays.</p>
        <nav className="bo-tabs" aria-label="Bootstrap operation sections" role="tablist">{tabs.map((item)=><button type="button" role="tab" id={`bo-tab-${item.id}`} aria-controls={`bo-panel-${item.id}`} aria-selected={tab===item.id} tabIndex={tab===item.id?0:-1} className={tab===item.id?'active':''} key={item.id} onClick={()=>setTab(item.id)} onKeyDown={(e)=>{const i=tabs.findIndex((t)=>t.id===tab);if(e.key==='ArrowRight'||e.key==='ArrowLeft'){e.preventDefault();const next=tabs[(i+(e.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length];setTab(next.id);document.getElementById(`bo-tab-${next.id}`)?.focus();}}}>{item.label}</button>)}</nav>
        <div role="tabpanel" id={`bo-panel-${tab}`} aria-labelledby={`bo-tab-${tab}`}>
            {tab==='runs'&&<RunOperations ops={ops}/>}{tab==='coverage'&&<CoverageOperations ops={ops}/>}{tab==='schedules'&&<SchedulePresetOperations ops={ops}/>}
        </div>
        {ops.busy && <span className="visually-hidden" role="status">Updating operations</span>}
    </section>;
}
