<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationScore extends Model
{
    protected $fillable = ['evaluation_id','competency_id','score','note'];
    public function competency(): BelongsTo { return $this->belongsTo(Competency::class); }
}
