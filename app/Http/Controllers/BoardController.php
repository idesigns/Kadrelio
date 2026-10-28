<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MondayItem;
use App\Support\GroupPagination;
use App\Support\HrForms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BoardController extends Controller
{
    public function home(Request $request): View
    {
        $companies = Company::visibleTo($request->user())->withCount(['employees', 'records', 'users', 'records as awaiting_data_count' => fn (Builder $query): Builder => $query->where('kind', 'contract')->where('status', 'awaiting_data'), 'records as awaiting_signature_count' => fn (Builder $query): Builder => $query->where('kind', 'contract')->where('status', 'sent')])->orderBy('name')->get();

        return view('dashboard', compact('companies'));
    }

    public function index(Request $request, Company $company, string $board = 'contracts'): View
    {
        Gate::authorize('view', $company);
        abort_unless(isset(HrForms::boards()[$board]), 404);
        $request->session()->put('company_id', $company->id);
        $definition = HrForms::boards()[$board];
        if ($board === 'links') {
            return view('boards.links', compact('company', 'board', 'definition'));
        }
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(HrForms::statuses()))],
            'department' => ['nullable', 'in:'.implode(',', HrForms::departments())],
            'sort' => ['nullable', 'in:newest,oldest,name'],
            'kind' => ['nullable', 'in:contract,amendment,termination'],
        ]);
        $kind = ['requests' => 'contract', 'contracts' => 'contract', 'amendments' => 'amendment', 'terminations' => 'termination', 'changes' => 'data_change', 'multisport' => 'multisport'][$board] ?? null;
        if ($board === 'employees') {
            $query = $company->employees()->when($filters['q'] ?? null, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query->whereLike('first_name', '%'.$search.'%')->orWhereLike('last_name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%')));
            $query->when($filters['department'] ?? null, fn (Builder $query, string $department): Builder => $query->whereRaw('LOWER(department) = ?', [mb_strtolower($department)]));
            $counts = (clone $query)->selectRaw('CASE WHEN profile_completed_at IS NULL THEN ? ELSE ? END AS board_group, COUNT(*) AS total', ['awaiting_data', 'completed'])->groupBy('board_group')->pluck('total', 'board_group');
            $query->orderBy('last_name')->orderBy('id');
        } else {
            $query = $company->records()->where('status', '!=', 'awaiting_confirmation')->with(['employee', 'creator', 'mondayItem' => fn (HasOne $query): HasOne => $query->with('parent')->withCount(['assets as available_assets_count' => fn (Builder $query): Builder => $query->whereNotNull('path')])])->when($kind, fn (Builder $query): Builder => $query->where('kind', $kind));
            if ($board === 'statements') {
                $query->whereNotNull('signed_at')->whereIn('kind', ['contract', 'amendment', 'termination']);
                $query->when($filters['kind'] ?? null, fn (Builder $query, string $recordKind): Builder => $query->where('kind', $recordKind));
            }
            $query->when($filters['q'] ?? null, fn (Builder $query, string $search): Builder => $query->whereHas('employee', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereLike('first_name', '%'.$search.'%')->orWhereLike('last_name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%'))));
            $query->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status));
            $query->when($filters['department'] ?? null, fn (Builder $query, string $department): Builder => $query->whereRaw('LOWER(department) = ?', [mb_strtolower($department)]));
            if (($filters['sort'] ?? 'newest') === 'name') {
                $query->orderBy(Employee::select('last_name')->whereColumn('employees.id', 'workflow_records.employee_id')->limit(1));
            } else {
                $query->orderBy('created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
            }
            $query->orderByDesc('id');
            $counts = (clone $query)->reorder()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        }

        $sourceQueries = [];
        if ($board === 'statements') {
            foreach (['contract', 'amendment', 'termination'] as $statementKind) {
                $sourceQuery = $this->sourceStatements($company, $filters, $statementKind);
                $count = (clone $sourceQuery)->count();
                if ($count) {
                    $group = 'source_'.$statementKind;
                    $counts[$group] = $count;
                    $sourceQueries[$group] = $sourceQuery;
                }
            }
        }
        $total = $counts->sum();
        $requestedGroup = $request->header('X-Board-Group');
        abort_if($requestedGroup !== null && ! $counts->has($requestedGroup), 404);
        $groups = collect();
        foreach ($counts as $group => $count) {
            if ($requestedGroup !== null && $requestedGroup !== $group) {
                continue;
            }
            if (isset($sourceQueries[$group])) {
                $groups[$group] = GroupPagination::paginate($request, $sourceQueries[$group], $group, (int) $count);

                continue;
            }
            $groupQuery = clone $query->getQuery();
            if ($board === 'employees') {
                $group === 'completed' ? $groupQuery->whereNotNull('profile_completed_at') : $groupQuery->whereNull('profile_completed_at');
            } else {
                $groupQuery->where('status', $group);
            }
            $groups[$group] = GroupPagination::paginate($request, $groupQuery, $group, (int) $count);
        }
        if ($requestedGroup !== null) {
            return view(str_starts_with($requestedGroup, 'source_') ? 'boards.statement-group' : 'boards.group', ['company' => $company, 'board' => $board, 'kind' => $kind, 'group' => $requestedGroup, 'items' => $groups[$requestedGroup]]);
        }

        return view($request->header('X-Board-Search') === '1' ? 'boards.results' : 'boards.index', compact('company', 'board', 'definition', 'total', 'groups', 'filters', 'kind'));
    }

    private function sourceStatements(Company $company, array $filters, string $kind): Builder
    {
        $query = MondayItem::whereHas('board', fn (Builder $query): Builder => $query->where('company_id', $company->id)->where('kind', $kind.'_statements'))
            ->with(['board', 'employee', 'parent.record'])->withCount(['assets as available_assets_count' => fn (Builder $query): Builder => $query->whereNotNull('path')]);
        if (! empty($filters['kind']) && $filters['kind'] !== $kind) {
            $query->whereRaw('1 = 0');
        }
        $query->when($filters['q'] ?? null, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%')->orWhereHas('employee', fn (Builder $query): Builder => $query->whereLike('first_name', '%'.$search.'%')->orWhereLike('last_name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%'))));
        $query->when($filters['department'] ?? null, fn (Builder $query, string $department): Builder => $query->whereHas('parent.record', fn (Builder $query): Builder => $query->whereRaw('LOWER(department) = ?', [mb_strtolower($department)])));
        if (! empty($filters['status'])) {
            match ($filters['status']) {
                'signed' => $query->where('status', 'Podpisane'),
                'submitted' => $query->where('status', 'Złożone'),
                'draft' => $query->whereNull('status'),
                default => $query->whereRaw('1 = 0'),
            };
        }
        if (($filters['sort'] ?? '') === 'name') {
            $query->orderBy('name');
        } else {
            $query->orderBy('document_date', ($filters['sort'] ?? '') === 'oldest' ? 'asc' : 'desc');
        }

        return $query->orderByDesc('id');
    }
}
