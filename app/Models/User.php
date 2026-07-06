<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// ════════════════════════════════════════════════════════════
//  موديل المستخدم
// ════════════════════════════════════════════════════════════

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'username', 'full_name', 'email', 'password',
        'role_id', 'is_active', 'must_change_password', 'last_login_at',
        'failed_attempts', 'locked_until',
        'user_type', 'ad_username',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    // ── هل المستخدم يملك صلاحية معيّنة؟ ──
    public function hasPermission(string $permission): bool
    {
        return \App\Security\Permissions::roleHasPermission($this->role->code, $permission);
    }

    // ── هل المستخدم بأحد هذه الأدوار؟ ──
    public function hasRole(string ...$codes): bool
    {
        return in_array($this->role->code, $codes, true);
    }
}
