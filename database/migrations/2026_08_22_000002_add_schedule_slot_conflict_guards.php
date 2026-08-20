<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  تعارض الوقت — الشخص الواحد في اللحظة الواحدة
// ════════════════════════════════════════════════════════════
//
// «النصاب عدّاد لا سدّ» قرارٌ إداريّ مكتوب في SchedulingPeriodController، ومعه
// نصفُه الثاني: «ما يُمنع فعلاً هو تعارض الوقت — وهو خطأ إدخال لا قرار إداري».
// والنصف الثاني لم يُكتب: لا في متحكّم الجدولة ولا في غيره. مقيّمٌ في مقابلتين
// في العاشرة والربع كان يُقبل صامتاً، ويُطبع في كشفَي حضورٍ متزامنين.
//
// والقيد هو الحارس لا فحصٌ في الكود تسبقه ضغطتان متزامنتان — كما في
// discussion_circles_evaluator_slot_unique و reception_assignments_active_unique
// و candidate_update_requests_one_pending. وفحصُ PHP يبقى فوقه ليُحوِّل 23505
// إلى رسالةٍ عربية تُقرأ، لا ليحلّ محلّه.
//
// ── لماذا المقابلة وحدها في قيدَي المقيّم والمساعد ──
//
// أدوات القياس والتمرين التكاملي جلستان **جماعيّتان**: مشرفٌ واحد وعدّة مشاركين
// في القاعة نفسها في اللحظة نفسها، ولكلّ مشاركٍ صفُّه. فقيدٌ يشملهما يمنع جدولة
// المشارك الثاني وهو إدخالٌ سليم. وحلقة النقاش جماعيّةٌ كذلك، وحارسها قائمٌ في
// جدولها هي (discussion_circles) حيث الحلقة صفٌّ واحد لا صفوف.
//
// أمّا المشارك فقيدُه يشمل كل نشاط بلا استثناء: الجلسة الجماعية تعطيه صفّاً
// واحداً لا أكثر، وشخصٌ في مكانين في لحظةٍ واحدة خطأٌ في كل الأحوال.
//
// ── الجلسات بلا وقت ──
//
// ReceptionController::approve و DistributionService::approve يكتبان جلساتٍ بلا
// schedule_time. تُستثنى من القيود كلها: NULL لا يشغل لحظةً فلا يزاحم عليها.
return new class extends Migration
{
    /** القيود: الاسم ← [الجدول، الأعمدة، الشرط، وصفه العربي] */
    private const GUARDS = [
        'schedules_evaluator_slot_unique' => [
            'schedules',
            'evaluator_id, schedule_date, schedule_time',
            "evaluator_id IS NOT NULL AND schedule_time IS NOT NULL AND activity = 'interview'",
            'مقيّم في مقابلتين في اللحظة نفسها',
        ],
        'schedules_assistant_slot_unique' => [
            'schedules',
            'assistant_id, schedule_date, schedule_time',
            "assistant_id IS NOT NULL AND schedule_time IS NOT NULL AND activity = 'interview'",
            'مساعد في مقابلتين في اللحظة نفسها',
        ],
        'schedules_candidate_slot_unique' => [
            'schedules',
            'candidate_id, schedule_date, schedule_time',
            'schedule_time IS NOT NULL',
            'مشارك في جلستين في اللحظة نفسها',
        ],
        // الحلقة كان لها حارسٌ للمستشار وحده منذ إنشائها، و assistant_id بجانبه
        // في الجدول نفسه بلا حارس — سهوٌ لا قرار: تعليق الهجرة يقول «مستشارٌ
        // واحد لا يقع في حلقتين في اللحظة نفسها»، والمساعد مثله.
        'discussion_circles_assistant_slot_unique' => [
            'discussion_circles',
            'assistant_id, circle_date, circle_time',
            'assistant_id IS NOT NULL',
            'مساعد في حلقتَي نقاشٍ في اللحظة نفسها',
        ],
    ];

    public function up(): void
    {
        // ── الكشف قبل الإنشاء ──
        // القاعدة حيّة وفيها بيانات. فهرسٌ فريد يفشل إنشاؤه إن سبقه تعارضٌ
        // مخزَّن، ورسالة postgres عندها تذكر اسم الفهرس ولا تذكر أي صفٍّ أفسده.
        // فنكشف أولاً ونرفع رسالةً تُسمّي الصفوف — الهجرة التي تفشل يجب أن تقول
        // ما يُصلحها، وإلا وقف النشر على استعلامٍ يُكتب مساءً على خادم الإنتاج.
        $clashes = [];
        foreach (self::GUARDS as $name => [$table, $columns, $where, $label]) {
            $rows = DB::select(
                "SELECT {$columns}, count(*) AS c
                   FROM {$table}
                  WHERE {$where}
               GROUP BY {$columns}
                 HAVING count(*) > 1
               ORDER BY c DESC
                  LIMIT 20"
            );
            if ($rows) {
                $total = count($rows);
                $sample = array_map(
                    fn ($r) => implode(' · ', array_map(fn ($v) => (string) $v, (array) $r)),
                    array_slice($rows, 0, 5)
                );
                $clashes[] = "— {$label} ({$table}): {$total} تعارضاً على الأقل\n     "
                    . implode("\n     ", $sample);
            }
        }

        if ($clashes) {
            throw new RuntimeException(
                "لا يمكن تركيب حرّاس التعارض: توجد تعارضاتٌ مخزَّنة.\n"
                . implode("\n", $clashes)
                . "\n\nالأعمدة بترتيب كل سطر تسبق العدد. عالِج التعارضات ثم أعِد الهجرة."
            );
        }

        foreach (self::GUARDS as $name => [$table, $columns, $where, $label]) {
            // بلا CONCURRENTLY: لا يعمل داخل معاملة الهجرة. الجدول عند الإطلاق
            // صغير فالإنشاء لحظي؛ وعلى تثبيتٍ كبير يُنشأ الفهرس يدوياً بـ
            // CREATE UNIQUE INDEX CONCURRENTLY خارجها ثم تُشغَّل الهجرة (IF NOT
            // EXISTS يجعلها بلا أثر عندئذ) — كما في 2026_08_01_000001.
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$name}
                 ON {$table} ({$columns})
                 WHERE {$where}"
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::GUARDS) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
