<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\CommunicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// ════════════════════════════════════════════════════════════
//  إرسال رسالة نصية خلفياً — يفصل زمن بوّابة الرسائل عن دورة الطلب.
//  يعمل على سجلّ SmsLog معلّق أُنشئ مسبقاً (queueSms)، فلا يُنشئ صفوفاً
//  مكرّرة عند إعادة المحاولة. الفشل العابر (اتصال/5xx) يرمي ⇒ إعادة محاولة
//  بتباعد؛ الفشل الدائم (4xx/مفتاح تالف) يوسم failed بلا رمي.
// ════════════════════════════════════════════════════════════

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // تباعد تصاعدي بين المحاولات (ثوانٍ): دقيقة ثم خمس
    public array $backoff = [60, 300];

    public function __construct(public int $smsLogId)
    {
    }

    public function handle(CommunicationService $comm): void
    {
        $log = SmsLog::find($this->smsLogId);

        // السجلّ حُذف، أو سُلّم سابقاً (إعادة محاولة بعد نجاح) — لا تُعد الإرسال
        if ($log === null || $log->status === 'sent') {
            return;
        }

        $comm->deliverPendingSms($log); // يرمي على الفشل العابر ⇒ يعيد الطابور المحاولة
    }

    // بعد استنفاد كل المحاولات: السجلّ موسوم failed أصلاً من deliverPendingSms،
    // نكتفي بأثر تشغيلي يلتقطه الرصد (failed_jobs + هذا السطر).
    public function failed(\Throwable $e): void
    {
        Log::error("SendSmsJob failed permanently for sms_log {$this->smsLogId}: " . $e->getMessage());
    }
}
