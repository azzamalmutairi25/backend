<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Competency extends Model
{
    protected $fillable = ['name_ar', 'type', 'group', 'domain', 'max_level', 'weight', 'target_upper', 'target_middle', 'sort_order'];
    protected $casts = [
        'weight' => 'float', 'max_level' => 'integer',
        'target_upper' => 'integer', 'target_middle' => 'integer',
    ];

    /**
     * يجيب معرّفات (IDs) الكفاءات التي تُقيَّم في نشاط معيّن.
     * يُستخدم للتحقق من اكتمال التقييم حسب النشاط.
     */
    public static function idsForActivity(string $activity): array
    {
        return \DB::table('activity_competency')
            ->where('activity', $activity)
            ->pluck('competency_id')
            ->toArray();
    }
}
