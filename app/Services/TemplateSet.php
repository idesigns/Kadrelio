<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\WorkflowRecord;
use App\Support\TemplateFields;
use Illuminate\Database\Eloquent\Builder;

class TemplateSet
{
    public function forRecord(WorkflowRecord $record): array
    {
        $type = $record->isB2b() ? 'b2b' : 'mandate';
        $primary = $this->find($record->company_id, $record->kind, $type, $record->language, $record->department);
        $kinds = $primary?->package_kinds ?? ($record->kind === 'contract' ? ['contract', 'declaration', 'attachment'] : [$record->kind]);
        $templates = [];
        $missing = [];
        foreach (array_unique([$record->kind, ...$kinds]) as $kind) {
            $template = $kind === $record->kind ? $primary : $this->find($record->company_id, $kind, $type, $record->language, $record->department);
            if ($template) {
                $templates[] = $template;
            } else {
                $missing[] = 'Brakuje szablonu: '.mb_strtolower(TemplateFields::kinds()[$kind] ?? $kind).' ('.$record->language.', '.($record->department ?: 'bez działu').').';
            }
        }

        return ['templates' => $templates, 'missing' => $missing];
    }

    private function find(int $companyId, string $kind, string $agreementType, string $market, ?string $department): ?DocumentTemplate
    {
        return DocumentTemplate::where('company_id', $companyId)->where('status', 'active')->where('kind', $kind)->where('agreement_type', $agreementType)
            ->whereRaw('COALESCE(market, language) = ?', [$market])
            ->where(fn (Builder $query): Builder => $query->whereNull('department')->orWhereRaw('LOWER(department) = ?', [mb_strtolower($department ?? '')]))
            ->orderByRaw('CASE WHEN department IS NULL THEN 1 ELSE 0 END')->latest('id')->first();
    }
}
