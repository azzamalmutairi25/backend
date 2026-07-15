<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// استثناء صلاحية لمستخدم بعينه فوق دوره — منحاً (granted=true) أو سحباً (false).
class UserPermissionOverride extends Model
{
    protected $fillable = ['user_id', 'permission', 'granted', 'reason', 'created_by'];

    protected $casts = ['granted' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
