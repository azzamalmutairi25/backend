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

    // الأدوار المحصورة بقطاع: لا تُقيّم إلا مرشحي قطاعها
    public const SECTOR_BOUND_ROLES = ['EVALUATOR', 'DISCUSSION_EVAL', 'ASSISTANT'];

    protected $fillable = [
        'username', 'full_name', 'email', 'password',
        'role_id', 'sector_id', 'is_active', 'must_change_password', 'last_login_at',
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

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    // ── هل هذا المستخدم محصور بقطاع؟ ──
    public function isSectorBound(): bool
    {
        return in_array($this->role->code, self::SECTOR_BOUND_ROLES, true);
    }

    // ── هل يجوز لهذا المستخدم أن يتعامل مع مرشّح هذا القطاع؟ ──
    // غير المحصور (مدير النظام، الجدولة…) يمرّ. والمحصور بلا قطاع مضبوط
    // يُمنع لا يُسمح: بيانات ناقصة لا تُقرأ كإذن مفتوح.
    public function coversSector(?int $sectorId): bool
    {
        if (!$this->isSectorBound()) {
            return true;
        }
        return $this->sector_id !== null && $this->sector_id === $sectorId;
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
