<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rank extends Model
{
    protected $fillable = ['label', 'category', 'tier', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    // تصنيف رتبة مرشّح عبر القائمة المُدارة: أطول تسمية مطابِقة (احتواءً) تفوز —
    // كي تسبق «مدير عام» «مدير». تُرجع 'upper'/'middle' أو null إن لا مطابقة.
    public static function tierFor(string $rankLabel, bool $isMilitary): ?string
    {
        $category = $isMilitary ? 'military' : 'civilian';
        $match = static::where('is_active', true)->where('category', $category)
            ->get()
            ->filter(fn ($r) => $r->label !== '' && mb_strpos($rankLabel, $r->label) !== false)
            ->sortByDesc(fn ($r) => mb_strlen($r->label))
            ->first();

        return $match?->tier;
    }
}
