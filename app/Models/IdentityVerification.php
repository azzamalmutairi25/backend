<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// سجلّ محاولة تحقق من الهوية (أثر تدقيقي)
class IdentityVerification extends Model
{
    protected $fillable = [
        'candidate_id', 'status', 'provider', 'detail', 'checked_by',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
