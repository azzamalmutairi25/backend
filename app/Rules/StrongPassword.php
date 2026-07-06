<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pw = (string) $value;

        if (strlen($pw) < 8) {
            $fail('كلمة المرور يجب أن تكون ٨ أحرف على الأقل');
            return;
        }
        if (!preg_match('/[A-Z]/', $pw)) {
            $fail('كلمة المرور يجب أن تحتوي على حرف كبير (A-Z)');
            return;
        }
        if (!preg_match('/[a-z]/', $pw)) {
            $fail('كلمة المرور يجب أن تحتوي على حرف صغير (a-z)');
            return;
        }
        if (!preg_match('/[0-9]/', $pw)) {
            $fail('كلمة المرور يجب أن تحتوي على رقم');
            return;
        }
    }
}
