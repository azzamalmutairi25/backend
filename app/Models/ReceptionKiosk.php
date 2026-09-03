<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

// رمز كشك الاستقبال — رابط يومٍ واحد يفتحه مسؤول المشاركين على الجهاز اللوحي
class ReceptionKiosk extends Model
{
    protected $fillable = ['token', 'kiosk_date', 'label', 'created_by', 'revoked_at', 'last_used_at'];

    protected $casts = [
        'kiosk_date' => 'date',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    // ٤٨ محرفاً عشوائياً — نفس طول confirm_token في الدورات. الطول هنا هو
    // الحارس الأول: الرابط يُفتح بلا مصادقة، فالتخمين لا بدّ أن يكون مستحيلاً
    // لا صعباً، وتقييدُ المعدّل وحده لا يكفي لرمزٍ قصير.
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    // صالح = يومه هو اليوم ولم يُبطَل. الشرطان معاً: رمزُ أمس لا يفتح كشف
    // اليوم، والإبطال يُقفل رمز اليوم فوراً.
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->kiosk_date->isSameDay(now());
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ReceptionVisit::class, 'kiosk_id');
    }
}
