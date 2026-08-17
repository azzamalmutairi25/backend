<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// جهةٌ تُسلَّم إليها الجدولة، ومعها الفئات التي تستقبلها.
class DispatchAuthority extends Model
{
    // فئات المرشحين — مطابقة لـcandidates.personnel_category
    public const CATEGORIES = ['civilian', 'military', 'contractor'];

    public const CATEGORY_LABEL = [
        'civilian' => 'مدني',
        'military' => 'عسكري',
        'contractor' => 'متعاقد',
    ];

    protected $fillable = ['code', 'name_ar', 'categories', 'email', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function dispatches()
    {
        return $this->hasMany(ScheduleDispatch::class, 'authority_id');
    }

    /** @return array<int,string> الفئات التي تستقبلها هذه الجهة */
    public function categoryList(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $this->categories)),
            fn ($c) => in_array($c, self::CATEGORIES, true)
        ));
    }

    public function categoryLabels(): array
    {
        return array_map(fn ($c) => self::CATEGORY_LABEL[$c] ?? $c, $this->categoryList());
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
