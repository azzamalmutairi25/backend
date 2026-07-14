<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\Candidate;
use App\Models\Competency;
use App\Models\DevelopmentPlanItem;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\FinalReport;
use App\Models\MeasurementResult;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  بيانات عرض واقعية عبر دورة حياة المرشّح كاملة.
//
//  تُبنى كل حالة بعمقها الصحيح لا بحقول عشوائية: المجدول له جدول
//  وحضور معلّق، والمُقيَّم له درجات ونتائج قياس، والمعتمد له تقرير،
//  والمكتمل له خطة تطوير. أي شيء غير ذلك ينتج لوحات تبدو مليئة
//  وتنهار عند أول نقرة.
//
//  إعادة التشغيل آمنة: تُزال بيانات العرض السابقة أولاً (رموز DM-*).
//  php artisan db:seed --class=DemoDataSeeder
// ════════════════════════════════════════════════════════════

class DemoDataSeeder extends Seeder
{
    private const CODE_PREFIX = 'DM';

    // رتب عسكرية: عميد فما فوق = فئة عليا (حسب Candidate::classifyTier)
    private const MILITARY_UPPER = ['عميد', 'لواء', 'فريق'];
    private const MILITARY_MID = ['عقيد', 'مقدم', 'رائد'];
    // مدني: م-13 فأعلى = فئة عليا
    private const CIVIL_UPPER = ['م-15', 'م-14', 'م-13'];
    private const CIVIL_MID = ['م-12', 'م-11', 'م-10'];

    private const FIRST = ['عبدالله','محمد','خالد','سعد','فهد','ناصر','بندر','ماجد','سلطان','تركي',
        'عبدالعزيز','مشعل','ريان','ياسر','وليد','هاني','طلال','بدر','منصور','عمر',
        'نورة','سارة','هيفاء','منال','لطيفة','أمل','ريم','دانة','الجوهرة','مها'];
    private const LAST = ['القحطاني','العتيبي','الغامدي','الشهري','الحربي','الدوسري','الزهراني','المطيري',
        'السبيعي','الشمري','البقمي','الرشيدي','العنزي','الخالدي','الأحمدي','السهلي'];

    private const RECOMMENDATIONS = [
        'مرشّح قوي — جاهز للتكليف القيادي',
        'مرشّح مناسب — يحتاج تطويراً محدوداً',
        'مرشّح واعد — يحتاج برنامج تطوير',
        'غير مناسب للمستوى المطلوب حالياً',
    ];

    private const STRENGTHS = ['قيادة الفرق','التواصل الفعّال','التفكير الاستراتيجي','صنع القرار تحت الضغط',
        'بناء العلاقات','المرونة والتكيّف','التخطيط بعيد المدى','إدارة الأزمات'];
    private const DEV_AREAS = ['التفويض الفعّال','إدارة الوقت','التغذية الراجعة','التفكير التحليلي',
        'إدارة التغيير','الحضور القيادي'];

    private const DEV_ACTIONS = [
        'برنامج تدريبي متخصص (٥ أيام)',
        'إرشاد مهني (mentoring) لمدة ٦ أشهر',
        'تكليف بمشروع تحسيني بإشراف قيادي',
        'ورشة عملية + متابعة ربع سنوية',
    ];

    private int $seq = 0;

