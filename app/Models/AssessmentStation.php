<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// محطّةٌ مطلوبة في دورةٍ بعينها — والاكتمال يُقاس على ما اختير لا على ثلاث.
//
// كانت المحطّات ثلاثاً محفورةً في الرأس، وأوّلُ تقييمٍ يُرسَل يقلب المشارك
// إلى «تمّ تقييمه». فمن أدّى المقابلة وحدها كُتب تقريرُه على ثلثِ صورة،
// ومن طُلب له تقييمٌ جزئيّ بقي «ناقصاً» بلا مخرج.
class AssessmentStation extends Model
{
    protected $fillable = ['assessment_id', 'station', 'sort_order'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function label(): string
    {
        return Assessment::stationLabel($this->station);
    }
}
