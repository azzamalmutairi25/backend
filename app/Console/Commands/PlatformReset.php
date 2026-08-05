<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  تفريغ المنصّة لتسليمها للتشغيل الحقيقي.
//
//  يمسح كل ما أنتجه العرض والتجارب، ويُبقي ما لا يصحّ للنظام أن يعمل بدونه:
//  الأدوار (مقترنة بمصفوفة الصلاحيات في الشيفرة)، ومراحل الاعتماد، والإعدادات،
//  وحساب مدير واحد للدخول الأول.
//
//  ثلاث ضمانات مقصودة:
//   ١) نسخة احتياطية قبل أي حذف — لا تُتخطّى إلا بعَلَمٍ صريح.
//   ٢) كل جدول مصنَّف بالاسم. جدولٌ جديد لا يعرفه هذا الملف يوقف الأمر بدل أن
//      ينجو من التفريغ صامتاً ويُسلَّم للوزارة وفيه بيانات تجريبية.
//   ٣) TRUNCATE داخل معاملة واحدة: إمّا فرغت كلها أو لم تُمَسّ.
// ════════════════════════════════════════════════════════════
class PlatformReset extends Command
{
    protected $signature = 'platform:reset
        {--keep-user=admin : اسم المستخدم الذي يبقى للدخول الأول (فارغ = لا يبقى أحد)}
        {--with-reference : امسح المرجعيات أيضاً (القطاعات والكفاءات والرتب) ليُدخلها الموظفون}
        {--skip-backup : تخطَّ النسخة الاحتياطية — لا تستعمله على خادم فيه بيانات}
        {--force : لا تسأل}';

    protected $description = 'تفريغ بيانات المنصّة استعداداً لإدخال البيانات الحقيقية (مع نسخة احتياطية)';

    /** جداول النظام: بنيةٌ لا بيانات — مسحها يُعطّل المنصّة */
    private const KEEP = [
        'migrations',        // سجل الهجرات — مسحه يعيد تشغيلها كلها
        'roles',             // الأكواد مقترنة بـ Permissions::forRole()
        'workflow_stages',   // سلسلة اعتماد التقرير
        'settings',          // قوالب الرسائل وأوقات الجلسات
    ];

    /** مرجعيات يُدخلها الموظفون بأنفسهم — تُمسح مع --with-reference فقط */
    private const REFERENCE = [
        'sectors',
        'competencies',
        'ranks',
        'activity_competency',
    ];

    /** بيانات التشغيل — تُمسح دائماً */
    private const OPERATIONAL = [
        'assessments',
        'attendance',
        'audit_logs',
        'candidate_cvs',
        'candidate_update_requests',
        'candidates',
        'chat_messages',
        'chat_threads',
        'development_plan_items',
        'distribution_items',
        'distribution_proposals',
        'email_logs',
        'evaluation_scores',
        'evaluations',
        'final_reports',
        'identity_verifications',
        'measurement_results',
        'notifications',
        'participant_code_counters', // وإلا بدأ ترقيم المشاركين الحقيقي من رقم التجارب
        'roster_groups',
        'schedules',
        'sms_logs',
        'user_permission_overrides',
    ];