    public function run(): void
    {
        $sectors = Sector::all()->keyBy('code');
        if ($sectors->isEmpty()) {
            $this->command->error('لا توجد قطاعات — شغّل DatabaseSeeder أولاً');
            return;
        }

        $evaluators = $this->usersByRole(['EVALUATOR', 'DISCUSSION_EVAL']);
        $managers = $this->usersByRole(['ASSESS_MANAGER', 'CENTER_MANAGER', 'ADMIN']);
        $assistants = $this->usersByRole(['ASSISTANT', 'RECEPTIONIST']);
        $devManagers = $this->usersByRole(['DEV_MANAGER', 'ADMIN']);

        if ($evaluators->isEmpty() || $managers->isEmpty()) {
            $this->command->error('لا يوجد مقيّمون/مديرون — شغّل DatabaseSeeder أولاً');
            return;
        }

        $this->purge();

        // التوزيع يعكس قمعاً واقعياً: كثيرون في الأعلى، قليلون أنهوا الرحلة
        $plan = [
            ['status' => 'draft', 'count' => 4],
            ['status' => 'scheduled', 'count' => 6],
            ['status' => 'assessed', 'count' => 6],
            ['status' => 'approved', 'count' => 5],
            ['status' => 'completed', 'count' => 4],
        ];

        $made = [];
        foreach ($plan as $p) {
            for ($i = 0; $i < $p['count']; $i++) {
                $made[] = $this->makeCandidate($p['status'], $sectors, $evaluators, $managers, $assistants, $devManagers);
            }
        }

        $counts = array_count_values(array_column($made, 'status'));
        $this->command->info('✓ بيانات العرض:');
        foreach ($plan as $p) {
            $this->command->info(sprintf('   %-11s %d', $p['status'], $counts[$p['status']] ?? 0));
        }
        $this->command->info(sprintf('   %-11s %d', 'الإجمالي', count($made)));
    }

    // ── إزالة بيانات العرض السابقة فقط — لا تُمسّ البيانات الحقيقية ──
    private function purge(): void
    {
        $ids = Candidate::where('participant_code', 'like', self::CODE_PREFIX . '-%')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($ids) {
            $assessmentIds = Assessment::whereIn('candidate_id', $ids)->pluck('id');
            $scheduleIds = Schedule::whereIn('candidate_id', $ids)->pluck('id');
            $evalIds = Evaluation::whereIn('candidate_id', $ids)->pluck('id');

            EvaluationScore::whereIn('evaluation_id', $evalIds)->delete();
            Evaluation::whereIn('id', $evalIds)->delete();
            Attendance::whereIn('schedule_id', $scheduleIds)->delete();
            Schedule::whereIn('id', $scheduleIds)->delete();
            FinalReport::whereIn('candidate_id', $ids)->delete();
            MeasurementResult::whereIn('candidate_id', $ids)->delete();
            DevelopmentPlanItem::whereIn('candidate_id', $ids)->delete();
            Notification::whereIn('entity_id', $ids->map(fn ($i) => (string) $i))
                ->where('entity_type', 'candidate')->delete();
            Assessment::whereIn('id', $assessmentIds)->delete();
            Candidate::whereIn('id', $ids)->delete();
        });

