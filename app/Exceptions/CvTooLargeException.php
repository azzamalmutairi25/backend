<?php

namespace App\Exceptions;

use RuntimeException;

// حمولة السيرة تجاوزت حدّاً بنيوياً (عدد عناصر مصفوفة). يُرمى قبل مُحقّق
// Laravel كي لا يُفرَد قيدٌ عبر آلاف العناصر (نفخ مصفوفة). المتحكّم يحوّله 413.
class CvTooLargeException extends RuntimeException
{
    public function __construct(public string $field)
    {
        parent::__construct("cv payload too large: {$field}");
    }
}
