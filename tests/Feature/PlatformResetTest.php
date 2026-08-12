<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  أمر تفريغ المنصّة — يُسلَّم به نظامٌ للوزارة، فخطؤه لا يُكتشف إلا بعد
//  أن تُدخَل بيانات حقيقية فوق بيانات تجريبية ناجية.
// ════════════════════════════════════════════════════════════
class PlatformResetTest extends TestCase
{
    use RefreshDatabase;

    // ⚠ القاعدة مبذورة مرّة واحدة لكل تشغيل والبذر مُثبَّت خارج معاملة الاختبار،
    // فالبداية ليست فارغة: فيها أدوار وقطاعات وكفاءات وحسابات عرض. التأكيدات
    // أدناه نسبيّة لذلك — افتراض الفراغ كان يُنجحها منفردةً ويُخفقها مجتمعة.
    protected $seed = true;

    private function seedMinimal(): array
    {
        $admin = Role::where('code', 'ADMIN')->firstOrFail();
        $sector = Sector::create(['code' => 'TS', 'name_ar' => 'قطاع اختبار', 'is_military' => false]);

        $keeper = User::where('username', 'admin')->firstOrFail();
        $other = User::create([
            'username' => 'reset_other', 'full_name' => 'موظّف', 'email' => 'b@k.local',
            'password' => 'Secret@12345', 'role_id' => $admin->id, 'is_active' => true,
        ]);

        // full_name/national_id مُحوِّلات تشفير خارج $fillable — تُسنَد لا تُملأ
        $c = new Candidate(['sector_id' => $sector->id, 'participant_code' => 'TS-001', 'rank_label' => 'مدير عام']);
        $c->full_name = 'مرشّح تجريبي';
        $c->national_id = '1234567890';
        $c->save();

        return compact('admin', 'sector', 'keeper', 'other');
    }

    public function test_it_wipes_operational_data_but_keeps_system_tables(): void
    {
        $this->seedMinimal();

        $this->artisan('platform:reset --force --skip-backup')->assertSuccessful();

        $this->assertSame(0, Candidate::count(), 'المرشحون التجريبيون نجوا من التفريغ');
        // جداول النظام: مسحها يُعطّل المنصّة، فوجودها بعد التفريغ شرط لا خيار
        $this->assertGreaterThan(0, Role::count(), 'الأدوار مقترنة بمصفوفة الصلاحيات ولا تُمسح');
        $this->assertGreaterThan(0, Sector::count(), 'المرجعيات لا تُمسح بلا --with-reference');
    }

    public function test_it_keeps_only_the_named_login_account(): void
    {
        $this->seedMinimal();

        $this->artisan('platform:reset --force --skip-backup --keep-user=admin')->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertNotNull(User::where('username', 'admin')->first(), 'ضاع الحساب الوحيد القادر على الدخول');
    }

    public function test_it_refuses_when_the_named_account_does_not_exist(): void
    {
        $this->seedMinimal();

        // بلا هذا الحارس يُفرَّغ النظام ولا يبقى فيه من يستطيع الدخول لإصلاحه
        $before = User::count();

        $this->artisan('platform:reset --force --skip-backup --keep-user=ghost')->assertFailed();

        $this->assertSame($before, User::count(), 'مُسح شيء رغم فشل الأمر');
    }

    public function test_with_reference_flag_clears_reference_data_too(): void
    {
        $this->seedMinimal();

        $this->artisan('platform:reset --force --skip-backup --with-reference')->assertSuccessful();

        $this->assertSame(0, Sector::count());
        $this->assertGreaterThan(0, Role::count(), 'الأدوار ليست مرجعية قابلة للمسح');
    }

    // الاختبار الذي كان غيابه يكلّف الدخول إلى النظام كلّه:
    // `users.sector_id` يشير إلى `sectors`، وTRUNCATE … CASCADE في PostgreSQL
    // يُفرِغ الجداول المشيرة أيضاً — فكان تفريغ القطاعات يمسح المستخدمين معها.
    // اختبار «إبقاء الحساب» كان يمرّ لأنه لا يُمرّر --with-reference إطلاقاً.
    public function test_the_kept_account_survives_a_reference_wipe(): void
    {
        $d = $this->seedMinimal();
        // مستخدم مرتبط بقطاع — أشدّ الحالات: الإشارة قائمة وقت حذف القطاع
        $d['keeper']->update(['sector_id' => $d['sector']->id]);

        $this->artisan('platform:reset --force --skip-backup --with-reference --keep-user=admin')
            ->assertSuccessful();

        $keeper = User::where('username', 'admin')->first();
        $this->assertNotNull($keeper, 'مُسح حساب الدخول مع القطاعات — لا سبيل لدخول النظام');
        $this->assertNull($keeper->sector_id, 'بقيت إشارة إلى قطاع محذوف');
        $this->assertSame(0, Sector::count());
    }

