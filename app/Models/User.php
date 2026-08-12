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
        'role_id', 'sector_id', 'manager_id', 'is_active', 'must_change_password', 'last_login_at',
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

    // مدير المستخدم — للمساعد: مدير إدارة التقييم الذي يعتمد تقاريره
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function team(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    // الأدوار التي تُسنَد لمدير — المساعد يكتب التقرير ومديره يعتمده
    public const MANAGED_ROLES = ['ASSISTANT'];

    public function isManaged(): bool
    {
        return in_array($this->role->code, self::MANAGED_ROLES, true);
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

    public function permissionOverrides(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    // ── هل المستخدم يملك صلاحية معيّنة؟ ──
    // الدور أولاً، ثم استثناء المستخدم إن وُجد — منحاً أو سحباً.
    //
    // السحب يسبق '*': مدير النظام نفسه يُسحب منه استثناءً. لولا ذلك لصار '*'
    // بابَ التفافٍ على كل سحبٍ يُكتب.
    public function hasPermission(string $permission): bool
    {
        $override = $this->relationLoaded('permissionOverrides')
            ? $this->permissionOverrides->firstWhere('permission', $permission)
            : $this->permissionOverrides()->where('permission', $permission)->first();

        if ($override) {
            return (bool) $override->granted;
        }

        return \App\Security\Permissions::roleHasPermission($this->role->code, $permission);
    }

    // ── الصلاحيات الفعلية: الدور + الاستثناءات ──
    // تُرسل للواجهة عند الدخول لتضبط ما يُعرض.
    public function effectivePermissions(): array
    {
        $base = \App\Security\Permissions::forRole($this->role->code);
        $overrides = $this->permissionOverrides()->get();

        if ($overrides->isEmpty()) {
            return $base;
        }

        // '*' يُفرَد قبل تطبيق السحب — وإلا لم يكن للسحب أثر على مدير النظام
        if (in_array('*', $base, true)) {
            $base = \App\Security\Permissions::all();
        }

        $granted = $overrides->where('granted', true)->pluck('permission')->all();
        $revoked = $overrides->where('granted', false)->pluck('permission')->all();

        return array_values(array_diff(array_unique([...$base, ...$granted]), $revoked));
    }

    // ── هل المستخدم بأحد هذه الأدوار؟ ──
    public function hasRole(string ...$codes): bool
    {
        return in_array($this->role->code, $codes, true);
    }
}
