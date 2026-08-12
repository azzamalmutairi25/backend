<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionItem extends Model
{
    protected $fillable = [
        'proposal_id', 'candidate_id', 'evaluator_id', 'sector_id',
        'scheduled_date', 'activity', 'schedule_id', 'drop_reason',
    ];

    protected $casts = ['scheduled_date' => 'date'];

    public function proposal(): BelongsTo { return $this->belongsTo(DistributionProposal::class, 'proposal_id'); }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function evaluator(): BelongsTo { return $this->belongsTo(User::class, 'evaluator_id'); }
    public function sector(): BelongsTo { return $this->belongsTo(Sector::class); }
}
