<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateUpdateRequest;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  تحضير بيئة اختبار الحمل.
//
//  لماذا أمر بدل تسجيل دخول عادي؟ لأن حدود المعدّل تُفسد القياس:
//   • /login محدود بـ10 محاولات/دقيقة لكل IP — مولّد الحمل يستهلكها فوراً.
//   • كل مسارات /api محدودة بـAPI_RATE_LIMIT (٣٠٠/دقيقة) لكل مستخدم مُصادَق،
//     أي ٥ طلبات/ثانية للمستخدم الواحد. رمزٌ واحد يقيس المُقيِّد لا التطبيق.
//  فنُصدر رموز Sanctum مباشرة لمجموعة مستخدمين — وهو أيضاً أقرب للواقع:
//  الحمل الحقيقي يأتي من مستخدمين كثيرين لا من واحد.
//
//  العزل: كل ما يُنشأ هنا يقع في قطاع «LT» وحده، فالتنظيف حذفٌ لقطاعٍ كامل
//  ولا يلمس بيانات حقيقية، ولا يُفسد تسلسل رموز المشاركين في القطاعات الفعلية.
// ════════════════════════════════════════════════════════════
class LoadTestPrepare extends Command
{
    protected $signature = 'loadtest:prepare
        {--readers=20 : عدد مستخدمي القراءة (دور SCHEDULER)}
        {--writers=10 : عدد مستخدمي الكتابة (دور EXTERNAL_ADD)}
        {--candidates=200 : مشاركون يُبذَرون في قطاع الاختبار لتكون القوائم واقعية}
        {--out=load-test/tokens.json : ملف الرموز الناتج}
        {--cleanup : حذف كل ما أنشأه هذا الأمر ثم الخروج}
        {--force : السماح بالتشغيل خارج بيئة التطوير}';

    protected $description = 'تحضير مستخدمين ورموز وبيانات معزولة لاختبار الحمل (أو تنظيفها بـ--cleanup)';

    // قطاع معزول لكل ما يخصّ اختبار الحمل
    private const SECTOR_CODE = 'LT';
    private const USER_PREFIX = 'lt_';

    public function handle(): int
    {
        // حارس البيئة: بذر مستخدمين برموز وصول في إنتاج ليس خطأً يُصحَّح لاحقاً
        if (app()->environment('production') && !$this->option('force')) {
            $this->error('مرفوض في بيئة الإنتاج. استعمل --force إن كنت تقصد ذلك فعلاً.');
            return self::FAILURE;
        }

        return $this->option('cleanup') ? $this->cleanup() : $this->prepare();
    }

    private function prepare(): int
    {
        $sector = Sector::updateOrCreate(
            ['code' => self::SECTOR_CODE],
            ['name_ar' => 'اختبار الحمل', 'participant_prefix' => 'LT']
        );

        $readers = $this->makeUsers('SCHEDULER', (int) $this->option('readers'), 'r');
        $writers = $this->makeUsers('EXTERNAL_ADD', (int) $this->option('writers'), 'w');

        $seeded = $this->seedCandidates($sector, (int) $this->option('candidates'));

        $payload = [
            'baseUrlHint' => config('app.url'),
            'sectorId' => $sector->id,
            'sectorCode' => $sector->code,
            'apiRateLimitPerMinute' => (int) env('API_RATE_LIMIT', 300),
            'readers' => $readers,
            'writers' => $writers,
            'seededCandidates' => $seeded,
            // عيّنة معرّفات لسيناريو «تفاصيل مشارك» — قراءة بمعرّف حقيقي لا بتخمين
            // يرتدّ 404، فيقيس السيناريو المسار الكامل (فكّ تشفير + تدقيق) لا الرفض
            'candidateIdSample' => Candidate::where('sector_id', $sector->id)
                ->inRandomOrder()->limit(200)->pluck('id')->all(),
            // الطابع الزمني يُمرَّر من PHP لا من مولّد الحمل — مصدر واحد للوقت
            'preparedAt' => now()->toIso8601String(),
        ];

        $path = base_path($this->option('out'));
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600); // الملف يحمل رموز وصول صالحة — لا يُقرأ للعموم

        $this->info("✅ جاهز: {$readers['count']} قارئ، {$writers['count']} كاتب، {$seeded} مشاركاً في قطاع «{$sector->name_ar}».");
        $this->line("   الرموز: {$path} (0600)");
        $this->newLine();
        $this->comment('حدّ المعدّل الحالي: ' . $payload['apiRateLimitPerMinute'] . ' طلب/دقيقة لكل مستخدم'
            . ' — أي سقف نظري ' . round($payload['apiRateLimitPerMinute'] * ($readers['count'] + $writers['count']) / 60) . ' طلب/ثانية.');
        $this->comment('لقياس السعة الخام ارفع API_RATE_LIMIT مؤقتاً في .env ثم: php artisan config:clear');

