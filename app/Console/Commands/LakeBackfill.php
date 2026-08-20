<?php

namespace App\Console\Commands;

use App\Models\FinalReport;
use App\Services\ReportSnapshotService;
use App\Support\LakeRef;
use App\Support\LakeSuppressed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════════════════
//  التعبئة التاريخية — كلُّ تقريرٍ قائمٍ إلى البحيرة
//
//  المسار نفسه الذي تسلكه الأحداث الحيّة: الظرف يُبنى بـ
//  ReportSnapshotService ويُكتب في صندوق الصادر، ثم يشحنه الشاحن.
//  مسارٌ ثانٍ منفصل كان سيُنتج حمولةً تختلف قليلاً عن الحيّة، ولا يُكتشف
//  الفرقُ إلا بعد أشهر حين لا يُطابق تقريرٌ قديم تقريراً جديداً.
//
//  تحذير مقصود: الظرف يُبنى بأبعاد اليوم لا بأبعاد يوم الاعتماد — فتلك
//  لم تُحفظ قَطّ. الحمولة تحمل بصماتِ الأبعاد الحالية، ويُعلَّم الحدث
//  report.backfilled لا report.approved تمييزاً له. هذا حدُّ ما يمكن
//  استرجاعُه بأمانة، وقولُه أصدق من إخفائه.
// ════════════════════════════════════════════════════════════════════════
class LakeBackfill extends Command
{
    protected $signature = 'kafaat:lake:backfill
        {--chunk=100 : عدد التقارير في الدفعة}
        {--dry-run : عدٌّ دون كتابة}';

    protected $description = 'تعبئة بحيرة التقارير بكل التقارير القائمة';

    public function handle(ReportSnapshotService $snapshots): int
    {
        if (!config('lake.enabled') && !$this->option('dry-run')) {
            $this->error('البحيرة معطّلة (LAKE_ENABLED=false). فعّلها أو استخدم --dry-run.');
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $total = FinalReport::count();
        $this->info("تقارير في المنصّة: {$total}" . ($dry ? '  [تجربة]' : ''));

        $written = 0; $suppressed = 0; $failed = 0; $skipped = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        FinalReport::with(['candidate.sector', 'assessment'])
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($reports) use (
                $snapshots, $dry, &$written, &$suppressed, &$failed, &$skipped, $bar
            ) {
                foreach ($reports as $report) {
                    $bar->advance();
                    try {
                        $payload = $snapshots->freeze($report, 'report.backfilled', $report->status);
                    } catch (LakeSuppressed $e) {
                        $suppressed++;   // مُصنَّف — لا يغادر، بالتصميم
                        continue;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->newLine();
                        $this->warn("تقرير {$report->id}: {$e->getMessage()}");
                        continue;
                    }

                    if ($dry) { $written++; continue; }

                    // لحظةُ الوقوع = updated_at لا now(): التاريخ يجب أن
                    // يهبط في شهره لا في اليوم الذي عُبِّئ فيه، وإلّا صار
                    // اتّجاهُ الاعتماد كلُّه عموداً واحداً في هذا الشهر.
                    $occurredAt = $report->updated_at ?? $report->created_at ?? now();

                    // مفتاحٌ اشتقاقيّ ثابت: إعادةُ التشغيل بعد فشلٍ جزئيّ
                    // — وهي الحالة الطبيعية لا الاستثناء — تُنتج المعرّف
                    // نفسه فتُمتصّ بدل أن تُضاعف كلَّ صفّ.
                    $uuid = LakeRef::eventUuid(sprintf('backfill:report:%d:%s',
                        $report->id, $occurredAt->format('Y-m-d\TH:i:s')));

                    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
                    $candidate = $report->candidate;

                    $ok = DB::table('report_lake_outbox')->insertOrIgnore([
                        'event_uuid' => $uuid,
                        'occurred_at' => $occurredAt,
                        'event_type' => 'report.backfilled',
                        'subject_type' => 'report',
                        'source_report_id' => $report->id,
                        'source_assessment_id' => $report->assessment_id,
                        'person_ref' => $candidate ? LakeRef::person($candidate->id) : null,
                        'participant_code' => config('lake.publish.participant_code')
                            ? $candidate?->participant_code : null,
                        'sector_id' => $candidate?->sector_id,
                        'classification' => $candidate?->classification ?? 'normal',
                        'degraded' => false,
                        'payload' => $json,
                        'payload_sha256' => hash('sha256', $json),
                        'payload_bytes' => strlen($json),
                        'created_at' => now(),
                    ]);

                    $ok ? $written++ : $skipped++;
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("كُتب: {$written}   محجوب (مُصنَّف): {$suppressed}   مكرَّر: {$skipped}   فشل: {$failed}");

        if (!$dry && $written > 0) {
            $this->line('التالي:  php artisan kafaat:lake:ship --batches=1000');
        }
        return self::SUCCESS;
    }
}
