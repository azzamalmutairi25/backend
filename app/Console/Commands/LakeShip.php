<?php

namespace App\Console\Commands;

use App\Services\LakeShipper;
use App\Support\LakeEmitter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// يشحن صندوق الصادر إلى بحيرة التقارير. يُشغَّل من المجدول كل دقيقة.
class LakeShip extends Command
{
    protected $signature = 'kafaat:lake:ship {--batches=20 : أقصى عدد دفعاتٍ في التشغيلة}';

    protected $description = 'شحن أحداث التقارير المُعلَّقة إلى بحيرة التقارير';

    public function handle(LakeShipper $shipper): int
    {
        if (! config('lake.enabled')) {
            $this->line('البحيرة معطّلة (LAKE_ENABLED=false) — لا شيء يُشحن.');

            return self::SUCCESS;
        }

        $r = $shipper->drain((int) $this->option('batches'));

        $this->info(sprintf(
            'دفعات: %d — مطلوب: %d، هبط: %d، أُسقط: %d، مُعزَل: %d',
            $r['batches'], $r['claimed'], $r['landed'], $r['projected'], $r['quarantined']));

        // التراكم فوق الحدّ يعني أن البحيرة متوقّفة — تنبيهٌ واحدٌ للتشغيلة
        // كلِّها لا واحدٌ لكل صفّ، وإلّا أغرق التنبيهُ السجلَّ الذي يُقرأ.
        $backlog = app(LakeEmitter::class)->backlog();
        $alarm = (int) config('lake.backlog_alarm', 5000);
        if ($backlog > $alarm) {
            Log::error('lake: تراكمٌ فوق الحدّ في صندوق الصادر', [
                'backlog' => $backlog, 'alarm' => $alarm,
            ]);
            $this->error("تحذير: {$backlog} حدثاً معلّقاً (الحدّ {$alarm}).");
        }

        return self::SUCCESS;
    }
}
