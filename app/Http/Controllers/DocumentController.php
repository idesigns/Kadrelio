<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WorkflowRecord;
use App\Services\PdfGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function show(Request $request, Company $company, WorkflowRecord $record, PdfGenerator $pdf): Response|StreamedResponse
    {
        Gate::authorize('view', $company);
        abort_if($record->isImported(), 409, 'Oryginalne pliki pobierzesz z wpisu Monday.');
        $statement = $request->boolean('statement');
        abort_if($statement && ! $record->signed_at, 404);
        if ($request->boolean('preview')) {
            if (! $statement && ! empty($record->data['document_parts'])) {
                return Storage::disk('local')->response($pdf->save($record), 'podglad-dokumentu.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
            }
            $html = $statement ? view('documents.statement', compact('record'))->render() : $record->document_html;
            abort_unless($html, 404);

            return response($html)->header('Cache-Control', 'private, no-store');
        }
        $path = $pdf->save($record, $statement);

        return Storage::disk('local')->download($path, ($statement ? 'oswiadczenie-' : 'dokument-').$record->id.'.pdf', ['Cache-Control' => 'private, no-store']);
    }

    public function public(WorkflowRecord $record, PdfGenerator $pdf): StreamedResponse
    {
        abort_unless($record->canBeSigned(), 410);

        return Storage::disk('local')->download($pdf->save($record), 'dokument-do-podpisu.pdf', ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
