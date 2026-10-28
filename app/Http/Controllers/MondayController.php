<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\MondayBoard;
use App\Models\MondayImportBatch;
use App\Models\MondayItem;
use App\Models\MondayRevision;
use App\Services\HrWorkflow;
use App\Services\MondayImport;
use App\Services\MondayProjector;
use App\Support\GroupPagination;
use App\Support\MondayFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MondayController extends Controller
{
    public function index(Request $request, MondayImport $importer): View
    {
        $boards = MondayBoard::with('company')->where(fn (Builder $query): Builder => $query->whereIn('company_id', Company::visibleTo($request->user())->select('id'))->when($request->user()->isAdmin(), fn (Builder $query): Builder => $query->orWhereNull('company_id')))->withCount(['items', 'items as review_count' => fn (Builder $query): Builder => $query->needsReview()])->orderByRaw('CASE WHEN company_id IS NULL THEN 1 ELSE 0 END')->orderBy('company_id')->orderBy('id')->get();
        $reports = $request->user()->isAdmin() ? MondayImportBatch::orderBy('id')->get()->map(fn (MondayImportBatch $batch): array => ['batch' => $batch, 'report' => $importer->verify($batch)]) : collect();

        return view('monday.index', compact('boards', 'reports'));
    }

    public function board(Request $request, MondayBoard $mondayBoard): View
    {
        $this->authorizeBoard($mondayBoard);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', 'string', 'max:255'], 'group' => ['nullable', 'string', 'max:255'], 'review' => ['nullable', 'in:1'], 'sort' => ['nullable', 'in:source,newest,oldest,name'], 'columns' => ['nullable', 'array', 'max:150'], 'columns.*' => ['string', Rule::in(array_keys($mondayBoard->columns))]]);
        $query = $mondayBoard->items()->with(['assets', 'employee', 'parent.board'])->withCount(['updates', 'assets'])->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))->when($filters['group'] ?? null, fn (Builder $query, string $group): Builder => $query->where('group_name', $group))->when($filters['review'] ?? null, fn (Builder $query): Builder => $query->needsReview());
        if (! empty($filters['q'])) {
            $needle = MondayFields::normalize($filters['q']);
            $ids = (clone $query)->select(['id', 'fields'])->cursor()->filter(fn (MondayItem $item): bool => str_contains(MondayFields::normalize(implode(' ', $item->fields)), $needle))->map(fn (MondayItem $item): int => $item->id)->all();
            $query->whereIn('id', $ids);
        }
        match ($filters['sort'] ?? 'source') {
            'newest' => $query->orderByDesc('document_date')->orderByDesc('position'),
            'oldest' => $query->orderBy('document_date')->orderBy('position'),
            'name' => $query->orderBy('name'),
            default => $query->orderBy('position'),
        };
        $query->orderBy('id');
        $counts = (clone $query)->reorder()->select('group_name')->selectRaw('COUNT(*) AS total')->groupBy('group_name')->toBase()->get();
        $groupOrder = array_flip($mondayBoard->groups);
        $counts = $counts->sortBy(fn (object $group): int => $groupOrder[$group->group_name] ?? count($groupOrder))->values();
        $total = $counts->sum('total');
        $requestedGroup = $request->header('X-Board-Group');
        $groups = collect();
        foreach ($counts as $index => $count) {
            $key = substr(hash('sha256', json_encode($count->group_name)), 0, 16);
            if ($requestedGroup !== null && $requestedGroup !== $key) {
                continue;
            }
            $items = GroupPagination::paginate($request, (clone $query->getQuery())->where('group_name', $count->group_name), $key, (int) $count->total, 40);
            $groups[$key] = ['name' => $count->group_name ?? 'Bez grupy', 'color' => ['blue', 'green', 'purple', 'orange'][$index % 4], 'items' => $items];
        }
        abort_if($requestedGroup !== null && ! $groups->has($requestedGroup), 404);
        $columns = $this->columns($mondayBoard, $request->boolean('columns_custom') ? ($filters['columns'] ?? null) : null);
        if ($requestedGroup !== null) {
            return view('monday.group', ['mondayBoard' => $mondayBoard, 'group' => $requestedGroup, 'columns' => $columns, ...$groups[$requestedGroup]]);
        }
        if ($request->header('X-Board-Search') === '1') {
            return view('monday.results', compact('mondayBoard', 'total', 'groups', 'columns', 'filters'));
        }
        $statuses = $mondayBoard->items()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status');
        $company = $mondayBoard->company;
        if ($company) {
            $request->session()->put('company_id', $company->id);
        }

        return view('monday.board', compact('mondayBoard', 'company', 'total', 'groups', 'columns', 'filters', 'statuses'));
    }

    public function show(Request $request, MondayBoard $mondayBoard, MondayItem $item, HrWorkflow $workflow): View
    {
        $this->authorizeBoard($mondayBoard);
        $item->load(['assets', 'updates', 'employee', 'record', 'parent.board', 'children.board', 'revisions.user']);
        $company = $mondayBoard->company;
        $candidateKind = $this->parentKind($mondayBoard->kind);
        $candidateSearch = $request->validate(['candidate_q' => ['nullable', 'string', 'max:150']])['candidate_q'] ?? null;
        $candidates = $candidateKind && $company ? MondayItem::whereHas('board', fn (Builder $query): Builder => $query->where('company_id', $company->id)->where('monday_import_batch_id', $mondayBoard->monday_import_batch_id)->where('kind', $candidateKind))->when($candidateSearch, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query->where('source_id', $search)->orWhereLike('name', '%'.$search.'%')), fn (Builder $query): Builder => $query->where('identity_hash', $item->identity_hash)->when(! $item->identity_hash, fn (Builder $query): Builder => $query->where('name', $item->name)))->orderByDesc('document_date')->orderBy('id')->limit(50)->get(['id', 'source_id', 'name', 'document_date']) : collect();
        $versions = $item->employee_id ? $item->employee->mondayItems()->whereHas('board', fn (Builder $query): Builder => $query->where('kind', 'profiles'))->with('board')->orderBy('id')->get() : collect();
        $editableColumns = array_filter($item->source_data['headers'], fn (string $label, string $column): bool => ! $this->protectedColumn($item, $column), ARRAY_FILTER_USE_BOTH);
        $hasDocumentActions = $item->record || array_filter($mondayBoard->columns, MondayFields::isAction(...)) !== [];
        $generationBlockers = $item->record && in_array($item->record->kind, ['contract', 'amendment', 'termination'], true) ? $workflow->generationBlockers($item->record) : [];

        return view('monday.show', compact('mondayBoard', 'company', 'item', 'candidateKind', 'candidates', 'versions', 'editableColumns', 'hasDocumentActions', 'generationBlockers'));
    }

    public function update(Request $request, MondayBoard $mondayBoard, MondayItem $item, MondayProjector $projector): RedirectResponse
    {
        $this->authorizeBoard($mondayBoard, true);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'fields' => ['required', 'array', 'max:150'], 'fields.*' => ['nullable', 'string', 'max:30000'], 'group_name' => ['nullable', 'string', Rule::in($mondayBoard->groups)], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        DB::transaction(function () use ($item, $data, $request, $projector, $mondayBoard): void {
            $item = MondayItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($item->revision === (int) $data['revision'], 409, 'Ktoś zmienił ten wpis. Odśwież stronę przed zapisaniem.');
            $fields = $item->fields;
            $changes = [];
            foreach ($data['fields'] as $column => $value) {
                abort_unless(isset($item->source_data['headers'][$column]) && ! $this->protectedColumn($item, $column), 422, 'Tej kolumny nie można zmienić.');
                $value = $value ?? '';
                $label = $item->source_data['headers'][$column];
                $options = MondayFields::options($label, $mondayBoard->kind, $fields[$column] ?? '');
                abort_unless($options === [] || $value === '' || in_array($value, $options, true), 422, 'Wybierz poprawną wartość z listy.');
                if ($column === 'A' || str_starts_with($label, 'Status') || in_array($label, ['Imię/Name*', 'Imię', 'E-mail', 'Telefon/phone number'], true)) {
                    abort_if(mb_strlen($value) > 255, 422, 'Wartość jest zbyt długa. Maksymalnie 255 znaków.');
                }
                if (($fields[$column] ?? '') !== $value) {
                    $changes[$column] = ['before' => $fields[$column] ?? '', 'after' => $value];
                    $fields[$column] = $value;
                }
            }
            $group = array_key_exists('group_name', $data) ? $data['group_name'] : $item->group_name;
            if ($item->group_name !== $group) {
                $changes['group_name'] = ['before' => $item->group_name, 'after' => $group];
            }
            if ($changes === []) {
                return;
            }
            $item->fields = $fields;
            $item->name = $fields['A'] ?? '';
            $item->group_name = $group;
            $item->status = $item->value('Status umowy', 'Status') ?: null;
            $item->reviewed_at = null;
            $item->reviewed_by = null;
            $item->revision++;
            $item->save();
            $projector->syncItem($item, array_keys($changes));
            $this->revision($item, $request, ['reason' => $data['reason'], 'fields' => $changes]);
        });

        return back()->with('success', 'Zapisano zmiany. Oryginalny eksport i historia zmian zostały zachowane.');
    }

    public function relation(Request $request, MondayBoard $mondayBoard, MondayItem $item): RedirectResponse
    {
        $this->authorizeBoard($mondayBoard, true);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'parent_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $kind = $this->parentKind($mondayBoard->kind);
        abort_unless($kind && $mondayBoard->company_id, 422);
        DB::transaction(function () use ($item, $mondayBoard, $kind, $data, $request): void {
            $item = MondayItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($item->revision === (int) $data['revision'], 409, 'Wpis zmienił się. Odśwież stronę.');
            abort_if($item->record?->data['monday_workflow_active'] ?? false, 409, 'Dokument jest już w bieżącym obiegu. Jego umowy pierwotnej nie można podmienić.');
            $parent = MondayItem::whereKey($data['parent_id'])->whereHas('board', fn (Builder $query): Builder => $query->where('company_id', $mondayBoard->company_id)->where('monday_import_batch_id', $mondayBoard->monday_import_batch_id)->where('kind', $kind))->firstOrFail();
            $before = $item->parent_id;
            $item->parent_id = $parent->id;
            $item->relation_state = 'manual';
            $item->revision++;
            $item->save();
            if (in_array($mondayBoard->kind, ['amendments', 'terminations'], true) && $item->record) {
                $item->record->contract_id = $parent->workflow_record_id;
                $item->record->save();
            }
            $this->revision($item, $request, ['reason' => $data['reason'], 'parent' => ['before' => $before, 'after' => $parent->id]]);
        });

        return back()->with('success', 'Powiązanie zostało zatwierdzone i zapisane w historii.');
    }

    public function profile(Request $request, MondayBoard $mondayBoard, MondayItem $item, MondayProjector $projector): RedirectResponse
    {
        $this->authorizeBoard($mondayBoard, true);
        abort_unless($mondayBoard->kind === 'profiles' && $item->employee_id, 422);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        DB::transaction(function () use ($item, $request, $data, $projector): void {
            $item = MondayItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($item->revision === (int) $data['revision'], 409, 'Wpis zmienił się. Odśwież stronę.');
            $employee = $item->employee()->lockForUpdate()->firstOrFail();
            $before = $employee->personal_data['monday_profile_id'] ?? null;
            $projector->applyProfile($employee, $item);
            $item->reviewed_at = now();
            $item->reviewed_by = $request->user()->id;
            $item->revision++;
            $item->save();
            $this->revision($item, $request, ['reason' => $data['reason'], 'active_profile' => ['before' => $before, 'after' => $item->id]]);
        });

        return back()->with('success', 'Wybrano aktualną wersję danych pracownika. Wszystkie wersje historyczne pozostały dostępne.');
    }

    public function asset(MondayBoard $mondayBoard, MondayItem $item, int $asset): StreamedResponse
    {
        $this->authorizeBoard($mondayBoard);
        $file = $item->assets()->findOrFail($asset);
        abort_unless($file->path && Storage::disk('local')->exists($file->path), 404, 'Plik nie został jeszcze dołączony z assets.');
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $file->name);

        return Storage::disk('local')->download($file->path, $name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function review(Request $request, MondayBoard $mondayBoard, MondayItem $item): RedirectResponse
    {
        $this->authorizeBoard($mondayBoard, true);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        DB::transaction(function () use ($item, $data, $request): void {
            $item = MondayItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($item->revision === (int) $data['revision'], 409, 'Wpis zmienił się. Odśwież stronę.');
            abort_if($item->board->kind === 'profiles' && ($item->employee?->personal_data['profile_review_required'] ?? false), 422, 'Najpierw wybierz aktualną wersję danych pracownika.');
            $item->reviewed_at = now();
            $item->reviewed_by = $request->user()->id;
            $item->revision++;
            $item->save();
            $this->revision($item, $request, ['reason' => $data['reason'], 'review' => true]);
        });

        return back()->with('success', 'Potwierdzono sprawdzenie danych. Oryginalne wartości zostały zachowane.');
    }

    public function workbook(MondayBoard $mondayBoard): StreamedResponse
    {
        $this->authorizeBoard($mondayBoard);
        abort_unless(Storage::disk('local')->exists($mondayBoard->workbook_path), 404);

        return Storage::disk('local')->download($mondayBoard->workbook_path, 'monday-'.$mondayBoard->source_id.'.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(MondayBoard $mondayBoard): StreamedResponse
    {
        $this->authorizeBoard($mondayBoard);

        return response()->streamDownload(function () use ($mondayBoard): void {
            echo '{"board_id":'.json_encode($mondayBoard->source_id).',"columns":'.json_encode($mondayBoard->columns, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).',"items":[';
            $first = true;
            foreach ($mondayBoard->items()->orderBy('position')->orderBy('id')->lazy(100) as $item) {
                echo ($first ? '' : ',').json_encode(['source_id' => $item->source_id, 'group' => $item->group_name, 'fields' => $item->fields, 'source' => $item->source_data, 'relation' => $item->relation_state, 'parent_id' => $item->parent_id, 'issues' => $item->issues], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $first = false;
            }
            echo ']}';
        }, 'monday-'.$mondayBoard->source_id.'.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function protectedColumn(MondayItem $item, string $column): bool
    {
        $label = $item->source_data['headers'][$column] ?? '';

        if (MondayFields::isAction($label) || MondayFields::isFile($label) || MondayFields::isRelation($label) || ($item->board->kind === 'links' && MondayFields::normalize($label) === 'link')) {
            return true;
        }
        if (($item->record?->data['monday_workflow_active'] ?? false) && (! in_array($item->record->status, ['draft', 'ready'], true) || in_array(MondayFields::normalize($label), ['status', 'status umowy'], true))) {
            return true;
        }

        return $label === 'Item ID (auto generated)' || str_contains(MondayFields::normalize($label), 'pesel') || str_starts_with(MondayFields::normalize($label), 'data') || $label === 'Umowa zostaje rozwiązana ze skutkiem na dzień:' || str_contains($item->fields[$column] ?? '', '/resources/') || ($item->source_data['cells'][$column]['formula'] ?? null) !== null;
    }

    private function authorizeBoard(MondayBoard $board, bool $write = false): void
    {
        if ($board->company_id) {
            Gate::authorize('view', $board->company);
            if ($write) {
                Gate::authorize('update', $board->company);
            }
        } else {
            abort_unless(auth()->user()->isAdmin(), 404);
        }
    }

    private function columns(MondayBoard $board, ?array $selected): array
    {
        $available = array_filter($board->columns, fn (string $label): bool => $label !== 'Item ID (auto generated)');
        if ($selected) {
            return array_intersect_key($available, array_flip(['A', ...$selected]));
        }
        $keys = ['A'];
        foreach (['Status umowy', 'Status', 'Imię/Name*', 'Data zawarcia umowy formularz', 'Data zawarcia aneksu', 'Data pierwotnej umowy', 'Umowa zostaje rozwiązana ze skutkiem na dzień:', 'Data oświadczenia', 'Data szkolenia', 'Data wypełnienia formularza', 'Załącznik UZ', 'Rodzaj zmiany', 'E-mail strony umowy', 'E-mail', 'Adres e-mail', 'Nr seryjne sprzętów', 'Rodzaj sprzetów'] as $label) {
            $column = array_search($label, $available, true);
            if ($column !== false) {
                $keys[] = $column;
            }
        }
        if (count($keys) < 4) {
            $keys = [...$keys, ...array_slice(array_keys($available), 0, 7)];
        }
        $columns = [];
        foreach (array_slice(array_unique($keys), 0, 9) as $key) {
            if (isset($available[$key])) {
                $columns[$key] = $available[$key];
            }
        }

        return $columns;
    }

    private function parentKind(string $kind): ?string
    {
        return match ($kind) {
            'amendments', 'terminations', 'contract_statements' => 'contracts', 'amendment_statements' => 'amendments', 'termination_statements' => 'terminations', default => null,
        };
    }

    private function revision(MondayItem $item, Request $request, array $changes): void
    {
        $revision = new MondayRevision;
        $revision->forceFill(['monday_item_id' => $item->id, 'user_id' => $request->user()->id, 'changes' => $changes])->save();
    }
}
