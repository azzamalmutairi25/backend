<?php

namespace App\Console\Commands;

use App\Data\MoiSectors;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  تقاعد القطاعات التجريبية الثمانية لصالح قطاعات الوزارة المعتمدة.
//
//  هجرة الزرع (2026_08_15) تحذف الخالي منها وحده، وتترك ما ارتبط ببيانات
//  لهذا الأمر: نقل صفوفٍ وإعادةُ ترقيمِ رموزٍ صادرة قرارٌ صريح لا أثرٌ جانبي
//  لأمر migrate.
//
//  ما يفعله لكل قطاع تجريبي (وفق MoiSectors::LEGACY_MAP):
//   ١) ينقل المشاركين والمستخدمين وصفوف التوزيع إلى القطاع المعتمد المقابل.
//   ٢) يُعيد كتابة بادئة رمز المشارك (DA-001 → CD-001) على المشارك وعلى
//      دوراته معاً — الرمز مكتوب في الجدولين، وتركُ أحدهما يفصم الاثنين.
//   ٣) يرفع عدّاد البادئة الجديدة فوق أعلى رقم منقول، وإلّا ولّد الرمز التالي
//      رقماً مستعملاً فدار على حلقة التخطّي حتى ترميها.
//   ٤) يحذف القطاع التجريبي بعد أن يخلو.
//
//  ⚠ الرمز المنقول يتغيّر: مشاركٌ طُبعت بطاقته بـ DA-001 يصير CD-001. مقبولٌ
//    على بيانات العرض، مؤذٍ على بيانات حقيقية — لذلك يسأل قبل التنفيذ،
//    ويشترط --force في الإنتاج، ويتخطّى أي بادئة يقع رقمها على رمز مستعمل.
// ════════════════════════════════════════════════════════════
class RetireDemoSectors extends Command
{
    protected $signature = 'kafaat:retire-demo-sectors
        {--dry-run : اعرض ما سيحدث دون تنفيذ}
        {--keep-codes : لا تُعِد كتابة رموز المشاركين — انقل الصفوف وحدها}
        {--force : لا تسأل}';

    protected $description = 'نقل بيانات القطاعات التجريبية الثمانية إلى قطاعات الوزارة المعتمدة ثم حذفها';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $plan = [];
        foreach (MoiSectors::LEGACY_MAP as $legacyCode => $targetCode) {
            $legacy = DB::table('sectors')->where('code', $legacyCode)->first();
            if (!$legacy) continue;

            $target = DB::table('sectors')->where('code', $targetCode)->first();
            if (!$target) {
                $this->error("القطاع المعتمد {$targetCode} غير موجود — شغّل php artisan migrate أولاً");
                return self::FAILURE;
            }

            $plan[] = [
                'legacy' => $legacy,
                'target' => $target,
                'candidates' => DB::table('candidates')->where('sector_id', $legacy->id)->count(),
                'users' => DB::table('users')->where('sector_id', $legacy->id)->count(),
                'items' => Schema::hasTable('distribution_items')
                    ? DB::table('distribution_items')->where('sector_id', $legacy->id)->count() : 0,
            ];
        }

        if (!$plan) {
            $this->info('✓ لا توجد قطاعات تجريبية — لا شيء ليُنقل');
            return self::SUCCESS;
        }

        $this->table(
            ['من', 'إلى', 'مشاركون', 'مستخدمون', 'صفوف توزيع'],
            array_map(fn ($p) => [
                "{$p['legacy']->code} — {$p['legacy']->name_ar}",
                "{$p['target']->code} — {$p['target']->name_ar}",
                $p['candidates'], $p['users'], $p['items'],
            ], $plan),
        );

        if ($dry) {
            $this->comment('— تجربة فقط (--dry-run): لم يُنفَّذ شيء');
            return self::SUCCESS;
        }

