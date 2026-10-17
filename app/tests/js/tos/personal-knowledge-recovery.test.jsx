import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import { showToast } from '../../../resources/js/src/toast';
import KnowledgeBoardPage from '../../../resources/js/src/pages/KnowledgeBoardPage';
import KnowledgeBoardTagsPage from '../../../resources/js/src/pages/KnowledgeBoardTagsPage';
import WikiPage from '../../../resources/js/src/pages/WikiPage';
import PublicWikiPage from '../../../resources/js/src/pages/PublicWikiPage';
import ContextualNotesContent from '../../../resources/js/src/components/ContextualNotesContent';
import CalendarPage from '../../../resources/js/src/pages/CalendarPage';
import KnowledgeNoteCard from '../../../resources/js/src/components/knowledgeBoard/KnowledgeNoteCard';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, getApiErrorMessage: () => 'Synthetic unavailable' }));
const portfolioState = vi.hoisted(() => ({ id: 1 }));
vi.mock('../../../resources/js/src/context/PortfolioContext', () => ({ usePortfolio: () => ({ activePortfolio: { id: portfolioState.id } }) }));
vi.mock('../../../resources/js/src/context/AuthContext', () => ({ useAuth: () => ({ user: { is_admin: false } }) }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/knowledgeBoard/KnowledgeNoteGrid', () => ({ default: ({ notes, onArchive }) => <>{notes.map(note => <div key={note.id}>{note.title}<button onClick={() => onArchive(note)}>Archive fixture</button></div>)}</> }));
vi.mock('../../../resources/js/src/components/knowledgeBoard/KnowledgeNoteEditorModal', () => ({ default: () => null }));
beforeEach(() => { cleanup(); vi.resetAllMocks(); localStorage.clear(); sessionStorage.clear(); portfolioState.id = 1; sessionStorage.setItem('portfolio_active_id', '1'); });
afterEach(() => { cleanup(); vi.restoreAllMocks(); });
const unavailable = () => ({ response: { status: 503 }, message: 'Synthetic unavailable' });
const at = (path, route, node) => render(<MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={node} /></Routes></MemoryRouter>);
const tag = (id, name) => ({ id, name, color: '#198754' });
const deferred = () => { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const switchProfile = (id) => {
    sessionStorage.setItem('portfolio_active_id', String(id));
    window.dispatchEvent(new CustomEvent('portfolio-changed', { detail: { portfolioId: id } }));
};

it('opens the touch note-action menu without depending on Bootstrap document handlers', () => {
    const onTogglePin = vi.fn();
    render(<KnowledgeNoteCard note={{ id: 1, content_html: '<p>Reviewed note</p>', tags: [], is_pinned: false }} onTogglePin={onTogglePin} />);
    const menu = screen.getByRole('button', { name: 'Note actions' });
    expect(menu).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(menu);
    expect(menu).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('button', { name: 'Pin', exact: true }).closest('.dropdown-menu')).toHaveClass('show');
    fireEvent.click(screen.getByRole('button', { name: 'Pin', exact: true }));
    expect(onTogglePin).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(menu).toHaveAttribute('aria-expanded', 'false');
});

it('distinguishes unavailable notes from an empty Board and retries reads', async () => {
    let failed = true; api.get.mockImplementation(async path => { if (path.endsWith('/notes') && failed) throw unavailable(); return { data: { data: [] } }; });
    render(<MemoryRouter><KnowledgeBoardPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Knowledge notes could not be loaded'); expect(screen.queryByText(/No notes yet/)).toBeNull(); failed = false;
    fireEvent.click(screen.getByRole('button', { name: 'Retry knowledge notes' })); expect(await screen.findByText(/No notes yet/)).toBeVisible(); expect(api.post).not.toHaveBeenCalled();
});
it('does not remove or announce an archive when the write is rejected', async () => {
    api.get.mockImplementation(async path => ({ data: { data: path.endsWith('/notes') ? [{ id: 1, title: 'Retained note' }] : [] } })); api.put.mockRejectedValue(unavailable());
    render(<MemoryRouter><KnowledgeBoardPage /></MemoryRouter>); fireEvent.click(await screen.findByRole('button', { name: 'Archive fixture' }));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Could not update note.', 'danger')); expect(screen.getByText('Retained note')).toBeVisible(); expect(showToast).not.toHaveBeenCalledWith('Note archived.');
});
it('does not label failed tag reads empty and offers read recovery', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockResolvedValue({ data: { data: [] } });
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Knowledge tags could not be loaded'); expect(screen.queryByText('No tags yet.')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry knowledge tags' })); expect(await screen.findByText('No tags yet.')).toBeVisible(); expect(api.post).not.toHaveBeenCalled();
});
it('retains same-profile tags as stale on read failure and accepts only a confirmed retry result', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [tag(1, 'Known tag')] } })
        .mockRejectedValueOnce(unavailable())
        .mockResolvedValueOnce({ data: { data: [tag(2, 'Updated tag')] } });
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    expect(await screen.findByText('Known tag')).toBeVisible();
    act(() => window.dispatchEvent(new CustomEvent('portfolio-changed', { detail: { portfolioId: 1 } })));
    expect(await screen.findByRole('alert')).toHaveTextContent('may be out of date');
    expect(screen.getByText('Known tag')).toBeVisible(); expect(screen.queryByText('No tags yet.')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry knowledge tags' }));
    expect(await screen.findByText('Updated tag')).toBeVisible(); expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByText('Known tag')).toBeNull(); expect(api.get).toHaveBeenCalledTimes(3);
});
it('does not treat a malformed successful tag envelope as a confirmed empty list', async () => {
    api.get.mockResolvedValueOnce({ data: { data: null } }).mockResolvedValueOnce({ data: { data: [] } });
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Knowledge tags could not be loaded');
    expect(screen.queryByText('No tags yet.')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry knowledge tags' }));
    expect(await screen.findByText('No tags yet.')).toBeVisible(); expect(api.get).toHaveBeenCalledTimes(2);
});
it('does not let late tag reads or errors win after A-to-B-to-A profile changes', async () => {
    const firstA = deferred(); const firstB = deferred(); const secondA = deferred();
    api.get.mockReturnValueOnce(firstA.promise).mockReturnValueOnce(firstB.promise).mockReturnValueOnce(secondA.promise);
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    act(() => switchProfile(2));
    await act(async () => { firstB.resolve({ data: { data: [tag(22, 'B current')] } }); });
    expect(screen.getByText('B current')).toBeVisible();
    act(() => switchProfile(1));
    await act(async () => { secondA.resolve({ data: { data: [tag(12, 'A current')] } }); });
    expect(screen.getByText('A current')).toBeVisible();
    await act(async () => { firstA.reject(unavailable()); });
    expect(screen.getByText('A current')).toBeVisible(); expect(screen.queryByText('B current')).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull(); expect(screen.queryByText('Loading…')).toBeNull();
    expect(showToast).not.toHaveBeenCalledWith('Failed to load tags.', 'danger');
});
it('does not let an old read finally clear the active profile loading state', async () => {
    const oldA = deferred(); const currentB = deferred();
    api.get.mockReturnValueOnce(oldA.promise).mockReturnValueOnce(currentB.promise);
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    act(() => switchProfile(2));
    await act(async () => { oldA.reject(unavailable()); });
    expect(screen.getByText('Loading…')).toBeVisible(); expect(screen.queryByRole('alert')).toBeNull();
    await act(async () => { currentB.resolve({ data: { data: [tag(22, 'B settled')] } }); });
    expect(screen.getByText('B settled')).toBeVisible(); expect(screen.queryByText('Loading…')).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull(); expect(showToast).not.toHaveBeenCalled();
});
it('ignores late A and B successes after an A-to-B-to-A read sequence', async () => {
    const oldA = deferred(); const oldB = deferred(); const currentA = deferred();
    api.get.mockReturnValueOnce(oldA.promise).mockReturnValueOnce(oldB.promise).mockReturnValueOnce(currentA.promise);
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    act(() => switchProfile(2)); act(() => switchProfile(1));
    await act(async () => { currentA.resolve({ data: { data: [tag(12, 'Current A')] } }); });
    await act(async () => { oldA.resolve({ data: { data: [tag(11, 'Old A')] } }); });
    await act(async () => { oldB.resolve({ data: { data: [tag(22, 'Old B')] } }); });
    expect(screen.getByText('Current A')).toBeVisible(); expect(screen.queryByText('Old A')).toBeNull();
    expect(screen.queryByText('Old B')).toBeNull(); expect(screen.queryByRole('alert')).toBeNull();
});
it.each(['create', 'rename', 'delete', 'merge'].flatMap(kind => ['success', 'failure'].map(outcome => [kind, outcome])))('ignores a late %s %s from the prior profile without changing the new profile draft', async (kind, outcome) => {
    const pending = deferred();
    api.get.mockImplementation(async () => ({ data: { data: sessionStorage.getItem('portfolio_active_id') === '1' ? [tag(1, 'A tag'), tag(2, 'A target')] : [tag(22, 'B tag'), tag(23, 'B target')] } }));
    if (kind === 'create' || kind === 'merge') api.post.mockReturnValue(pending.promise);
    if (kind === 'rename') api.put.mockReturnValue(pending.promise);
    if (kind === 'delete') api.delete.mockReturnValue(pending.promise);
    if (kind === 'delete') vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    expect(await screen.findByText('A tag')).toBeVisible();

    if (kind === 'create') {
        fireEvent.change(screen.getByRole('textbox', { name: 'New tag name' }), { target: { value: 'A draft' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add', exact: true }));
    } else if (kind === 'rename') {
        fireEvent.click(screen.getAllByRole('button', { name: 'Rename', exact: true })[0]);
        fireEvent.change(screen.getByRole('textbox', { name: 'Rename tag' }), { target: { value: 'A renamed' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
    } else if (kind === 'delete') {
        fireEvent.click(screen.getAllByRole('button', { name: 'Delete', exact: true })[0]);
    } else {
        fireEvent.change(screen.getByLabelText('Source (removed)'), { target: { value: '1' } });
        fireEvent.change(screen.getByLabelText('Target (kept)'), { target: { value: '2' } });
        fireEvent.click(screen.getByRole('button', { name: 'Merge', exact: true }));
    }

    act(() => switchProfile(2));
    expect(await screen.findByText('B tag')).toBeVisible();
    if (kind === 'create') expect(screen.getByRole('textbox', { name: 'New tag name' })).toHaveValue('');
    if (kind === 'rename') expect(screen.queryByRole('textbox', { name: 'Rename tag' })).toBeNull();
    if (kind === 'merge') {
        expect(screen.getByLabelText('Source (removed)')).toHaveValue('');
        expect(screen.getByLabelText('Target (kept)')).toHaveValue('');
    }
    if (kind === 'create') fireEvent.change(screen.getByRole('textbox', { name: 'New tag name' }), { target: { value: 'B draft' } });
    if (kind === 'rename') {
        fireEvent.click(screen.getAllByRole('button', { name: 'Rename', exact: true })[0]);
        fireEvent.change(screen.getByRole('textbox', { name: 'Rename tag' }), { target: { value: 'B draft' } });
    }
    if (kind === 'merge') {
        fireEvent.change(screen.getByLabelText('Source (removed)'), { target: { value: '22' } });
        fireEvent.change(screen.getByLabelText('Target (kept)'), { target: { value: '23' } });
    }
    const writesBeforeLateReply = api.post.mock.calls.length + api.put.mock.calls.length + api.delete.mock.calls.length;
    await act(async () => {
        if (outcome === 'failure') pending.reject(unavailable());
        else if (kind === 'create') pending.resolve({ data: { data: tag(11, 'A created late') } });
        else if (kind === 'rename') pending.resolve({ data: { data: tag(1, 'A renamed late') } });
        else pending.resolve({ data: { data: {} } });
    });
    expect(screen.getByText('B tag')).toBeVisible();
    expect(screen.queryByText('A created late')).toBeNull(); expect(screen.queryByText('A renamed late')).toBeNull();
    if (kind === 'create') expect(screen.getByRole('textbox', { name: 'New tag name' })).toHaveValue('B draft');
    if (kind === 'rename') expect(screen.getByRole('textbox', { name: 'Rename tag' })).toHaveValue('B draft');
    if (kind === 'merge') {
        expect(screen.getByLabelText('Source (removed)')).toHaveValue('22');
        expect(screen.getByLabelText('Target (kept)')).toHaveValue('23');
        expect(api.get).toHaveBeenCalledTimes(2);
    }
    expect(api.post.mock.calls.length + api.put.mock.calls.length + api.delete.mock.calls.length).toBe(writesBeforeLateReply);
    expect(showToast).not.toHaveBeenCalledWith('Tag created.'); expect(showToast).not.toHaveBeenCalledWith('Tag updated.');
    expect(showToast).not.toHaveBeenCalledWith('Tag deleted.'); expect(showToast).not.toHaveBeenCalledWith('Tags merged.');
    expect(showToast).not.toHaveBeenCalledWith('Could not create tag.', 'danger');
    expect(showToast).not.toHaveBeenCalledWith('Could not update tag.', 'danger');
    expect(showToast).not.toHaveBeenCalledWith('Could not delete tag.', 'danger');
    expect(showToast).not.toHaveBeenCalledWith('Could not merge tags.', 'danger');
});
it('ignores pending tag reads and writes after the component unmounts', async () => {
    const pendingRead = deferred(); api.get.mockReturnValueOnce(pendingRead.promise);
    const view = render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    view.unmount();
    await act(async () => { pendingRead.reject(unavailable()); });
    expect(showToast).not.toHaveBeenCalledWith('Failed to load tags.', 'danger');

    const pendingCreate = deferred(); api.get.mockResolvedValue({ data: { data: [tag(1, 'A tag')] } }); api.post.mockReturnValue(pendingCreate.promise);
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    expect(await screen.findByText('A tag')).toBeVisible();
    fireEvent.change(screen.getByRole('textbox', { name: 'New tag name' }), { target: { value: 'A pending tag' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add', exact: true }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1)); cleanup();
    await act(async () => { pendingCreate.resolve({ data: { data: tag(2, 'A pending tag') } }); });
    expect(showToast).not.toHaveBeenCalledWith('Tag created.');
});
it('keeps the active tag read through React StrictMode effect replay', async () => {
    api.get.mockResolvedValue({ data: { data: [tag(1, 'StrictMode tag')] } });
    render(<React.StrictMode><MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter></React.StrictMode>);
    expect(await screen.findByText('StrictMode tag')).toBeVisible();
    expect(screen.queryByText('Loading…')).toBeNull(); expect(screen.queryByRole('alert')).toBeNull();
});
it('keeps both acknowledged same-profile creates when their responses finish out of order', async () => {
    const first = deferred(); const second = deferred();
    api.get.mockResolvedValue({ data: { data: [] } }); api.post.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    expect(await screen.findByText('No tags yet.')).toBeVisible();
    const input = screen.getByRole('textbox', { name: 'New tag name' }); const add = screen.getByRole('button', { name: 'Add', exact: true });
    fireEvent.change(input, { target: { value: 'First tag' } }); fireEvent.click(add);
    fireEvent.change(input, { target: { value: 'Second tag' } }); fireEvent.click(add);
    await act(async () => { second.resolve({ data: { data: tag(2, 'Second tag') } }); });
    await act(async () => { first.resolve({ data: { data: tag(1, 'First tag') } }); });
    expect(screen.getByText('First tag')).toBeVisible(); expect(screen.getByText('Second tag')).toBeVisible();
    expect(showToast).toHaveBeenCalledWith('Tag created.'); expect(showToast).toHaveBeenCalledTimes(2);
});
it('keeps a confirmed same-profile merge acknowledgement when its follow-up read fails and Retry repeats only the GET', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [tag(1, 'Source'), tag(2, 'Target')] } })
        .mockRejectedValueOnce(unavailable())
        .mockResolvedValueOnce({ data: { data: [tag(2, 'Target')] } });
    api.post.mockResolvedValue({ data: { data: {} } });
    render(<MemoryRouter><KnowledgeBoardTagsPage /></MemoryRouter>);
    expect(await screen.findByText('Source')).toBeVisible();
    fireEvent.change(screen.getByLabelText('Source (removed)'), { target: { value: '1' } });
    fireEvent.change(screen.getByLabelText('Target (kept)'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Merge', exact: true }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Knowledge tags could not be loaded');
    expect(showToast).toHaveBeenCalledWith('Tags merged.'); expect(api.post).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'Retry knowledge tags' }));
    expect(await screen.findByText('Target')).toBeVisible(); expect(screen.queryByText('Source')).toBeNull();
    expect(api.get).toHaveBeenCalledTimes(3); expect(api.post).toHaveBeenCalledTimes(1);
});
it('keeps the requested Wiki route after unavailable detail and retries it', async () => {
    let failed = true; api.get.mockImplementation(async path => { if (path.endsWith('/wiki/pages')) return { data: { data: [] } }; if (failed) throw unavailable(); return { data: { data: { uuid: 'page-one', title: 'Recovered Wiki', markdown: 'Retained source', rendered_html: '', revisions: [] } } }; });
    at('/knowledge-board/wiki/page-one', '/knowledge-board/wiki/:pageId', <WikiPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Wiki Page could not be loaded'); failed = false;
    fireEvent.click(screen.getByRole('button', { name: 'Retry Wiki Page' })); expect(await screen.findByLabelText('Wiki Page title')).toHaveValue('Recovered Wiki'); expect(api.post).not.toHaveBeenCalled();
});
it('handles rejected Wiki preview without losing the reviewed Markdown', async () => {
    api.get.mockImplementation(async path => ({ data: { data: path.endsWith('/wiki/pages') ? [] : { uuid: 'page-one', title: 'Reviewed Wiki', markdown: 'Reviewed source', rendered_html: '', revisions: [] } } })); api.post.mockRejectedValue(unavailable());
    at('/knowledge-board/wiki/page-one', '/knowledge-board/wiki/:pageId', <WikiPage />); await screen.findByLabelText('Markdown source'); fireEvent.click(screen.getByRole('button', { name: 'Preview', exact: true }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Wiki action could not be confirmed'); expect(screen.getByLabelText('Markdown source')).toHaveValue('Reviewed source');
});
it('reports a rejected Wiki image upload and permits the same file to be selected again', async () => {
    api.get.mockImplementation(async path => ({ data: { data: path.endsWith('/wiki/pages') ? [] : { uuid: 'page-one', title: 'Reviewed Wiki', markdown: '', rendered_html: '', revisions: [] } } }));
    api.post.mockRejectedValue(unavailable());
    at('/knowledge-board/wiki/page-one', '/knowledge-board/wiki/:pageId', <WikiPage />);
    const input = await screen.findByLabelText('Insert image');
    const file = new File(['synthetic'], 'synthetic.png', { type: 'image/png' });
    fireEvent.change(input, { target: { files: [file] } });
    expect(await screen.findByRole('alert')).toHaveTextContent('Wiki action could not be confirmed');
    fireEvent.change(input, { target: { files: [file] } });
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(screen.getByLabelText('Markdown source')).toHaveValue('');
});
it('blocks unknown contextual-note overwrite until a failed read is recovered', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockResolvedValue({ data: { data: [{ id: 2, body: 'Existing private evidence' }] } });
    render(<MemoryRouter><ContextualNotesContent onClose={() => {}} /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Contextual note could not be loaded'); expect(screen.getByRole('button', { name: 'Save', exact: true })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Retry contextual note' })); expect(await screen.findByLabelText('Contextual note')).toHaveValue('Existing private evidence'); expect(api.post).not.toHaveBeenCalled();
});
it('permits shared-read retry without confusing a transport failure with revocation', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockRejectedValueOnce({ response: { status: 404 } });
    at('/wiki/shared/synthetic', '/wiki/shared/:token', <PublicWikiPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Shared Wiki Page could not be loaded'); fireEvent.click(screen.getByRole('button', { name: 'Retry shared Wiki Page' }));
    expect(await screen.findByText('This shared Wiki Page is unavailable or its link has been revoked.')).toBeVisible(); expect(screen.queryByRole('button', { name: 'Retry shared Wiki Page' })).toBeNull(); expect(api.post).not.toHaveBeenCalled();
});
it('recovers failed Calendar reads instead of presenting an empty year', async () => {
    let failed = true; api.get.mockImplementation(async () => { if (failed) throw unavailable(); return { data: { data: [] } }; });
    render(<MemoryRouter><CalendarPage /></MemoryRouter>); expect(await screen.findByRole('alert')).toHaveTextContent('Calendar could not be loaded'); failed = false;
    fireEvent.click(screen.getByRole('button', { name: 'Retry calendar' })); await waitFor(() => expect(screen.getAllByRole('button', { name: /^Open events for / }).length).toBeGreaterThan(0)); expect(api.post).not.toHaveBeenCalled();
});
it('discards an old Portfolio preview response after the active Wiki context changes', async () => {
    let resolvePreview;
    api.get.mockImplementation(async path => ({ data: { data: path.endsWith('/wiki/pages') ? [] : { uuid: 'page-one', title: `Portfolio ${portfolioState.id}`, markdown: `Source ${portfolioState.id}`, rendered_html: `<p>Preview ${portfolioState.id}</p>`, revisions: [] } } }));
    api.post.mockImplementation(() => new Promise(resolve => { resolvePreview = resolve; }));
    const node = <MemoryRouter initialEntries={['/knowledge-board/wiki/page-one']}><Routes><Route path="/knowledge-board/wiki/:pageId" element={<WikiPage />} /></Routes></MemoryRouter>;
    const view = render(node); await screen.findByLabelText('Markdown source'); fireEvent.click(screen.getByRole('button', { name: 'Preview', exact: true }));
    portfolioState.id = 2; view.rerender(<MemoryRouter initialEntries={['/knowledge-board/wiki/page-one']}><Routes><Route path="/knowledge-board/wiki/:pageId" element={<WikiPage />} /></Routes></MemoryRouter>);
    await waitFor(() => expect(screen.getByLabelText('Wiki Page title')).toHaveValue('Portfolio 2'));
    await act(async () => { resolvePreview({ data: { data: { rendered_html: '<p>Old private preview</p>' } } }); });
    expect(screen.queryByText('Old private preview')).toBeNull(); expect(screen.getByRole('region', { name: 'Safe Wiki preview' })).toHaveTextContent('Preview 2');
});
