<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// صلاحية ممنوحة لدور. الجدول هو مرجع الصلاحيات بعد بذره من
// Permissions::matrix()، والمصفوفة تبقى الافتراضي ومرجعَ الرجوع.
class RolePermission extends Model
{
    protected $fillable = ['role_id', 'permission', 'updated_by'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
