<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\V8\MlAcceptanceSource;
use App\Models\V8\MlAcceptanceCampaign;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use App\Services\ML\MlAcceptanceSourceService;
use App\Services\ML\MlAcceptanceCampaignService;
use App\Services\ML\MlAcceptanceBackfillService;
use App\Services\ML\MlAcceptanceReportService;
use Illuminate\Http\Request;

class MlAcceptanceController extends Controller
{
    private function response(array $data, int $status = 200) { return response()->json(['data' => app(MlAcceptanceReportService::class)->safe($data)], $status); }
    public function report() { return $this->response(app(MlAcceptanceReportService::class)->report()); }
    public function sources() { return $this->response(MlAcceptanceSource::query()->latest()->paginate(25)->toArray()); }
    public function source(MlAcceptanceSource $source) { return $this->response($source->toArray()); }
    public function createSource(Request $request, MlAcceptanceSourceService $service) { return $this->response($service->create($request->all(), $request->user()->id)->toArray(), 201); }
    public function chunk(Request $request, MlAcceptanceSource $source, MlAcceptanceSourceService $service)
    {
        $data = $request->validate(['offset' => 'required|integer|min:0', 'chunk' => 'required|string|max:1398104']);
        $bytes = base64_decode($data['chunk'], true);
        abort_if($bytes === false, 422, 'Invalid base64 chunk.');
        return $this->response($service->chunk($source, $data['offset'], $bytes, $request->user()->id)->toArray());
    }
    public function sourceAction(Request $request, MlAcceptanceSource $source, string $action, MlAcceptanceSourceService $service)
    {
        return $this->response((match ($action) { 'finalize' => $service->finalize($source, $request->user()->id), 'resume' => $service->resume($source, $request->user()->id), 'cancel' => $service->cancel($source, $request->user()->id) })->toArray());
    }
    public function preview(Request $request, MlAcceptanceBackfillService $service)
    {
        $data = $request->validate(['sources' => 'required|array|min:1|max:5000', 'sources.*' => 'required|uuid|distinct']);
        return $this->response($service->preview($data['sources'], $request->user()->id)->toArray(), 202);
    }
    public function backfill(MlUniverseSnapshotBackfillRun $backfill) { abort_unless(is_array($backfill->acceptance), 404); return $this->response($backfill->toArray()); }
    public function backfillAction(Request $request, MlUniverseSnapshotBackfillRun $backfill, string $action, MlAcceptanceBackfillService $service) { return $this->response($service->action($backfill, $action, $request->user()->id)->toArray()); }
    public function createCampaign(Request $request, MlAcceptanceCampaignService $service)
    {
        $data = $request->validate(['cutoff_date' => 'required|date_format:Y-m-d|before_or_equal:today']);
        return $this->response($service->create($data['cutoff_date'], $request->user()->id)->toArray(), 202);
    }
    public function campaign(MlAcceptanceCampaign $campaign) { return $this->response($campaign->toArray()); }
    public function campaignAction(Request $request, MlAcceptanceCampaign $campaign, string $action, MlAcceptanceCampaignService $service) { return $this->response($service->action($campaign, $action, $request->user()->id)->toArray()); }
}