        $this->command->warn('… أُزيلت بيانات عرض سابقة: ' . $ids->count() . ' مرشح');
    }

    private function usersByRole(array $codes)
    {
        return User::whereHas('role', fn ($q) => $q->whereIn('code', $codes))
            ->where('is_active', true)->get();
    }

    private function makeCandidate(string $status, $sectors, $evaluators, $managers, $assistants, $devManagers): array
    {
        $this->seq++;
        $sector = $sectors->random();
        $isMil = (bool) $sector->is_military;

        // الرتبة تحدّد الفئة عبر نفس المنطق الذي يستعمله المتحكّم — لا تُكتب الفئة يدوياً
        $upper = $this->seq % 3 !== 0; // ثلثا المرشحين فئة عليا
        $rank = $isMil
            ? $this->pick($upper ? self::MILITARY_UPPER : self::MILITARY_MID)
            : $this->pick($upper ? self::CIVIL_UPPER : self::CIVIL_MID);
        $tier = Candidate::classifyTier($rank, $isMil);

        // أغلب المرشحين عاديون؛ قلة مصنّفة لتظهر بوابة التصنيف في الواجهة
        $classification = match (true) {
            $this->seq % 11 === 0 => 'top_secret',
            $this->seq % 7 === 0 => 'secret',
            default => 'normal',
        };

        $name = $this->pick(self::FIRST) . ' ' . $this->pick(self::LAST);
        $type = $tier === 'upper' && $this->seq % 4 === 0 ? 'executive' : 'comprehensive';
        $code = sprintf('%s-%03d', self::CODE_PREFIX, $this->seq);

        $c = new Candidate();
        $c->participant_code = $code;
        $c->national_id = $this->nationalId();
        $c->full_name = $name;
        $c->mobile = '05' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $c->email = 'demo' . $this->seq . '@example.com';
        $c->sector_id = $sector->id;
        $c->rank_label = $rank;
        $c->tier = $tier;
        $c->assessment_type = $type;
        $c->status = $status;
        $c->classification = $classification;
        $c->created_at = now()->subDays(random_int(20, 120));
        $c->save();

        $a = Assessment::create([
            'candidate_id' => $c->id,
            'participant_code' => $code,
            'assessment_type' => $type,
            'status' => $status,
            'created_by' => $managers->random()->id,
            'confirm_token' => Assessment::generateConfirmToken(),
            'confirmed_at' => $status === 'draft' ? null : now()->subDays(random_int(5, 30)),
            'arrived_at' => in_array($status, ['assessed', 'approved', 'completed'], true)
                ? now()->subDays(random_int(3, 25)) : null,
        ]);
        $a->created_at = $c->created_at;
        $a->save();

        if ($status === 'draft') {
            return ['status' => $status, 'id' => $c->id];
        }

        // ── مجدول فأعلى: جدول + حضور ──
        $done = in_array($status, ['assessed', 'approved', 'completed'], true);
        $day = $done ? now()->subDays(random_int(3, 25)) : now()->addDays(random_int(1, 21));

        foreach (['interview', 'discussion', 'measurement'] as $i => $activity) {
            $s = Schedule::create([
                'candidate_id' => $c->id,
                'assessment_id' => $a->id,
                'schedule_date' => $day->toDateString(),
                'schedule_time' => sprintf('%02d:00:00', 9 + $i * 2),
                'activity' => $activity,
                'evaluator_id' => $evaluators->random()->id,
                'assistant_id' => $assistants->isNotEmpty() ? $assistants->random()->id : null,
                'location' => 'مركز التقييم — قاعة ' . (($i % 3) + 1),
            ]);

            Attendance::create([
                'schedule_id' => $s->id,
                'status' => $done ? 'present' : 'pending',
                'check_in_time' => $done ? $day->copy()->setTime(8 + $i * 2, random_int(35, 59)) : null,
                'recorded_by' => $done && $assistants->isNotEmpty() ? $assistants->random()->id : null,
            ]);
        }

        if ($status === 'scheduled') {
            return ['status' => $status, 'id' => $c->id];
        }

        // ── مُقيَّم فأعلى: درجات الكفاءات + نتائج القياس ──
        // مستوى أداء ثابت لكل مرشح تتذبذب حوله درجاته — وإلا كان التوافق ضجيجاً بلا معنى
        $level = $upper ? random_int(3, 5) : random_int(2, 4);
        $competencies = Competency::orderBy('sort_order')->get();

        foreach (['interview', 'discussion'] as $activity) {
            $e = Evaluation::create([
                'candidate_id' => $c->id,
                'assessment_id' => $a->id,
                'evaluator_id' => $evaluators->random()->id,
                'activity' => $activity,
                'status' => 'approved',
                'notes' => 'أداء ' . ($level >= 4 ? 'متميّز' : ($level >= 3 ? 'جيد' : 'يحتاج تطويراً'))
                    . ' في ' . ($activity === 'interview' ? 'المقابلة الشخصية' : 'حلقة النقاش') . '.',
                'submitted_at' => $day->copy()->addHours(2),
                'approved_at' => $day->copy()->addDays(1),
                'approved_by' => $managers->random()->id,
            ]);

            foreach ($competencies as $comp) {
                EvaluationScore::create([
                    'evaluation_id' => $e->id,
                    'competency_id' => $comp->id,
                    'score' => max(1, min($comp->max_level, $level + random_int(-1, 1))),
                ]);
            }
        }

        MeasurementResult::create([
            'candidate_id' => $c->id,
            'assessment_id' => $a->id,
            'personality_score' => random_int($level * 15, min(100, $level * 20)),
            'analytical_score' => random_int($level * 14, min(100, $level * 19)),
            'english_score' => random_int($level * 13, min(100, $level * 21)),
            'uploaded_by' => $managers->random()->id,
        ]);

        if ($status === 'assessed') {
            return ['status' => $status, 'id' => $c->id];
        }

        // ── معتمد فأعلى: تقرير نهائي بتوافق محتسَب من الدرجات الفعلية ──
        $fit = app(\App\Services\ScoringService::class)->computeFit($a->fresh());
        $rec = match (true) {
            ($fit['overallFit'] ?? 0) >= 85 => self::RECOMMENDATIONS[0],
            ($fit['overallFit'] ?? 0) >= 70 => self::RECOMMENDATIONS[1],
            ($fit['overallFit'] ?? 0) >= 55 => self::RECOMMENDATIONS[2],
            default => self::RECOMMENDATIONS[3],
        };

        $r = FinalReport::create([
            'candidate_id' => $c->id,
            'assessment_id' => $a->id,
            'behavioral_fit' => $fit['behavioralFit'],
            'technical_fit' => $fit['technicalFit'],
            'recommendation' => $rec,
            // الفاعل «النتائج» لا الشخص: قائمة الأسماء تضم الجنسين، وإسناد الفعل
            // للمرشّح مباشرة يفرض مطابقة تذكير/تأنيث تُنتج عربية خاطئة لنصف الصفوف
            'overview_text' => 'أظهرت نتائج التقييم مستوى '
                . ($level >= 4 ? 'متقدماً' : ($level >= 3 ? 'جيداً' : 'متوسطاً'))
                . " من الكفاءات القيادية لدى {$name} خلال أنشطة الدورة."
                . ' التوافق العام المحتسَب ' . ($fit['overallFit'] ?? 0) . '%.',
            'strengths' => $this->sample(self::STRENGTHS, random_int(2, 4)),
            'development_areas' => $this->sample(self::DEV_AREAS, random_int(1, 3)),
            'status' => 'approved',
            'return_count' => $this->seq % 5 === 0 ? 1 : 0,
            'created_by' => $managers->random()->id,
        ]);
        $r->created_at = $day->copy()->addDays(2);
        $r->save();

        if ($status === 'approved') {
            return ['status' => $status, 'id' => $c->id];
        }

        // ── مكتمل: خطة تطوير فردية بمتابعة ──
        foreach ($this->sample(self::DEV_AREAS, random_int(2, 3)) as $i => $area) {
            $st = ['done', 'in_progress', 'pending'][$i % 3];
            DevelopmentPlanItem::create([
                'candidate_id' => $c->id,
                'assessment_id' => $a->id,
                'area' => $area,
                'action' => $this->pick(self::DEV_ACTIONS),
                'target_date' => now()->addDays(random_int(30, 180))->toDateString(),
                'status' => $st,
                'created_by' => $devManagers->isNotEmpty() ? $devManagers->random()->id : $managers->random()->id,
                'completed_at' => $st === 'done' ? now()->subDays(random_int(1, 20)) : null,
            ]);
        }

        return ['status' => $status, 'id' => $c->id];
    }

    private function pick(array $a)
    {
        return $a[array_rand($a)];
    }

    private function sample(array $a, int $n): array
    {
        shuffle($a);
        return array_slice($a, 0, $n);
    }

    // هوية سعودية صالحة: ١٠ أرقام تبدأ بـ ١، والخانة الأخيرة تُحسب
    // ليقبلها App\Rules\SaudiNationalId (نفس منطق التحقق)
    private function nationalId(): string
    {
        do {
            $base = '1' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $d = (int) $base[$i];
                if ($i % 2 === 0) {
                    $x = $d * 2;
                    $sum += $x > 9 ? $x - 9 : $x;
                } else {
                    $sum += $d;
                }
            }
            $id = $base . ((10 - ($sum % 10)) % 10);
        } while (Candidate::where('national_id_hash', hash('sha256', $id))->exists());

        return $id;
    }
}
