<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Security\Permissions;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  ثوابت مصفوفة الصلاحيات — أخطاء تصمت ولا تُرى في التشغيل.
//
//  خطأ مطبعي في اسم صلاحية داخل المصفوفة لا يرمي شيئاً: الدور يفقد الصلاحية
//  بصمت فيشتكي المستخدم بعد أشهر. وصلاحية لا يملكها أحد تعني فحصاً لا يمرّه
//  أحد — شاشةٌ ميتة. وصلاحية بلا مجموعة عرض لا تظهر في شاشة الصلاحيات فلا
//  تُمنح ولا تُسحب أبداً. هذه كلها تُمسك هنا لا في التشغيل.
// ════════════════════════════════════════════════════════════
class PermissionMatrixInvariantsTest extends TestCase
{
    // لا حاجة لقاعدة بيانات إلا في اختبار الأدوار المبذورة
    public function test_every_permission_in_the_matrix_is_a_declared_constant(): void
    {
        $declared = Permissions::all();
        $unknown = [];

        foreach (Permissions::matrix() as $role => $perms) {
            foreach ($perms as $p) {
                if ($p === '*') {
                    continue;
                }
                if (!in_array($p, $declared, true)) {
                    $unknown[] = "{$role} ⇒ {$p}";
                }
            }
        }

        $this->assertSame([], $unknown,
            "أسماء صلاحيات في المصفوفة لا تقابل ثابتاً معرَّفاً (خطأ مطبعي يُسقط الصلاحية بصمت):\n"
            . implode("\n", $unknown));
    }

    public function test_only_the_system_administrator_holds_the_wildcard(): void
    {
        $withStar = [];
        foreach (Permissions::matrix() as $role => $perms) {
            if (in_array('*', $perms, true)) {
                $withStar[] = $role;
            }
        }

        $this->assertSame(['ADMIN'], $withStar, 'الرمز العام «*» لا يكون إلا لمدير النظام');
    }

    // صلاحيات لمدير النظام وحده — بقرار معلن. أي إضافة هنا تعني «سلطة لا
    // يُفوَّض بها دورٌ آخر»، وهو قرار حوكمة يُراجَع لا سهوٌ يمرّ.
    private const ADMIN_ONLY = [
        Permissions::USER_MANAGE,
        Permissions::SETTINGS_MANAGE,
        // ضبط مراحل الاعتماد سلطة نظام كنظيرتيها. فُصلت عن settings.manage
        // كي تُمنَح وحدها من شاشة الأدوار عند الحاجة، ولا تُبذَر لأحد.
        Permissions::WORKFLOW_MANAGE,
    ];

    public function test_every_declared_permission_is_held_by_at_least_one_role(): void
    {
        $held = [];
        foreach (Permissions::matrix() as $perms) {
            if (in_array('*', $perms, true)) {
                continue; // مدير النظام يملك كل شيء ولا يُثبت شيئاً
            }
            $held = [...$held, ...$perms];
        }
        $held = array_unique([...$held, ...self::ADMIN_ONLY]);

        $orphans = array_values(array_diff(Permissions::all(), $held));

        $this->assertSame([], $orphans,
            "صلاحيات لا يملكها أي دور غير مدير النظام — فحصٌ لا يمرّه أحد:\n" . implode("\n", $orphans));
    }

    public function test_every_permission_appears_in_a_display_group(): void
    {
        $grouped = [];
        foreach (Permissions::grouped() as $g) {
            $grouped = [...$grouped, ...($g['permissions'] ?? [])];
        }

        $missing = array_values(array_diff(Permissions::all(), $grouped));

        $this->assertSame([], $missing,
            "صلاحيات خارج مجموعات العرض — لا تظهر في شاشة الصلاحيات فلا تُمنح ولا تُسحب:\n"
            . implode("\n", $missing));
    }

    public function test_every_display_group_has_an_arabic_label(): void
    {
        $unlabelled = [];
        foreach (Permissions::grouped() as $prefix => $g) {
            $label = $g['label'] ?? '';
            // بلا تسمية عربية يعرض المفتاح اللاتيني الخام في شاشة عربية
            if ($label === '' || $label === $prefix) {
                $unlabelled[] = $prefix;
            }
        }

        $this->assertSame([], $unlabelled,
            'مجموعات صلاحيات بلا تسمية عربية: ' . implode('، ', $unlabelled));
    }

    public function test_non_delegable_permissions_are_real_and_administrative(): void
    {
        foreach (Permissions::NON_DELEGABLE as $p) {
            $this->assertContains($p, Permissions::all(), "صلاحية غير مفوَّضة غير معرَّفة: {$p}");
        }

        // سلطات النظام الثلاث — إن أُضيفت رابعة فليكن ذلك قراراً واعياً يُحدَّث هنا
        $this->assertEqualsCanonicalizing(
            [Permissions::USER_MANAGE, Permissions::SETTINGS_MANAGE, Permissions::AUDIT_VIEW],
            Permissions::NON_DELEGABLE
        );
    }

    public function test_an_unknown_permission_or_role_fails_closed(): void
    {
        $this->assertFalse(Permissions::roleHasPermission('ADMIN', 'candidate.does_not_exist'));
        $this->assertFalse(Permissions::roleHasPermission('NO_SUCH_ROLE', Permissions::CANDIDATE_VIEW));
        $this->assertSame([], Permissions::forRole('NO_SUCH_ROLE'));
    }