        return self::SUCCESS;
    }

    // مستخدمون بدور محدّد + رمز Sanctum نصّي لكلٍّ منهم
    private function makeUsers(string $roleCode, int $count, string $tag): array
    {
        $role = Role::where('code', $roleCode)->first();
        if (!$role) {
            $this->warn("الدور {$roleCode} غير موجود — تخطّي");
            return ['role' => $roleCode, 'count' => 0, 'tokens' => []];
        }

        $tokens = [];
        for ($i = 1; $i <= $count; $i++) {
            $username = self::USER_PREFIX . $tag . $i;
            $user = User::updateOrCreate(
                ['username' => $username],
                [
                    'full_name' => "حمل — {$roleCode} {$i}",
                    'email' => "{$username}@loadtest.local",
                    'password' => 'LoadTest@' . bin2hex(random_bytes(8)), // لا يُستعمل: الدخول عبر الرمز
                    'role_id' => $role->id,
                    'sector_id' => null, // هذان الدوران غير محصورَين بقطاع
                    'user_type' => 'external',
                    'is_active' => true,
                    'must_change_password' => false, // وإلا حجبها وسيط فرض تغيير كلمة المرور
                    'failed_attempts' => 0,
                    'locked_until' => null,
                ]
            );

            // رمز واحد حيّ لكل مستخدم — القديم يُلغى فلا تتراكم الرموز عبر التشغيلات
            $user->tokens()->delete();
            $tokens[] = $user->createToken('loadtest')->plainTextToken;
        }

        return ['role' => $roleCode, 'count' => count($tokens), 'tokens' => $tokens];
    }

    // مشاركون في قطاع الاختبار — قوائم فارغة تقيس استعلاماً لا يشبه الإنتاج
    private function seedCandidates(Sector $sector, int $target): int
    {
        $existing = Candidate::where('sector_id', $sector->id)->count();
        if ($existing >= $target) {
            return $existing;
        }

        $bar = $this->output->createProgressBar($target - $existing);
        $bar->start();

        // إدراج مباشر بلا دورات تقييم: الهدف حجمُ بيانات واقعي للقراءة،
        // لا محاكاة دورة كاملة (تلك يصنعها سيناريو الكتابة أثناء الاختبار).
        for ($i = $existing + 1; $i <= $target; $i++) {
            $c = new Candidate();
            $c->national_id = $this->syntheticNationalId($i);
            $c->full_name = "مشارك حمل {$i}";
            $c->mobile = '05' . str_pad((string) ($i % 100000000), 8, '0', STR_PAD_LEFT);
            $c->sector_id = $sector->id;
            $c->rank_label = $i % 3 === 0 ? 'مدير عام' : 'عميد';
            $c->tier = $i % 3 === 0 ? 'middle' : 'upper';
            $c->status = 'draft';
            $c->classification = 'normal';
            $c->participant_code = sprintf('LT-%06d', $i);
            $c->save();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return Candidate::where('sector_id', $sector->id)->count();
    }

    // هوية اصطناعية صالحة (لُون، تبدأ بـ2 لتبتعد عن نطاق بيانات حقيقية)
    private function syntheticNationalId(int $seed): string
    {
        $body = '2' . str_pad((string) ($seed % 100000000), 8, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $d = (int) $body[$i];
            if ($i % 2 === 0) { $x = $d * 2; $sum += $x > 9 ? $x - 9 : $x; }
            else { $sum += $d; }
        }
        return $body . ((10 - ($sum % 10)) % 10);
    }

    private function cleanup(): int
    {
        $sector = Sector::where('code', self::SECTOR_CODE)->first();

        $candidates = 0;
        if ($sector) {
            // حذفٌ صفّاً صفّاً عمداً: Candidate::booted يمسح سجلّات المراسلات
            // أولاً، وحذفٌ جماعي بـdelete() يتخطّى ذلك فتمنعه قيود المفاتيح
            $ids = Candidate::where('sector_id', $sector->id)->pluck('id');
            DB::transaction(function () use ($ids, &$candidates) {
                CandidateUpdateRequest::whereIn('candidate_id', $ids)->delete();
                foreach ($ids as $id) {
                    Candidate::find($id)?->delete();
                    $candidates++;
                }
            });
        }

        $users = User::where('username', 'like', self::USER_PREFIX . '%')->get();
        $userIds = $users->pluck('id')->all();
        $userCount = $users->count();

        foreach ($users as $u) {
            $u->tokens()->delete(); // إبطال الوصول أولاً — يتمّ ولو تعذّر الحذف
        }

        // آثار المستخدم في الجداول المرجعية: قيودها RESTRICT فتمنع حذفه.
        // هذه سجلّات اصطناعية من اختبار، لا أثر تدقيقي لعملٍ حقيقي — تُحذف.
        $residue = 0;
        if ($userIds) {
            foreach (['audit_logs' => 'user_id', 'sms_logs' => 'created_by',
                      'email_logs' => 'created_by', 'notifications' => 'created_by'] as $table => $col) {
                $residue += DB::table($table)->whereIn($col, $userIds)->delete();
            }
        }

        // الحذف قد يصطدم بمرجعٍ لم نتوقّعه (جدول جديد بقيد RESTRICT). حينها
        // نتدرّج إلى التعطيل بدل أن نترك الأمر يرمي: مستخدمٌ معطَّل بلا رموز
        // غير ضارّ، وإخفاء الفشل أسوأ من الإبقاء عليه.
        $deleted = true;
        try {
            User::whereIn('id', $userIds)->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            $deleted = false;
            User::whereIn('id', $userIds)->update(['is_active' => false]);
            $this->warn('تعذّر حذف مستخدمي الاختبار (مراجع قائمة) — عُطِّلوا وأُبطلت رموزهم.');
            $this->line('  السبب: ' . str($e->getMessage())->limit(160));
        }

        // القطاع يُحذف أخيراً — بعد أن خلا من مشاركيه. يبقى إن بقي مستخدموه.
        if ($deleted) {
            $sector?->delete();
        }

        $out = base_path($this->option('out'));
        if (is_file($out)) {
            @unlink($out);
        }

        $verb = $deleted ? 'حُذف' : 'عُطِّل';
        $this->info("🧹 حُذف {$candidates} مشاركاً، و{$verb} {$userCount} مستخدماً، وأُزيل {$residue} سجلّاً مرافقاً وملف الرموز.");

        return self::SUCCESS;
    }
}
