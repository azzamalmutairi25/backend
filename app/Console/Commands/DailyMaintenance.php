<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\FinalReport;
use App\Models\Schedule;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// صيانة يومية آلية: كشف الغياب، تذكير جلسات الغد، تصعيد التقارير المتأخرة
class DailyMaintenance extends Command
{
    protected $signature = 'kafaat:daily {--days=3 : أيام تجاوز مهلة اعتماد التقرير قبل التصعيد} {--noshow-days=7 : نافذة الأيام رجوعاً لكشف الغياب التلقائي}';
    protected $description = 'صيانة يومية: كشف الغياب التلقائي، تذكير جلسات الغد، وتصعيد التقارير المتأخرة';

    public function handle(NotificationService $notify): int
    {
        $today = now()->toDateString();

        // 1) كشف الغياب التلقائي ضمن نافذة محدودة (تفادي وسم كل الجلسات التاريخية غياباً عند أول تشغيل)
        $noShowFrom = now()->subDays((int) $this->option('noshow-days'))->toDateString();
        $noShowIds = Schedule::whereDate('schedule_date', '>=', $noShowFrom)
            ->whereDate('schedule_date', '<', $today)
            ->whereDoesntHave('attendance')->pluck('id');
        $noShows = 0;
        foreach ($noShowIds as $sid) {
            $inserted = Attendance::insertOrIgnore([
                'schedule_id' => $sid,
                'status' => 'absent_unexcused',
                'absence_reason' => 'غياب تلقائي — لم يُسجَّل حضور للجلسة',
                'recorded_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $noShows += $inserted;
        }

        // 2) تذكير جلسات الغد للمُقيّمين المُسندين
        $tomorrow = now()->addDay()->toDateString();
        $reminders = 0;
        foreach (Schedule::whereDate('schedule_date', $tomorrow)->whereNotNull('evaluator_id')
                     ->with('candidate')->get() as $s) {
            $notify->notify($s->evaluator_id, 'info', 'تذكير: جلسة غداً',
                "لديك جلسة {$s->activity} غداً — المشارك {$s->candidate->participant_code}",
                'schedule', (string) $s->id, null);
            $reminders++;
        }

        // 3) تصعيد التقارير المتأخرة (بانتظار الاعتماد أطول من المهلة)
        $cutoff = now()->subDays((int) $this->option('days'));
        $escalated = 0;
        // مرّة واحدة لكل حالة تأخّر (whereNull escalated_at) — يمنع إعادة إشعار DEV_MANAGER يومياً بلا حدّ
        foreach (FinalReport::where('status', 'pending_dev_approval')
                     ->where('updated_at', '<', $cutoff)->whereNull('escalated_at')
                     ->with('candidate')->get() as $r) {
            $notify->notifyRole('DEV_MANAGER', 'approval', 'تقرير متأخر بانتظار الاعتماد',
                "تجاوز تقرير المشارك {$r->candidate->participant_code} مهلة الاعتماد — يرجى المراجعة",
                'report', (string) $r->id, null);
            // طابع التصعيد دون لمس updated_at (query builder لا يشغّل طوابع Eloquent)
            DB::table('final_reports')->where('id', $r->id)->update(['escalated_at' => now()]);
            $escalated++;
        }

        $this->info("noShows={$noShows} reminders={$reminders} escalated={$escalated}");

        return self::SUCCESS;
    }
}
