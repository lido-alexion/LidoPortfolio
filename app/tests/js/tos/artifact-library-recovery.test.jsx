import React from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import ArtifactLibraryPage from '../../../resources/js/src/pages/ArtifactLibraryPage.jsx';
import ArtifactLibraryDetailPage from '../../../resources/js/src/pages/ArtifactLibraryDetailPage.jsx';
import { apiMock } from './helpers/mockApi.js';

const UUID_A = 'synthetic-artifact-a';
const UUID_B = 'synthetic-artifact-b';
const detail = (name, draft = false) => ({
    name,
    slug: name.toLowerCase().replaceAll(' ', '-'),
    type: 'screener',
    permission: 'owner',
    origin: 'user',
    versions: [
        { id: `${name}-v1`, semver: '1.0.0', status: 'published', content: {}, documentation: {}, dependencies: [], definition_hash: 'fixture-only', lock_version: 1 },
        { id: `${name}-v2`, semver: '1.1.0', status: 'published', content: {}, documentation: {}, dependencies: [], definition_hash: 'fixture-only', lock_version: 1 },
        ...(draft ? [{ id: `${name}-draft`, semver: '1.2.0', status: 'draft', content: { draft: name }, documentation: {}, change_summary: '', dependencies: [], definition_hash: 'fixture-only', lock_version: 1 }] : []),
    ],
    draft_version: draft ? '1.2.0' : null,
    portfolio_binding: null,
});
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
};

function RouteHarness() {
    const navigate = useNavigate();
    return <>
        <button type="button" onClick={() => navigate(`/artifact-library/${UUID_A}`)}>Route A</button>
        <button type="button" onClick={() => navigate(`/artifact-library/${UUID_B}`)}>Route B</button>
        <Routes><Route path="/artifact-library/:uuid" element={<ArtifactLibraryDetailPage />} /></Routes>
    </>;
}

beforeEach(() => {
    apiMock.get.mockReset();
    apiMock.post.mockReset();
    apiMock.put.mockReset();
});

