<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

// رفعةُ استيراد واحدة: صفوفها، وحالتها، وما أُنشئ منها وما رُفض.
class ImportBatch extends Model
{
    protected $fillable = [
        'user_id', 'filename', 'status', 'total_rows', 'processed_rows',
        'created_count', 'updated_count', 'failed_count', 'payload', 'failures', 'error',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'failures' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    // الحمولة لا تُقرأ من خارج الطابور، وحملُها في استجابةٍ سهوٌ لا قصد
    protected $hidden = ['payload'];

    // ── الحمولة مشفّرة، لا `json` عارياً ──
    // كانت `'payload' => 'array'` تكتب الصفوف نصّاً صريحاً: الأسماء والهويات
    // والجوالات والسيرة كاملةً. وجدولُ المشاركين يشفّر الثلاثة، فصفٌّ واحد هنا
    // كان يُبطل تشفيرَه. الآن نصّ مشفّر يُفكّ عند القراءة — والعمود صار `text`.
    protected function payload(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                // حمولةٌ كُتبت قبل التشفير (رفعةٌ عالقة من قبل الترقية) تُقرأ
                // كما هي بدل أن يُرمى الطابور كلّه على استثناء فكّ تشفير
                try {
                    return json_decode(Crypt::decryptString($value), true);
                } catch (\Throwable) {
                    return json_decode($value, true);
                }
            },
            set: fn ($value) => ['payload' => $value === null
                ? null
                : Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE))],
        );
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    // ما تقرؤه شاشة التقدّم — بلا `payload`: عشرة آلاف صفٍّ تُعاد مع كل
    // استفتاء تُغرق الشبكة بما لا يُعرض. والإخفاقات محدودةٌ بمئتين للسبب نفسه:
    // مَن رُدّ عليه ألفا سطر لا يقرؤها على الشاشة — يُنزّلها ملفّاً.
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'status' => $this->status,
            'totalRows' => $this->total_rows,
            'processedRows' => $this->processed_rows,
            'createdCount' => $this->created_count,
            'failedCount' => $this->failed_count,
            'failures' => array_slice($this->failures ?? [], 0, 200),
            'failuresTruncated' => max(0, count($this->failures ?? []) - 200),
            'error' => $this->error,
            'startedAt' => optional($this->started_at)->toIso8601String(),
            'finishedAt' => optional($this->finished_at)->toIso8601String(),
        ];
    }
}
