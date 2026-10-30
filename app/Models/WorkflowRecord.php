<?php

namespace App\Models;

use App\Support\HrForms;
use App\Support\MondayFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['language', 'department', 'concluded_at', 'starts_at', 'ends_at', 'data'])]
#[Hidden(['data', 'document_html', 'signing_token'])]
class WorkflowRecord extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'document_html' => 'encrypted', 'concluded_at' => 'date', 'starts_at' => 'date', 'ends_at' => 'date', 'signed_at' => 'datetime', 'generated_at' => 'datetime', 'signing_expires_at' => 'datetime', 'mail_sent_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contract_id');
    }

    public function mondayItem(): HasOne
    {
        return $this->hasOne(MondayItem::class);
    }

    public function isImported(): bool
    {
        return isset($this->data['monday_source']) && ! ($this->data['monday_workflow_active'] ?? false);
    }

    public function canEditSubmission(): bool
    {
        return $this->kind === 'contract'
            && ! isset($this->data['monday_source'])
            && ! $this->signed_at
            && in_array($this->status, ['awaiting_data', 'draft', 'ready'], true);
    }

    public function canEditSubmissionIdentity(): bool
    {
        return $this->canEditSubmission()
            && ! $this->employee->profile_completed_at
            && ! $this->employee->records()->where('id', '!=', $this->id)->exists()
            && ! $this->employee->mondayItems()->exists();
    }

    public function submissionFingerprint(): string
    {
        return hash_hmac('sha256', json_encode([$this->getRawOriginal(), $this->employee->getRawOriginal(), $this->canEditSubmissionIdentity()], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function submissionValues(): array
    {
        return [
            'first_name' => $this->employee->first_name, 'last_name' => $this->employee->last_name,
            'email' => $this->employee->email, 'pesel' => $this->employee->pesel,
            'agreement_type' => $this->data['agreement_type'] ?? 'mandate',
            'contractor_tax_id' => $this->data['contractor_tax_id'] ?? '',
            'reporter_name' => $this->data['reporter_name'] ?? '', 'reporter_email' => $this->data['reporter_email'] ?? '',
            'language' => $this->language, 'department' => $this->department,
            'concluded_at' => $this->concluded_at?->format('Y-m-d'),
            'starts_at' => $this->starts_at?->format('Y-m-d'), 'ends_at' => $this->ends_at?->format('Y-m-d'),
        ];
    }

    public function isB2b(): bool
    {
        if ($this->isImported()) {
            return MondayFields::agreementType($this->sourceAgreementType()) === 'b2b';
        }

        return ($this->data['agreement_type'] ?? 'mandate') === 'b2b';
    }

    public function sourceAgreementType(): string
    {
        return $this->mondayItem?->value('Typ Umowy') ?: ($this->mondayItem?->parent?->value('Typ Umowy') ?: ($this->data['monday_agreement_type'] ?? ''));
    }

    public function documentLanguage(): string
    {
        return $this->isImported() ? MondayFields::language($this->sourceAgreementType()) : $this->language;
    }

    public function canBeSigned(): bool
    {
        return ! $this->isImported()
            && in_array($this->kind, ['contract', 'amendment', 'termination'], true)
            && $this->status === 'sent'
            && ! $this->signed_at
            && $this->generated_at
            && $this->signing_expires_at?->isFuture()
            && ($this->kind === 'contract' || $this->contract?->status === 'signed');
    }

    public function agreementLabel(): string
    {
        if ($this->isImported()) {
            $type = $this->sourceAgreementType();

            return MondayFields::agreementType($type) !== 'unknown' ? $type : 'Typ umowy do ustalenia';
        }

        return $this->isB2b() ? 'Umowa B2B' : 'Umowa zlecenie';
    }

    public function statusLabel(): string
    {
        if ($this->isImported() && $this->mondayItem?->status) {
            return $this->mondayItem->status;
        }

        return HrForms::statuses()[$this->status] ?? $this->status;
    }
}
