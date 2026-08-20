<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// رفعةُ استيراد واحدة: صفوفها، وحالتها، وما أُنشئ منها وما رُفض.
class ImportBatch extends Model
{
    protected $fillable = [
        'user_id', 'filename', 'status', 'total_rows', 'processed_rows',
        'created_count', 'updated_count', 'failed_count', 'payload', 'failures', 'error',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'failures' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

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
