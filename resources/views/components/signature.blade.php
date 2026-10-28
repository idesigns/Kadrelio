<section class="signature-section field-wide" data-signature>
    <h3>Twój podpis <span class="required">*</span>
</h3>
    <x-field name="signature_name" :field="['label' => 'Imię i nazwisko podpisującego', 'required' => true, 'autocomplete' => 'name']" />
    <p class="field-hint">Narysuj podpis myszką lub palcem, a następnie wybierz „Użyj podpisu”.</p>
    <div class="signature-canvas-wrap">
<canvas width="800" height="220" aria-label="Pole do narysowania podpisu" tabindex="0">
</canvas>
<span class="signature-placeholder">Rysuj podpis tutaj</span>
</div>
    <input type="hidden" name="signature_image" value="">
    <div class="signature-actions">
<button type="button" class="button button-secondary" data-signature-clear>Wyczyść</button>
<button type="button" class="button button-secondary" data-signature-use>
<x-icon name="check" :size="16" /> Użyj podpisu</button>
<span class="signature-status" role="status">
</span>
</div>
    @error('signature_image')<p class="field-error">{{ $message }}</p>@enderror
</section>
