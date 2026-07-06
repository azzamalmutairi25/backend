<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalReport extends Model
{
    protected $fillable = ['candidate_id','behavioral_fit','technical_fit','recommendation',
        'overview_text','strengths','development_areas','status','return_reason',
        'return_count','last_returned_by','last_returned_at','created_by'];
    protected $casts = ['strengths'=>'array','development_areas'=>'array','last_returned_at'=>'datetime'];
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
}
