import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
import { showToast } from '../toast';

function flatten(nodes, depth = 0) {
    return (nodes || []).flatMap((node) => [{ ...node, depth }, ...flatten(node.children, depth + 1)]);
}

function downloadBlob(response, fallback) {
    const match = response.headers?.['content-disposition']?.match(/filename="?([^";]+)"?/i);
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = match?.[1] || fallback;
    link.click();
    URL.revokeObjectURL(url);
}

export default function WikiPage() {
    const { pageId } = useParams();
    const navigate = useNavigate();
    const [tree, setTree] = useState([]);
    const [page, setPage] = useState(null);
    const [title, setTitle] = useState('');
    const [markdown, setMarkdown] = useState('');
    const [previewHtml, setPreviewHtml] = useState('');
    const [busy, setBusy] = useState(false);
    const pages = useMemo(() => flatten(tree), [tree]);

    const loadTree = useCallback(async () => {
        const response = await api.get('/knowledge-board/wiki/pages');
        setTree(response.data?.data || []);
    }, []);
    const loadPage = useCallback(async () => {
        if (!pageId) { setPage(null); return; }
        const response = await api.get(`/knowledge-board/wiki/pages/${pageId}`);
        const next = response.data?.data;
        setPage(next); setTitle(next?.title || ''); setMarkdown(next?.markdown || ''); setPreviewHtml(next?.rendered_html || '');
    }, [pageId]);

    useEffect(() => { loadTree().catch(() => setTree([])); }, [loadTree]);
    useEffect(() => { loadPage().catch(() => navigate('/knowledge-board/wiki')); }, [loadPage, navigate]);

    const createPage = async (parentUuid = null) => {
        const nextTitle = window.prompt(parentUuid ? 'Child page title' : 'Root page title');
        if (!nextTitle?.trim()) return;
        const response = await api.post('/knowledge-board/wiki/pages', { title: nextTitle.trim(), parent_uuid: parentUuid });
        await loadTree(); navigate(`/knowledge-board/wiki/${response.data.data.uuid}`);
    };
    const save = async () => {
        setBusy(true);
        try { await api.put(`/knowledge-board/wiki/pages/${pageId}`, { title, markdown }); await Promise.all([loadTree(), loadPage()]); showToast('Wiki Page saved', 'success'); }
        catch (error) { showToast(getApiErrorMessage(error, 'Could not save Wiki Page'), 'danger'); }
        finally { setBusy(false); }
    };
    const move = async (parentUuid) => {
        await api.put(`/knowledge-board/wiki/pages/${pageId}/move`, { parent_uuid: parentUuid || null, display_order: 0 });
        await Promise.all([loadTree(), loadPage()]);
    };
    const remove = async () => {
        const current = pages.find((item) => item.uuid === pageId);
        const descendants = current ? flatten(current.children).length : 0;
        const count = descendants + 1;
        if (!window.confirm(count > 1 ? `Permanently delete this ${count}-page branch?` : 'Permanently delete this Wiki Page?')) return;
        await api.delete(`/knowledge-board/wiki/pages/${pageId}`, { data: count > 1 ? { recursive: true, confirm_count: count } : {} });
        await loadTree(); navigate('/knowledge-board/wiki');
    };
    const restore = async (revisionId) => {
        if (!window.confirm('Restore this revision as a new current revision?')) return;
        await api.post(`/knowledge-board/wiki/pages/${pageId}/revisions/${revisionId}/restore`); await Promise.all([loadTree(), loadPage()]);
    };
    const exportFile = async (scope) => {
        const endpoint = scope === 'wiki' ? '/knowledge-board/wiki/export' : `/knowledge-board/wiki/pages/${pageId}/${scope === 'branch' ? 'export-branch' : 'export'}`;
        downloadBlob(await api.get(endpoint, { responseType: 'blob' }), scope === 'page' ? `${page.slug}.md` : 'wiki.zip');
    };
    const share = async (action = 'share') => {
        if (action === 'stop') { await api.delete(`/knowledge-board/wiki/pages/${pageId}/share`); showToast('Public sharing stopped', 'success'); return; }
        const endpoint = `/knowledge-board/wiki/pages/${pageId}/share${action === 'regenerate' ? '/regenerate' : ''}`;
        const response = await api.post(endpoint);
        await navigator.clipboard?.writeText(response.data.data.url);
        showToast('Public link copied', 'success');
    };
    const insertLink = (uuid) => {
        if (!uuid) return;
        setMarkdown((value) => `${value}${value ? '\n' : ''}[[wiki:${uuid}]]`);
    };
    const preview = async () => {
        const response = await api.post(`/knowledge-board/wiki/pages/${pageId}/preview`, { markdown });
        setPreviewHtml(response.data?.data?.rendered_html || '');
    };

    return <div className="container-fluid py-3">
        <div className="d-flex justify-content-between align-items-center mb-3"><div><Link to="/knowledge-board">Knowledge Board</Link> / Wiki</div><button className="btn btn-primary btn-sm" onClick={() => createPage()}>New root page</button></div>
        <div className="row g-3">
            <aside className="col-lg-3"><div className="card"><div className="card-header">Wiki hierarchy</div><div className="list-group list-group-flush">{pages.map((item) => <Link key={item.uuid} className={`list-group-item list-group-item-action ${item.uuid === pageId ? 'active' : ''}`} style={{ paddingLeft: `${1 + item.depth * 1.1}rem` }} to={`/knowledge-board/wiki/${item.uuid}`}>{item.title}</Link>)}</div></div></aside>
            <main className="col-lg-9">{!page ? <div className="card"><div className="card-body text-muted">Select a Wiki Page or create a root page.</div></div> : <div className="d-grid gap-3">
                <div className="small">{(page.breadcrumbs || []).map((crumb, index) => <React.Fragment key={`${crumb.uuid}-${index}`}>{index ? ' / ' : ''}{crumb.uuid ? <Link to={`/knowledge-board/wiki/${crumb.uuid}`}>{crumb.title}</Link> : <Link to="/knowledge-board">{crumb.title}</Link>}</React.Fragment>)}</div>
                <div className="card"><div className="card-body d-grid gap-3">
                    <input className="form-control form-control-lg" value={title} onChange={(event) => setTitle(event.target.value)} aria-label="Wiki Page title" />
                    <div className="row g-3"><div className="col-md-6"><label className="form-label">Markdown source</label><textarea className="form-control font-monospace" rows="18" value={markdown} onChange={(event) => setMarkdown(event.target.value)} /></div><div className="col-md-6"><label className="form-label">Safe preview</label><div className="border rounded p-3 h-100 lido-knowledge-markdown-preview" dangerouslySetInnerHTML={{ __html: previewHtml }} /></div></div>
                    <div className="d-flex flex-wrap gap-2"><button className="btn btn-primary" disabled={busy} onClick={save}>{busy ? 'Saving…' : 'Save'}</button><button className="btn btn-outline-primary" onClick={preview}>Preview</button><button className="btn btn-outline-primary" onClick={() => createPage(pageId)}>New child</button>
                        <select className="form-select w-auto" defaultValue="__choose" onChange={(event) => event.target.value !== '__choose' && move(event.target.value)} aria-label="Move Wiki Page"><option value="__choose" disabled>Move…</option><option value="">Move to root</option>{pages.filter((item) => item.uuid !== pageId).map((item) => <option key={item.uuid} value={item.uuid}>{'—'.repeat(item.depth)} {item.title}</option>)}</select>
                        <select className="form-select w-auto" defaultValue="" onChange={(event) => insertLink(event.target.value)} aria-label="Insert Wiki Link"><option value="">Insert Wiki Link…</option>{pages.filter((item) => item.uuid !== pageId).map((item) => <option key={item.uuid} value={item.uuid}>{item.title}</option>)}</select>
                        <button className="btn btn-outline-secondary" onClick={() => exportFile('page')}>Export page</button><button className="btn btn-outline-secondary" onClick={() => exportFile('branch')}>Export branch</button><button className="btn btn-outline-secondary" onClick={() => exportFile('wiki')}>Export Wiki</button>
                        <button className="btn btn-outline-secondary" onClick={() => share()}>Share publicly</button><button className="btn btn-outline-secondary" onClick={() => share('regenerate')}>Regenerate link</button><button className="btn btn-outline-secondary" onClick={() => share('stop')}>Stop sharing</button><button className="btn btn-outline-danger" onClick={remove}>Delete</button>
                    </div>
                </div></div>
                <div className="card"><div className="card-header">Revision history</div><div className="list-group list-group-flush">{(page.revisions || []).map((revision) => <div key={revision.id} className="list-group-item d-flex justify-content-between align-items-center"><span>#{revision.revision_number} · {revision.change_type} · {new Date(revision.created_at).toLocaleString()}</span><button className="btn btn-link btn-sm" onClick={() => restore(revision.id)}>Restore</button></div>)}</div></div>
            </div>}</main>
        </div>
    </div>;
}
