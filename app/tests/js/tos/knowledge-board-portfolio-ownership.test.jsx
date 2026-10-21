import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import { showToast } from '../../../resources/js/src/toast';
import KnowledgeBoardPage from '../../../resources/js/src/pages/KnowledgeBoardPage';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import { PortfolioProvider, usePortfolio } from '../../../resources/js/src/context/PortfolioContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, getApiErrorMessage: () => 'Synthetic unavailable' }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/knowledgeBoard/KnowledgeNoteGrid', () => ({ default: ({ notes, onDuplicate, onEdit, selectedIds, onToggleSelect }) => <>{notes.map(note => <div key={note.id}>{note.title}<button onClick={() => onEdit(note)}>Edit {note.title}</button><button onClick={() => onDuplicate(note)}>Duplicate {note.title}</button><button onClick={() => onToggleSelect(note.id)}>{selectedIds.has(String(note.id)) ? 'Selected' : 'Select'} {note.title}</button></div>)}</> }));

const deferred = () => { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const note = (id, title) => ({ id, title, content_html: `<p>${title}</p>`, content_json: { type: 'doc', content: [] }, color_palette: 'default', is_pinned: false, is_archived: false, tags: [] });
const tag = (id, name) => ({ id, name, color: '#198754' });
const unavailable = () => ({ response: { status: 503 }, message: 'Synthetic unavailable' });
function mount(controls = null) {
    const configuredGet = api.get.getMockImplementation();
    api.get.mockImplementation(path => path === '/portfolios'
        ? Promise.resolve({ data: { data: [{ id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Research' }] } })
        : configuredGet?.(path));
    return render(<MemoryRouter><AuthContext.Provider value={{ loading: false, isAuthenticated: true, user: { id: 1, default_portfolio_id: 1, is_admin: false } }}><PortfolioProvider><PortfolioSwitcher />{controls}<KnowledgeBoardPage /></PortfolioProvider></AuthContext.Provider></MemoryRouter>);
}
const switchTo = async (name) => {
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name, exact: true }));
};

beforeEach(() => { cleanup(); vi.resetAllMocks(); localStorage.clear(); sessionStorage.clear(); sessionStorage.setItem('portfolio_active_id', '1'); });
afterEach(() => { cleanup(); vi.restoreAllMocks(); });

it('ignores a deferred A duplicate result and keeps the returned A read current after A→B→A', async () => {
    const bRead = deferred(); const currentA = deferred(); const obsoleteDuplicate = deferred();
    const aCalls = { notes: 0 };
    api.get.mockImplementation((path) => {
        if (path.endsWith('/tags')) return Promise.resolve({ data: { data: [] } });
        if (path.endsWith('/notes')) {
            const id = sessionStorage.getItem('portfolio_active_id');
            if (id === '2') return bRead.promise;
            aCalls.notes += 1;
            return aCalls.notes <= 1
                ? Promise.resolve({ data: { data: [note(11, 'A source')] } })
                : currentA.promise;
        }
        return Promise.resolve({ data: { data: [] } });
    });
    api.post.mockReturnValue(obsoleteDuplicate.promise);
    mount();
    await screen.findByText('A source');
    fireEvent.click(await screen.findByRole('button', { name: 'Duplicate A source' }));
    await switchTo('Research');
    await act(async () => bRead.resolve({ data: { data: [note(22, 'B confirmed')] } }));
    expect(await screen.findByText('B confirmed')).toBeVisible();
    await switchTo('Main');
    await act(async () => currentA.resolve({ data: { data: [note(12, 'A current')] } }));
    expect(await screen.findByText('A current')).toBeVisible();
    await act(async () => obsoleteDuplicate.resolve({ data: { data: note(99, 'A late copy') } }));
    expect(screen.queryByText('A late copy')).toBeNull();
    expect(screen.getByText('A current')).toBeVisible();
});

it('does not let stale A read failures clear B loading or a later B selection', async () => {
    const oldA = deferred(); const currentB = deferred(); const oldATags = deferred(); const currentBTags = deferred();
    api.get.mockImplementation(path => {
        const activeId = sessionStorage.getItem('portfolio_active_id');
        if (path.endsWith('/tags')) {
            if (activeId === '2') return currentBTags.promise;
            return oldATags.promise;
        }
        if (activeId === '2') return currentB.promise;
        return oldA.promise;
    });
    mount();
    await waitFor(() => expect(api.get).toHaveBeenCalled());
    await switchTo('Research');
    await act(async () => currentBTags.resolve({ data: { data: [tag(22, 'B tag')] } }));
    await act(async () => oldA.reject(unavailable()));
    expect(screen.getByText('Loading notes…')).toBeVisible();
    expect(screen.queryByRole('alert')).toBeNull();
    await act(async () => currentB.resolve({ data: { data: [note(22, 'B confirmed')] } }));
    expect(await screen.findByText('B confirmed')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Select B confirmed' }));
    await act(async () => oldATags.reject(unavailable()));
    expect(screen.getByRole('button', { name: 'Selected B confirmed' })).toBeVisible();
    expect(screen.queryByRole('alert')).toBeNull();
});

it('does not publish a deferred A editor create over confirmed B content or a B editor', async () => {
    const oldCreate = deferred();
    api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/notes') && sessionStorage.getItem('portfolio_active_id') === '2' ? [note(22, 'B confirmed')] : [] } }));
    api.post.mockReturnValue(oldCreate.promise);
    mount();
    await waitFor(() => expect(screen.getByText(/No notes yet/)).toBeVisible());
    fireEvent.click(screen.getByRole('button', { name: '+ New', exact: true }));
    let editor = screen.getByRole('dialog', { name: 'New note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'A late create' } });
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    await switchTo('Research');
    expect(await screen.findByText('B confirmed')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: '+ New', exact: true }));
    editor = screen.getByRole('dialog', { name: 'New note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'B current draft' } });
    await act(async () => oldCreate.resolve({ data: { data: note(99, 'A late create') } }));
    expect(screen.getByRole('dialog', { name: 'New note' })).toBeVisible();
    expect(within(editor).getByLabelText('Note content')).toHaveValue('B current draft');
    expect(screen.queryByText('A late create')).toBeNull();
    expect(screen.getByText('B confirmed')).toBeVisible();
    expect(showToast).not.toHaveBeenCalledWith('Note saved.');
    await switchTo('Main');
    fireEvent.click(await screen.findByRole('button', { name: 'Resume note draft' }));
    editor = screen.getByRole('dialog', { name: 'Edit note' });
    expect(within(editor).getByLabelText('Note content')).toHaveValue('A late create');
    api.put.mockResolvedValue({ data: { data: note(99, 'A late create') } });
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/knowledge-board/notes/99', expect.any(Object)));
    expect(api.post).toHaveBeenCalledTimes(1);
});

it('invalidates an obsolete duplicate completion after the Board unmounts', async () => {
    const oldDuplicate = deferred();
    api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/notes') ? [note(11, 'A source')] : [] } }));
    api.post.mockReturnValue(oldDuplicate.promise);
    const view = mount();
    fireEvent.click(await screen.findByRole('button', { name: 'Duplicate A source' }));
    view.unmount();
    await act(async () => oldDuplicate.reject(unavailable()));
    expect(showToast).not.toHaveBeenCalledWith('Could not duplicate note.', 'danger');
});

it('retains a failed new-note draft and retries it as a new A create after switching away and back', async () => {
    const write = deferred();
    api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/notes') ? [] : [] } }));
    api.post.mockReturnValueOnce(Promise.reject(unavailable()))
        .mockReturnValueOnce(write.promise)
        .mockResolvedValueOnce({ data: { data: note(26, 'Private A draft') } });
    mount();
    await waitFor(() => expect(screen.getByText(/No notes yet/)).toBeVisible());
    fireEvent.click(screen.getByRole('button', { name: '+ New', exact: true }));
    let editor = screen.getByRole('dialog', { name: 'New note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'Private A draft' } });
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    expect(within(editor).getByLabelText('Note content')).toHaveValue('Private A draft');
    await switchTo('Research');
    expect(screen.queryByRole('dialog', { name: 'New note' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: '+ New', exact: true }));
    editor = screen.getByRole('dialog', { name: 'New note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'Private B draft' } });
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    await act(async () => write.resolve({ data: { data: note(25, 'Private B draft') } }));
    await switchTo('Main');
    expect(await screen.findByRole('button', { name: 'Resume note draft' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Resume note draft' }));
    editor = screen.getByRole('dialog', { name: 'New note' });
    expect(within(editor).getByLabelText('Note content')).toHaveValue('Private A draft');
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(3));
    expect(api.post.mock.calls[2][0]).toBe('/knowledge-board/notes');
});

it('resumes an existing A note edit as the same PUT target after editing B', async () => {
    api.get.mockImplementation(path => {
        const data = path.endsWith('/notes')
            ? (sessionStorage.getItem('portfolio_active_id') === '2' ? [note(902, 'B source')] : [note(901, 'A source')])
            : [];
        return Promise.resolve({ data: { data } });
    });
    api.put.mockImplementation(async (path, payload) => ({ data: { data: { ...note(Number(path.split('/').pop()), 'Saved A edit'), ...payload } } }));
    mount();
    await screen.findByText('A source');
    fireEvent.click(screen.getByRole('button', { name: 'Edit A source' }));
    let editor = screen.getByRole('dialog', { name: 'Edit note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'A retained edit' } });
    await switchTo('Research');
    await screen.findByText('B source');
    fireEvent.click(screen.getByRole('button', { name: 'Edit B source' }));
    editor = screen.getByRole('dialog', { name: 'Edit note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'B retained edit' } });
    await switchTo('Main');
    fireEvent.click(await screen.findByRole('button', { name: 'Resume note draft' }));
    editor = screen.getByRole('dialog', { name: 'Edit note' });
    expect(within(editor).getByLabelText('Note content')).toHaveValue('A retained edit');
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/knowledge-board/notes/901', expect.any(Object)));
    expect(api.put).not.toHaveBeenCalledWith('/knowledge-board/notes/902', expect.any(Object));
});

it('cancels a pending autosave when its editor is suspended in another Portfolio', async () => {
    api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/notes') ? [] : [] } }));
    mount();
    await waitFor(() => expect(screen.getByText(/No notes yet/)).toBeVisible());
    vi.useFakeTimers();
    try {
    fireEvent.click(screen.getByRole('button', { name: '+ New', exact: true }));
    const editor = screen.getByRole('dialog', { name: 'New note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'A pending autosave' } });
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(screen.getByRole('button', { name: 'Research', exact: true }));
    expect(screen.queryByRole('dialog', { name: 'New note' })).toBeNull();
    await act(async () => { await vi.advanceTimersByTimeAsync(3000); });
    expect(api.post).not.toHaveBeenCalled();
    } finally {
        vi.useRealTimers();
    }
});

it('keeps a resumed A create in flight once, then saves its receipt as the same note', async () => {
    const create = deferred();
    api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/notes') && sessionStorage.getItem('portfolio_active_id') === '2' ? [note(22, 'B confirmed')] : [] } }));
    api.post.mockReturnValue(create.promise);
    api.put.mockResolvedValue({ data: { data: note(991, 'A pending create') } });
    mount();
    await screen.findByText(/No notes yet/);
    fireEvent.click(screen.getByRole('button', { name: '+ New', exact: true }));
    let editor = screen.getByRole('dialog', { name: 'New note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'A pending create' } });
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    await switchTo('Research');
    expect(await screen.findByText('B confirmed')).toBeVisible();
    await switchTo('Main');
    vi.useFakeTimers();
    try {
        fireEvent.click(screen.getByRole('button', { name: 'Resume note draft' }));
        editor = screen.getByRole('dialog', { name: 'New note' });
        await act(async () => { await vi.advanceTimersByTimeAsync(3000); });
        expect(api.post).toHaveBeenCalledTimes(1);
        await act(async () => create.resolve({ data: { data: note(991, 'A pending create') } }));
        fireEvent.click(within(screen.getByRole('dialog', { name: 'Edit note' })).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
        await waitFor(() => expect(api.put).toHaveBeenCalledWith('/knowledge-board/notes/991', expect.any(Object)));
        expect(api.post).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
        await switchTo('Research');
        expect(screen.getByText('B confirmed')).toBeVisible();
        expect(screen.queryByText('A pending create')).toBeNull();
    } finally {
        vi.useRealTimers();
    }
});

it('keeps a pending create session open through its receipt when the user closes', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const create = deferred();
    api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/notes') ? [] : [] } }));
    api.post.mockReturnValue(create.promise);
    api.put.mockResolvedValue({ data: { data: note(992, 'Receipt retained') } });
    mount();
    await screen.findByText(/No notes yet/);
    fireEvent.click(screen.getByRole('button', { name: '+ New', exact: true }));
    let editor = screen.getByRole('dialog', { name: 'New note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'Receipt retained' } });
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));

    fireEvent.click(within(editor).getByRole('button', { name: 'Close', exact: true }));
    expect(screen.getByRole('dialog', { name: 'New note' })).toBeVisible();
    expect(showToast).toHaveBeenCalledWith('A note save is still pending. Keep the editor open until it finishes.');
    expect(api.post).toHaveBeenCalledTimes(1);

    await act(async () => create.resolve({ data: { data: note(992, 'Receipt retained') } }));
    expect(await screen.findByText('Receipt retained')).toBeVisible();
    expect(screen.getByRole('dialog', { name: 'Edit note' })).toBeVisible();
    expect(api.post).toHaveBeenCalledTimes(1);

    editor = screen.getByRole('dialog', { name: 'Edit note' });
    fireEvent.click(within(editor).getByRole('button', { name: 'Close', exact: true }));
    expect(screen.queryByRole('dialog', { name: 'Edit note' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Edit Receipt retained' }));
    editor = screen.getByRole('dialog', { name: 'Edit note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'Receipt retained edit' } });
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/knowledge-board/notes/992', expect.any(Object)));
    expect(api.post).toHaveBeenCalledTimes(1);
});

it('keeps a newer same-Portfolio editor when an older delayed delete succeeds', async () => {
    const deletion = deferred();
    api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/notes') ? [note(901, 'A source'), note(902, 'B source')] : [] } }));
    api.delete.mockReturnValue(deletion.promise);
    api.put.mockImplementation(async (path, payload) => ({ data: { data: { ...note(Number(path.split('/').pop()), 'Saved B edit'), ...payload } } }));
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    mount();
    await screen.findByText('A source');
    fireEvent.click(screen.getByRole('button', { name: 'Edit A source' }));
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Edit note' })).getByRole('button', { name: 'Delete note' }));
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/knowledge-board/notes/901'));
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Edit note' })).getByRole('button', { name: 'Close' }));
    fireEvent.click(screen.getByRole('button', { name: 'Edit B source' }));
    let editor = screen.getByRole('dialog', { name: 'Edit note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'B unsaved current draft' } });
    await act(async () => deletion.resolve({ data: {} }));
    editor = screen.getByRole('dialog', { name: 'Edit note' });
    expect(within(editor).getByLabelText('Note content')).toHaveValue('B unsaved current draft');
    expect(screen.queryByText('A source')).toBeNull();
    expect(screen.getByText('B source')).toBeVisible();
    fireEvent.click(within(editor).getByRole('button', { name: 'Save (Ctrl/Cmd + S)' }));
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/knowledge-board/notes/902', expect.any(Object)));
});

it('does not surface an old delete failure in a newer same-Portfolio editor', async () => {
    const deletion = deferred();
    api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/notes') ? [note(901, 'A source'), note(902, 'B source')] : [] } }));
    api.delete.mockReturnValue(deletion.promise);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    mount();
    await screen.findByText('A source');
    fireEvent.click(screen.getByRole('button', { name: 'Edit A source' }));
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Edit note' })).getByRole('button', { name: 'Delete note' }));
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/knowledge-board/notes/901'));
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Edit note' })).getByRole('button', { name: 'Close' }));
    fireEvent.click(screen.getByRole('button', { name: 'Edit B source' }));
    const editor = screen.getByRole('dialog', { name: 'Edit note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'B current draft' } });
    showToast.mockClear();
    await act(async () => deletion.reject(unavailable()));
    expect(screen.getByRole('dialog', { name: 'Edit note' })).toBeVisible();
    expect(within(screen.getByRole('dialog', { name: 'Edit note' })).getByLabelText('Note content')).toHaveValue('B current draft');
    expect(showToast).not.toHaveBeenCalledWith('Could not delete note.', 'danger');
});

it('retains only the originating editor draft across batched A to B to A selection', async () => {
    function BatchedSelection() {
        const { setActivePortfolio } = usePortfolio();
        return <button onClick={() => { setActivePortfolio(2); setActivePortfolio(1); }}>Select B then A in one event</button>;
    }
    api.get.mockImplementation(() => Promise.resolve({ data: { data: [] } }));
    mount(<BatchedSelection />);
    await screen.findByText(/No notes yet/);
    fireEvent.click(screen.getByRole('button', { name: '+ New', exact: true }));
    let editor = screen.getByRole('dialog', { name: 'New note' });
    fireEvent.change(within(editor).getByLabelText('Note content'), { target: { value: 'Private A retained draft' } });
    fireEvent.click(screen.getByRole('button', { name: 'Select B then A in one event' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Resume note draft' }));
    editor = screen.getByRole('dialog', { name: 'New note' });
    expect(within(editor).getByLabelText('Note content')).toHaveValue('Private A retained draft');
    await switchTo('Research');
    await screen.findByText(/No notes yet/);
    expect(screen.queryByRole('button', { name: 'Resume note draft' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: '+ New', exact: true }));
    expect(within(screen.getByRole('dialog', { name: 'New note' })).getByLabelText('Note content')).toHaveValue('');
});
