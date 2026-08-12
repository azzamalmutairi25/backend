<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\DistributionItem;
use App\Models\DistributionProposal;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  التوزيع الأسبوعي: يوم الأحد يُقترَح توزيع الأسبوع القادم، ومسؤول
//  الجدولة يعتمده. الحدّ لكل مقيّم في اليوم — إعداد قابل للتغيير.
//
//  خطوتان منفصلتان عمداً:
//    propose  — يحسب توزيعاً عادلاً ويحفظه مسودّة (بلا جلسات بعد)
//    approve  — يعيد التحقق من كل بند حيّاً ثم يصنع الجلسات الناجية
//  الفجوة بين الأحد والاعتماد قد تُبطل بنوداً (مرشّح أُلغي، مقيّم عُطّل)،
//  فالاعتماد لا ينسخ المسودّة بل يبنيها من جديد على البيانات الحيّة.
// ════════════════════════════════════════════════════════════

class DistributionService
{
    private const WORK_DAYS = 5; // الأحد–الخميس

    public function dailyCap(): int
    {
        return max(1, (int) (Setting::find('distribution.daily_cap_per_evaluator')?->value ?? 5));
    }

    // الأحد القادم الذي يبدأ به الأسبوع الموزَّع (من أي يوم يُشغَّل)
    public function nextWeekStart(): Carbon
    {
        // Carbon: الأحد = 0. next(0) يعطي الأحد القادم دائماً، ولو كان اليوم أحداً
        return now()->startOfDay()->next(Carbon::SUNDAY);
    }

    // أيام العمل الخمسة للأسبوع القادم
    private function weekDays(Carbon $start): array
    {
        return array_map(fn ($i) => $start->copy()->addDays($i), range(0, self::WORK_DAYS - 1));
    }

