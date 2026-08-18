<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rank extends Model
{
    protected $fillable = ['label', 'category', 'tier', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    // تصنيف رتبة مشارك عبر القائمة المُدارة: أطول تسمية مطابِقة (احتواءً) تفوز —
    // كي تسبق «مدير عام» «مدير». تُرجع 'upper'/'middle' أو null إن لا مطابقة.
    //
    // الفئة تأتي من المشارك لا من قطاعه: القطاع جهةٌ يعمل فيها الصنفان معاً.
    // والمتعاقد بلا قائمة مُدارة — طبقتُه تُختار صراحةً فلا يُسأل هذا الفحص عنها.
    public static function tierFor(string $rankLabel, string $category): ?string
    {
        $match = static::where('is_active', true)->where('category', $category)
            ->get()
            ->filter(fn ($r) => $r->label !== '' && mb_strpos($rankLabel, $r->label) !== false)
            ->sortByDesc(fn ($r) => mb_strlen($r->label))
            ->first();

        return $match?->tier;
    }
}
