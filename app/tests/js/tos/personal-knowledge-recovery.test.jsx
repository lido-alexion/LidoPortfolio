import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
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
beforeEach(() => { cleanup(); vi.resetAllMocks(); localStorage.clear(); portfolioState.id = 1; });
const unavailable = () => ({ response: { status: 503 }, message: 'Synthetic unavailable' });
const at = (path, route, node) => render(<MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={node} /></Routes></MemoryRouter>);
function ContextualNotesNavigation() {
    const navigate = useNavigate();
    return <><button type="button" onClick={() => navigate('/strategies')}>Switch to context B</button><button type="button" onClick={() => navigate('/screeners')}>Return to context A</button></>;
}
const contextualNotesTree = (path = '/screeners') => <MemoryRouter initialEntries={[path]}><ContextualNotesContent onClose={() => {}} /><ContextualNotesNavigation /></MemoryRouter>;
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => { resolve = resolvePromise; reject = rejectPromise; });
    return { promise, resolve, reject };
}
const contextualNoteList = (id, body) => ({ data: { data: [{ id, body }] } });
const contextualNoteWrite = (id, body) => ({ data: { data: { id, body } } });
afterEach(() => vi.restoreAllMocks());

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
it.each(['save', 'delete'])('preserves same-scope %s success acknowledgement and rejected draft', async action => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    api.get.mockResolvedValue(contextualNoteList(11, 'Retained same-scope note'));
    const view = render(<React.StrictMode>{contextualNotesTree()}</React.StrictMode>);
    const editor = await screen.findByLabelText('Contextual note');
    await waitFor(() => expect(editor).toHaveValue('Retained same-scope note'));
    const initialReadCount = api.get.mock.calls.length;

    if (action === 'save') {
        fireEvent.change(editor, { target: { value: 'Same-scope draft' } });
        api.put.mockRejectedValueOnce(unavailable()).mockResolvedValueOnce(contextualNoteWrite(11, 'Same-scope draft'));
        fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
        await waitFor(() => expect(showToast).toHaveBeenCalledWith('Synthetic unavailable', 'danger'));
        expect(editor).toHaveValue('Same-scope draft');
        fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
        await waitFor(() => expect(showToast).toHaveBeenCalledWith('Note saved'));
        expect(api.put).toHaveBeenCalledTimes(2);
        expect(api.put).toHaveBeenLastCalledWith('/contextual-notes/11', { body: 'Same-scope draft' });
    } else {
        api.delete.mockRejectedValueOnce(unavailable()).mockResolvedValueOnce({ data: {} });
        fireEvent.click(screen.getByRole('button', { name: 'Delete', exact: true }));
        await waitFor(() => expect(showToast).toHaveBeenCalledWith('Synthetic unavailable', 'danger'));
        expect(editor).toHaveValue('Retained same-scope note');
        fireEvent.click(screen.getByRole('button', { name: 'Delete', exact: true }));
        await waitFor(() => expect(showToast).toHaveBeenCalledWith('Note deleted'));
        expect(api.delete).toHaveBeenCalledTimes(2);
        expect(api.delete).toHaveBeenLastCalledWith('/contextual-notes/11');
        expect(editor).toHaveValue('');
    }
    expect(api.get).toHaveBeenCalledTimes(initialReadCount);
    view.unmount();
});
it.each(['success', 'error'])('ignores a stale PUT %s after the active Portfolio changes and keeps B saves on B identity', async outcome => {
    api.get.mockImplementation(async (_url, config) => Number(config.params.profile_id) === 1
        ? contextualNoteList(11, 'Portfolio A note')
        : contextualNoteList(22, 'Portfolio B note'));
    const pendingA = deferred();
    api.put.mockReturnValueOnce(pendingA.promise);
    const view = render(contextualNotesTree());
    const editor = await screen.findByLabelText('Contextual note');
    await waitFor(() => expect(editor).toHaveValue('Portfolio A note'));
    fireEvent.change(editor, { target: { value: 'A pending edit' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));

    portfolioState.id = 2;
    view.rerender(contextualNotesTree());
    await waitFor(() => expect(editor).toHaveValue('Portfolio B note'));
    await act(async () => {
        if (outcome === 'success') pendingA.resolve(contextualNoteWrite(11, 'Stale A response'));
        else pendingA.reject(unavailable());
    });

    expect(editor).toHaveValue('Portfolio B note');
    expect(showToast).not.toHaveBeenCalledWith('Note saved');
    expect(showToast).not.toHaveBeenCalledWith('Synthetic unavailable', 'danger');
    api.put.mockResolvedValueOnce(contextualNoteWrite(22, 'Portfolio B updated'));
    fireEvent.change(editor, { target: { value: 'Portfolio B updated' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(2));
    expect(api.put).toHaveBeenLastCalledWith('/contextual-notes/22', { body: 'Portfolio B updated' });
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Note saved'));
    expect(api.get).toHaveBeenCalledTimes(2);
});
it('ignores a delayed POST from an empty A context after B has loaded its note', async () => {
    api.get.mockImplementation(async (_url, config) => Number(config.params.profile_id) === 1
        ? { data: { data: [] } }
        : contextualNoteList(22, 'Portfolio B note'));
    const pendingA = deferred();
    api.post.mockReturnValueOnce(pendingA.promise);
    const view = render(contextualNotesTree());
    const editor = await screen.findByLabelText('Contextual note');
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save', exact: true })).toBeEnabled());
    fireEvent.change(editor, { target: { value: 'A new note pending' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));

    portfolioState.id = 2;
    view.rerender(contextualNotesTree());
    await waitFor(() => expect(editor).toHaveValue('Portfolio B note'));
    await act(async () => pendingA.resolve(contextualNoteWrite(11, 'Stale A create')));

    expect(editor).toHaveValue('Portfolio B note');
    expect(showToast).not.toHaveBeenCalledWith('Note saved');
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.get).toHaveBeenCalledTimes(2);
});
it.each(['success', 'error'])('ignores a stale DELETE %s after a Portfolio switch', async outcome => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    api.get.mockImplementation(async (_url, config) => Number(config.params.profile_id) === 1
        ? contextualNoteList(11, 'Portfolio A note')
        : contextualNoteList(22, 'Portfolio B note'));
    const pendingDelete = deferred();
    api.delete.mockReturnValueOnce(pendingDelete.promise);
    const view = render(contextualNotesTree());
    const editor = await screen.findByLabelText('Contextual note');
    await waitFor(() => expect(editor).toHaveValue('Portfolio A note'));
    fireEvent.click(screen.getByRole('button', { name: 'Delete', exact: true }));
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/contextual-notes/11'));

    portfolioState.id = 2;
    view.rerender(contextualNotesTree());
    await waitFor(() => expect(editor).toHaveValue('Portfolio B note'));
    await act(async () => {
        if (outcome === 'success') pendingDelete.resolve({ data: {} });
        else pendingDelete.reject(unavailable());
    });

    expect(editor).toHaveValue('Portfolio B note');
    expect(showToast).not.toHaveBeenCalledWith('Note deleted');
    expect(showToast).not.toHaveBeenCalledWith('Synthetic unavailable', 'danger');
    expect(api.delete).toHaveBeenCalledTimes(1);
    expect(api.get).toHaveBeenCalledTimes(2);
});
it('ignores an old A completion after A to B to A returns with a fresh note identity', async () => {
    let screenersReads = 0;
    api.get.mockImplementation(async (_url, config) => {
        if (config.params.context_key === 'strategies') return contextualNoteList(22, 'Context B note');
        screenersReads += 1;
        return screenersReads === 1
            ? contextualNoteList(11, 'First A note')
            : contextualNoteList(33, 'Fresh A note');
    });
    const pendingA = deferred();
    api.put.mockReturnValueOnce(pendingA.promise);
    render(contextualNotesTree());
    const editor = await screen.findByLabelText('Contextual note');
    await waitFor(() => expect(editor).toHaveValue('First A note'));
    fireEvent.change(editor, { target: { value: 'Old A edit' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));

    fireEvent.click(screen.getByRole('button', { name: 'Switch to context B' }));
    await waitFor(() => expect(editor).toHaveValue('Context B note'));
    fireEvent.click(screen.getByRole('button', { name: 'Return to context A' }));
    await waitFor(() => expect(editor).toHaveValue('Fresh A note'));
    await act(async () => pendingA.resolve(contextualNoteWrite(11, 'Stale first A response')));

    expect(editor).toHaveValue('Fresh A note');
    expect(api.get).toHaveBeenCalledTimes(3);
    expect(showToast).not.toHaveBeenCalledWith('Note saved');
});
it('does not let old A finally clear a newer B operation busy state', async () => {
    api.get.mockImplementation(async (_url, config) => Number(config.params.profile_id) === 1
        ? contextualNoteList(11, 'Portfolio A note')
        : contextualNoteList(22, 'Portfolio B note'));
    const pendingA = deferred();
    const pendingB = deferred();
    api.put.mockReturnValueOnce(pendingA.promise).mockReturnValueOnce(pendingB.promise);
    const view = render(contextualNotesTree());
    const editor = await screen.findByLabelText('Contextual note');
    await waitFor(() => expect(editor).toHaveValue('Portfolio A note'));
    fireEvent.change(editor, { target: { value: 'A pending edit' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));

    portfolioState.id = 2;
    view.rerender(contextualNotesTree());
    await waitFor(() => expect(editor).toHaveValue('Portfolio B note'));
    fireEvent.change(editor, { target: { value: 'B pending edit' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(2));
    expect(screen.getByRole('button', { name: 'Save', exact: true })).toBeDisabled();

    await act(async () => pendingA.resolve(contextualNoteWrite(11, 'Old A response')));
    expect(editor).toHaveValue('B pending edit');
    expect(screen.getByRole('button', { name: 'Save', exact: true })).toBeDisabled();
    expect(showToast).not.toHaveBeenCalledWith('Note saved');
    await act(async () => pendingB.resolve(contextualNoteWrite(22, 'B pending edit')));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save', exact: true })).toBeEnabled());
    expect(showToast).toHaveBeenCalledTimes(1);
    expect(showToast).toHaveBeenCalledWith('Note saved');
});
it('does not toast when a pending save completes after the notes component unmounts', async () => {
    api.get.mockResolvedValue(contextualNoteList(11, 'Portfolio A note'));
    const pendingSave = deferred();
    api.put.mockReturnValueOnce(pendingSave.promise);
    const view = render(contextualNotesTree());
    const editor = await screen.findByLabelText('Contextual note');
    await waitFor(() => expect(editor).toHaveValue('Portfolio A note'));
    fireEvent.change(editor, { target: { value: 'Pending edit' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save', exact: true }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));

    view.unmount();
    await act(async () => pendingSave.resolve(contextualNoteWrite(11, 'Pending edit')));
    expect(showToast).not.toHaveBeenCalled();
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
