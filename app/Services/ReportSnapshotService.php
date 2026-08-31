<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Competency;
use App\Models\DevelopmentPlanItem;
use App\Models\Evaluation;
use App\Models\FinalReport;
use App\Models\MeasurementResult;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\WorkflowStage;
use App\Support\LakeRef;
use App\Support\LakeSuppressed;

// ════════════════════════════════════════════════════════════════════════
//  بناء ظرف التقرير المُجمَّد
//
//  المشكلة التي تحلّها هذه الخدمة: جسدُ التقرير غيرُ موجودٍ في القاعدة.
//  final_reports يحفظ الترويسة فقط (التوافق، التوصية، النصّ)، أمّا تفصيلُ
//  الكفاءات فيُعاد حسابُه عند كل عرضٍ من evaluation_scores × competencies
//  (ScoringService::computeFit). و competencies.weight / max_level /
//  target_* قابلةٌ للتحرير من الشاشة — فالتقرير نفسُه يُنتج أرقاماً
//  مختلفةً قبل التعديل وبعده. ScoringService نفسه يشهد بذلك: يقصر النسبة
//  على ١٠٠ تحسّباً لأن يكون max_level قد خُفّض بعد الرصد.
//
//  لذلك لا تُنسخ الصفوف: تُجمَّد الحسبةُ لحظةَ وقوع الحدث، ومعها إصداراتُ
//  الأبعاد التي حُسبت بها. هذا هو الفرق بين أرشيفٍ يصمد وبين مرآةٍ تنجرف.
//
//  ولا شيء من هذا يُعدّل سلوكاً قائماً: الخدمة تقرأ ولا تكتب في المنصّة.
// ════════════════════════════════════════════════════════════════════════

class ReportSnapshotService
{
    public function __construct(private ScoringService $scoring) {}

    /**
     * يبني ظرف الحدث كاملاً.
     *
     * @param  string  $toStatus  الحالة المقصودة بعد الانتقال — تُمرَّر صراحةً
     *   ولا تُقرأ من النموذج: عند الاعتماد يُستدعى البناء والنموذجُ قد لا
     *   يكون قد زُوملت حالتُه بعد، فكانت البحيرة ستُسجّل الحالة السابقة
     *   دائماً — أي أن «معتمَد» لا يظهر فيها أبداً.
     * @throws LakeSuppressed إن كان المشارك مُصنَّفاً.
     */
    public function freeze(FinalReport $report, string $eventType, ?string $toStatus = null, array $ctx = []): array
    {
        $candidate = $report->candidate;
        if (!$candidate) {
            throw new LakeSuppressed('تقريرٌ بلا مشارك — لا يُصدَّر');
        }

        // ── البوّابة الأولى: التصنيف ──
        // المنصّة تُنكر وجود الصفّ المُصنَّف أصلاً (٤٠٤ لا ٤٠٣). تصديرُه
        // إلى قاعدةٍ يقرؤها طرفٌ ثالث نقضٌ للضابط نفسه، فيُمنع هنا قبل
        // أن تُبنى الحمولة — لا بعدها.
        $allowed = (array) config('lake.classifications', ['normal']);
        if (!in_array($candidate->classification ?? 'normal', $allowed, true)) {
            throw new LakeSuppressed('مشاركٌ مُصنَّف — لا يغادر القاعدة الأساسية');
        }

        $assessment = $report->assessment;
        $status = $toStatus ?? $report->status;
        $tier = $candidate->tier ?: 'middle';

        $payload = [
            'contract_version' => config('lake.contract_version'),
            'event_type' => $eventType,

            // ── الموضوع: مؤشراتٌ وقطاعات، بلا اسمٍ ولا هويّة ──
            'subject' => array_filter([
                'sector_id' => $candidate->sector_id,
                'sector_name_ar' => $candidate->sector?->name_ar,
                'sector_code' => $candidate->sector?->code,
                'rank_label' => $candidate->rank_label,
                'tier' => $tier,
                'gender' => $candidate->gender,
                'personnel_category' => $candidate->personnel_category,
                'assessment_type' => $candidate->assessment_type,
            ], fn ($v) => $v !== null),

            'report' => [
                'status' => $status,
                'recommendation' => $report->recommendation,
                'behavioral_fit' => $report->behavioral_fit,
                'technical_fit' => $report->technical_fit,
                'return_count' => $report->return_count,
                'created_at' => optional($report->created_at)->toIso8601String(),
            ] + $this->approvalStamp($report, $eventType, $status),

            'dimensions' => $this->dimensions(),
        ];

        // ── الحسبة المُجمَّدة ──
        if ($assessment) {
            $fit = $this->scoring->computeFit($assessment);
            $payload['report']['overall_fit'] = $fit['overallFit'];
            $payload['report']['competencies_scored'] = $fit['competenciesScored'];
            $payload['report']['evaluations_count'] = $fit['evaluationsCount'];
            $payload['breakdown'] = $this->breakdown($fit['breakdown'], $tier);
            $payload['measurements'] = $this->measurements($assessment->id);
            $payload['activities'] = $this->activities($assessment->id);
        }

        $payload['development_plan'] = $this->developmentPlan($report, $candidate);

        // ── السرد: خلف مفتاحٍ صريح ──
        // مُعطَّل بقرار المالك (مجهوليّة كاملة). حتى حين يُفعَّل يمرّ على
        // CvGuard الذي يُطبّع العربية ويلتقط الأسماء المُتباعدة الحروف —
        // ومع ذلك يبقى خلف دورِ قراءةٍ منفصل، لأن CvGuard نفسه يُقرّ بأن
        // شبهَ المُعرِّف ينجو من التنقية.
        if (config('lake.publish.narrative')) {
            $payload['narrative'] = $this->narrative($report, $candidate);
        }

        return $payload;
    }

