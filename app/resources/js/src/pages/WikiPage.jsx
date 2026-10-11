import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
import { usePortfolio } from '../context/PortfolioContext';
import ScrollToTop from '../components/ScrollToTop';
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
    const { activePortfolio } = usePortfolio();
    const profileId = activePortfolio?.id;
    const navigate = useNavigate();
    const [tree, setTree] = useState([]);
    const [page, setPage] = useState(null);
    const [title, setTitle] = useState('');
    const [markdown, setMarkdown] = useState('');
    const [previewHtml, setPreviewHtml] = useState('');
    const [busy, setBusy] = useState(false);
    const [comparison, setComparison] = useState(null);
    const [dragged, setDragged] = useState(null);
    const [treeError, setTreeError] = useState('');
    const [pageError, setPageError] = useState('');
    const [pageLoading, setPageLoading] = useState(Boolean(pageId));
    const [actionError, setActionError] = useState('');
    const [shareUrl, setShareUrl] = useState('');
    const profileIdentityRef = useRef(profileId);
    profileIdentityRef.current = profileId;
    const treeRequestRef = useRef(0);
    const pageRequestRef = useRef(0);
    const actionBusyRef = useRef(false);
    const viewIdentityRef = useRef('');
    viewIdentityRef.current = `${profileId || ''}:${pageId || ''}`;
    const pages = useMemo(() => flatten(tree), [tree]);

    const loadTree = useCallback(async () => {
        const requestId = ++treeRequestRef.current;
        setTreeError('');
        setTree([]);
        try {
            const response = await api.get('/knowledge-board/wiki/pages');
            if (requestId === treeRequestRef.current && profileIdentityRef.current === profileId) setTree(response.data?.data || []);
        } catch {
            if (requestId === treeRequestRef.current && profileIdentityRef.current === profileId) setTreeError('Wiki hierarchy could not be loaded.');
        }
    }, [profileId]);
    const loadPage = useCallback(async () => {
        const requestId = ++pageRequestRef.current;
        const identity = viewIdentityRef.current;
        setPage(null); setPageError(''); setComparison(null); setShareUrl('');
        if (!pageId) { setPageLoading(false); return; }
        setPageLoading(true);
        try {
            const response = await api.get(`/knowledge-board/wiki/pages/${pageId}`);
            if (requestId !== pageRequestRef.current || identity !== viewIdentityRef.current) return;
            const next = response.data?.data;
            setPage(next); setTitle(next?.title || ''); setMarkdown(next?.markdown || ''); setPreviewHtml(next?.rendered_html || '');
        } catch (error) {
            if (requestId === pageRequestRef.current && identity === viewIdentityRef.current) setPageError(error?.response?.status === 404 ? 'Wiki Page is unavailable in this Portfolio.' : 'Wiki Page could not be loaded.');
        } finally {
            if (requestId === pageRequestRef.current && identity === viewIdentityRef.current) setPageLoading(false);
        }
    }, [pageId, profileId]);

    useEffect(() => { loadTree(); }, [loadTree]);
    useEffect(() => { setActionError(''); loadPage(); }, [loadPage]);

    const runAction = async (action) => {
        if (actionBusyRef.current) return;
        actionBusyRef.current = true;
        const identity = viewIdentityRef.current;
        const isCurrent = () => identity === viewIdentityRef.current;
        setBusy(true); setActionError('');
        try { await action(isCurrent); }
        catch (error) {
            if (!isCurrent()) return;
            setActionError('Wiki action could not be confirmed. Review current evidence before retrying.');
            showToast(getApiErrorMessage(error, 'Could not complete Wiki action'), 'danger');
        } finally { actionBusyRef.current = false; setBusy(false); }
    };

    const createPage = async (parentUuid = null) => {
        const nextTitle = window.prompt(parentUuid ? 'Child page title' : 'Root page title');
        if (!nextTitle?.trim()) return;
        await runAction(async (isCurrent) => {
            const response = await api.post('/knowledge-board/wiki/pages', { title: nextTitle.trim(), parent_uuid: parentUuid });
            if (!isCurrent()) return;
            await loadTree(); if (isCurrent()) navigate(`/knowledge-board/wiki/${response.data.data.uuid}`);
        });
    };
    const save = () => runAction(async (isCurrent) => { await api.put(`/knowledge-board/wiki/pages/${pageId}`, { title, markdown }); if (!isCurrent()) return; await Promise.all([loadTree(), loadPage()]); if (isCurrent()) showToast('Wiki Page saved', 'success'); });
    const movePage = (uuid, parentUuid, displayOrder = 0) => runAction(async (isCurrent) => {
        await api.put(`/knowledge-board/wiki/pages/${uuid}/move`, { parent_uuid: parentUuid || null, display_order: displayOrder });
        if (!isCurrent()) return;
        await Promise.all([loadTree(), loadPage()]);
    });
    const move = (parentUuid) => movePage(pageId, parentUuid);
    const remove = async () => {
        const current = pages.find((item) => item.uuid === pageId);
        const descendants = current ? flatten(current.children).length : 0;
        const count = descendants + 1;
        if (!window.confirm(count > 1 ? `Permanently delete this ${count}-page branch?` : 'Permanently delete this Wiki Page?')) return;
        await runAction(async (isCurrent) => {
            await api.delete(`/knowledge-board/wiki/pages/${pageId}`, { data: count > 1 ? { recursive: true, confirm_count: count } : {} });
            if (!isCurrent()) return;
            await loadTree(); if (isCurrent()) navigate('/knowledge-board/wiki');
        });
    };
    const restore = async (revisionId) => {
        if (!window.confirm('Restore this revision as a new current revision?')) return;
        await runAction(async (isCurrent) => { await api.post(`/knowledge-board/wiki/pages/${pageId}/revisions/${revisionId}/restore`); if (!isCurrent()) return; await Promise.all([loadTree(), loadPage()]); });
    };
    const compare = (revisionId) => runAction(async (isCurrent) => {
        const response = await api.get(`/knowledge-board/wiki/pages/${pageId}/revisions/${revisionId}`);
        if (!isCurrent()) return;
        setComparison(response.data?.data || null);
    });
    const uploadImage = async (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        const form = new FormData(); form.append('display', file); form.append('full', file); form.append('original_name', file.name);
        await runAction(async (isCurrent) => {
        const upload = await api.post('/knowledge-board/images', form);
        if (!isCurrent()) return;
        const image = upload.data.data;
        await api.post(`/knowledge-board/wiki/pages/${pageId}/images/${image.uuid}`);
        if (!isCurrent()) return;
        setMarkdown((value) => `${value}${value ? '\n' : ''}![${file.name}](wiki-image:${image.uuid})`);
        showToast('Image attached and inserted; save the page to keep the Markdown change', 'success');
        });
        event.target.value = '';
    };
    const exportFile = (scope) => runAction(async (isCurrent) => {
        const endpoint = scope === 'wiki' ? '/knowledge-board/wiki/export' : `/knowledge-board/wiki/pages/${pageId}/${scope === 'branch' ? 'export-branch' : 'export'}`;
        const response = await api.get(endpoint, { responseType: 'blob' });
        if (isCurrent()) downloadBlob(response, scope === 'page' ? `${page.slug}.md` : 'wiki.zip');
    });
    const share = (action = 'share') => runAction(async (isCurrent) => {
        if (action === 'stop') { await api.delete(`/knowledge-board/wiki/pages/${pageId}/share`); if (!isCurrent()) return; setShareUrl(''); showToast('Public sharing stopped', 'success'); return; }
        const endpoint = `/knowledge-board/wiki/pages/${pageId}/share${action === 'regenerate' ? '/regenerate' : ''}`;
        const response = await api.post(endpoint);
        if (!isCurrent()) return;
        const nextUrl = response.data?.data?.url || '';
        setShareUrl(nextUrl);
        if (!nextUrl) throw new Error('Share URL unavailable');
        if (navigator.clipboard?.writeText) {
            try { await navigator.clipboard.writeText(nextUrl); showToast('Public link copied', 'success'); }
            catch { showToast('Public link is ready; copy it from the link field.', 'success'); }
        } else showToast('Public link is ready; copy it from the link field.', 'success');
    });
    const insertLink = (uuid) => {
        if (!uuid) return;
        setMarkdown((value) => `${value}${value ? '\n' : ''}[[wiki:${uuid}]]`);
    };
    const preview = () => runAction(async (isCurrent) => {
        const response = await api.post(`/knowledge-board/wiki/pages/${pageId}/preview`, { markdown });
        if (!isCurrent()) return;
        setPreviewHtml(response.data?.data?.rendered_html || '');
    });

    return <>
    <div className="container-fluid py-3">
        {actionError && <div className="alert alert-danger" role="alert">{actionError}</div>}
        {treeError && <div className="alert alert-danger" role="alert"><p>{treeError}</p><button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={loadTree}>Retry Wiki hierarchy</button></div>}
        <div className="d-flex justify-content-between align-items-center mb-3"><div><Link to="/knowledge-board">Knowledge Board</Link> / Wiki</div><button className="btn btn-primary btn-sm" disabled={busy} onClick={() => createPage()}>New root page</button></div>
        <div className="row g-3">
            <aside className="col-lg-3"><div className="card"><div className="card-header">Wiki hierarchy</div><div className="list-group list-group-flush"><div className="small text-muted p-2 border-bottom" onDragOver={(event) => event.preventDefault()} onDrop={() => dragged && movePage(dragged, null, 0)}>Drop here to move to root</div>{pages.map((item) => <React.Fragment key={item.uuid}><div style={{ height: 6 }} onDragOver={(event) => event.preventDefault()} onDrop={() => dragged && movePage(dragged, item.parent_uuid, item.display_order)} /><Link draggable onDragStart={() => setDragged(item.uuid)} onDragEnd={() => setDragged(null)} onDragOver={(event) => event.preventDefault()} onDrop={() => dragged && dragged !== item.uuid && movePage(dragged, item.uuid, 0)} className={`list-group-item list-group-item-action ${item.uuid === pageId ? 'active' : ''}`} style={{ paddingLeft: `${1 + item.depth * 1.1}rem` }} to={`/knowledge-board/wiki/${item.uuid}`}>{item.title}</Link></React.Fragment>)}</div></div></aside>
            <main className="col-lg-9">{pageError ? <div className="alert alert-danger" role="alert"><p>{pageError}</p>{pageError === 'Wiki Page could not be loaded.' && <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy || pageLoading} onClick={loadPage}>Retry Wiki Page</button>}</div> : pageLoading ? <p>Loading Wiki Page…</p> : !page ? <div className="card"><div className="card-body text-muted">Select a Wiki Page or create a root page.</div></div> : <div className="d-grid gap-3">
                <div className="small">{(page.breadcrumbs || []).map((crumb, index) => <React.Fragment key={`${crumb.uuid}-${index}`}>{index ? ' / ' : ''}{crumb.uuid ? <Link to={`/knowledge-board/wiki/${crumb.uuid}`}>{crumb.title}</Link> : <Link to="/knowledge-board">{crumb.title}</Link>}</React.Fragment>)}</div>
                <div className="card"><div className="card-body d-grid gap-3">
                    <input className="form-control form-control-lg" value={title} onChange={(event) => setTitle(event.target.value)} aria-label="Wiki Page title" disabled={busy} />
                    <div className="row g-3"><div className="col-md-6"><label className="form-label" htmlFor="wiki-markdown-source">Markdown source</label><textarea id="wiki-markdown-source" disabled={busy} className="form-control font-monospace" rows="18" value={markdown} onChange={(event) => setMarkdown(event.target.value)} /></div><div className="col-md-6"><div className="form-label">Safe preview</div><div className="border rounded p-3 h-100 lido-knowledge-markdown-preview" role="region" aria-label="Safe Wiki preview" dangerouslySetInnerHTML={{ __html: previewHtml }} /></div></div>
                    <div className="d-flex flex-wrap gap-2"><button className="btn btn-primary" disabled={busy} onClick={save}>{busy ? 'Saving…' : 'Save'}</button><button className="btn btn-outline-primary" disabled={busy} onClick={preview}>Preview</button><button className="btn btn-outline-primary" disabled={busy} onClick={() => createPage(pageId)}>New child</button>
                        <select className="form-select w-auto" disabled={busy} defaultValue="__choose" onChange={(event) => { const target = event.target.value; event.target.value = '__choose'; if (target !== '__choose') move(target); }} aria-label="Move Wiki Page"><option value="__choose" disabled>Move…</option><option value="">Move to root</option>{pages.filter((item) => item.uuid !== pageId).map((item) => <option key={item.uuid} value={item.uuid}>{'—'.repeat(item.depth)} {item.title}</option>)}</select>
                        <select className="form-select w-auto" disabled={busy} defaultValue="" onChange={(event) => insertLink(event.target.value)} aria-label="Insert Wiki Link"><option value="">Insert Wiki Link…</option>{pages.filter((item) => item.uuid !== pageId).map((item) => <option key={item.uuid} value={item.uuid}>{item.title}</option>)}</select>
                        <button className="btn btn-outline-secondary" disabled={busy} onClick={() => exportFile('page')}>Export page</button><button className="btn btn-outline-secondary" disabled={busy} onClick={() => exportFile('branch')}>Export branch</button><button className="btn btn-outline-secondary" disabled={busy} onClick={() => exportFile('wiki')}>Export Wiki</button>
                        <label className="btn btn-outline-secondary mb-0">Insert image<input className="visually-hidden" type="file" disabled={busy} accept="image/jpeg,image/png,image/webp,image/gif" onChange={uploadImage} /></label><button className="btn btn-outline-secondary" disabled={busy} onClick={() => share()}>Share publicly</button><button className="btn btn-outline-secondary" disabled={busy} onClick={() => share('regenerate')}>Regenerate link</button><button className="btn btn-outline-secondary" disabled={busy} onClick={() => share('stop')}>Stop sharing</button><button className="btn btn-outline-danger" disabled={busy} onClick={remove}>Delete</button>
                    </div>
                    {shareUrl && <div><label className="form-label" htmlFor="wiki-public-link">Public Wiki link</label><input id="wiki-public-link" className="form-control" readOnly value={shareUrl} /></div>}
                </div></div>
                <div className="card"><div className="card-header">Revision history</div><div className="list-group list-group-flush">{(page.revisions || []).map((revision) => <div key={revision.id} className="list-group-item d-flex justify-content-between align-items-center"><span>#{revision.revision_number} · {revision.change_type} · {new Date(revision.created_at).toLocaleString()}</span><span><button className="btn btn-link btn-sm" disabled={busy} onClick={() => compare(revision.id)}>Compare</button><button className="btn btn-link btn-sm" disabled={busy} onClick={() => restore(revision.id)}>Restore</button></span></div>)}</div>{comparison ? <div className="card-body border-top"><div className="d-flex justify-content-between"><strong>Revision #{comparison.revision.revision_number} vs current</strong><button className="btn-close" aria-label="Close comparison" onClick={() => setComparison(null)} /></div><div className="row g-2 mt-1"><div className="col-md-6"><div className="small text-muted">Historical Markdown</div><pre className="border rounded p-2 text-wrap">{comparison.revision.markdown}</pre></div><div className="col-md-6"><div className="small text-muted">Current Markdown</div><pre className="border rounded p-2 text-wrap">{comparison.current.markdown}</pre></div></div></div> : null}</div>
            </div>}</main>
        </div>
    </div>
    <ScrollToTop />
    </>;
}
