<?php

namespace App\Http\Controllers;

use App\Models\RecruitJob;
use App\Models\RecruitJobCategory;
use App\Models\RecruitCandidate;
use App\Models\RecruitApplication;
use App\Models\RecruitInterview;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecruitController extends Controller
{
    /**
     * Store an uploaded recruit file under public/images/recruit/<dir>
     * and return its public-relative path. Stocky serves uploads from
     * public/images directly (no storage:link).
     */
    private function storeUpload($file, string $dir): string
    {
        $destination = public_path('images/recruit/' . $dir);
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        $filename = Str::random(20) . '.' . $file->getClientOriginalExtension();
        $file->move($destination, $filename);

        return 'images/recruit/' . $dir . '/' . $filename;
    }

    // ======================== DASHBOARD ========================

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitJob::class);

        $stats = [
            'total_jobs'         => RecruitJob::count(),
            'open_jobs'          => RecruitJob::where('status', 'open')->count(),
            'total_candidates'   => RecruitCandidate::count(),
            'total_applications' => RecruitApplication::count(),
            'pending_interviews' => RecruitInterview::where('status', 'scheduled')->count(),
            'hired_count'        => RecruitApplication::where('stage', 'hired')->count(),
        ];

        $recent_applications = RecruitApplication::with(['candidate', 'job'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $upcoming_interviews = RecruitInterview::with(['application.candidate', 'application.job'])
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get();

        $pipeline = RecruitApplication::select('stage', DB::raw('count(*) as count'))
            ->groupBy('stage')
            ->pluck('count', 'stage')
            ->toArray();

        return response()->json([
            'stats'               => $stats,
            'recent_applications' => $recent_applications,
            'upcoming_interviews' => $upcoming_interviews,
            'pipeline'            => $pipeline,
        ]);
    }

    // ======================== JOB CATEGORIES ========================

    public function categories_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitJobCategory::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $categories = RecruitJobCategory::withCount('jobs')
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $categories->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $categories = $categories->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        return response()->json([
            'categories' => $categories,
            'totalRows'  => $totalRows,
        ]);
    }

    public function categories_all()
    {
        return response()->json(
            RecruitJobCategory::where('is_active', true)->orderBy('name')->get(['id', 'name'])
        );
    }

    public function categories_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitJobCategory::class);

        $request->validate(['name' => 'required|string|max:255']);

        RecruitJobCategory::create([
            'name'        => $request->name,
            'description' => $request->description,
            'is_active'   => $request->filled('is_active') ? $request->is_active : true,
        ]);

        return response()->json(['success' => true]);
    }

    public function categories_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitJobCategory::class);

        $request->validate(['name' => 'required|string|max:255']);

        RecruitJobCategory::whereId($id)->update([
            'name'        => $request->name,
            'description' => $request->description,
            'is_active'   => $request->filled('is_active') ? $request->is_active : true,
        ]);

        return response()->json(['success' => true]);
    }

    public function categories_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitJobCategory::class);

        RecruitJobCategory::whereId($id)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function categories_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitJobCategory::class);

        foreach ($request->selectedIds as $id) {
            RecruitJobCategory::whereId($id)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== JOBS ========================

    public function jobs_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitJob::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $jobs = RecruitJob::with('category')
            ->withCount('applications')
            ->where(function ($query) use ($request) {
                $query->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', $request->status);
                });
                $query->when($request->filled('category_id'), function ($q) use ($request) {
                    $q->where('category_id', $request->category_id);
                });
                $query->when($request->filled('search'), function ($q) use ($request) {
                    $q->where('title', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $jobs->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $jobs = $jobs->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        return response()->json([
            'jobs'      => $jobs,
            'totalRows' => $totalRows,
        ]);
    }

    public function jobs_all()
    {
        return response()->json(
            RecruitJob::orderBy('title')->get(['id', 'title', 'status'])
        );
    }

    public function jobs_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitJob::class);

        $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'job_type'    => 'required|in:full_time,part_time,contract,internship,remote',
        ]);

        $data = $request->all();
        $data['slug'] = Str::slug($request->title) . '-' . Str::random(5);
        $data['created_by'] = $request->user('api') ? $request->user('api')->id : null;

        RecruitJob::create($data);

        return response()->json(['success' => true]);
    }

    public function jobs_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitJob::class);

        $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'job_type'    => 'required|in:full_time,part_time,contract,internship,remote',
        ]);

        $job = RecruitJob::findOrFail($id);
        $data = $request->all();
        if ($request->filled('title') && $request->title !== $job->title) {
            $data['slug'] = Str::slug($request->title) . '-' . Str::random(5);
        }
        $job->update($data);

        return response()->json(['success' => true]);
    }

    public function jobs_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitJob::class);

        RecruitJob::whereId($id)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function jobs_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitJob::class);

        foreach ($request->selectedIds as $id) {
            RecruitJob::whereId($id)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== CANDIDATES ========================

    public function candidates_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitCandidate::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $candidates = RecruitCandidate::withCount('applications')
            ->where(function ($query) use ($request) {
                $query->when($request->filled('source'), function ($q) use ($request) {
                    $q->where('source', $request->source);
                });
                $query->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;
                    $q->where(function ($qq) use ($s) {
                        $qq->where('first_name', 'LIKE', "%$s%")
                            ->orWhere('last_name', 'LIKE', "%$s%")
                            ->orWhere('email', 'LIKE', "%$s%");
                    });
                });
            });

        $totalRows = $candidates->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $candidates = $candidates->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        return response()->json([
            'candidates' => $candidates,
            'totalRows'  => $totalRows,
        ]);
    }

    public function candidates_all()
    {
        $candidates = RecruitCandidate::orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email']);

        return response()->json($candidates);
    }

    public function candidates_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitCandidate::class);

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|unique:recruit_candidates,email',
        ]);

        $data = $request->except(['resume', 'photo']);

        if ($request->hasFile('resume')) {
            $data['resume_path'] = $this->storeUpload($request->file('resume'), 'resumes');
        }
        if ($request->hasFile('photo')) {
            $data['photo'] = $this->storeUpload($request->file('photo'), 'photos');
        }

        RecruitCandidate::create($data);

        return response()->json(['success' => true]);
    }

    public function candidates_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitCandidate::class);

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|unique:recruit_candidates,email,' . $id,
        ]);

        $candidate = RecruitCandidate::findOrFail($id);
        $data = $request->except(['resume', 'photo']);

        if ($request->hasFile('resume')) {
            $data['resume_path'] = $this->storeUpload($request->file('resume'), 'resumes');
        }
        if ($request->hasFile('photo')) {
            $data['photo'] = $this->storeUpload($request->file('photo'), 'photos');
        }

        $candidate->update($data);

        return response()->json(['success' => true]);
    }

    public function candidates_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitCandidate::class);

        RecruitCandidate::whereId($id)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function candidates_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitCandidate::class);

        foreach ($request->selectedIds as $id) {
            RecruitCandidate::whereId($id)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== APPLICATIONS ========================

    public function applications_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitApplication::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $apps = RecruitApplication::with(['job', 'candidate'])
            ->where(function ($query) use ($request) {
                $query->when($request->filled('job_id'), function ($q) use ($request) {
                    $q->where('job_id', $request->job_id);
                });
                $query->when($request->filled('stage'), function ($q) use ($request) {
                    $q->where('stage', $request->stage);
                });
                $query->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;
                    $q->whereHas('candidate', function ($qq) use ($s) {
                        $qq->where('first_name', 'LIKE', "%$s%")
                            ->orWhere('last_name', 'LIKE', "%$s%")
                            ->orWhere('email', 'LIKE', "%$s%");
                    });
                });
            });

        $totalRows = $apps->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $apps = $apps->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        return response()->json([
            'applications' => $apps,
            'totalRows'    => $totalRows,
        ]);
    }

    public function applications_all()
    {
        $apps = RecruitApplication::with(['job:id,title', 'candidate:id,first_name,last_name'])
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($apps);
    }

    public function applications_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitApplication::class);

        $request->validate([
            'job_id'       => 'required|exists:recruit_jobs,id',
            'candidate_id' => 'required|exists:recruit_candidates,id',
        ]);

        $data = $request->all();
        $data['applied_date'] = $request->applied_date ?: now()->toDateString();

        RecruitApplication::create($data);

        return response()->json(['success' => true]);
    }

    public function applications_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitApplication::class);

        $app = RecruitApplication::findOrFail($id);
        $app->update($request->all());

        return response()->json(['success' => true]);
    }

    public function applications_update_stage(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitApplication::class);

        $request->validate(['stage' => 'required|in:applied,screening,shortlisted,interview,offered,hired,rejected']);

        RecruitApplication::whereId($id)->update([
            'stage'       => $request->stage,
            'reviewed_by' => $request->user('api') ? $request->user('api')->id : null,
            'reviewed_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function applications_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitApplication::class);

        RecruitApplication::whereId($id)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function applications_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitApplication::class);

        foreach ($request->selectedIds as $id) {
            RecruitApplication::whereId($id)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== INTERVIEWS ========================

    public function interviews_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitInterview::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $interviews = RecruitInterview::with(['application.candidate', 'application.job'])
            ->where(function ($query) use ($request) {
                $query->when($request->filled('status'), function ($q) use ($request) {
                    $q->where('status', $request->status);
                });
                $query->when($request->filled('application_id'), function ($q) use ($request) {
                    $q->where('application_id', $request->application_id);
                });
            });

        $totalRows = $interviews->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $interviews = $interviews->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        return response()->json([
            'interviews' => $interviews,
            'totalRows'  => $totalRows,
        ]);
    }

    public function interviews_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RecruitInterview::class);

        $request->validate([
            'application_id' => 'required|exists:recruit_applications,id',
            'scheduled_at'   => 'required|date',
            'type'           => 'required|in:phone,video,in_person,technical,panel,group',
        ]);

        RecruitInterview::create($request->all());

        // Auto-advance the application to the interview stage.
        $app = RecruitApplication::find($request->application_id);
        if ($app && in_array($app->stage, ['applied', 'screening', 'shortlisted'])) {
            $app->update(['stage' => 'interview']);
        }

        return response()->json(['success' => true]);
    }

    public function interviews_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RecruitInterview::class);

        $request->validate([
            'scheduled_at' => 'required|date',
            'type'         => 'required|in:phone,video,in_person,technical,panel,group',
        ]);

        RecruitInterview::whereId($id)->update($request->only([
            'application_id', 'type', 'scheduled_at', 'duration_minutes', 'location',
            'meeting_link', 'interviewer_id', 'status', 'rating', 'feedback', 'notes',
        ]));

        return response()->json(['success' => true]);
    }

    public function interviews_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitInterview::class);

        RecruitInterview::whereId($id)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function interviews_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RecruitInterview::class);

        foreach ($request->selectedIds as $id) {
            RecruitInterview::whereId($id)->update(['deleted_at' => Carbon::now()]);
        }

        return response()->json(['success' => true]);
    }

    // ======================== REPORTS ========================

    public function reports(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RecruitJob::class);

        $period = $request->get('period', '6months');
        $dateFrom = match ($period) {
            '1month'  => now()->subMonth(),
            '3months' => now()->subMonths(3),
            '1year'   => now()->subYear(),
            default   => now()->subMonths(6),
        };

        $funnel = RecruitApplication::select('stage', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $dateFrom)
            ->groupBy('stage')
            ->pluck('count', 'stage');

        $byJob = RecruitApplication::select('job_id', DB::raw('count(*) as count'))
            ->with('job:id,title')
            ->where('created_at', '>=', $dateFrom)
            ->groupBy('job_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $bySource = RecruitCandidate::select('source', DB::raw('count(*) as count'))
            ->where('created_at', '>=', $dateFrom)
            ->groupBy('source')
            ->pluck('count', 'source');

        $monthlyTrend = RecruitApplication::select(
                DB::raw('DATE_FORMAT(applied_date, "%Y-%m") as month'),
                DB::raw('count(*) as total'),
                DB::raw('SUM(CASE WHEN stage = "hired" THEN 1 ELSE 0 END) as hired'),
                DB::raw('SUM(CASE WHEN stage = "rejected" THEN 1 ELSE 0 END) as rejected')
            )
            ->where('applied_date', '>=', $dateFrom)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $avgTimeToHire = RecruitApplication::where('stage', 'hired')
            ->where('created_at', '>=', $dateFrom)
            ->selectRaw('AVG(DATEDIFF(reviewed_at, applied_date)) as avg_days')
            ->value('avg_days');

        $byCategory = RecruitJob::select('category_id', DB::raw('count(*) as count'))
            ->with('category:id,name')
            ->where('created_at', '>=', $dateFrom)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json([
            'funnel'           => $funnel,
            'by_job'           => $byJob,
            'by_source'        => $bySource,
            'monthly_trend'    => $monthlyTrend,
            'avg_time_to_hire' => round($avgTimeToHire ?? 0, 1),
            'by_category'      => $byCategory,
        ]);
    }
}