    public function test_it_rolls_everything_back_if_the_kept_account_would_be_lost(): void
    {
        $d = $this->seedMinimal();
        $d['keeper']->update(['sector_id' => $d['sector']->id]);

        // مُشغِّلٌ يمحو المستخدمين عند حذف أي قطاع — يُحاكي أثر التتالي الذي
        // أوقع العطل، وأيّ مسارٍ مستقبلي يؤدّي إليه. المطلوب أن تُلغى المعاملة
        // كاملةً: تفريغٌ ينجح ويترك النظام بلا دخول أسوأ من تفريغٍ يفشل.
        DB::statement('CREATE OR REPLACE FUNCTION kill_users() RETURNS trigger AS $$
            BEGIN DELETE FROM users; RETURN OLD; END; $$ LANGUAGE plpgsql');
        DB::statement('CREATE TRIGGER t_kill_users AFTER DELETE ON sectors
            FOR EACH STATEMENT EXECUTE FUNCTION kill_users()');

        try {
            $this->artisan('platform:reset --force --skip-backup --with-reference --keep-user=admin')
                ->assertFailed();

            $this->assertNotNull(User::where('username', 'admin')->first(), 'لم تُلغَ المعاملة');
            $this->assertGreaterThan(0, Candidate::count(), 'مُسحت بيانات رغم إلغاء المعاملة');
            $this->assertGreaterThan(0, Sector::count());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS t_kill_users ON sectors');
            DB::statement('DROP FUNCTION IF EXISTS kill_users()');
        }
    }

    public function test_it_resets_the_participant_code_counter(): void
    {
        $this->seedMinimal();
        DB::table('participant_code_counters')->insert(['prefix' => 'TS', 'last_number' => 266]);

        $this->artisan('platform:reset --force --skip-backup')->assertSuccessful();

        // العدّاد الناجي يجعل أول مشارك حقيقي يحمل رقم آخر مشاركٍ تجريبي
        $this->assertSame(0, DB::table('participant_code_counters')->count());
    }

    public function test_it_records_its_own_run_in_the_audit_log(): void
    {
        $this->seedMinimal();

        $this->artisan('platform:reset --force --skip-backup')->assertSuccessful();

        $row = DB::table('audit_logs')->where('action', 'platform.reset')->first();
        $this->assertNotNull($row, 'سجل تدقيق فارغ بلا سببٍ مسجَّل لخلوّه');
    }

    public function test_an_unclassified_table_stops_the_command(): void
    {
        $this->seedMinimal();

        // محاكاة هجرةٍ مستقبلية تُضيف جدولاً لا يعرفه الأمر. المطلوب أن يتوقّف:
        // الجدول المجهول ينجو من التفريغ صامتاً ويُسلَّم وفيه بيانات تجريبية.
        Schema::create('future_feature_rows', function ($t) {
            $t->id();
            $t->string('note')->nullable();
        });

        try {
            $this->artisan('platform:reset --force --skip-backup')->assertFailed();
            $this->assertGreaterThan(0, Candidate::count(), 'مُسح شيء رغم وجود جدول غير مصنَّف');
        } finally {
            Schema::dropIfExists('future_feature_rows');
        }
    }

    public function test_a_failed_backup_stops_the_wipe(): void
    {
        $this->seedMinimal();

        // النسخة الاحتياطية شرط لا خطوة: توجيه pg_dump إلى منفذ لا يستمع عليه
        // أحد يجب أن يُوقف الأمر قبل أن يُمسح صفٌّ واحد. المنفذ محلي ليُرفض
        // الاتصال فوراً — عنوانٌ غير قابل للتوجيه يُعلّق الاختبار حتى المهلة.
        config(['database.connections.' . config('database.default') . '.port' => 1]);

        $this->artisan('platform:reset --force')->assertFailed();

        $this->assertGreaterThan(0, Candidate::count(), 'فُرِّغت القاعدة رغم فشل النسخة الاحتياطية');
    }
}