    // ── الاقتراح: توزيع عادل بحدّ لكل مقيّم، محفوظ مسودّة ──
    // القيد الفريد على week_start يجعل ضغطتين متزامنتين تصطدم إحداهما بـ23505.
    public function propose(User $actor): DistributionProposal
    {
        $start = $this->nextWeekStart();
        $days = $this->weekDays($start);
        $cap = $this->dailyCap();

        // التصنيفات المسموحة للمُشغِّل: التوزيع الآلي يجب ألا يكشف مرشّحاً مصنّفاً
        // لمسؤول جدولة بلا CANDIDATE_VIEW_CLASSIFIED (يملك DISTRIBUTION_MANAGE وحدها)،
        // ولا يُسنده لمقيّم بلا تصريح. غير المصرَّح يُقصَر على 'normal'، ويُجدوَل
        // المصنّفون يدوياً بمقيّم صريح. يطابق Controller::allowedClassifications.
        $allowedClassifications = $actor->hasPermission(\App\Security\Permissions::CANDIDATE_VIEW_CLASSIFIED)
            ? ['normal', 'secret', 'top_secret']
            : ['normal'];

        return DB::transaction(function () use ($actor, $start, $days, $cap, $allowedClassifications) {
            $proposal = DistributionProposal::create([
                'week_start' => $start->toDateString(),
                'week_end' => end($days)->toDateString(),
                'daily_cap' => $cap,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);

            // مرشحون محجوزون في مسودّة توزيع أخرى (بلا جدولة/إسقاط بعد) — لا يُوزَّعون
            // مرتين لو اعتُمدت المسودّتان (سباق أسبوعين متتاليين لا يزالان مسودّة)
            $inOpenDraft = DistributionItem::whereHas('proposal', fn ($q) => $q->where('status', 'draft'))
                ->whereNull('schedule_id')->whereNull('drop_reason')
                ->pluck('candidate_id')->all();

            // المرشحون الجاهزون: معتمدون للتقييم بلا جلسة مقابلة في دورتهم الحالية
            $candidates = Candidate::with('sector')
                ->where('status', 'scheduled')
                ->whereIn('classification', $allowedClassifications)
                ->whereDoesntHave('assessments.schedules', fn ($q) => $q->where('activity', 'interview'))
                ->when(!empty($inOpenDraft), fn ($q) => $q->whereNotIn('id', $inOpenDraft))
                ->orderBy('sector_id')->orderBy('id')
                ->get()
                ->groupBy('sector_id');

            // جلسات المقابلة القائمة على أيام الأسبوع (يدوية) — تُخصَم من سعة كل مقيّم/يوم
            $weekDates = array_map(fn ($d) => $d->toDateString(), $days);
            $existing = Schedule::where('activity', 'interview')
                ->whereIn('schedule_date', $weekDates)
                ->whereNotNull('evaluator_id')
                ->get(['evaluator_id', 'schedule_date'])
                ->groupBy(fn ($s) => $s->evaluator_id . '|' . substr((string) $s->schedule_date, 0, 10))
                ->map->count();

            foreach ($candidates as $sectorId => $sectorCandidates) {
                // مقيّمو هذا القطاع الفعّالون
                $evaluators = User::whereHas('role', fn ($q) => $q->where('code', 'EVALUATOR'))
                    ->where('is_active', true)
                    ->where('sector_id', $sectorId)
                    ->pluck('id')->values();

                if ($evaluators->isEmpty()) {
                    continue; // قطاع بلا مقيّم — يُترك، ويظهر في «غير موزّعين»
                }

                // سعة الأسبوع = مقيّمون × أيام × حدّ (ناقص القائم). الزائد يُترك للأسبوع التالي.
                $this->placeSector($proposal, $sectorId, $sectorCandidates->values(), $evaluators, $days, $cap, $existing);
            }

            return $proposal;
        });
    }

    // يوزّع مرشّحي قطاع على مقيّميه وأيامه، بحدّ لكل مقيّم في اليوم.
    // خوارزمية دوّارة: يملأ (يوم، مقيّم) واحداً واحداً حتى ينفد الحدّ أو المرشحون.
    private function placeSector($proposal, int $sectorId, $candidates, $evaluators, array $days, int $cap, $existing = null): void
    {
        $ci = 0;
        $total = $candidates->count();

        foreach ($days as $day) {
            $dateStr = $day->toDateString();
            foreach ($evaluators as $evaluatorId) {
                // نبدأ من عدد الجلسات القائمة لهذا المقيّم في هذا اليوم كي لا يُتجاوَز الحدّ
                $used = $existing["$evaluatorId|$dateStr"] ?? 0;
                for ($slot = $used; $slot < $cap; $slot++) {
                    if ($ci >= $total) {
                        return; // نفد مرشحو القطاع
                    }
                    $c = $candidates[$ci++];
                    DistributionItem::create([
                        'proposal_id' => $proposal->id,
                        'candidate_id' => $c->id,
                        'evaluator_id' => $evaluatorId,
                        'sector_id' => $sectorId,
                        'scheduled_date' => $day->toDateString(),
                        'activity' => 'interview',
                    ]);
                }
            }
        }
        // ما تبقّى (ci < total) يُترك للأسبوع التالي — لا يُوضع فوق الحدّ
    }

    // ── الاعتماد: إعادة تحقّق كل بند حيّاً ثم صنع الجلسات الناجية ──
    // لا ينسخ المسودّة: يعيد فحص الحالة والقطاع وتفعيل المقيّم على البيانات الحيّة،
    // ويُسقط ما بطل بدل جدولته. تحت قفل الصف فالاعتماد المزدوج لا يُنشئ جلسات مرتين.
    public function approve(DistributionProposal $proposal, User $actor): array
    {
        return DB::transaction(function () use ($proposal, $actor) {
            // إعادة قراءة تحت القفل — اعتماد ثانٍ متزامن ينتظر ثم يجد الحالة approved
            $locked = DistributionProposal::whereKey($proposal->id)->lockForUpdate()->first();
            if ($locked->status !== 'draft') {
                return ['placed' => $locked->placed, 'dropped' => $locked->dropped, 'alreadyDone' => true];
            }

            $placed = 0;
            $dropped = 0;

            foreach ($locked->items()->with('candidate')->get() as $item) {
                // قفل صفّ المرشّح: يسلسل اعتمادين متزامنين لمرشّح واحد، فالثاني يرى
                // جلسته أُنشئت ويُسقطه بدل جدولته مرتين (فحص «جُدوِل» يصير تحت القفل)
                if ($item->candidate_id) {
                    Candidate::whereKey($item->candidate_id)->lockForUpdate()->first();
                }
                $reason = $this->revalidate($item);
                if ($reason) {
                    $item->update(['drop_reason' => $reason]);
                    $dropped++;
                    continue;
                }

                // الدورة النشطة تُعاد resolution حيّاً — قد تكون تغيّرت منذ الاقتراح
                $assessment = $item->candidate->assessments()
                    ->where('status', '!=', 'completed')->orderByDesc('id')->first();

                $schedule = Schedule::create([
                    'candidate_id' => $item->candidate_id,
                    'assessment_id' => $assessment->id,
                    'schedule_date' => $item->scheduled_date,
                    'activity' => $item->activity,
                    'evaluator_id' => $item->evaluator_id,
                ]);
                $item->update(['schedule_id' => $schedule->id]);
                $placed++;
            }

            $locked->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'placed' => $placed,
                'dropped' => $dropped,
            ]);

            return ['placed' => $placed, 'dropped' => $dropped, 'alreadyDone' => false];
        });
    }

    // إعادة التحقق من بند: يرجع سبب الإسقاط أو null إن كان سليماً.
    // تُعالَج كل الحالات التي قد تكون رقّت منذ الاقتراح.
    private function revalidate(DistributionItem $item): ?string
    {
        $c = $item->candidate;
        if (!$c) {
            return 'حُذف المرشّح';
        }
        // الحالة: قد يكون أُلغي أو أُعيد تقييمه أو اكتمل
        if ($c->status !== 'scheduled') {
            return 'تغيّرت حالة المرشّح';
        }
        // قطاع المرشّح قد يكون تغيّر بعد الاقتراح
        if ($c->sector_id !== $item->sector_id) {
            return 'تغيّر قطاع المرشّح';
        }
        // المقيّم: قد يكون عُطّل أو نُقل قطاعه (exists لا يكفي — is_active مطلوب)
        $ev = User::where('id', $item->evaluator_id)
            ->where('is_active', true)->where('sector_id', $item->sector_id)->first();
        if (!$ev) {
            return 'المقيّم غير متاح';
        }
        // دورة نشطة قائمة
        $hasActive = $c->assessments()->where('status', '!=', 'completed')->exists();
        if (!$hasActive) {
            return 'لا دورة نشطة';
        }
        // لا يُجدوَل مرتين: قد تكون جلسة مقابلة أُنشئت يدوياً منذ الاقتراح
        $already = Schedule::where('candidate_id', $c->id)->where('activity', 'interview')->exists();
        if ($already) {
            return 'جُدوِل يدوياً';
        }
        return null;
    }
}