        // الإنتاج لا يُنقل بسؤالٍ على الشاشة: بياناته حقيقية ورموزه مطبوعة
        if (app()->environment('production') && !$this->option('force')) {
            $this->error('على الإنتاج يلزم --force صراحةً (تُعاد كتابة رموز مشاركين صادرة)');
            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm('تُنقل البيانات أعلاه ثم تُحذف القطاعات التجريبية. متابعة؟')) {
            $this->comment('أُلغي');
            return self::SUCCESS;
        }

        $rewrite = !$this->option('keep-codes');
        $now = now();

        DB::transaction(function () use ($plan, $rewrite, $now) {
            foreach ($plan as $p) {
                [$legacy, $target] = [$p['legacy'], $p['target']];

                $oldPrefix = strtoupper($legacy->participant_prefix ?: substr($legacy->code, 0, 2));
                $newPrefix = strtoupper($target->participant_prefix ?: substr($target->code, 0, 2));

                if ($rewrite && $oldPrefix !== $newPrefix) {
                    $this->rewriteCodes($legacy->id, $oldPrefix, $newPrefix, $now);
                }

                DB::table('candidates')->where('sector_id', $legacy->id)
                    ->update(['sector_id' => $target->id, 'updated_at' => $now]);
                DB::table('users')->where('sector_id', $legacy->id)
                    ->update(['sector_id' => $target->id, 'updated_at' => $now]);
                if (Schema::hasTable('distribution_items')) {
                    DB::table('distribution_items')->where('sector_id', $legacy->id)
                        ->update(['sector_id' => $target->id]);
                }

                DB::table('sectors')->where('id', $legacy->id)->delete();
                DB::table('participant_code_counters')->where('prefix', $oldPrefix)->delete();

                $this->line("  ✓ {$legacy->code} → {$target->code}");
            }
        });

        $this->info('✓ تمّت إحالة القطاعات التجريبية');
        return self::SUCCESS;
    }

    // إعادة ترقيم رموز مشاركي القطاع من بادئة إلى أخرى، مع رفع عدّاد الجديدة
    private function rewriteCodes(int $legacySectorId, string $oldPrefix, string $newPrefix, $now): void
    {
        $candidates = DB::table('candidates')
            ->where('sector_id', $legacySectorId)
            ->where('participant_code', 'like', $oldPrefix . '-%')
            ->get(['id', 'participant_code']);

        $highest = 0;

        foreach ($candidates as $c) {
            $number = (int) substr($c->participant_code, strlen($oldPrefix) + 1);
            $newCode = sprintf('%s-%03d', $newPrefix, $number);

            // رمزٌ مستعمل أصلاً بالبادئة الجديدة يبقى على حاله بدلاً من أن
            // يسقط على القيد الفريد ويُسقط النقل كلّه
            $taken = DB::table('candidates')->where('participant_code', $newCode)->exists()
                || DB::table('assessments')->where('participant_code', $newCode)->exists();
            if ($taken) {
                $this->warn("  • {$c->participant_code} بقي كما هو — {$newCode} مستعمل");
                continue;
            }

            DB::table('candidates')->where('id', $c->id)
                ->update(['participant_code' => $newCode, 'updated_at' => $now]);
            // الرمز مُثبَّت على الدورة أيضاً — يُنقل معه وإلا انفصل الاثنان
            DB::table('assessments')->where('candidate_id', $c->id)
                ->where('participant_code', $c->participant_code)
                ->update(['participant_code' => $newCode, 'updated_at' => $now]);

            $highest = max($highest, $number);
        }

        if ($highest > 0) {
            $this->bumpCounter($newPrefix, $highest, $now);
        }
    }

    // العدّاد لا ينزل: يُرفع إلى أعلى رقم منقول إن كان دونه
    private function bumpCounter(string $prefix, int $highest, $now): void
    {
        $row = DB::table('participant_code_counters')->where('prefix', $prefix)->first();

        if (!$row) {
            DB::table('participant_code_counters')->insert([
                'prefix' => $prefix, 'last_number' => $highest,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return;
        }

        if ((int) $row->last_number < $highest) {
            DB::table('participant_code_counters')->where('prefix', $prefix)
                ->update(['last_number' => $highest, 'updated_at' => $now]);
        }
    }
}
