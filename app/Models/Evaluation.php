<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evaluation extends Model
{
    protected $fillable = ['candidate_id','evaluator_id','activity','status','notes','submitted_at','approved_at','approved_by'];
    protected $casts = ['submitted_at'=>'datetime','approved_at'=>'datetime'];
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function scores(): HasMany { return $this->hasMany(EvaluationScore::class); }
}