it('does not label an unavailable library as empty and retries the read explicitly', async () => {
    apiMock.get.mockRejectedValueOnce({ response: { status: 503, data: { message: 'Synthetic Library unavailable.' } } }).mockResolvedValueOnce({ data: { data: [] } });
    render(<MemoryRouter><ArtifactLibraryPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic Library unavailable.'); expect(screen.queryByText('No accessible artifacts match these filters.')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry Library', exact: true })); expect(await screen.findByText('No accessible artifacts match these filters.')).toBeVisible();
});
it('retries an unavailable artifact without enabling any mutation before a confirmed read', async () => {
    apiMock.get.mockRejectedValueOnce({ response: { status: 503, data: { message: 'Synthetic artifact unavailable.' } } }).mockResolvedValueOnce({ data: { data: { name: 'Confirmed artifact', slug: 'confirmed', type: 'screener', permission: 'shared', origin: 'user', versions: [] } } });
    render(<MemoryRouter initialEntries={['/artifact-library/synthetic-uuid']}><Routes><Route path="/artifact-library/:uuid" element={<ArtifactLibraryDetailPage />} /></Routes></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic artifact unavailable.'); expect(apiMock.post).not.toHaveBeenCalled(); fireEvent.click(screen.getByRole('button', { name: 'Retry artifact', exact: true })); expect(await screen.findByRole('heading', { name: 'Confirmed artifact' })).toBeVisible(); expect(apiMock.post).not.toHaveBeenCalled();
});

it('keeps the committed UUID detail through reverse A/B completions and an A→B→A route sequence', async () => {
    const firstA = deferred();
    const requestB = deferred();
    const latestA = deferred();
    const requests = { [UUID_A]: [firstA, latestA], [UUID_B]: [requestB] };
    apiMock.get.mockImplementation((path) => {
        const uuid = String(path).split('/').at(-1);
        const request = requests[uuid]?.shift();
        if (!request) throw new Error(`Unexpected synthetic detail GET ${path}`);
        return request.promise;
    });
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(`/v1/artifact-library/${UUID_A}`));
    fireEvent.click(screen.getByRole('button', { name: 'Route B' }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(`/v1/artifact-library/${UUID_B}`));
    fireEvent.click(screen.getByRole('button', { name: 'Route A' }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(3));

    await act(async () => latestA.resolve({ data: { data: detail('A current') } }));
    expect(await screen.findByRole('heading', { name: 'A current' })).toBeVisible();
    await act(async () => requestB.resolve({ data: { data: detail('B stale') } }));
    await act(async () => firstA.resolve({ data: { data: detail('A oldest') } }));
    expect(screen.getByRole('heading', { name: 'A current' })).toBeVisible();
    expect(screen.queryByRole('heading', { name: 'B stale' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'A oldest' })).not.toBeInTheDocument();
});

it('ignores an obsolete detail rejection and keeps the current route loading state independent', async () => {
    const oldA = deferred();
    const currentB = deferred();
    apiMock.get.mockImplementation((path) => String(path).endsWith(UUID_A) ? oldA.promise : currentB.promise);
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(`/v1/artifact-library/${UUID_A}`));
    fireEvent.click(screen.getByRole('button', { name: 'Route B' }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(`/v1/artifact-library/${UUID_B}`));
    await act(async () => oldA.reject(new Error('obsolete synthetic A failure')));
    expect(screen.getByText('Loading artifact…')).toBeVisible();
    expect(screen.queryByRole('heading', { name: 'A' })).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    await act(async () => currentB.resolve({ data: { data: detail('B current') } }));
    expect(await screen.findByRole('heading', { name: 'B current' })).toBeVisible();
    expect(screen.queryByText('Loading artifact…')).not.toBeInTheDocument();
});

it('keeps the new route loading while an obsolete detail success settles', async () => {
    const oldA = deferred();
    const currentB = deferred();
    apiMock.get.mockImplementation((path) => String(path).endsWith(UUID_A) ? oldA.promise : currentB.promise);
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(`/v1/artifact-library/${UUID_A}`));
    fireEvent.click(screen.getByRole('button', { name: 'Route B' }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(`/v1/artifact-library/${UUID_B}`));

    await act(async () => oldA.resolve({ data: { data: detail('A obsolete') } }));
    expect(screen.getByText('Loading artifact…')).toBeVisible();
    expect(screen.queryByRole('heading', { name: 'A obsolete' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'B current' })).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();

    await act(async () => currentB.resolve({ data: { data: detail('B current') } }));
    expect(await screen.findByRole('heading', { name: 'B current' })).toBeVisible();
    expect(screen.queryByText('Loading artifact…')).not.toBeInTheDocument();
});

it('does not render a completed comparison after the artifact route changes', async () => {
    const comparison = deferred();
    apiMock.get.mockImplementation((path) => {
        if (String(path).endsWith('/diff')) return comparison.promise;
        const uuid = String(path).split('/').at(-1);
        return Promise.resolve({ data: { data: detail(uuid === UUID_A ? 'A comparison source' : 'B comparison target') } });
    });
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    expect(await screen.findByRole('heading', { name: 'A comparison source' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Compare', exact: true }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(`/v1/artifact-library/${UUID_A}/diff`, { params: { from: '1.1.0', to: '1.0.0' } }));
    fireEvent.click(screen.getByRole('button', { name: 'Route B' }));
    expect(await screen.findByRole('heading', { name: 'B comparison target' })).toBeVisible();
    await act(async () => comparison.resolve({ data: { data: { changes: [{ path: '/stale/A', change: 'changed', before: 1, after: 2 }] } } }));
    expect(screen.queryByText('/stale/A')).not.toBeInTheDocument();
    expect(screen.queryByText('No structural changes.')).not.toBeInTheDocument();
});

it('discards a comparison when its from/to selection changes before the read completes', async () => {
    const comparison = deferred();
    apiMock.get.mockImplementation((path) => String(path).endsWith('/diff')
        ? comparison.promise
        : Promise.resolve({ data: { data: detail('Selection comparison') } }));
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    expect(await screen.findByRole('heading', { name: 'Selection comparison' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Compare', exact: true }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(`/v1/artifact-library/${UUID_A}/diff`, { params: { from: '1.1.0', to: '1.0.0' } }));
    fireEvent.change(screen.getByLabelText('Compare from version'), { target: { value: '1.0.0' } });
    await act(async () => comparison.resolve({ data: { data: { changes: [{ path: '/obsolete-selection', change: 'changed', before: 1, after: 2 }] } } }));
    expect(screen.queryByText('/obsolete-selection')).not.toBeInTheDocument();
    expect(apiMock.get).toHaveBeenCalledTimes(2);
});

it('does not surface an obsolete comparison failure after the selection changes', async () => {
    const comparison = deferred();
    apiMock.get.mockImplementation((path) => String(path).endsWith('/diff')
        ? comparison.promise
        : Promise.resolve({ data: { data: detail('Changed comparison') } }));
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    expect(await screen.findByRole('heading', { name: 'Changed comparison' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Compare', exact: true }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(2));
    fireEvent.change(screen.getByLabelText('Compare from version'), { target: { value: '1.0.0' } });
    fireEvent.change(screen.getByLabelText('Compare to version'), { target: { value: '1.1.0' } });
    expect(screen.getByRole('button', { name: 'Compare', exact: true })).toBeEnabled();
    await act(async () => comparison.reject(new Error('obsolete synthetic compare failure')));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Compare', exact: true })).toBeEnabled();
});

it('accepts only the current StrictMode detail effect and ignores its replayed predecessor', async () => {
    const obsoleteEffect = deferred();
    const committedEffect = deferred();
    const queue = [obsoleteEffect, committedEffect];
    apiMock.get.mockImplementation(() => queue.shift().promise);
    render(<React.StrictMode><MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><Routes><Route path="/artifact-library/:uuid" element={<ArtifactLibraryDetailPage />} /></Routes></MemoryRouter></React.StrictMode>);
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(2));
    await act(async () => committedEffect.resolve({ data: { data: detail('Strict current') } }));
    expect(await screen.findByRole('heading', { name: 'Strict current' })).toBeVisible();
    await act(async () => obsoleteEffect.reject(new Error('obsolete StrictMode request')));
    expect(screen.getByRole('heading', { name: 'Strict current' })).toBeVisible();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('ignores a detail response after the page unmounts', async () => {
    const request = deferred();
    apiMock.get.mockReturnValue(request.promise);
    const view = render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><Routes><Route path="/artifact-library/:uuid" element={<ArtifactLibraryDetailPage />} /></Routes></MemoryRouter>);
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(1));
    view.unmount();
    await act(async () => request.resolve({ data: { data: detail('Unmounted detail') } }));
    expect(apiMock.post).not.toHaveBeenCalled();
    expect(apiMock.put).not.toHaveBeenCalled();
});

it('preserves same-UUID DraftEditor text across an unrelated parent rerender', async () => {
    apiMock.get.mockResolvedValue({ data: { data: detail('Draft identity', true) } });
    function RerenderHarness() {
        const [count, setCount] = React.useState(0);
        return <><button type="button" onClick={() => setCount(value => value + 1)}>Rerender parent</button><span>{count}</span><MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><Routes><Route path="/artifact-library/:uuid" element={<ArtifactLibraryDetailPage />} /></Routes></MemoryRouter></>;
    }
    render(<RerenderHarness />);
    const editor = await screen.findByLabelText('Artifact envelope JSON');
    fireEvent.change(editor, { target: { value: '{"unsaved":"same uuid"}' } });
    fireEvent.click(screen.getByRole('button', { name: 'Rerender parent' }));
    expect(screen.getByLabelText('Artifact envelope JSON')).toHaveValue('{"unsaved":"same uuid"}');
    expect(apiMock.get).toHaveBeenCalledTimes(1);
});

it('reads the exact Bundle plan after an uncertain deploy response and exposes committed member evidence', async () => {
    const bundle = { ...detail('Recovery Bundle'), type: 'bundle', versions: [{ id: 88, semver: '1.0.0', status: 'published', content: {}, documentation: {}, dependencies: [], definition_hash: 'fixture-only', lock_version: 1 }] };
    const planUuid = '00000000-0000-4000-8000-000000000034';
    apiMock.get.mockImplementation((path) => String(path).includes('/bundle-deployments/')
        ? Promise.resolve({ data: { data: { deployment_uuid: planUuid, status: 'completed', error_code: null, items: [{ artifact_uuid: UUID_A, version: '1.0.0', action: 'bind', resulting_revision_id: 17 }] } } })
        : Promise.resolve({ data: { data: bundle } }));
    apiMock.post.mockImplementation((path) => String(path).endsWith('/bundle-plan')
        ? Promise.resolve({ data: { data: { deployment_uuid: planUuid, status: 'planned', items: [{ artifact_uuid: UUID_A }] } } })
        : Promise.reject(new Error('response lost after server commit')));
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><Routes><Route path="/artifact-library/:uuid" element={<ArtifactLibraryDetailPage />} /></Routes></MemoryRouter>);
    fireEvent.click(await screen.findByRole('button', { name: 'Deploy Bundle', exact: true }));
    const recovery = await screen.findByRole('region', { name: 'Bundle deployment recovery' });
    const planIdentity = await within(recovery).findByText(planUuid, { selector: 'code', exact: true });
    expect(planIdentity).toBeVisible();
    await waitFor(() => expect(planIdentity.parentElement).toHaveTextContent(new RegExp(`^Plan ${planUuid} · completed$`)));
    expect(within(recovery).getByRole('listitem')).toHaveTextContent(/^synthetic-artifact-a 1\.0\.0 · bind · revision 17$/);
    expect(apiMock.get).toHaveBeenCalledWith(`/v1/artifact-library/bundle-deployments/${planUuid}`);
    expect(apiMock.post).toHaveBeenCalledTimes(2);
    expect(apiMock.post).toHaveBeenLastCalledWith(`/v1/artifact-library/bundle-deployments/${planUuid}/deploy`);
    expect(screen.getByRole('button', { name: 'Existing Bundle plan needs review' })).toBeDisabled();
    confirm.mockRestore();
});

const bundleDetail = (name) => ({ ...detail(name), type: 'bundle', versions: [{ id: `${name}-v1`, semver: '1.0.0', status: 'published', content: {}, documentation: {}, dependencies: [], definition_hash: 'fixture-only', lock_version: 1 }] });

it('does not confirm or deploy a plan response after its artifact route changes', async () => {
    const oldPlan = deferred();
    apiMock.get.mockImplementation((path) => Promise.resolve({ data: { data: bundleDetail(String(path).endsWith(UUID_A) ? 'Bundle A' : 'Bundle B') } }));
    apiMock.post.mockReturnValue(oldPlan.promise);
    const confirm = vi.spyOn(window, 'confirm');
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    fireEvent.click(await screen.findByRole('button', { name: 'Deploy Bundle', exact: true }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/v1/artifact-library/versions/Bundle A-v1/bundle-plan'));
    fireEvent.click(screen.getByRole('button', { name: 'Route B' }));
    expect(await screen.findByRole('heading', { name: 'Bundle B' })).toBeVisible();
    await act(async () => oldPlan.resolve({ data: { data: { deployment_uuid: 'obsolete-plan-a', status: 'planned', items: [{}] } } }));
    expect(confirm).not.toHaveBeenCalled();
    expect(apiMock.post).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('region', { name: 'Bundle deployment recovery' })).not.toBeInTheDocument();
    confirm.mockRestore();
});

it('rejects an obsolete same-UUID plan after an A to B to A route sequence', async () => {
    const oldPlan = deferred();
    apiMock.get.mockImplementation((path) => Promise.resolve({ data: { data: bundleDetail(String(path).endsWith(UUID_A) ? 'Bundle A' : 'Bundle B') } }));
    apiMock.post.mockReturnValue(oldPlan.promise);
    const confirm = vi.spyOn(window, 'confirm');
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    fireEvent.click(await screen.findByRole('button', { name: 'Deploy Bundle', exact: true }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Route B' }));
    expect(await screen.findByRole('heading', { name: 'Bundle B' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Route A' }));
    expect(await screen.findByRole('heading', { name: 'Bundle A' })).toBeVisible();
    await act(async () => oldPlan.resolve({ data: { data: { deployment_uuid: 'obsolete-same-uuid', status: 'planned', items: [{}] } } }));
    expect(confirm).not.toHaveBeenCalled();
    expect(apiMock.post).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('region', { name: 'Bundle deployment recovery' })).not.toBeInTheDocument();
    confirm.mockRestore();
});

it('ignores an obsolete Bundle inspection rejection after the route changes', async () => {
    const inspection = deferred();
    apiMock.get.mockImplementation((path) => String(path).includes('/bundle-deployments/')
        ? inspection.promise
        : Promise.resolve({ data: { data: bundleDetail(String(path).endsWith(UUID_A) ? 'Bundle A' : 'Bundle B') } }));
    apiMock.post.mockResolvedValue({ data: { data: { deployment_uuid: 'inspect-plan-a', status: 'planned', items: [] } } });
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    fireEvent.click(await screen.findByRole('button', { name: 'Deploy Bundle', exact: true }));
    expect(await screen.findByRole('region', { name: 'Bundle deployment recovery' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Inspect this plan' }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith('/v1/artifact-library/bundle-deployments/inspect-plan-a'));
    fireEvent.click(screen.getByRole('button', { name: 'Route B' }));
    expect(await screen.findByRole('heading', { name: 'Bundle B' })).toBeVisible();
    await act(async () => inspection.reject(new Error('obsolete inspection failure')));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Bundle deployment recovery' })).not.toBeInTheDocument();
    confirm.mockRestore();
});

it('does not let an obsolete plan completion release a newer route operation busy state', async () => {
    const oldPlan = deferred();
    const currentPlan = deferred();
    apiMock.get.mockImplementation((path) => Promise.resolve({ data: { data: bundleDetail(String(path).endsWith(UUID_A) ? 'Bundle A' : 'Bundle B') } }));
    apiMock.post.mockImplementation((path) => String(path).includes('Bundle A-v1') ? oldPlan.promise : currentPlan.promise);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<MemoryRouter initialEntries={[`/artifact-library/${UUID_A}`]}><RouteHarness /></MemoryRouter>);
    fireEvent.click(await screen.findByRole('button', { name: 'Deploy Bundle', exact: true }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Route B' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Deploy Bundle', exact: true }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(2));
    await act(async () => oldPlan.resolve({ data: { data: { deployment_uuid: 'old-plan', status: 'planned', items: [] } } }));
    expect(screen.getByRole('button', { name: 'Deploy Bundle', exact: true })).toBeDisabled();
    await act(async () => currentPlan.resolve({ data: { data: { deployment_uuid: 'current-plan', status: 'planned', items: [] } } }));
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(apiMock.post).toHaveBeenCalledTimes(2);
    confirm.mockRestore();
});
