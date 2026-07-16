<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DistributionProposal extends Model
{
    protected $fillable = [
        'week_start', 'week_end', 'daily_cap', 'status',
        'created_by', 'approved_by', 'approved_at', 'placed', 'dropped',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'approved_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DistributionItem::class, 'proposal_id');
    }
}