    // الدور الخارجي هو سطح الهجوم الأوسع (حساب لجهة خارج المركز) — أي توسيع
    // له يجب أن يكون قراراً صريحاً يُحدَّث هنا، لا إضافةً تمرّ في مراجعة.
    public function test_the_external_role_stays_minimal(): void
    {
        $this->assertEqualsCanonicalizing(
            [Permissions::CANDIDATE_CREATE, Permissions::CANDIDATE_UPDATE_REQUEST],
            Permissions::forRole('EXTERNAL_ADD')
        );
    }

    // من يقرأ الأسماء أو المصنّفين أو يعتمد — قوائم مغلقة تُراجَع بالعين
    public function test_sensitive_permissions_have_a_closed_holder_list(): void
    {
        $holders = function (string $perm) {
            $out = [];
            foreach (Permissions::matrix() as $role => $perms) {
                if (in_array('*', $perms, true) || in_array($perm, $perms, true)) {
                    $out[] = $role;
                }
            }
            return $out;
        };

        $this->assertEqualsCanonicalizing(
            ['ADMIN', 'SCHEDULER', 'RECEPTIONIST', 'ASSESS_MANAGER'],
            $holders(Permissions::CANDIDATE_VIEW_NAMES),
            'رؤية أسماء المرشحين — أي توسيع قرارٌ أمني'
        );

        $this->assertEqualsCanonicalizing(
            ['ADMIN', 'CENTER_MANAGER', 'ASSESS_MANAGER', 'DEV_MANAGER'],
            $holders(Permissions::CANDIDATE_VIEW_CLASSIFIED),
            'رؤية المرشحين المصنّفين — أي توسيع قرارٌ أمني'
        );

        $this->assertEqualsCanonicalizing(
            ['ADMIN'],
            $holders(Permissions::USER_MANAGE),
            'إدارة المستخدمين لمدير النظام وحده'
        );

        $this->assertEqualsCanonicalizing(
            ['ADMIN', 'SCHEDULER'],
            $holders(Permissions::CANDIDATE_UPDATE_APPROVE),
            'اعتماد تحديث بيانات المرشحين'
        );
    }

    // كل دور في المصفوفة له صفّ في القاعدة — دورٌ بلا صفّ لا يُسنَد لأحد،
    // وصفٌّ بلا دور في المصفوفة يعني حساباً بلا أي صلاحية يصمت عن سببه
    public function test_matrix_roles_and_seeded_roles_match(): void
    {
        $this->seed();

        $matrix = array_keys(Permissions::matrix());
        $seeded = Role::pluck('code')->all();

        $this->assertSame([], array_values(array_diff($matrix, $seeded)),
            'أدوار في المصفوفة بلا صفّ في القاعدة: ' . implode('، ', array_diff($matrix, $seeded)));
        $this->assertSame([], array_values(array_diff($seeded, $matrix)),
            'أدوار في القاعدة بلا صلاحيات في المصفوفة: ' . implode('، ', array_diff($seeded, $matrix)));
    }

    // صلاحية كل مرحلة اعتماد تُقرأ من القاعدة لا من ثابت. وبعد أن صار '*'
    // يُفرَد على المعرَّف فقط، صار اسمٌ مكتوبٌ خطأً في صفٍّ يعني مرحلةً لا
    // يعتمدها أحد — ولا مدير النظام. الحقل غير قابل للتعديل عبر الـAPI،
    // فالخطر الوحيد هجرةٌ جديدة؛ وهنا يُمسك.
    public function test_every_workflow_stage_permission_is_a_declared_constant(): void
    {
        $this->seed();

        $bad = \App\Models\WorkflowStage::pluck('permission', 'status_key')
            ->reject(fn ($p) => in_array($p, Permissions::all(), true))
            ->map(fn ($p, $k) => "{$k} ⇒ {$p}")
            ->values()->all();

        $this->assertSame([], $bad,
            "مراحل اعتماد بصلاحيات غير معرَّفة — لا يعتمدها أحد:\n" . implode("\n", $bad));
    }

    // مرآة الواجهة (perms.js) تنجرف بصمت: مفتاح ناقص يجعل hasPermission(undefined)
    // يرجع false دائماً فيختفي زرٌّ عن كل مستخدم — وقد وقع هذا فعلاً من قبل.
    public function test_the_frontend_permission_mirror_matches_the_backend(): void
    {
        $path = base_path('../frontend/src/services/perms.js');
        if (!is_file($path)) {
            $this->markTestSkipped('مستودع الواجهة غير موجود بجانب الخلفية');
        }

        $src = (string) file_get_contents($path);
        preg_match('/export const PERM = \{(.*?)\n\}/s', $src, $m);
        $this->assertNotEmpty($m, 'تعذّر العثور على كائن PERM في perms.js');

        preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $m[1], $vals);
        $mirror = array_unique($vals[1]);

        $unknown = array_values(array_diff($mirror, Permissions::all()));
        $this->assertSame([], $unknown,
            "صلاحيات في مرآة الواجهة لا وجود لها في الخلفية:\n" . implode("\n", $unknown));
    }
}
