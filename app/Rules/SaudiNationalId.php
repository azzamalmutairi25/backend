<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SaudiNationalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = (string) $value;

        if (!preg_match('/^\d{10}$/', $id)) {
            $fail('رقم الهوية يجب أن يكون ١٠ أرقام');
            return;
        }

        $type = (int) $id[0];
        if ($type !== 1 && $type !== 2) {
            $fail('رقم الهوية يجب أن يبدأ بـ ١ (مواطن) أو ٢ (مقيم)');
            return;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $id[$i];
            if ($i % 2 === 0) {
                $doubled = $digit * 2;
                $sum += $doubled > 9 ? ($doubled - 9) : $doubled;
            } else {
                $sum += $digit;
            }
        }

        if ($sum % 10 !== 0) {
            $fail('رقم الهوية غير صحيح (فشل التحقق)');
            return;
        }
    }
}
