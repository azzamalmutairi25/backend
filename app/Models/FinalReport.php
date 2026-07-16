<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalReport extends Model
{
    protected $fillable = ['candidate_id','assessment_id','behavioral_fit','technical_fit','recommendation',
        'overview_text','executive_summary','exec_summary_by','exec_summary_at',
        'strengths','development_areas','status','escalated_at','return_reason',
        'return_count','last_returned_by','last_returned_at','created_by'];
    // fit كنوع رقمي ثابت عبر كل النقاط (list/detail/journey) — وإلا رجع نصًّا في بعضها ورقمًا في أخرى
    protected $casts = ['strengths'=>'array','development_areas'=>'array','last_returned_at'=>'datetime',
        'escalated_at'=>'datetime','exec_summary_at'=>'datetime','behavioral_fit'=>'float','technical_fit'=>'float'];
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function assessment(): BelongsTo { return $this->belongsTo(Assessment::class); }
}