    /**
     * لحظةُ الاعتماد.
     *
     * الحدثُ الحيّ يعرفها يقيناً — هي لحظةُ وقوعه. أمّا التعبئة التاريخية
     * فلا تملك إلا updated_at، وهو ما تستنتج به المنصّةُ نفسُها شهرَ
     * الاعتماد (AnalyticsController.php:192) — ويَنجرف كلّما حُرِّر تقريرٌ
     * بعد اعتماده.
     *
     * فيُختم الاستنتاجُ بعلامته: approved_at_inferred. إخفاءُ الفرق كان
     * يجعل الرقمَ يبدو مقطوعاً به وهو مُستنتَج، والمستهلكُ لا يملك ما
     * يكشف به ذلك.
     */
    private function approvalStamp(FinalReport $report, string $eventType, string $status): array
    {
        if ($status !== WorkflowStage::FINAL_STATUS) {
            return [];
        }
        if ($eventType === 'report.approved') {
            return ['approved_at' => now()->toIso8601String(), 'approved_at_inferred' => false];
        }
        return [
            'approved_at' => optional($report->updated_at)->toIso8601String(),
            'approved_at_inferred' => true,
        ];
    }

    /** تفصيل الكفاءات + الفجوة، مدموجَين بمعرّف الكفاءة لا باسمها. */
    private function breakdown(array $fitBreakdown, string $tier): array
    {
        // ScoringService::computeGap يُخرج اسم الكفاءة ولا يُخرج معرّفها،
        // فدمجُه بالاسم كان سيصير وصلةً تنجرف بصمت أوّلَ ما يُحرَّر اسم.
        // تُقرأ الأهداف من الجدول مباشرةً بدل استدعائه — فلا يُعدَّل
        // ScoringService ولا تُفقد الدقّة.
        $targetCol = $tier === 'upper' ? 'target_upper' : 'target_middle';
        $targets = Competency::whereNotNull($targetCol)->pluck($targetCol, 'id');

        $out = [];
        foreach ($fitBreakdown as $b) {
            $cid = $b['competencyId'] ?? null;
            $target = $cid !== null ? $targets->get($cid) : null;
            $achieved = $b['avgScore'] ?? null;

            $out[] = [
                'competency_id' => $cid,
                'name_ar' => $b['name'] ?? null,
                'type' => $b['type'] ?? null,
                // السلوكية تُجمَّع بـ group والفنّية بـ domain — يُوحَّدان
                // في عمودٍ واحد فيقرأ المستهلك تصنيفاً واحداً لا اثنين.
                'group_domain' => $b['group'] ?? $b['domain'] ?? null,
                'weight' => $b['weight'] ?? null,
                'max_level' => $b['maxLevel'] ?? null,
                'avg_score' => $achieved,
                'pct' => $b['pct'] ?? null,
                'target_level' => $target,
                'gap' => ($target !== null && $achieved !== null) ? round($achieved - $target, 2) : null,
                'met' => ($target !== null && $achieved !== null) ? $achieved >= $target : null,
            ];
        }
        return $out;
    }

    /** أدوات القياس الثلاث كصفوفٍ عامّة — تُضاف رابعةٌ يوماً بلا هجرة. */
    private function measurements(int $assessmentId): array
    {
        $m = MeasurementResult::where('assessment_id', $assessmentId)->orderByDesc('id')->first();
        if (!$m) return [];

        $out = [];
        foreach ([
            'personality' => $m->personality_score,
            'analytical' => $m->analytical_score,
            'english' => $m->english_score,
        ] as $tool => $score) {
            if ($score === null) continue;
            $out[] = ['tool_code' => $tool, 'scale_code' => null, 'score' => (float) $score, 'band' => null];
        }
        return $out;
    }

