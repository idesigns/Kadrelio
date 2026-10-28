<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SignatureImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 500000 || ! str_starts_with($value, 'data:image/png;base64,')) {
            $fail('Narysuj podpis i kliknij „Użyj podpisu”.');

            return;
        }
        $image = base64_decode(substr($value, 22), true);
        $size = $image ? @getimagesizefromstring($image) : false;
        if (! $size || $size[2] !== IMAGETYPE_PNG || $size[0] < 10 || $size[1] < 10 || $size[0] > 2000 || $size[1] > 1000) {
            $fail('Podpis musi być poprawnym obrazem PNG. Narysuj go ponownie.');
        }
    }
}
