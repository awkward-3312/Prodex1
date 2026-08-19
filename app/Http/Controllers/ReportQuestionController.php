<?php

namespace App\Http\Controllers;

use App\Models\ReportQuestion;
use App\Models\Client;
use App\Services\ReportQuestionService;
use App\Services\DateRangeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Warehouse;
use App\Models\UserWarehouse;

class ReportQuestionController extends BaseController
{
    protected $reportService;

    public function __construct(ReportQuestionService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'AI_Reports', Client::class);
        $questions = ReportQuestion::active()->ordered()->get(['id','title','report_key','needs_insights']);
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::whereNull('deleted_at')->get(['id','name']);
        } else {
            $ids = UserWarehouse::where('user_id',$user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::whereNull('deleted_at')->whereIn('id',$ids)->get(['id','name']);
        }
        return response()->json(['questions'=>$questions,'warehouses'=>$warehouses]);
    }

    public function run(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'AI_Reports', Client::class);
        $validator = Validator::make($request->all(), [
            'question_id'=>'required|integer|exists:report_questions,id',
            'warehouse_id'=>'nullable|integer|exists:warehouses,id',
            'date_from'=>'nullable|date',
            'date_to'=>'nullable|date|after_or_equal:date_from',
        ]);
        if ($validator->fails()) return $this->sendError('Error de validación', $validator->errors());

        $question = ReportQuestion::active()->findOrFail($request->question_id);
        $rawFilters = $question->default_filters ?? [];
        $filters = is_array($rawFilters) ? $rawFilters : (is_string($rawFilters) ? (json_decode($rawFilters,true) ?? []) : []);
        if ($request->has('date_from') && $request->has('date_to')) {
            $dateFrom=$request->date_from;$dateTo=$request->date_to;unset($filters['range']);
        } elseif (isset($filters['range'])) {
            $resolved=DateRangeResolver::resolve($filters['range']);$dateFrom=$resolved['date_from'];$dateTo=$resolved['date_to'];
        } else {
            $resolved=DateRangeResolver::resolve('today');$dateFrom=$resolved['date_from'];$dateTo=$resolved['date_to'];
        }
        $warehouseId=$request->warehouse_id;
        $effectiveFilters=array_merge($filters,['date_from'=>$dateFrom,'date_to'=>$dateTo]);
        if($warehouseId)$effectiveFilters['warehouse_id']=$warehouseId;
        $data=null;$compare=null;$insights=null;
        switch($question->report_key){
            case 'daily_sales_summary':
                $data=$this->reportService->dailySalesSummary($dateFrom,$dateTo,$warehouseId);
                if($question->needs_insights&&$question->default_compare){$compareRange=$question->default_compare['range']??'previous_day';$compareDates=DateRangeResolver::resolveCompare($compareRange,$dateFrom,$dateTo);$compare=$this->reportService->dailySalesSummary($compareDates['date_from'],$compareDates['date_to'],$warehouseId);$insights=$this->reportService->generateInsights($data,$compare);}break;
            case 'sales_by_product':$data=$this->reportService->salesByProduct($dateFrom,$dateTo,$warehouseId,$filters);break;
            case 'late_payments':$data=$this->reportService->latePayments($filters);break;
            default:return $this->sendError('Tipo de informe desconocido: '.$question->report_key);
        }
        return response()->json(['question'=>['id'=>$question->id,'title'=>$question->title,'report_key'=>$question->report_key],'filters'=>$effectiveFilters,'data'=>$data,'compare'=>$compare,'insights'=>$insights]);
    }
}
