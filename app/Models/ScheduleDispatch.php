<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// سجلُّ تسليمٍ واحد: ماذا سُلّم، لمن، ومتى، وبصمةُ ما سُلّم.
class ScheduleDispatch extends Model
{
    protected $fillable = [
        'authority_id', 'period_id', 'date_from', 'date_to',
        'rows_count', 'channel', 'checksum', 'sent_by', 'sent_at',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'sent_at' => 'datetime',
        'rows_count' => 'integer',
    ];

    public function authority()
    {
        return $this->belongsTo(DispatchAuthority::class, 'authority_id');
    }

    public function period()
    {
        return $this->belongsTo(SchedulingPeriod::class, 'period_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
