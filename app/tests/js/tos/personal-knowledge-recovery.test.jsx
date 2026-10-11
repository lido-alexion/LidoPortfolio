import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
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

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, getApiErrorMessage: () => 'Synthetic unavailable' }));
const portfolioState = vi.hoisted(() => ({ id: 1 }));
vi.mock('../../../resources/js/src/context/PortfolioContext', () => ({ usePortfolio: () => ({ activePortfolio: { id: portfolioState.id } }) }));
vi.mock('../../../resources/js/src/context/AuthContext', () => ({ useAuth: () => ({ user: { is_admin: false } }) }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/knowledgeBoard/KnowledgeNoteGrid', () => ({ default: ({ notes, onArchive }) => <>{notes.map(note => <div key={note.id}>{note.title}<button onClick={() => onArchive(note)}>Archive fixture</button></div>)}</> }));
vi.mock('../../../resources/js/src/components/knowledgeBoard/KnowledgeNoteEditorModal', () => ({ default: () => null }));
beforeEach(() => { cleanup(); vi.clearAllMocks(); localStorage.clear(); portfolioState.id = 1; });
const unavailable = () => ({ response: { status: 503 }, message: 'Synthetic unavailable' });
const at = (path, route, node) => render(<MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={node} /></Routes></MemoryRouter>);

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