    /** ذاكرة وطوابير وجلسات — تُفرَّغ دائماً، وفقدانها لا يضرّ */
    private const EPHEMERAL = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'personal_access_tokens', // إبطال كل الجلسات المفتوحة
        'sessions',
    ];

    public function handle(): int
    {
        $keepUser = trim((string) $this->option('keep-user'));
        $withRef = (bool) $this->option('with-reference');

        // ── ١) التحقّق من أن التصنيف يغطّي القاعدة كاملة ──
        $actual = collect(Schema::getTableListing())
            ->map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
            ->unique()->values();
        $known = collect(self::KEEP)->merge(self::REFERENCE)
            ->merge(self::OPERATIONAL)->merge(self::EPHEMERAL)->merge(['users']);

        $unknown = $actual->diff($known)->values();
        if ($unknown->isNotEmpty()) {
            $this->error('جداول غير مصنَّفة في PlatformReset: ' . $unknown->implode('، '));
            $this->line('  صنّفها (KEEP / REFERENCE / OPERATIONAL / EPHEMERAL) ثم أعد المحاولة.');
            $this->line('  التوقّف هنا مقصود: جدولٌ غير مصنَّف ينجو من التفريغ ولا يلاحظه أحد.');
            return self::FAILURE;
        }
        $missing = $known->diff($actual)->values();
        if ($missing->isNotEmpty()) {
            $this->warn('جداول مذكورة في التصنيف وغير موجودة في القاعدة (ستُتخطّى): ' . $missing->implode('، '));
        }

        // ── ٢) ما الذي سيُمسح فعلاً ──
        $toTruncate = collect(self::OPERATIONAL)->merge(self::EPHEMERAL);
        if ($withRef) {
            $toTruncate = $toTruncate->merge(self::REFERENCE);
        }
        $toTruncate = $toTruncate->intersect($actual)->values();

        $counts = [];
        foreach ($toTruncate as $t) {
            $n = DB::table($t)->count();
            if ($n > 0) $counts[$t] = $n;
        }
        $usersTotal = DB::table('users')->count();
        $usersKept = $keepUser === '' ? 0 : DB::table('users')->where('username', $keepUser)->count();
        $usersDropped = $usersTotal - $usersKept;

        $this->newLine();
        $this->line('<options=bold>سيُمسح:</>');
        foreach ($counts as $t => $n) $this->line(sprintf('  %-28s %d صفّاً', $t, $n));
        $this->line(sprintf('  %-28s %d من %d', 'users', $usersDropped, $usersTotal));
        $this->newLine();
        $this->line('<options=bold>سيبقى:</> ' . collect(self::KEEP)->implode('، ')
            . ($withRef ? '' : '، ' . collect(self::REFERENCE)->implode('، ')));

        if ($keepUser !== '') {
            if ($usersKept === 0) {
                $this->error("المستخدم «{$keepUser}» غير موجود — لن يبقى أحد يستطيع الدخول.");
                $this->line('  مرّر --keep-user باسم موجود، أو --keep-user= صراحةً إن كنت تقصد ذلك.');
                return self::FAILURE;
            }
            $this->line("<options=bold>حساب الدخول الباقي:</> {$keepUser}");
        } else {
            $this->warn('لن يبقى أي مستخدم — ستحتاج إلى إنشاء مدير يدوياً بعد التفريغ.');
        }
        $this->newLine();

        // ── ٣) التأكيد قبل النسخة: الإلغاء يجب ألّا يكلّف شيئاً ──
        if (!$this->option('force')) {
            $env = app()->environment();
            if (!$this->confirm("تنفيذ التفريغ على بيئة «{$env}»؟", false)) {
                $this->line('أُلغي — لم تُمسّ القاعدة.');
                return self::SUCCESS;
            }
        }

        // ── ٤) النسخة الاحتياطية، ولا شيء بينها وبين الحذف ──
        if ($this->option('skip-backup')) {
            $this->warn('⚠ تُخطّيت النسخة الاحتياطية (--skip-backup).');
            if (!$this->option('force') && !$this->confirm('متأكّد؟ لا رجعة بلا نسخة.', false)) {
                return self::FAILURE;
            }
        } else {
            $path = $this->backup();
            if ($path === null) return self::FAILURE;
            $this->info("✓ نسخة احتياطية: {$path}");
        }

        // ── ٥) التنفيذ ──
        $driver = DB::getDriverName();
        DB::transaction(function () use ($toTruncate, $keepUser, $driver) {
            if ($driver === 'pgsql') {
                // TRUNCATE … CASCADE في عبارة واحدة: المفاتيح الأجنبية متشابكة،
                // وحذفها جدولاً جدولاً يفشل بترتيبٍ ما مهما رُتِّب.
                $list = $toTruncate->map(fn ($t) => '"' . $t . '"')->implode(', ');
                DB::statement("TRUNCATE TABLE {$list} RESTART IDENTITY CASCADE");
                if ($keepUser === '') {
                    DB::statement('TRUNCATE TABLE "users" RESTART IDENTITY CASCADE');
                } else {
                    DB::table('users')->where('username', '!=', $keepUser)->delete();
                }
            } else {
                Schema::disableForeignKeyConstraints();
                foreach ($toTruncate as $t) DB::table($t)->truncate();
                if ($keepUser === '') DB::table('users')->truncate();
                else DB::table('users')->where('username', '!=', $keepUser)->delete();
                Schema::enableForeignKeyConstraints();
            }
        });

        // ── ٦) أثرٌ للتفريغ نفسه: أول سطر في سجل التدقيق الجديد ──
        // سجل التدقيق مُسِح للتوّ، فيلزم أن يُفتح بما يفسّر خلوّه — وإلا بدا
        // النظام لاحقاً كأنّ تاريخه بدأ من فراغٍ بلا سبب مسجَّل.
        if ($actual->contains('audit_logs')) {
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'platform.reset',
                'entity_type' => 'system',
                'entity_id' => null,
                'details' => json_encode([
                    'note' => 'تفريغ المنصّة استعداداً للتشغيل الحقيقي',
                    'kept_user' => $keepUser ?: null,
                    'reference_wiped' => $withRef,
                ], JSON_UNESCAPED_UNICODE),
                'ip_address' => '127.0.0.1',
                'created_at' => now(),
            ]);
        }

        $this->newLine();
        $this->info('✓ فُرِّغت المنصّة. شغّل الآن: php artisan cache:clear && php artisan config:cache');
        return self::SUCCESS;
    }

    /** نسخة pg_dump مضغوطة إلى storage/app/backups */
    private function backup(): ?string
    {
        $cfg = config('database.connections.' . config('database.default'));
        if (($cfg['driver'] ?? '') !== 'pgsql') {
            $this->error('النسخ الاحتياطي هنا يدعم PostgreSQL فقط — استعمل --skip-backup بعد نسخٍ يدوي.');
            return null;
        }

        $dir = storage_path('app/backups');
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $file = $dir . '/pre-reset-' . now()->format('Ymd-His') . '.dump';

        // -Fc: صيغة مخصّصة تُستعاد بـ pg_restore انتقائياً (جدول واحد إن لزم)
        $cmd = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s -Fc -f %s 2>&1',
            escapeshellarg((string) $cfg['password']),
            escapeshellarg((string) $cfg['host']),
            escapeshellarg((string) $cfg['port']),
            escapeshellarg((string) $cfg['username']),
            escapeshellarg((string) $cfg['database']),
            escapeshellarg($file)
        );

        exec($cmd, $out, $code);
        if ($code !== 0 || !file_exists($file) || filesize($file) < 1024) {
            $this->error('فشلت النسخة الاحتياطية — أُوقف التفريغ.');
            foreach ($out as $l) $this->line('  ' . $l);
            return null;
        }

        chmod($file, 0640);
        return $file . ' (' . number_format(filesize($file) / 1024, 0) . ' KB)';
    }
}