    /** الجدولة والحضور وحالة التقييم، نشاطاً نشاطاً. */
    private function activities(int $assessmentId): array
    {
        $schedules = Schedule::where('assessment_id', $assessmentId)
            ->orderBy('schedule_date')->orderBy('schedule_time')->get();

        $attendance = Attendance::whereIn('schedule_id', $schedules->pluck('id'))
            ->get()->keyBy('schedule_id');

        $evalStatus = Evaluation::where('assessment_id', $assessmentId)
            ->pluck('status', 'activity');

        return $schedules->map(fn ($s) => [
            'activity_code' => $s->activity,
            'scheduled_date' => optional($s->schedule_date)->format('Y-m-d'),
            // الوقت لا التوقيت الدقيق: الفترة تكفي للتحليل ولا تُضيّق
            // مجموعةَ من حضر في ساعةٍ بعينها.
            'session_slot' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : null,
            'attendance_code' => $attendance->get($s->id)?->status,
            'evaluation_status' => $evalStatus->get($s->activity),
        ])->all();
    }

    /**
     * بنود خطة التطوير.
     * النصّ يُنقّى دائماً — لا حين يُنشر السرد فقط — لأن البند يُكتب بلغةٍ
     * حرّة وقد يحمل اسماً. وإن كان النشر معطّلاً يُرسَل العدد وحده.
     */
    private function developmentPlan(FinalReport $report, $candidate): array
    {
        $items = DevelopmentPlanItem::where('assessment_id', $report->assessment_id)
            ->orderBy('id')->get();

        if (!config('lake.publish.narrative')) {
            return $items->map(fn ($i) => ['area' => null, 'action' => null, 'priority' => $i->status])->all();
        }

        return $items->map(function ($i) use ($candidate) {
            $clean = \App\Services\CvGuard::scrub(
                ['area' => (string) $i->area, 'action' => (string) $i->action], $candidate);
            return [
                'area' => $clean['area'] ?? null,
                'action' => $clean['action'] ?? null,
                'priority' => $i->status,
            ];
        })->all();
    }

    private function narrative(FinalReport $report, $candidate): array
    {
        $doc = [
            'overview' => (string) $report->overview_text,
            'executive_summary' => (string) $report->executive_summary,
            'strengths' => array_map('strval', (array) $report->strengths),
            'development_areas' => array_map('strval', (array) $report->development_areas),
        ];
        return \App\Services\CvGuard::scrub($doc, $candidate);
    }

    /**
     * إصدارات الأبعاد وقت التجميد.
     * بها وحدها يُفهم لاحقاً رقمٌ حُسب قبل أن تُحرَّر الكفاءاتُ أو تُعاد
     * ترتيبُ سلسلة الاعتماد أو تُبدَّل حدودُ الشرائح. بدونها يبقى الرقم
     * صحيحاً ولا يبقى مفهوماً.
     */
    private function dimensions(): array
    {
        $stages = WorkflowStage::chain()->map(fn ($s) => [
            'status_key' => $s->status_key,
            'position' => $s->position,
            'label_ar' => $s->label,
            'role_code' => $s->role_code,
            'is_active' => (bool) $s->is_active,
        ])->values()->all();

        return [
            'competency_version' => $this->fingerprint(
                Competency::orderBy('id')->get(['id', 'weight', 'max_level', 'target_upper', 'target_middle'])->toJson()),
            'workflow_version' => $this->fingerprint(json_encode($stages)),
            'settings_version' => $this->fingerprint(
                (string) (Setting::find('tier.military_upper_ranks')?->value)
                . '|' . (string) (Setting::find('tier.civilian_upper_grade')?->value)),
            'workflow_stages' => $stages,
        ];
    }

    private function fingerprint(string $s): string
    {
        return substr(hash('sha256', $s), 0, 16);
    }

    /** ظرفٌ هيكليّ حين يتعذّر البناء الكامل — يُحفظ الحدث ولا يُفقد. */
    public function skeleton(FinalReport $report, string $eventType, ?string $toStatus, string $why): array
    {
        return [
            'contract_version' => config('lake.contract_version'),
            'event_type' => $eventType,
            'degraded' => true,
            'degraded_reason' => $why,
            'report' => ['status' => $toStatus ?? $report->status],
        ];
    }
}
