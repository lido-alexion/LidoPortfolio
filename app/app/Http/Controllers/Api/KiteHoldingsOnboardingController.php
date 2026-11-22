<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\Broker\BrokerGateway;
use App\Services\StockResolverService;
use App\Services\TransactionWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class KiteHoldingsOnboardingController extends Controller
{
    public function __construct(private BrokerGateway $broker, private StockResolverService $stocks, private TransactionWriteService $writes) {}

    public function show(): JsonResponse
    {
        $profile = \activePortfolio();
        $draft = DB::table('kite_onboarding_drafts')->where('profile_id', $profile->id)->first();
        return response()->json(['data' => $draft ? $this->payload($draft->id) : null]);
    }

    public function start(): JsonResponse
    {
        $profile = \activePortfolio();
        $snapshot = $this->broker->portfolioSnapshot((int) auth()->id());
        if (! $snapshot) return response()->json(['message' => 'Kite holdings could not be fetched. Reconnect Kite and retry.'], 422);
        $rows = collect($snapshot['holdings'] ?? [])->filter(fn ($r) => (float) ($r['quantity'] ?? 0) > 0)->values();
        $draft = DB::transaction(function () use ($profile, $snapshot, $rows) {
            $id = DB::table('kite_onboarding_drafts')->where('profile_id', $profile->id)->value('id');
            if (! $id) $id = DB::table('kite_onboarding_drafts')->insertGetId(['profile_id'=>$profile->id,'status'=>'draft','kite_snapshot'=>json_encode($snapshot),'snapshot_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            foreach ($rows as $row) {
                $stock = $this->stocks->resolveFromAttributes(['symbol'=>$row['symbol'] ?? '', 'exchange'=>$row['exchange'] ?? 'NSE']);
                $has = DB::table('kite_onboarding_stocks')->where('draft_id',$id)->where('stock_id',$stock->id)->exists();
                if ($has) continue;
                $qty=(float)$row['quantity']; $price=(float)($row['average_price'] ?? 0);
                DB::table('kite_onboarding_stocks')->insert(['draft_id'=>$id,'stock_id'=>$stock->id,'symbol'=>$stock->symbol,'exchange'=>$row['exchange'] ?? 'NSE','kite_quantity'=>$qty,'kite_average_price'=>$price,'entries'=>json_encode([['type'=>'buy','quantity'=>$qty,'price'=>$price,'date'=>today()->toDateString(),'date_placeholder'=>true,'provenance'=>'kite_opening_position']]),'status'=>'needs_review','created_at'=>now(),'updated_at'=>now()]);
            }
            return (int)$id;
        });
        return response()->json(['data'=>$this->payload($draft)],201);
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $data=$request->validate(['entries'=>'required|array|min:1|max:500','entries.*.type'=>'required|in:buy,sell','entries.*.quantity'=>'required|numeric|gt:0','entries.*.price'=>'required|numeric|gte:0','entries.*.date'=>'required|date_format:Y-m-d','entries.*.date_placeholder'=>'sometimes|boolean','entries.*.broker_trade_id'=>'nullable|string|max:100','entries.*.provenance'=>'nullable|string|max:80']);
        $row=DB::table('kite_onboarding_stocks')->join('kite_onboarding_drafts','kite_onboarding_drafts.id','=','kite_onboarding_stocks.draft_id')->where('kite_onboarding_stocks.id',$item)->where('profile_id',\activePortfolio()->id)->select('kite_onboarding_stocks.*')->first();
        abort_unless($row,404);
        DB::table('kite_onboarding_stocks')->where('id',$item)->update(['entries'=>json_encode($data['entries']),'status'=>'needs_review','audit'=>json_encode(array_merge(json_decode($row->audit ?: '[]',true),[['event'=>'edited','at'=>now()->toISOString(),'by'=>auth()->id()]])),'updated_at'=>now()]);
        return response()->json(['data'=>$this->payload($row->draft_id)]);
    }

    public function verify(int $item): JsonResponse
    {
        $row=DB::table('kite_onboarding_stocks')->join('kite_onboarding_drafts','kite_onboarding_drafts.id','=','kite_onboarding_stocks.draft_id')->where('kite_onboarding_stocks.id',$item)->where('profile_id',\activePortfolio()->id)->select('kite_onboarding_stocks.*')->first(); abort_unless($row,404);
        $entries=json_decode($row->entries,true); $net=collect($entries)->sum(fn($e)=>(($e['type']??'buy')==='buy'?1:-1)*(float)$e['quantity']);
        if (abs($net-(float)$row->kite_quantity) > 0.0001) throw ValidationException::withMessages(['entries'=>['Net staged quantity must match Kite. Review uncovered sells or corporate actions.']]);
        usort($entries, fn($a,$b)=>strcmp($a['date'],$b['date'])); $lots=[];
        foreach($entries as $entry){ $quantity=(float)$entry['quantity']; if($entry['type']==='buy'){ $lots[]=['qty'=>$quantity,'price'=>(float)$entry['price']]; continue; } foreach($lots as &$lot){ $used=min($quantity,$lot['qty']); $lot['qty']-=$used; $quantity-=$used; if($quantity<=0.0001) break; } unset($lot); if($quantity>0.0001) throw ValidationException::withMessages(['entries'=>['A sell is not covered by staged buys. Add the missing older purchase or review this tradebook window.']]); }
        $stagedBasis=array_sum(array_map(fn($lot)=>$lot['qty']*$lot['price'],$lots)); $kiteBasis=(float)$row->kite_quantity*(float)$row->kite_average_price;
        if(abs($stagedBasis-$kiteBasis)>max(1.0,$kiteBasis*0.01)) throw ValidationException::withMessages(['entries'=>['Staged remaining cost basis differs from Kite by more than 1%. Review missing trades, corporate actions, or prices before verification.']]);
        DB::table('kite_onboarding_stocks')->where('id',$item)->update(['status'=>'verified','audit'=>json_encode(array_merge(json_decode($row->audit ?: '[]',true),[['event'=>'verified','at'=>now()->toISOString(),'by'=>auth()->id()]])),'updated_at'=>now()]);
        return response()->json(['data'=>$this->payload($row->draft_id)]);
    }

    public function importTradebook(Request $request): JsonResponse
    {
        $file = $request->validate(['tradebook' => ['required', 'file', 'max:10240', 'mimes:csv,txt']])['tradebook'];
        $draft = DB::table('kite_onboarding_drafts')->where('profile_id', \activePortfolio()->id)->first();
        abort_unless($draft, 409, 'Fetch Kite holdings before importing a tradebook.');
        $handle = fopen($file->getRealPath(), 'rb');
        if (! $handle) throw ValidationException::withMessages(['tradebook' => ['The uploaded file could not be read.']]);
        $header = fgetcsv($handle);
        if (! is_array($header)) throw ValidationException::withMessages(['tradebook' => ['The CSV is empty.']]);
        $header = array_map(fn ($v) => strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string) $v))), $header);
        $pick = function (array $row, array $aliases) use ($header) { foreach ($aliases as $alias) { $i = array_search($alias, $header, true); if ($i !== false && isset($row[$i])) return trim((string) $row[$i]); } return ''; };
        $bySymbol = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if (count(array_filter($cells, fn ($v) => trim((string)$v) !== '')) === 0) continue;
            $symbol = strtoupper($pick($cells, ['symbol','tradingsymbol','trading_symbol']));
            $side = strtoupper($pick($cells, ['trade_type','transaction_type','type','buy_sell']));
            $qty = $pick($cells, ['quantity','qty']); $price = $pick($cells, ['price','average_price','trade_price']);
            $date = $pick($cells, ['trade_date','date','order_execution_time']);
            if ($symbol === '' || ! in_array($side, ['BUY','SELL'], true) || ! is_numeric($qty) || (float)$qty <= 0 || ! is_numeric($price) || ! strtotime($date)) continue;
            $tradeId = $pick($cells, ['trade_id','broker_trade_id','exchange_trade_id']);
            $bySymbol[$symbol][] = ['type'=>strtolower($side),'quantity'=>(float)$qty,'price'=>(float)$price,'date'=>date('Y-m-d',strtotime($date)),'date_placeholder'=>false,'provenance'=>'console_tradebook','broker_trade_id'=>$tradeId ?: null];
        }
        fclose($handle);
        $merged = 0;
        foreach ($bySymbol as $symbol => $newEntries) {
            $staged = DB::table('kite_onboarding_stocks')->where('draft_id',$draft->id)->whereRaw('UPPER(symbol) = ?',[$symbol])->first();
            if (! $staged) continue;
            $old = json_decode($staged->entries, true) ?: [];
            $ids = array_fill_keys(array_filter(array_column($old,'broker_trade_id')), true);
            $kept = array_values(array_filter($old, fn($e)=>($e['provenance']??'')!=='kite_opening_position'));
            foreach ($newEntries as $e) { if (!empty($e['broker_trade_id']) && isset($ids[$e['broker_trade_id']])) continue; $kept[]=$e; if(!empty($e['broker_trade_id'])) $ids[$e['broker_trade_id']]=true; }
            DB::table('kite_onboarding_stocks')->where('id',$staged->id)->update(['entries'=>json_encode($kept),'status'=>'needs_review','updated_at'=>now()]); $merged++;
        }
        return response()->json(['data'=>$this->payload($draft->id),'merged_stocks'=>$merged,'note'=>'Only rows matching currently held Kite stocks were considered. Review all quantities, cost basis, dates and uncovered sells.']);
    }

    public function commit(Request $request): JsonResponse
    {
        $ids=$request->validate(['stock_ids'=>'required|array|min:1','stock_ids.*'=>'integer'])['stock_ids']; $profile=\activePortfolio();
        $draft=DB::table('kite_onboarding_drafts')->where('profile_id',$profile->id)->firstOrFail();
        $fresh=$this->broker->portfolioSnapshot((int)auth()->id()); if(!$fresh) return response()->json(['message'=>'Fresh Kite recheck failed; nothing was committed.'],409);
        return DB::transaction(function() use($ids,$profile,$draft,$fresh){
            $rows=DB::table('kite_onboarding_stocks')->where('draft_id',$draft->id)->whereIn('id',$ids)->lockForUpdate()->get();
            if($rows->count()!==count(array_unique($ids)) || $rows->contains(fn($r)=>$r->status!=='verified')) return response()->json(['message'=>'Only verified staged stocks can be committed.'],409);
            foreach($rows as $row){ $match=collect($fresh['holdings']??[])->first(fn($h)=>strtoupper($h['symbol']??'')===strtoupper($row->symbol)); if(!$match || abs((float)$match['quantity']-(float)$row->kite_quantity)>0.0001 || abs((float)($match['average_price']??0)-(float)$row->kite_average_price)>0.01) return response()->json(['message'=>'Kite quantity or average cost changed since review. Refresh the draft and review again.'],409); $existing=\App\Models\Transaction::where('profile_id',$profile->id)->where('stock_id',$row->stock_id)->exists(); if($existing) return response()->json(['message'=>$row->symbol.' already has StoX transaction history. Reconcile it before onboarding; no existing holdings were changed.'],409); }
            foreach($rows as $row){ $stock=Stock::findOrFail($row->stock_id); foreach(json_decode($row->entries,true) as $entry){ $existing=isset($entry['broker_trade_id'])?\App\Models\Transaction::where('profile_id',$profile->id)->where('notes','like','%broker_trade_id:'.$entry['broker_trade_id'].'%')->exists():false; if($existing) continue; $this->writes->createFinancialUnit($profile,$stock,['type'=>$entry['type'],'quantity'=>$entry['quantity'],'price'=>$entry['price'],'fees'=>0,'transaction_date'=>$entry['date'],'notes'=>'Kite holdings onboarding; '.(($entry['date_placeholder']??false)?'date is an editable placeholder, not a verified acquisition date; ':'').(isset($entry['broker_trade_id'])?'broker_trade_id:'.$entry['broker_trade_id']:'') ,'source'=>'other'],auth()->user(),false); }
                DB::table('kite_onboarding_stocks')->where('id',$row->id)->update(['status'=>'committed','audit'=>json_encode(array_merge(json_decode($row->audit ?: '[]',true),[['event'=>'committed','at'=>now()->toISOString(),'by'=>auth()->id()]])),'updated_at'=>now()]); }
            return response()->json(['data'=>$this->payload($draft->id)]);
        });
    }

    private function payload(int $draftId): array
    { $draft=DB::table('kite_onboarding_drafts')->find($draftId); $rows=DB::table('kite_onboarding_stocks')->where('draft_id',$draftId)->orderBy('symbol')->get()->map(fn($r)=>['id'=>$r->id,'stock_id'=>$r->stock_id,'symbol'=>$r->symbol,'exchange'=>$r->exchange,'kite_quantity'=>(float)$r->kite_quantity,'kite_average_price'=>(float)$r->kite_average_price,'entries'=>json_decode($r->entries,true),'status'=>$r->status,'audit'=>json_decode($r->audit ?: '[]',true)]); return ['id'=>$draft->id,'snapshot_at'=>$draft->snapshot_at,'stocks'=>$rows]; }
}
