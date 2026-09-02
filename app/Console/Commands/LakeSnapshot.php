<?php

namespace App\Console\Commands;

use App\Services\DailyReportService;
use App\Services\ExecutiveAnalyticsService;
use App\Support\LakeEmitter;
use Illuminate\Console\Command;

// ════════════════════════════════════════════════════════════════════════
//  لقطاتُ ما لا يُخزَّن
//
//  التقرير اليومي وكلُّ مخرجات /api/analytics/* تُحسب حيّةً عند كل طلب
//  ولا يُحفظ منها شيء. فمن دون لقطةٍ مؤرَّخة تصير لوحاتُ الماضي غيرَ
//  قابلةٍ لإعادة البناء أصلاً — لأنها تقرأ أعمدةَ حالةٍ راهنةٍ متغيّرة.
//
//  والتقرير اليومي أشدُّها: DailyReportService::gather يختار التقارير بـ
//  created_at أو updated_at = التاريخ، فإعادةُ تشغيله لتاريخٍ مضى تُعطي
//  جواباً مختلفاً عمّا أعطى يومَها. يُلتقط في يومه أو يُفقد إلى الأبد.
//
//  ولأن المالك اختار المجهوليّة الكاملة، لا يُنقل من التقرير اليومي إلا
//  الأعداد: رموزُ المشاركين تُسقَط، وأسبابُ الغياب تُسقَط — وقد تكون
//  طبّية، وهي أحسّ ما في المستند كلِّه.
// ════════════════════════════════════════════════════════════════════════
class LakeSnapshot extends Command
{
    protected $signature = 'kafaat:lake:snapshot
        {--date= : تاريخ التقرير اليومي (افتراضيّاً اليوم)}
        {--skip-analytics : الاكتفاء باللقطة اليومية}';

    protected $description = 'التقاط التقرير اليومي والتحليلات التنفيذية إلى بحيرة التقارير';

    public function handle(LakeEmitter $lake, DailyReportService $daily, ExecutiveAnalyticsService $exec): int
    {
        if (! config('lake.enabled')) {
            $this->line('البحيرة معطّلة — لا لقطات.');

            return self::SUCCESS;
        }

        // التصنيف المسموح وحده — في كلّ نداء. اللقطة تجميعٌ، والتجميعُ
        // على مجموعةٍ تضمّ مُصنَّفين يُسرّبهم عدداً وإن لم يُسمّهم.
        $allowed = (array) config('lake.classifications', ['normal']);
        $date = $this->option('date') ?: now()->toDateString();

        // ── اللقطة اليومية ──
        $g = $daily->gather($date, $allowed);
        $t = $g['totals'] ?? [];

        $lake->snapshot('daily.snapshot', "daily:{$date}", [
            'report_date' => $date,
            'totals' => [
                'sessions' => $t['sessions'] ?? 0,
                'present' => $t['present'] ?? 0,
                // gather يدمج «بعذر» و«بدون عذر» في absent، ويُبقي pending
                // منفصلاً. يُنقل كما هو بدل أن يُخترع تفصيلٌ لا يملكه.
                'absent' => $t['absent'] ?? 0,
                'excused' => null,
                'pending' => $t['pending'] ?? 0,
                'reports_created' => count($g['reports'] ?? []),
                'reports_approved' => collect($g['reports'] ?? [])
                    ->where('status', 'معتمَد')->count(),
                'scored_sessions' => count($g['scores'] ?? []),
            ],
            // التوزيع بالقطاع دون رمزِ مشاركٍ واحد.
            'by_sector' => collect($g['presence'] ?? [])
                ->groupBy('sector')->map->count()->all(),
        ]);
        $this->info("لقطة يومية: {$date}");

        if ($this->option('skip-analytics')) {
            return self::SUCCESS;
        }

        // ── التحليلات التنفيذية ──
        // تُحفظ كما تُخرجها الخدمة: أرقامٌ ومؤشراتٌ مُجمَّعة أصلاً، بلا
        // أسماء. هي عين ما تعرضه شاشةُ الإدارة اليوم، مؤرَّخةً.
        foreach ([
            'executive' => fn () => $exec->executive($allowed),
            'platform_overview' => fn () => $exec->platformOverview($allowed),
        ] as $kind => $build) {
            try {
                $lake->snapshot('analytics.snapshot', "analytics:{$kind}:{$date}", [
                    'snapshot_date' => $date,
                    'kind' => $kind,
                    'data' => $build(),
                ]);
                $this->info("لقطة تحليلات: {$kind}");
            } catch (\Throwable $e) {
                // لقطةٌ تعذّرت لا تُسقط البقيّة.
                $this->warn("تعذّرت لقطة {$kind}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
