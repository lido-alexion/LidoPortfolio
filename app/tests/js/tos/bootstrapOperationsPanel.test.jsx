import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock('../../../resources/js/src/api', () => ({ default: api }));
import BootstrapOperationsPanel from '../../../resources/js/src/pages/BootstrapOperationsPanel';

const response = (data) => ({ data: { data } });
const setupReads = () => api.get.mockImplementation((path) => {
    if (path === '/stocks/search') return Promise.resolve(response([{ id: 17, symbol: 'AAA', name: 'Alpha Ltd', exchange: 'NSE' }]));
    if (path.endsWith('/health')) return Promise.resolve(response({ active_run: null, queued_count: 0, unresolved_failed_items: 0, unresolved_warnings: 0 }));
    if (path.endsWith('/coverage')) return Promise.resolve(response({ metrics: [] }));
    if (path.endsWith('/runs')) return Promise.resolve(response({ data: [], current_page: 1, last_page: 1, total: 0 }));
    if (path.endsWith('/gaps') || path.endsWith('/warnings') || path.endsWith('/presets') || path.endsWith('/schedules')) return Promise.resolve(response({ data: [], current_page: 1, last_page: 1, total: 0 }));
    return Promise.resolve(response({}));
});

describe('BootstrapOperationsPanel', () => {
    beforeEach(() => { cleanup(); sessionStorage.clear(); vi.clearAllMocks(); setupReads(); api.post.mockResolvedValue(response({ id: 42 })); api.put.mockResolvedValue(response({})); api.delete.mockResolvedValue(response({})); });

    it('is mount-safe for the existing fundamentals page and presents keyboard navigable sections', async () => {
        const { container } = render(<BootstrapOperationsPanel />);
        expect(container.querySelector('#bootstrap-operations')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Historical fundamentals' })).toBeTruthy();
        expect(screen.getByRole('tab', { name: 'Backfills and history' }).getAttribute('aria-selected')).toBe('true');
        fireEvent.keyDown(screen.getByRole('tab', { name: 'Backfills and history' }), { key: 'ArrowRight' });
        expect(screen.getByRole('tab', { name: 'Coverage and review' }).getAttribute('aria-selected')).toBe('true');
    });

    it('launches eligible universe with exact route, body, and stable idempotency header', async () => {
        render(<BootstrapOperationsPanel />);
        await waitFor(()=>expect(screen.getByRole('button', { name: 'Start backfill' }).disabled).toBe(false));
        await userEvent.click(screen.getByRole('button', { name: 'Start backfill' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/runs', { scope: 'all' }, expect.objectContaining({ headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }) })));
    });

    it('renders A run detail presenter fields and requests job pages with Laravel page query', async () => {
        api.get.mockImplementation((path,config={})=>{
            if(path.endsWith('/runs'))return Promise.resolve(response({data:[{id:44,status:'completed',scope:'all',counts:{requested:51,queued:0,running:0,completed:51,failed:0},updated_at:'2026-10-22T10:00:00Z'}],current_page:1,last_page:1,total:1}));
            if(path.endsWith('/runs/44'))return Promise.resolve(response({run:{id:44,status:'completed',request:{scope:'stocks',stock_ids:[17,24],forced:true,reason:'Historical correction',origin_run_id:40,schedule:{id:5,generation:2,version:7,preset_id:3,preset_revision:4,scheduled_at:'2026-10-22T10:00:00Z',run_now:true},gaps:{ids:[81,82],metric:'revenue',cadence:'quarterly',execution_scope:'stocks'}},actor:{id:9,name:'Admin'},counts:{completed:51,failed:0}},progress:{completed:51,failed:0,pending:0,current_item:null,last_updated_at:'2026-10-22T10:00:00Z'},jobs:{data:[{id:1,stock:{id:17,symbol:'AAA'},status:'complete_good',quarterly_status:'complete',annual_status:'no_data',coverage:{cadences:{quarterly:{providers:['yahoo'],accepted_rows:4}}},period:{from:'2024-03-31',to:'2026-03-31'},facts:{inserted:4,upgraded:1,deduped:0,rejected:0},updated_at:'2026-10-22T10:00:00Z'}],current_page:config.params?.page||1,last_page:2,per_page:50,from:1,to:1,total:51}}));
            if(path.endsWith('/health'))return Promise.resolve(response({active_run:null,queued_count:0}));
            if(path.endsWith('/coverage'))return Promise.resolve(response({coverage:[]}));
            return Promise.resolve(response({data:[],current_page:1,last_page:1,total:0}));
        });
        render(<BootstrapOperationsPanel/>);
        await userEvent.click(await screen.findByRole('button',{name:'View'}));
        expect(await screen.findByText('AAA')).toBeTruthy();
        expect(screen.getByText(/complete \/ no_data/i)).toBeTruthy();
        expect(screen.getByText(/yahoo/)).toBeTruthy();
        expect(screen.getByText(/Stock IDs 17, 24/)).toBeTruthy();
        expect(screen.getByText(/Historical correction/)).toBeTruthy();
        expect(screen.getByText(/Retry of run #40/)).toBeTruthy();
        expect(screen.getByText(/Schedule #5 · generation 2 · version 7/)).toBeTruthy();
        expect(screen.getByText(/Gap request: 81, 82 · revenue · quarterly · execution scope stocks/)).toBeTruthy();
        await userEvent.click(screen.getByRole('button',{name:'Next'}));
        await waitFor(()=>expect(api.get).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/runs/44',{params:{page:2}}));
    });

    it('keeps A run statuses separate from D CSV job status filters', async () => {
        api.get.mockImplementation((path)=>path.endsWith('/runs')?Promise.resolve(response({data:[{id:44,status:'completed',scope:'all',counts:{completed:3,failed:0}}],current_page:1,last_page:1,total:1})):path.endsWith('/health')?Promise.resolve(response({active_run:null})):path.endsWith('/coverage')?Promise.resolve(response({coverage:[]})):Promise.resolve(response({data:[],current_page:1,last_page:1,total:0})));
        render(<BootstrapOperationsPanel/>);
        await waitFor(()=>expect(screen.getByRole('button',{name:'View'}).disabled).toBe(false));
        const runStatus=screen.getByLabelText('Run status');
        expect(within(runStatus).getByRole('option',{name:'completed'})).toBeTruthy();
        expect(within(runStatus).queryByRole('option',{name:'complete_good'})).toBeNull();
        expect(screen.getByRole('option',{name:'forced rerun'})).toBeTruthy();
        await userEvent.selectOptions(screen.getByLabelText('CSV item status'),'complete_good');
        let link=new URL(screen.getByRole('link',{name:'Export CSV'}).href,window.location.origin);
        expect(link.searchParams.get('status')).toBe('complete_good');
        expect(link.searchParams.has('failed_only')).toBe(false);
        await userEvent.selectOptions(screen.getByLabelText('CSV item status'),'failed');
        link=new URL(screen.getByRole('link',{name:'Export CSV'}).href,window.location.origin);
        expect(link.searchParams.get('status')).toBe('failed');
        expect(link.searchParams.get('failed_only')).toBe('1');
        await userEvent.click(screen.getByRole('button',{name:'Reconcile #44'}));
        await waitFor(()=>expect(api.post).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/reconcile',{run_id:44},expect.objectContaining({headers:expect.objectContaining({'Idempotency-Key':expect.any(String)})})));
    });

    it('selects a stock from the existing searchable picker and submits its stable stock ID', async () => {
        const user = userEvent.setup({ delay: null });
        render(<BootstrapOperationsPanel />);
        await user.selectOptions(await screen.findByLabelText('Stocks to include'), 'stocks');
        await waitFor(()=>expect(screen.getByLabelText('Add an eligible stock').disabled).toBe(false));
        await user.type(screen.getByLabelText('Add an eligible stock'), 'aa');
        await user.click(await screen.findByRole('button', { name: /AAA/i }));
        await user.click(screen.getByRole('button', { name: 'Start backfill' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/runs', { scope: 'stocks', stock_ids: [17] }, expect.objectContaining({headers:expect.objectContaining({'Idempotency-Key':expect.any(String)})})));
    });

    it('does not report a 409 launch as success and reuses its key only after a fresh server read', async () => {
        api.post.mockRejectedValueOnce({ response: { status: 409, data: { message: 'busy' } } }).mockResolvedValueOnce(response({ id: 42 }));
        render(<BootstrapOperationsPanel />);
        await waitFor(()=>expect(screen.getByRole('button', { name: 'Start backfill' }).disabled).toBe(false));
        const start = screen.getByRole('button', { name: 'Start backfill' });
        await userEvent.click(start);
        await screen.findByText(/rejected the action with a conflict/i);
        await userEvent.click(screen.getByRole('button', { name: 'Start backfill' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
        expect(api.post.mock.calls[0][2].headers['Idempotency-Key']).toBe(api.post.mock.calls[1][2].headers['Idempotency-Key']);
        expect(api.get.mock.invocationCallOrder.some((order)=>order>api.post.mock.invocationCallOrder[0]&&order<api.post.mock.invocationCallOrder[1])).toBe(true);
    });

    it('retries failed stock items through A as a new keyed run request', async () => {
        api.get.mockImplementation((path)=>path.endsWith('/runs')?Promise.resolve(response({data:[{id:73,status:'failed',scope:'stocks',counts:{failed:2}}],current_page:1,last_page:1,total:1})):path.endsWith('/health')?Promise.resolve(response({active_run:null})):path.endsWith('/coverage')?Promise.resolve(response({coverage:[]})):Promise.resolve(response({data:[],current_page:1,last_page:1,total:0})));
        render(<BootstrapOperationsPanel/>);
        await waitFor(()=>expect(screen.getByRole('button',{name:'Retry failed'}).disabled).toBe(false));
        await userEvent.click(screen.getByRole('button',{name:'Retry failed'}));
        await waitFor(()=>expect(api.post).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/runs/73/retry-failed',{},expect.objectContaining({headers:expect.objectContaining({'Idempotency-Key':expect.any(String)})})));
    });

    it('blocks a repeated write until a failed reconciliation refresh succeeds', async () => {
        render(<BootstrapOperationsPanel/>);
        await waitFor(()=>expect(screen.getByRole('button',{name:'Start backfill'}).disabled).toBe(false));
        api.get.mockRejectedValue({message:'offline'});
        api.post.mockRejectedValue({message:'timeout'});
        await userEvent.click(screen.getByRole('button',{name:'Start backfill'}));
        await screen.findByText(/current state could not be refreshed/i);
        expect(screen.getByRole('button',{name:'Start backfill'}).disabled).toBe(true);
        expect(api.post).toHaveBeenCalledTimes(1);
    });

    it('allows force only for selected eligible stocks and clears force when scope returns to all', async () => {
        const user=userEvent.setup({delay:null});
        render(<BootstrapOperationsPanel />);
        await waitFor(()=>expect(screen.getByRole('button', { name: 'Start backfill' }).disabled).toBe(false));
        const forceCheckbox=screen.getByRole('checkbox',{name:/force rerun/i});
        expect(forceCheckbox.disabled).toBe(true);
        await user.selectOptions(screen.getByLabelText('Stocks to include'),'stocks');
        await waitFor(()=>expect(screen.getByLabelText('Add an eligible stock').disabled).toBe(false));
        await user.type(screen.getByLabelText('Add an eligible stock'),'aa');
        await user.click(await screen.findByRole('button',{name:/AAA/i}));
        await user.click(forceCheckbox);
        await user.type(screen.getByLabelText('Why is this rerun needed?'), 'Corrected source mapping');
        expect(screen.getByText(/quality rules still apply/i)).toBeTruthy();
        await user.click(screen.getByRole('button', { name: 'Start backfill' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/runs', { scope: 'stocks', stock_ids: [17], force: true, reason: 'Corrected source mapping' }, expect.objectContaining({headers:expect.objectContaining({'Idempotency-Key':expect.any(String)})})));
        await user.selectOptions(screen.getByLabelText('Stocks to include'),'all');
        expect(screen.queryByLabelText('Why is this rerun needed?')).toBeNull();
        expect(forceCheckbox.checked).toBe(false);
        await user.click(screen.getByRole('button', { name: 'Start backfill' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/runs', { scope: 'all' }, expect.anything()));
    });

    it('exposes an accessible destructive cancel confirmation only after selecting cancel', async () => {
        setupReads();
        api.get.mockImplementation((path) => path.endsWith('/runs') ? Promise.resolve(response({ data: [{ id: 8, status: 'running', scope: 'all', counts: { failed: 0 } }], current_page: 1, last_page: 1, total: 1 })) : path.endsWith('/health') ? Promise.resolve(response({ active_run: { id: 8 }, queued_count: 0 })) : path.endsWith('/coverage') ? Promise.resolve(response({ metrics: [] })) : Promise.resolve(response({ data: [], current_page: 1, last_page: 1, total: 0 })));
        render(<BootstrapOperationsPanel />);
        await waitFor(()=>expect(screen.getByRole('button', { name: 'Cancel' }).disabled).toBe(false));
        const cancelButton=screen.getByRole('button', { name: 'Cancel' });
        const user=userEvent.setup();
        await user.click(cancelButton);
        expect(screen.getByRole('alertdialog', { name: 'Cancel run #8?' })).toBeTruthy();
        expect(screen.getByText(/cannot resume/i)).toBeTruthy();
        expect(document.activeElement).toBe(screen.getByLabelText('Reason'));
        await user.keyboard('{Shift>}{Tab}{/Shift}');
        expect(document.activeElement).toBe(screen.getByRole('button',{name:'Keep running'}));
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(document.activeElement).toBe(cancelButton);
    });

    it('reports a busy cancellation as unresolved rather than success', async () => {
        api.get.mockImplementation((path) => path.endsWith('/runs') ? Promise.resolve(response({ data: [{ id: 8, status: 'running', scope: 'all', counts: { failed: 0 } }], current_page: 1, last_page: 1, total: 1 })) : path.endsWith('/health') ? Promise.resolve(response({ active_run: { id: 8 }, queued_count: 0 })) : path.endsWith('/coverage') ? Promise.resolve(response({ metrics: [] })) : Promise.resolve(response({ data: [], current_page: 1, last_page: 1, total: 0 })));
        api.post.mockRejectedValue({ response: { status: 409, data: { error: { message: 'Another operation is active.' } } } });
        render(<BootstrapOperationsPanel />);
        await waitFor(()=>expect(screen.getByRole('button', { name: 'Cancel' }).disabled).toBe(false));
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await userEvent.type(screen.getByLabelText('Reason'), 'Stop the scheduled run');
        await userEvent.click(screen.getByRole('button', { name: 'Confirm cancellation' }));
        await screen.findByText(/rejected the action with a conflict/i);
        expect(screen.queryByText(/cancelled successfully/i)).toBeNull();
    });

    it('keeps coverage gaps distinct from quality warnings and explains stock-level targeting', async () => {
        render(<BootstrapOperationsPanel />);
        await userEvent.click(await screen.findByRole('tab', { name: 'Coverage and review' }));
        expect(screen.getByText(/not automatically an ingestion failure/i)).toBeTruthy();
        expect(screen.getByText(/selected metric, cadence, and period do not restrict/i)).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Data-quality warnings' })).toBeTruthy();
    });

    it('keeps run-now action separate from recurrence controls', async () => {
        api.get.mockImplementation((path) => path.endsWith('/schedules') ? Promise.resolve(response({ data: [{ id: 6, name: 'Daily', frequency: 'daily', local_time: '09:00', timezone: 'Asia/Kolkata', enabled: true, version: 5, configuration: { scope: 'all' }, next_occurrence_at: '2026-10-23T03:30:00Z' }], current_page: 1, last_page: 1, total: 1 })) : path.endsWith('/health') ? Promise.resolve(response({ active_run: null })) : path.endsWith('/coverage') ? Promise.resolve(response({ metrics: [] })) : Promise.resolve(response({ data: [], current_page: 1, last_page: 1, total: 0 })));
        render(<BootstrapOperationsPanel />);
        await userEvent.click(await screen.findByRole('tab', { name: 'Presets and schedules' }));
        expect(screen.getByText(/Run now creates one run without advancing or changing/i)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Create disabled schedule' })).toBeTruthy();
        api.post.mockRejectedValueOnce({ response: { status: 409 } }).mockResolvedValueOnce(response({ run_id: 61, created: true }));
        await waitFor(()=>expect(screen.getByRole('button', { name: 'Run now' }).disabled).toBe(false));
        await userEvent.click(screen.getByRole('button', { name: 'Run now' }));
        await screen.findByText(/rejected the action with a conflict/i);
        await userEvent.click(screen.getByRole('button', { name: 'Run now' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/schedules/6/run-now', {}, expect.objectContaining({ headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }) })));
        expect(api.post.mock.calls[0][2].headers['Idempotency-Key']).toBe(api.post.mock.calls[1][2].headers['Idempotency-Key']);
        expect(api.put).not.toHaveBeenCalled();
        expect(await screen.findByText('Run #61 was queued.')).toBeTruthy();
    });

    it('paginates C preset and occurrence presenters with their actual query names', async () => {
        api.get.mockImplementation((path,config={})=>{
            if(path.endsWith('/presets'))return Promise.resolve(response({data:[{id:2,name:'Quarterly',revision:3,configuration:{scope:'all'},schedules_count:0}],current_page:config.params?.page||1,last_page:2,per_page:25,total:26}));
            if(path.endsWith('/schedules'))return Promise.resolve(response({data:[{id:5,name:'Daily',frequency:'daily',local_time:'09:00',timezone:'Asia/Kolkata',enabled:true,version:4,configuration:{scope:'all'},next_occurrence_at:'2026-10-23T03:30:00Z'}],current_page:1,last_page:1,per_page:25,total:1}));
            if(path.endsWith('/occurrences'))return Promise.resolve(response({data:[{id:11,status:'cannot_start',scheduled_at_local:'2026-10-23 09:00:00',timezone:'Asia/Kolkata',failure_code:'eligible_stocks_unavailable',failed_at:'2026-10-23T03:30:00Z'},{id:12,status:'pending',scheduled_at_local:'2026-10-24 09:00:00',timezone:'Asia/Kolkata',delivery_error:true}],current_page:config.params?.page||1,last_page:2,per_page:25,total:26}));
            if(path.endsWith('/health'))return Promise.resolve(response({active_run:null}));if(path.endsWith('/coverage'))return Promise.resolve(response({coverage:[]}));return Promise.resolve(response({data:[],current_page:1,last_page:1,total:0}));
        });
        render(<BootstrapOperationsPanel/>);await userEvent.click(await screen.findByRole('tab',{name:'Presets and schedules'}));
        const nextButtons=await screen.findAllByRole('button',{name:'Next'});await userEvent.click(nextButtons[0]);
        await waitFor(()=>expect(api.get).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/presets',{params:{page:2,per_page:25}}));
        await userEvent.click(await screen.findByRole('button',{name:'History'}));
        expect(await screen.findByText('Could not start: eligible_stocks_unavailable')).toBeTruthy();
        expect(screen.getByText(/Delivery attempt failed; retry remains pending/)).toBeTruthy();
        expect(screen.getByText(/Failed /)).toBeTruthy();
        const history=screen.getByRole('heading',{name:'Daily schedule history'}).closest('.bo-card');
        await userEvent.click(within(history).getByRole('button',{name:'Next'}));
        await waitFor(()=>expect(api.get).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/schedules/5/occurrences',{params:{page:2,per_page:25}}));
    });

    it('sends daily, weekly, and monthly recurrence bodies as local wall times', async () => {
        const user=userEvent.setup();
        render(<BootstrapOperationsPanel/>);
        await user.click(await screen.findByRole('tab',{name:'Presets and schedules'}));
        const save=screen.getByRole('button',{name:'Create disabled schedule'});
        expect(save.disabled).toBe(true);
        const timezone=screen.getByLabelText('Timezone').value;
        await user.type(screen.getByLabelText('Schedule name'),'Daily local');
        fireEvent.change(screen.getByLabelText('Starts (local date and time)'),{target:{value:'2030-05-07T08:15'}});
        fireEvent.change(screen.getByLabelText('Optional end date'),{target:{value:'2031-05-07'}});
        await user.type(screen.getByLabelText('Optional max occurrences'),'4');
        await waitFor(()=>expect(save.disabled).toBe(false));
        await user.click(save);
        await waitFor(()=>expect(api.post).toHaveBeenNthCalledWith(1,'/v1/admin/fundamentals/bootstrap-operations/schedules',{
            name:'Daily local',configuration:{scope:'all'},frequency:'daily',interval:1,timezone,local_time:'09:00',starts_at_local:'2030-05-07 08:15',ends_on_local:'2031-05-07',max_occurrences:4,
        },undefined));
        await waitFor(()=>expect(save.disabled).toBe(false));
        await user.clear(screen.getByLabelText('Schedule name'));await user.type(screen.getByLabelText('Schedule name'),'Weekly local');
        await user.selectOptions(screen.getByLabelText('Repeat'),'weekly');
        await user.clear(screen.getByLabelText('Weekday (ISO 1–7)'));await user.type(screen.getByLabelText('Weekday (ISO 1–7)'),'2');
        fireEvent.change(screen.getByLabelText('Optional end date'),{target:{value:''}});fireEvent.change(screen.getByLabelText('Optional max occurrences'),{target:{value:''}});
        await user.click(save);
        await waitFor(()=>expect(api.post).toHaveBeenNthCalledWith(2,'/v1/admin/fundamentals/bootstrap-operations/schedules',{
            name:'Weekly local',configuration:{scope:'all'},frequency:'weekly',interval:1,weekday:2,timezone,local_time:'09:00',starts_at_local:'2030-05-07 08:15',
        },undefined));
        await waitFor(()=>expect(save.disabled).toBe(false));
        await user.clear(screen.getByLabelText('Schedule name'));await user.type(screen.getByLabelText('Schedule name'),'Monthly local');
        await user.selectOptions(screen.getByLabelText('Repeat'),'monthly');
        await user.clear(screen.getByLabelText('Day of month'));await user.type(screen.getByLabelText('Day of month'),'31');
        await user.click(save);
        await waitFor(()=>expect(api.post).toHaveBeenNthCalledWith(3,'/v1/admin/fundamentals/bootstrap-operations/schedules',{
            name:'Monthly local',configuration:{scope:'all'},frequency:'monthly',interval:1,monthday:31,timezone,local_time:'09:00',starts_at_local:'2030-05-07 08:15',
        },undefined));
    });

    it('preserves forced preset and schedule snapshots while editing future recurrence only', async () => {
        const config={scope:'stocks',stock_ids:[17,24],force:true,reason:'Corrected source mapping'};
        const schedule={id:5,name:'Protected weekly',frequency:'weekly',interval:2,weekday:2,local_time:'09:00:30',timezone:'Asia/Kolkata',starts_at_local:'2035-06-02 08:10:15',ends_on_local:'2036-06-02',max_occurrences:8,enabled:true,version:6,configuration:config,next_occurrence_at:'2035-06-02T02:40:15Z'};
        api.get.mockImplementation((path)=>path.endsWith('/presets')?Promise.resolve(response({data:[{id:2,name:'Protected preset',revision:7,configuration:config,schedules_count:1}],current_page:1,last_page:1,total:1})):path.endsWith('/schedules')?Promise.resolve(response({data:[schedule],current_page:1,last_page:1,total:1})):path.endsWith('/health')?Promise.resolve(response({active_run:null})):path.endsWith('/coverage')?Promise.resolve(response({coverage:[]})):Promise.resolve(response({data:[],current_page:1,last_page:1,total:0})));
        render(<BootstrapOperationsPanel/>);
        await userEvent.click(await screen.findByRole('tab',{name:'Presets and schedules'}));
        await userEvent.click(await screen.findByRole('button',{name:'Edit'}));
        const presetCard=screen.getByRole('heading',{name:'Edit shared preset'}).closest('.bo-card');
        expect(within(presetCard).getByRole('checkbox',{name:'Force rerun successful selected stocks'}).checked).toBe(true);
        expect(within(presetCard).getByLabelText('Reason for forced rerun').value).toBe(config.reason);
        expect(screen.getByText(/Existing schedules retain their saved revision/)).toBeTruthy();
        await userEvent.click(screen.getByRole('button',{name:'Save preset changes'}));
        await waitFor(()=>expect(api.put).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/presets/2',{name:'Protected preset',configuration:config},undefined));
        await userEvent.selectOptions(screen.getByLabelText('Configuration'),'2');
        expect(screen.getByText(/selected stocks 17, 24 · forced rerun · reason: Corrected source mapping/)).toBeTruthy();
        await userEvent.click(await screen.findByRole('button',{name:'Edit future'}));
        const scheduleCard=screen.getByRole('heading',{name:'Edit future schedule occurrences'}).closest('.bo-card');
        expect(within(scheduleCard).getByRole('checkbox',{name:'Force rerun successful selected stocks'}).checked).toBe(true);
        expect(within(scheduleCard).getByLabelText('Reason for forced rerun').value).toBe(config.reason);
        expect(within(scheduleCard).getByLabelText('Local time').value).toBe('09:00:30');
        expect(within(scheduleCard).getByLabelText('Starts (local date and time)').value).toBe('2035-06-02T08:10:15');
        expect(within(scheduleCard).getByLabelText('Repeat every').value).toBe('2');
        await userEvent.click(screen.getByRole('button',{name:'Save future changes'}));
        await waitFor(()=>expect(api.put).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/schedules/5',{
            name:'Protected weekly',configuration:config,frequency:'weekly',interval:2,weekday:2,timezone:'Asia/Kolkata',local_time:'09:00:30',starts_at_local:'2035-06-02 08:10:15',ends_on_local:'2036-06-02',max_occurrences:8,
        },undefined));
    });

    it('rejects malformed stock ID tokens and clears force details when a preset returns to all stocks', async () => {
        const user=userEvent.setup();
        render(<BootstrapOperationsPanel/>);
        await user.click(await screen.findByRole('tab',{name:'Presets and schedules'}));
        const presetForm=within(screen.getByRole('heading',{name:'Create a shared preset'}).closest('.bo-card'));
        await user.type(presetForm.getByLabelText('Preset name'),'Explicit target');
        await user.selectOptions(presetForm.getByLabelText('Target'),'stocks');
        await user.type(presetForm.getByLabelText('Eligible stock IDs'),'17, nope');
        expect(screen.getByRole('button',{name:'Save preset'}).disabled).toBe(true);
        expect(screen.getByText(/invalid entries are not ignored/)).toBeTruthy();
        await user.clear(presetForm.getByLabelText('Eligible stock IDs'));
        await user.type(presetForm.getByLabelText('Eligible stock IDs'),'17, 24');
        await user.click(screen.getByRole('checkbox',{name:'Force rerun successful selected stocks'}));
        await user.type(presetForm.getByLabelText('Reason for forced rerun'),'Reviewed existing stock history');
        await user.selectOptions(presetForm.getByLabelText('Target'),'all');
        expect(screen.queryByRole('checkbox',{name:'Force rerun successful selected stocks'})).toBeNull();
        expect(screen.queryByLabelText('Reason for forced rerun')).toBeNull();
        await user.click(screen.getByRole('button',{name:'Save preset'}));
        await waitFor(()=>expect(api.post).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/presets',{name:'Explicit target',configuration:{scope:'all'}},undefined));
    });

    it('bulk acknowledges only a compatible metric, cadence, and reason selection', async () => {
        api.get.mockImplementation((path) => path.endsWith('/gaps') ? Promise.resolve(response({ data: [{ id: 21, stock: { symbol: 'AAA' }, metric: 'revenue', cadence: 'quarterly', reason: 'source_unavailable', state: 'actionable' }, { id: 22, stock: { symbol: 'BBB' }, metric: 'revenue', cadence: 'quarterly', reason: 'source_unavailable', state: 'actionable' }], current_page: 1, last_page: 1, total: 2 })) : path.endsWith('/health') ? Promise.resolve(response({ active_run: null })) : path.endsWith('/coverage') ? Promise.resolve(response({ metrics: [] })) : Promise.resolve(response({ data: [], current_page: 1, last_page: 1, total: 0 })));
        render(<BootstrapOperationsPanel />);
        await userEvent.click(await screen.findByRole('tab', { name: 'Coverage and review' }));
        await userEvent.click(await screen.findByRole('checkbox', { name: 'Select gap 21' }));
        await userEvent.click(screen.getByRole('checkbox', { name: 'Select gap 22' }));
        await waitFor(()=>expect(screen.getByRole('button', { name: 'Mark expected missing' }).disabled).toBe(false));
        await userEvent.click(screen.getByRole('button', { name: 'Mark expected missing' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/fundamentals/bootstrap-operations/gaps/acknowledge', { ids: [21, 22] }, expect.objectContaining({ headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }) })));
    });
});
