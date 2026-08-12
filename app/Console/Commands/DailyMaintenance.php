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

        // 3) تصعيد التقارير المتأخرة في أي مرحلة اعتماد
        // كل مرحلة تُصعَّد لصاحبها هو — تقرير عالق عند المقيّم لا يفيد إشعار تطوير الكفاءات به
        $cutoff = now()->subDays((int) $this->option('days'));
        $escalated = 0;
        $owners = [
            'pending_evaluator' => 'EVALUATOR',
            'pending_manager' => 'ASSESS_MANAGER',
            'pending_dev_approval' => 'DEV_MANAGER',
        ];
        // مرّة واحدة لكل حالة تأخّر (whereNull escalated_at) — يمنع إعادة الإشعار يومياً بلا حدّ
        foreach (FinalReport::whereIn('status', array_keys($owners))
                     ->where('updated_at', '<', $cutoff)->whereNull('escalated_at')
                     ->with('candidate')->get() as $r) {
            $notify->notifyRole($owners[$r->status], 'approval', 'تقرير متأخر بانتظار الاعتماد',
                "تجاوز تقرير المشارك {$r->candidate->participant_code} مهلة الاعتماد — يرجى المراجعة",
                'report', (string) $r->id, null);
            // طابع التصعيد دون لمس updated_at (query builder لا يشغّل طوابع Eloquent)
            DB::table('final_reports')->where('id', $r->id)->update(['escalated_at' => now()]);
            $escalated++;
        }

        // 4) التقرير اليومي بالبريد لمدير المركز
        $mailed = $this->mailDailyReport($today);

        $this->info("noShows={$noShows} reminders={$reminders} escalated={$escalated} dailyReport={$mailed}");

        return self::SUCCESS;
    }

    // يرسل تقرير اليوم لكل من يحمل دور مدير المركز وله بريد.
    // يُبنى من نفس الخدمة التي تعرضه في الصفحة، فلا يتباعد المُرسَل عن المعروض.
    private function mailDailyReport(string $today): int
    {
        $recipients = \App\Models\User::whereHas('role', fn ($q) => $q->where('code', 'CENTER_MANAGER'))
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $service = app(\App\Services\DailyReportService::class);
        $comm = app(\App\Services\CommunicationService::class);
        $html = $service->renderHtml($service->gather($today));

        $sent = 0;
        foreach ($recipients as $u) {
            // البريد نصّي في هذا النظام؛ نُرسل ملخّصاً ورابط العرض للتفاصيل الكاملة
            $summary = "التقرير اليومي — {$today}. افتح النظام لعرض التفاصيل والطباعة.";
            if ($comm->sendEmail($u->email, $u->full_name, "التقرير اليومي — {$today}", $summary, 'notification', null, null)) {
                $sent++;
            }
        }

        return $sent;
    }
}
