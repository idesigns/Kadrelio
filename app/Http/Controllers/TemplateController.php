<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTemplateRequest;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Services\TemplateManager;
use App\Services\TemplateRenderer;
use App\Support\TemplateFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateController extends Controller
{
    public function index(Company $company): View
    {
        Gate::authorize('view', $company);
        $templates = $company->templates()->orderBy('kind')->orderBy('language')->latest('id')->get();

        return view('templates.index', compact('company', 'templates') + ['kinds' => TemplateFields::kinds()]);
    }

    public function create(Company $company): View
    {
        $this->authorizeManagement($company);

        return view('templates.form', $this->viewData($company));
    }

    public function edit(Company $company, DocumentTemplate $template): View
    {
        $this->authorizeManagement($company);

        return view('templates.form', $this->viewData($company, $template));
    }

    public function store(StoreTemplateRequest $request, Company $company, TemplateManager $manager): RedirectResponse
    {
        $template = $manager->save($company, $request->validated(), $request->file('file'));

        return redirect()->route('companies.templates.edit', [$company, $template])->with('success', 'Wersja robocza została zapisana. Sprawdź przypisanie pól i wygeneruj podgląd.');
    }

    public function update(StoreTemplateRequest $request, Company $company, DocumentTemplate $template, TemplateManager $manager): RedirectResponse
    {
        $draft = $manager->save($company, $request->validated(), $request->file('file'), $template);

        return redirect()->route('companies.templates.edit', [$company, $draft])->with('success', $draft->id !== $template->id ? 'Nowa wersja robocza została zapisana. Dotychczasowy wzór nadal obowiązuje.' : 'Zmiany wersji roboczej zostały zapisane.');
    }

    public function preview(Request $request, Company $company, DocumentTemplate $template, TemplateManager $manager): RedirectResponse
    {
        $this->authorizeManagement($company);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
        $manager->preview($template, (int) $data['revision']);

        return redirect(route('companies.templates.edit', [$company, $template]).'#preview')->with('success', 'Podgląd PDF z przykładowymi danymi jest gotowy.');
    }

    public function activate(Request $request, Company $company, DocumentTemplate $template, TemplateManager $manager): RedirectResponse
    {
        $this->authorizeManagement($company);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'preview_confirmed' => ['accepted']]);
        $manager->activate($template, $request->user(), (int) $data['revision']);

        return redirect()->route('companies.templates.index', $company)->with('success', 'Wersja została aktywowana dla nowych dokumentów. Wcześniejsze dokumenty zachowują swoją treść.');
    }

    public function archive(Request $request, Company $company, DocumentTemplate $template): RedirectResponse
    {
        $this->authorizeManagement($company);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($template, $data): void {
            $locked = DocumentTemplate::whereKey($template->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->revision === (int) $data['revision'], 409, 'Wzór zmienił się. Odśwież stronę.');
            $locked->forceFill(['status' => 'archived', 'revision' => $locked->revision + 1])->save();
        });

        return redirect()->route('companies.templates.index', $company)->with('success', 'Wersja została wycofana. Oryginał i historia pozostają dostępne.');
    }

    public function source(Company $company, DocumentTemplate $template, TemplateRenderer $renderer): StreamedResponse
    {
        $this->authorizeManagement($company);
        $renderer->source($template);

        return Storage::disk('local')->download($template->source_path, $template->source_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function previewFile(Company $company, DocumentTemplate $template): StreamedResponse
    {
        $this->authorizeManagement($company);
        $disk = Storage::disk('local');
        abort_unless($template->hasCurrentPreview() && $disk->exists($template->preview_path), 404);
        abort_unless(hash_equals($template->preview_checksum ?? '', hash('sha256', $disk->get($template->preview_path))), 404);

        return $disk->response($template->preview_path, 'podglad-wzoru.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    private function authorizeManagement(Company $company): void
    {
        Gate::authorize('view', $company);
        Gate::authorize('update', $company);
    }

    private function viewData(Company $company, ?DocumentTemplate $template = null): array
    {
        $versions = $template?->series_key ? $company->templates()->where('series_key', $template->series_key)->orderByDesc('version')->get() : collect($template ? [$template] : []);

        return ['company' => $company, 'template' => $template, 'versions' => $versions, 'kinds' => TemplateFields::kinds(), 'fields' => TemplateFields::catalogue(), 'docxAvailable' => app(TemplateRenderer::class)->available('docx')];
    }
}
