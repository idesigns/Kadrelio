<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkflowRequest;
use App\Jobs\SendWorkflowMail;
use App\Models\Company;
use App\Models\WorkflowRecord;
use App\Services\HrWorkflow;
use App\Services\MondayProjector;
use App\Services\OutgoingMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WorkflowController extends Controller
{
    public function create(Company $company, string $kind): View
    {
        Gate::authorize('view', $company);
        $employees = $company->employees()->orderBy('last_name')->orderBy('id')->get(['id', 'first_name', 'last_name']);
        $contracts = $company->records()->with('employee')->where('kind', 'contract')->where('status', 'signed')->latest('id')->get();

        return view('records.form', compact('company', 'kind', 'employees', 'contracts'));
    }

    public function store(StoreWorkflowRequest $request, Company $company, string $kind, HrWorkflow $workflow): RedirectResponse
    {
        $record = $workflow->create($company, $kind, $request->validated(), $request->user());

        return redirect()->route('companies.records.show', [$company, $record])->with('success', 'Zgłoszenie zostało zapisane.'.($record->status === 'awaiting_data' ? (app(OutgoingMail::class)->enabled() ? ' Zaproszenie do formularza danych dodano do kolejki e-mail.' : ' Wysyłka e-mail jest wyłączona; możesz skopiować link do formularza.') : ' Możesz wygenerować dokumenty.'));
    }

    public function show(Request $request, Company $company, WorkflowRecord $record): View|RedirectResponse
    {
        Gate::authorize('view', $company);
        if ($record->isImported()) {
            $item = $record->mondayItem()->firstOrFail();

            $parameters = [$item->monday_board_id, $item];
            if ($request->boolean('panel')) {
                $parameters['panel'] = 1;
            }

            return redirect()->route('monday.items.show', $parameters);
        }
        $record->load(['employee', 'creator', 'contract']);

        return view('records.show', compact('company', 'record'));
    }

    public function generate(Request $request, Company $company, WorkflowRecord $record, HrWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('view', $company);
        $sourceRevision = null;
        if (isset($record->data['monday_source'])) {
            Gate::authorize('update', $company);
            if ($record->isImported() && in_array($record->status, ['draft', 'ready'], true)) {
                $sourceRevision = (int) $request->validate(['revision' => ['required', 'integer', 'min:0']])['revision'];
            }
        }
        $workflow->generate($record, $sourceRevision);

        return redirect()->route('companies.records.show', [$company, $record, 'panel' => $request->boolean('panel') ? 1 : null])->with('success', 'Dokumenty są gotowe. Sprawdź podgląd przed wysłaniem do podpisu.');
    }

    public function send(Company $company, WorkflowRecord $record): RedirectResponse
    {
        Gate::authorize('view', $company);
        abort_unless(app(OutgoingMail::class)->enabled(), 409, 'Wysyłka e-mail jest wyłączona w tym środowisku. Dokument nie został wysłany.');
        if (isset($record->data['monday_source'])) {
            Gate::authorize('update', $company);
        }
        abort_if($record->isImported(), 409, 'Importowane dokumenty zachowują oryginalny obieg Monday.');
        DB::transaction(function () use ($record): void {
            $record = WorkflowRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->signed_at && in_array($record->mail_status, ['failed', 'suppressed'], true)) {
                $record->mail_status = 'queued';
                $record->save();
                SendWorkflowMail::dispatch($record->id, true)->afterCommit();

                return;
            }
            abort_unless($record->generated_at && in_array($record->status, ['ready', 'sent'], true) && ! $record->signed_at, 409);
            if (! $record->signing_expires_at || $record->signing_expires_at->isPast()) {
                $record->signing_token = Str::random(64);
                $record->signing_expires_at = now()->addDays(config('hr.link_days'));
            }
            $record->status = 'sent';
            $record->mail_status = 'queued';
            $record->save();
            app(MondayProjector::class)->syncRecord($record);
            SendWorkflowMail::dispatch($record->id)->afterCommit();
        });

        return back()->with('success', 'Dokumenty dodano do kolejki e-mail. Status dostarczenia zobaczysz poniżej.');
    }

    public function update(Request $request, Company $company, WorkflowRecord $record): RedirectResponse
    {
        Gate::authorize('view', $company);
        if (isset($record->data['monday_source'])) {
            Gate::authorize('update', $company);
        }
        abort_if($record->isImported(), 409, 'Zmień dane w oryginalnym wpisie Monday.');
        DB::transaction(function () use ($record, $request): void {
            $record = WorkflowRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->kind === 'data_change') {
                abort_unless($record->signed_at && in_array($record->status, ['submitted', 'reviewed'], true), 409);
                $request->validate(['status' => ['required', 'in:submitted,reviewed']]);
                $record->status = $request->input('status');
            } else {
                $request->validate(['status' => ['required', 'in:not_concluded']]);
                abort_if($record->signed_at || ! in_array($record->status, ['draft', 'awaiting_data', 'ready', 'sent'], true), 409, 'Podpisaną umowę można rozwiązać wyłącznie przez obieg rozwiązania.');
                $record->status = 'not_concluded';
            }
            $record->save();
            app(MondayProjector::class)->syncRecord($record);
        });

        return back()->with('success', 'Status został zapisany.');
    }
}
