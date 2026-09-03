<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة المستخدمين'], 403);
        }

        $request->validate($this->listPagingRules($this->sortable()));

        $query = User::with(['role', 'sector', 'manager', 'expertiseAreas']);
        $meta = $this->applyListPaging($request, $query, $this->sortable(), 'name', 'id');

        $users = $query->get()->map(fn ($u) => [
            'id' => $u->id,
            'username' => $u->username,
            'fullName' => $u->full_name,
            'email' => $u->email,
            'roleCode' => $u->role->code,
            'roleName' => $u->role->name_ar,
            'sectorId' => $u->sector_id,
            // مجالات الخبرة — تقرأها نافذة الوسم لتبدأ بما هو مسجَّل فعلاً.
            // بدونها كانت النافذة تفتح فارغةً فيمحو الحفظُ وسماً قائماً.
            'expertiseAreaIds' => $u->expertiseAreas->pluck('id')->all(),
            'sectorName' => $u->sector?->name_ar,
            'sectorBound' => $u->isSectorBound(),
            'managerId' => $u->manager_id,
            'managerName' => $u->manager?->full_name,
            'managed' => $u->isManaged(),
            'userType' => $u->user_type,
            'adUsername' => $u->ad_username,
            'isActive' => $u->is_active,
            'mustChangePassword' => $u->must_change_password,
            'lastLoginAt' => $u->last_login_at,
            'isSelf' => $u->id === $request->user()->id,
        ]);

        return response()->json([
            'users' => $users,
            'meta' => $meta + ['shown' => $users->count()],
        ]);
    }

    // ── الفرز ──
    // الافتراضي `full_name` تصاعدياً كما كان قبل الترقيم. (وهو نصٌّ صريح هنا
    // بخلاف اسم المشارك المشفَّر — حسابات الموظفين ليست بيانات المشاركين.)
    private function sortable(): array
    {
        return [
            'name' => 'full_name',
            'username' => 'username',
            'active' => 'is_active',
            'lastLogin' => 'last_login_at',
            'created' => 'created_at',
            'role' => fn ($q, $dir) => $q->orderBy(
                Role::select('name_ar')->whereColumn('roles.id', 'users.role_id'),
                $dir
            ),
        ];
    }

    // GET /users/{id}/permissions — الصلاحيات الفعلية + مصدر كل واحدة
    public function permissions(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $user = User::with(['role', 'permissionOverrides'])->findOrFail($id);
        $fromRole = Permissions::forRole($user->role->code);
        $hasStar = in_array('*', $fromRole, true);
        $overrides = $user->permissionOverrides->keyBy('permission');

        $rows = [];
        foreach (Permissions::grouped() as $key => $group) {
            $perms = [];
            foreach ($group['permissions'] as $p) {
                $byRole = $hasStar || in_array($p, $fromRole, true);
                $o = $overrides->get($p);
                $perms[] = [
                    'permission' => $p,
                    'byRole' => $byRole,
                    // null = لا استثناء، true = ممنوحة، false = مسحوبة
                    'override' => $o ? $o->granted : null,
                    'effective' => $o ? $o->granted : $byRole,
                    'reason' => $o?->reason,
                ];
            }
            $rows[] = ['key' => $key, 'label' => $group['label'], 'permissions' => $perms];
        }

        return response()->json([
            'user' => ['id' => $user->id, 'fullName' => $user->full_name, 'roleName' => $user->role->name_ar],
            'groups' => $rows,
            'isSelf' => $user->id === $request->user()->id,
        ]);
    }

    // PUT /users/{id}/permissions — ضبط استثناءات المستخدم
    public function savePermissions(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $user = User::with('role')->findOrFail($id);

        // لا أحد يعدّل صلاحيات نفسه — وإلا صار من يملك USER_MANAGE قادراً على
        // منح نفسه كل شيء، فلا معنى لأي حدّ بعدها
        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'لا يمكنك تعديل صلاحيات حسابك'], 422);
        }

        $validated = $request->validate([
            'overrides' => 'present|array',
            'overrides.*.permission' => ['required', 'string', Rule::in(Permissions::all())],
            'overrides.*.granted' => 'required|boolean',
            'overrides.*.reason' => 'nullable|string|max:300',
        ]);

        $incoming = collect($validated['overrides']);

        // استثناء يطابق الدور أصلاً = ضجيج: يوحي باستثناء حيث لا استثناء،
        // ويصير كذباً صامتاً لحظةَ تغيّر الدور
        $fromRole = Permissions::forRole($user->role->code);
        $hasStar = in_array('*', $fromRole, true);
        $redundant = $incoming->filter(function ($o) use ($fromRole, $hasStar) {
            $byRole = $hasStar || in_array($o['permission'], $fromRole, true);

            return $o['granted'] === $byRole;
        });
        if ($redundant->isNotEmpty()) {
            return response()->json([
                'errors' => ['overrides' => [
                    'استثناء يطابق الدور بلا فائدة: '.$redundant->pluck('permission')->implode('، '),
                ]],
            ], 422);
        }

        // ── سقف الامتيازات: بلا هذا يصير تفويض user.manage لغير مدير باباً لتوزيع
        //    أي صلاحية على أي حساب، فتنهار حدود المصفوفة. ثلاث طبقات: ──
        $actor = $request->user();
        $actorHasStar = in_array('*', Permissions::forRole($actor->role->code), true);

        // (١) صلاحيات إدارية للنظام لا تُمنح ولا تُسحب عبر الاستثناء إطلاقاً — بالدور فقط
        $nonDelegable = $incoming->pluck('permission')->intersect(Permissions::NON_DELEGABLE);
        if ($nonDelegable->isNotEmpty()) {
            return response()->json(['errors' => ['overrides' => [
                'هذه الصلاحيات تُدار بالدور لا بالاستثناء الفردي: '.$nonDelegable->implode('، '),
            ]]], 422);
        }

        // (٢) لا يُمنح ما لا يملكه المانح نفسه — لا يُفوَّض ما لا تملكه
        $cannotGrant = $incoming->filter(fn ($o) => $o['granted'] && ! $actor->hasPermission($o['permission']));
        if ($cannotGrant->isNotEmpty()) {
            return response()->json(['errors' => ['overrides' => [
                'لا يمكنك منح صلاحية لا تملكها: '.$cannotGrant->pluck('permission')->implode('، '),
            ]]], 403);
        }

        // (٣) لا يُعدّل صلاحيات حامل '*' (مدير النظام) إلا حاملٌ لـ'*' مثله — منع قفل المدراء
        if (in_array('*', Permissions::forRole($user->role->code), true) && ! $actorHasStar) {
            return response()->json(['error' => 'لا يمكنك تعديل صلاحيات مدير النظام'], 403);
        }

        DB::transaction(function () use ($user, $incoming, $request) {
            $user->permissionOverrides()->delete();
            foreach ($incoming as $o) {
                $user->permissionOverrides()->create([
                    'permission' => $o['permission'],
                    'granted' => $o['granted'],
                    'reason' => $o['reason'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        // تغيّرت صلاحياته ⇒ اطرد جلساته ليعيد الدخول بها
        $user->tokens()->delete();

        $this->log($request, 'UPDATE_USER_PERMISSIONS', $user->id, [
            'username' => $user->username,
            'granted' => $incoming->where('granted', true)->pluck('permission')->all(),
            'revoked' => $incoming->where('granted', false)->pluck('permission')->all(),
        ]);

        return response()->json(['message' => 'تم حفظ الصلاحيات — سيُطلب من المستخدم إعادة الدخول']);
    }

    // GET /users/permission-catalog — الصلاحيات مجمّعةً بأسمائها العربية
    //
    // شاشة الوصول الجماعي لا تقرأ حالة مستخدمٍ بعينه، إنما قائمةَ ما يمكن
    // منحه أو سحبه. والمقفلُ يظهر مقفلاً بسببه لا يُحذف: غيابُه الصامت يُقرأ
    // «هذه الصلاحية غير موجودة» وهي موجودة ولها مالك.
    public function permissionCatalog(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $actor = $request->user();
        $groups = [];
        foreach (Permissions::grouped() as $key => $group) {
            $perms = [];
            foreach ($group['permissions'] as $p) {
                $nonDelegable = in_array($p, Permissions::NON_DELEGABLE, true);
                $owned = $actor->hasPermission($p);
                $perms[] = [
                    'permission' => $p,
                    'label' => Permissions::label($p),
                    // السحب مسموح لمن لا يملكها؛ المنع على المنح وحده —
                    // «لا تُعطِ ما ليس لك» لا «لا تسحب ما ليس لك»
                    'canGrant' => ! $nonDelegable && $owned,
                    'canRevoke' => ! $nonDelegable,
                    'lockedReason' => $nonDelegable
                        ? 'تُدار بالدور لا بالاستثناء الفردي'
                        : ($owned ? null : 'لا تملكها فلا تمنحها'),
                ];
            }
            $groups[] = ['key' => $key, 'label' => $group['label'], 'permissions' => $perms];
        }

        return response()->json(['groups' => $groups]);
    }

    // ════════════════════════════════════════════════════════
    //  POST /users/bulk-permissions — وصولٌ واحد على مجموعة موظفين
    // ════════════════════════════════════════════════════════
    //
    // فتحُ قفل كل موظّف وحده لضبط الصلاحية نفسها على عشرين حساباً عشرون فرصةً
    // للخطأ: يُنسى واحد، ويُمنح آخرُ ما لم يُقصد، ولا أحد يرى المحصّلة. هنا
    // قرارٌ واحد يُطبَّق على المحدَّدين جميعاً بسجلّ تدقيقٍ لكل حساب.
    //
    // ثلاثة أفعال لكل صلاحية: grant (منح فوق الدور) · revoke (سحب رغمه) ·
    // reset (رفعُ الاستثناء فيعود المستخدم لما يعطيه دوره).
    //
    // حدود الامتيازات هي حدود المسار الفردي نفسها — لا يُفتح بالجملة ما يُقفل
    // بالإفراد. والفرق الوحيد أن ما يطابق الدور هنا **يُمحى صامتاً** بدل أن
    // يُرفض الطلب: تحديدٌ فيه أدوارٌ مختلفة يجعل الصلاحية الواحدة استثناءً عند
    // بعضهم وتحصيلَ حاصلٍ عند غيرهم، فرفضُ الدفعة كلها لأجل ذلك يُعطّل الشاشة.
    public function bulkPermissions(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة المستخدمين'], 403);
        }

        $validated = $request->validate([
            'userIds' => 'required|array|min:1|max:500',
            'userIds.*' => 'integer|distinct|exists:users,id',
            'changes' => 'required|array|min:1',
            'changes.*.permission' => ['required', 'string', Rule::in(Permissions::all())],
            'changes.*.action' => ['required', Rule::in(['grant', 'revoke', 'reset'])],
            'reason' => 'nullable|string|max:300',
        ]);

        // صلاحيةٌ ذُكرت مرّتين بفعلين متناقضين: الأخيرة تفوز لو تُركت، وذلك
        // قرارٌ صامت في شاشة صلاحيات — تُرفض صراحةً.
        $changes = collect($validated['changes']);
        $duplicated = $changes->groupBy('permission')->filter(fn ($g) => $g->pluck('action')->unique()->count() > 1);
        if ($duplicated->isNotEmpty()) {
            return response()->json(['errors' => ['changes' => [
                'صلاحية بفعلين متناقضين في الطلب نفسه: '.$duplicated->keys()->implode('، '),
            ]]], 422);
        }
        $changes = $changes->unique('permission')->values();

        $actor = $request->user();
        $actorHasStar = in_array('*', Permissions::forRole($actor->role->code), true);

        // (١) سلطات النظام تُدار بالدور لا بالاستثناء — بالجملة كما بالإفراد
        $nonDelegable = $changes->pluck('permission')->intersect(Permissions::NON_DELEGABLE);
        if ($nonDelegable->isNotEmpty()) {
            return response()->json(['errors' => ['changes' => [
                'هذه الصلاحيات تُدار بالدور لا بالاستثناء الفردي: '.$nonDelegable->implode('، '),
            ]]], 422);
        }

        // (٢) لا يُفوَّض ما لا يملكه المفوِّض
        $cannotGrant = $changes->filter(fn ($c) => $c['action'] === 'grant' && ! $actor->hasPermission($c['permission']));
        if ($cannotGrant->isNotEmpty()) {
            return response()->json(['errors' => ['changes' => [
                'لا يمكنك منح صلاحية لا تملكها: '.$cannotGrant->pluck('permission')->implode('، '),
            ]]], 403);
        }

        $users = User::with('role')->whereIn('id', $validated['userIds'])->get();
        $applied = [];
        $skipped = [];

        DB::transaction(function () use ($request, $users, $changes, $actor, $actorHasStar, $validated, &$applied, &$skipped) {
            foreach ($users as $user) {
                // (٣) لا أحد يعدّل صلاحيات نفسه ولو ضمن تحديدٍ عريض — «تحديد
                //     الكل» لا يجوز أن يصير طريقاً لمنح النفس ما لا تملك
                if ($user->id === $actor->id) {
                    $skipped[] = ['id' => $user->id, 'fullName' => $user->full_name, 'reason' => 'لا يمكنك تعديل صلاحيات حسابك'];

                    continue;
                }
                // (٤) حامل '*' لا يمسّه إلا حاملٌ لـ'*' مثله — منع قفل المدراء
                if (in_array('*', Permissions::forRole($user->role->code), true) && ! $actorHasStar) {
                    $skipped[] = ['id' => $user->id, 'fullName' => $user->full_name, 'reason' => 'حساب مدير نظام'];

                    continue;
                }

                $fromRole = Permissions::forRole($user->role->code);
                $hasStar = in_array('*', $fromRole, true);
                $granted = [];
                $revoked = [];
                $reset = [];

                foreach ($changes as $c) {
                    $perm = $c['permission'];
                    $byRole = $hasStar || in_array($perm, $fromRole, true);
                    $wantGranted = $c['action'] === 'grant';
                    $existing = $user->permissionOverrides()->where('permission', $perm)->first();

                    // reset، أو استثناءٌ يطابق ما يعطيه الدور أصلاً: يُرفع الاستثناء
                    // فيعود المستخدم إلى دوره بدل أن يحمل استثناءً لا أثر له
                    if ($c['action'] === 'reset' || $wantGranted === $byRole) {
                        if ($existing) {
                            $existing->delete();
                            $reset[] = $perm;
                        }

                        continue;
                    }

                    if ($existing && $existing->granted === $wantGranted) {
                        continue;   // قائمٌ بالفعل — لا كتابة ولا ضجيج في السجل
                    }

                    $user->permissionOverrides()->updateOrCreate(
                        ['permission' => $perm],
                        ['granted' => $wantGranted, 'reason' => $validated['reason'] ?? null, 'created_by' => $actor->id]
                    );
                    if ($wantGranted) {
                        $granted[] = $perm;
                    } else {
                        $revoked[] = $perm;
                    }
                }

                if (! $granted && ! $revoked && ! $reset) {
                    continue;   // لم يتغيّر شيء عنده — لا تُطرد جلسته بلا سبب
                }

                // تغيّرت صلاحياته ⇒ اطرد جلساته ليعيد الدخول بها
                $user->tokens()->delete();
                $applied[] = ['id' => $user->id, 'fullName' => $user->full_name];

                $this->log($request, 'BULK_UPDATE_USER_PERMISSIONS', $user->id, [
                    'username' => $user->username,
                    'granted' => $granted,
                    'revoked' => $revoked,
                    'reset' => $reset,
                    'reason' => $validated['reason'] ?? null,
                ]);
            }
        });

        $n = count($applied);

        return response()->json([
            'message' => $n === 0
                ? 'لم يتغيّر شيء — الصلاحيات المطلوبة قائمة أصلاً لدى المحدَّدين'
                : "طُبّق الوصول على {$n} موظفاً — سيُطلب منهم إعادة الدخول",
            'applied' => $applied,
            'skipped' => $skipped,
        ]);
    }

    public function roles(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $roles = Role::where('code', '!=', 'EXTERNAL_ADD')->orderBy('name_ar')->get()->map(fn ($r) => [
            'id' => $r->id,
            'code' => $r->code,
            'nameAr' => $r->name_ar,
            'description' => $r->description,
            // تُصدَّر ليعرف النموذج متى يطلب القطاع — بدل تكرار القائمة في الواجهة فتتباعدان
            'sectorBound' => in_array($r->code, User::SECTOR_BOUND_ROLES, true),
            'managed' => in_array($r->code, User::MANAGED_ROLES, true),
        ]);

        return response()->json(['roles' => $roles]);
    }

    public function rolePermissions(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $labels = [
            'candidate.view' => 'عرض المشاركين',
            'candidate.create' => 'إضافة مشارك',
            'candidate.edit' => 'تعديل مشارك',
            'candidate.approve' => 'اعتماد مشارك',
            'candidate.view_names' => 'رؤية الأسماء الحساسة',
            'candidate.view_classified' => 'رؤية المشاركين المصنّفين',
            'candidate.cv_view' => 'عرض السيرة الذاتية',
            'schedule.view' => 'عرض الجدولة',
            'schedule.manage' => 'إدارة الجدولة',
            'schedule.distribute' => 'التوزيع الأسبوعي',
            'roster.manage' => 'إسناد مجموعات المشاركين',
            'attendance.view' => 'عرض الحضور',
            'attendance.record' => 'تسجيل الحضور',
            'evaluation.view' => 'عرض التقييم',
            'evaluation.input' => 'إدخال التقييم',
            'evaluation.approve' => 'اعتماد التقييم',
            'evaluation.assist' => 'مساعدة التقييم',
            'measurement.view' => 'عرض أدوات القياس',
            'measurement.upload' => 'رفع أدوات القياس',
            'report.view' => 'عرض التقارير',
            'report.create' => 'إنشاء تقرير',
            'report.approve' => 'اعتماد تقرير',
            'report.return' => 'إرجاع تقرير',
            'report.exec_summary' => 'الملخّص التنفيذي (مدير المركز)',
            'report.export' => 'تصدير التقارير',
            'competency.view' => 'عرض الكفاءات',
            'competency.manage' => 'إدارة الكفاءات',
            'communication.invite' => 'إرسال دعوات',
            'user.manage' => 'إدارة المستخدمين',
            'audit.view' => 'عرض سجل التدقيق',
            'settings.manage' => 'إدارة الإعدادات',
            'analytics.view' => 'عرض التحليلات',
        ];

        $matrix = Permissions::matrix();
        $result = [];
        foreach ($matrix as $roleCode => $perms) {
            if (in_array('*', $perms)) {
                $result[$roleCode] = ['isAdmin' => true, 'permissions' => array_values($labels)];
            } else {
                $result[$roleCode] = [
                    'isAdmin' => false,
                    'permissions' => array_map(fn ($p) => $labels[$p] ?? $p, $perms),
                ];
            }
        }

        return response()->json(['rolePermissions' => $result]);
    }

    // ── القطاع إلزامي للأدوار المحصورة، ولا معنى له لسواها ──
    // يرجع جسم خطأ جاهزاً أو null. الدور يأتي كمعرّف فنقرأ رمزه من القاعدة.
    private function sectorRuleError(int $roleId, ?int $sectorId): ?array
    {
        $code = Role::whereKey($roleId)->value('code');
        $bound = in_array($code, User::SECTOR_BOUND_ROLES, true);

        if ($bound && ! $sectorId) {
            return ['errors' => ['sectorId' => ['القطاع مطلوب لهذا الدور — كل مقيّم ومساعد مخصَّص لقطاع']]];
        }
        if (! $bound && $sectorId) {
            return ['errors' => ['sectorId' => ['هذا الدور غير محصور بقطاع']]];
        }

        return null;
    }

    // ── المدير إلزامي للأدوار المُدارة ──
    // مساعد بلا مدير يكتب تقارير لا يعتمدها أحد: مرحلة مدير التقييم تشترط أن
    // يكون الكاتب من فريق المعتمِد، فالتقرير يعلق. والمدير يجب أن يكون فعلاً
    // من يملك تلك المرحلة، لا أيّ مستخدم.
    private function managerRuleError(int $roleId, ?int $managerId): ?array
    {
        $code = Role::whereKey($roleId)->value('code');
        $managed = in_array($code, User::MANAGED_ROLES, true);

        if ($managed && ! $managerId) {
            return ['errors' => ['managerId' => ['المدير مطلوب لهذا الدور — تقاريره يعتمدها مديره']]];
        }
        if (! $managed && $managerId) {
            return ['errors' => ['managerId' => ['هذا الدور لا يُسنَد لمدير']]];
        }
        if ($managerId) {
            $manager = User::with('role')->find($managerId);
            if (! $manager || ! $manager->hasPermission(Permissions::REPORT_APPROVE_MANAGER)) {
                return ['errors' => ['managerId' => ['المدير المختار لا يملك اعتماد تقارير فريقه']]];
            }
        }

        return null;
    }

    // هل المُسنِد مدير نظام ('*')؟ فقط دور ADMIN يحمل '*' (غير قابل للتفويض)
    private function actorIsAdmin(User $actor): bool
    {
        return in_array('*', Permissions::forRole($actor->role->code), true);
    }

    // ── سقف إسناد الدور ──
    // لا يُسنِد المستخدمُ دوراً يملك صلاحيةً لا يملكها هو، ولا دورَ مدير النظام
    // إلا مديرُ نظام — وإلا صار تفويض USER_MANAGE لغير مدير باباً للترقية إلى ADMIN.
    private function roleAssignmentError(User $actor, int $roleId): ?string
    {
        if ($this->actorIsAdmin($actor)) {
            return null; // مدير النظام يُسند أي دور
        }
        $code = Role::whereKey($roleId)->value('code');
        $rolePerms = Permissions::forRole($code ?? '');
        if (in_array('*', $rolePerms, true)) {
            return 'لا يمكنك إسناد دور مدير النظام';
        }
        foreach ($rolePerms as $p) {
            if (! $actor->hasPermission($p)) {
                return 'لا يمكنك إسناد دور بصلاحيات تفوق صلاحياتك';
            }
        }

        return null;
    }

    // ── هل المستهدَف يفوق المُسنِد؟ (يحمل '*' أو صلاحيةً لا يملكها المُسنِد) ──
    // يمنع مديرَ مستخدمين مفوَّضاً من تعطيل/إعادة تعيين/تعديل حساب أعلى منه رتبةً.
    private function targetOutranksActor(User $actor, User $target): bool
    {
        if ($this->actorIsAdmin($actor)) {
            return false; // مدير النظام يدير الجميع
        }
        if (in_array('*', Permissions::forRole($target->role->code), true)) {
            return true; // المستهدف مدير نظام
        }
        foreach (Permissions::all() as $p) {
            if ($target->hasPermission($p) && ! $actor->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    public function store(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إضافة مستخدم'], 403);
        }

        $userType = $request->input('userType') === 'internal' ? 'internal' : 'external';
        $rules = [
            'username' => 'required|string|max:80|unique:users,username',
            'fullName' => 'required|string|max:200',
            'email' => 'nullable|email',
            'roleId' => 'required|exists:roles,id',
            'sectorId' => 'nullable|exists:sectors,id',
            'managerId' => 'nullable|exists:users,id',
        ];
        if ($userType === 'internal') {
            // مستخدم داخلي: يُصادَق عبر AD — لا كلمة مرور محلية، بل معرّف AD
            $rules['adUsername'] = 'required|string|max:120';
        } else {
            $rules['password'] = ['required', 'string', new StrongPassword];
        }
        $validated = $request->validate($rules, [
            'username.unique' => 'اسم المستخدم مسجّل مسبقاً',
        ]);

        // القطاع إلزامي للأدوار المحصورة به — مقيّم بلا قطاع لا يستطيع تقييم أحد،
        // فالسماح بإنشائه يُنتج حساباً معطّلاً بصمت
        if ($err = $this->sectorRuleError($validated['roleId'], $validated['sectorId'] ?? null)) {
            return response()->json($err, 422);
        }
        if ($err = $this->managerRuleError($validated['roleId'], $validated['managerId'] ?? null)) {
            return response()->json($err, 422);
        }
        // سقف الامتياز: لا تُنشئ حساباً بدور يفوق صلاحياتك (منع ترقية إلى ADMIN)
        if ($err = $this->roleAssignmentError($request->user(), $validated['roleId'])) {
            return response()->json(['error' => $err], 403);
        }

        $user = new User;
        $user->username = $validated['username'];
        $user->full_name = $validated['fullName'];
        $user->email = $validated['email'] ?? null;
        $user->role_id = $validated['roleId'];
        $user->sector_id = $validated['sectorId'] ?? null;
        $user->manager_id = $validated['managerId'] ?? null;
        $user->user_type = $userType;
        $user->is_active = true;
        if ($userType === 'internal') {
            $user->ad_username = $validated['adUsername'];
            $user->password = Str::random(40); // غير مستخدمة — الدخول عبر AD
            $user->must_change_password = false;
        } else {
            $user->ad_username = null;
            $user->password = $validated['password'];
            $user->must_change_password = true;
        }
        $user->save();

        $this->log($request, 'CREATE_USER', $user->id, ['username' => $user->username]);

        return response()->json(['message' => 'تمت إضافة المستخدم'], 201);
    }

    public function update(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية التعديل'], 403);
        }

        $user = User::findOrFail($id);

        $validated = $request->validate([
            'fullName' => 'required|string|max:200',
            'email' => 'nullable|email',
            'roleId' => 'required|exists:roles,id',
            'sectorId' => 'nullable|exists:sectors,id',
            'managerId' => 'nullable|exists:users,id',
        ]);

        if ($user->id === $request->user()->id && $user->role_id != $validated['roleId']) {
            return response()->json(['error' => 'لا يمكنك تغيير دورك الخاص'], 422);
        }
        // لا تُعدّل حساباً يفوقك رتبةً، ولا تُرقّه لدور يفوق صلاحياتك
        if ($this->targetOutranksActor($request->user(), $user)) {
            return response()->json(['error' => 'لا يمكنك تعديل حساب أعلى صلاحيةً منك'], 403);
        }
        if ($err = $this->roleAssignmentError($request->user(), $validated['roleId'])) {
            return response()->json(['error' => $err], 403);
        }

        // تغيير الدور قد يجعله محصوراً بقطاع أو يخرجه من الحصر — تُعاد القاعدة على
        // الدور الجديد لا القديم، وإلا نشأ مقيّم بلا قطاع بترقية مستخدم قائم
        if ($err = $this->sectorRuleError($validated['roleId'], $validated['sectorId'] ?? null)) {
            return response()->json($err, 422);
        }
        if ($err = $this->managerRuleError($validated['roleId'], $validated['managerId'] ?? null)) {
            return response()->json($err, 422);
        }
        // مستخدم لا يكون مدير نفسه — حلقة تجعل قاعدة الفريق تقبله على تقريره
        if (($validated['managerId'] ?? null) === $user->id) {
            return response()->json(['errors' => ['managerId' => ['لا يكون المستخدم مدير نفسه']]], 422);
        }

        $roleChanged = $user->role_id != $validated['roleId'];
        $sectorChanged = $user->sector_id != ($validated['sectorId'] ?? null);
        $user->full_name = $validated['fullName'];
        $user->email = $validated['email'] ?? null;
        $user->role_id = $validated['roleId'];
        $user->sector_id = $validated['sectorId'] ?? null;
        $user->manager_id = $validated['managerId'] ?? null;
        $user->save();

        // تغيير الدور أو القطاع يغيّر ما يراه ويقيّمه — أبطل جلساته ليعيد الدخول بنطاقه الجديد
        if ($roleChanged || $sectorChanged) {
            $user->tokens()->delete();
        }

        // منحُ استثناءٍ اعتُمد على الدور القديم يجب ألا يتسلّل إلى الدور الجديد:
        // EVALUATOR مُنِح report.approve ثم صار DISCUSSION_EVAL كان سيبقى معتمِداً نهائياً.
        // نحذف المنوحات فقط (granted=true)؛ السحوبات تبقى فلا يُعاد امتياز أُسقط عمداً.
        if ($roleChanged) {
            $revoked = $user->permissionOverrides()->where('granted', true)->delete();
            if ($revoked > 0) {
                $this->log($request, 'CLEAR_GRANTED_OVERRIDES_ON_ROLE_CHANGE', $user->id,
                    ['username' => $user->username, 'cleared' => $revoked]);
            }
        }

        $this->log($request, 'UPDATE_USER', $user->id, ['username' => $user->username]);

        return response()->json(['message' => 'تم تحديث المستخدم']);
    }

    public function toggleActive(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'لا يمكنك تعطيل حسابك الخاص'], 422);
        }
        // لا يُعطِّل مديرُ مستخدمين مفوَّض حساباً أعلى منه رتبةً (تعطيل مدير النظام)
        if ($this->targetOutranksActor($request->user(), $user)) {
            return response()->json(['error' => 'لا يمكنك تعطيل حساب أعلى صلاحيةً منك'], 403);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        // تعطيل الحساب يُنهي جلساته فورًا (لا يبقى token فعّالًا لموظف مُوقَف)
        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        $action = $user->is_active ? 'ENABLE_USER' : 'DISABLE_USER';
        $this->log($request, $action, $user->id, ['username' => $user->username]);

        return response()->json([
            'message' => $user->is_active ? 'تم تفعيل المستخدم' : 'تم تعطيل المستخدم',
            'isActive' => $user->is_active,
        ]);
    }

    public function resetPassword(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', new StrongPassword],
        ], [
            'password.min' => 'كلمة المرور يجب أن تكون ٨ أحرف على الأقل',
        ]);

        $user = User::findOrFail($id);
        // لا يُعيد مديرُ مستخدمين مفوَّض تعيينَ كلمة مرور حساب أعلى منه رتبةً (استيلاء على مدير النظام)
        if ($this->targetOutranksActor($request->user(), $user)) {
            return response()->json(['error' => 'لا يمكنك إعادة تعيين كلمة مرور حساب أعلى صلاحيةً منك'], 403);
        }
        // مستخدم داخلي يُصادَق عبر AD بلا كلمة مرور محلية — إعادة تعيينها تحبسه في حلقة «غيّر كلمة المرور» لا يستطيع إتمامها
        if ($user->user_type === 'internal') {
            return response()->json([
                'error' => 'هذا مستخدم يُصادَق عبر الدليل النشط (AD) ولا يملك كلمة مرور محلية',
            ], 422);
        }
        // ── فكّ القفل جزءٌ من إعادة التعيين لا خطوةٌ منفصلة ──
        //
        // قفلُ المحاولات الخاطئة (خمسٌ ⇒ ربع ساعة) كان يبقى بعد إعادة التعيين،
        // و`locked_until` لا تُمسح في الشيفرة كلّها إلا عند دخولٍ ناجح — وهو
        // مستحيل أثناء القفل. فلم يكن لمسؤولٍ سبيلٌ إلى فكّ قفل حساب البتّة.
        //
        // والأثر أسوأ من الانتظار: المسؤول يُعيد التعيين، ويقول للموظّف «جرّب
        // الآن»، فيفشل الدخول بالرسالة العامة نفسها التي تُخفي السبب عمداً.
        // فيظنّان كلمة المرور لم تُحفظ، ويُعيدان الكرّة، فتُضاف محاولاتٌ خاطئة
        // تُجدّد القفل — حلقةٌ لا تنكسر إلا بصمتٍ ربعَ ساعة.
        //
        // وإعادةُ التعيين قرارُ مسؤولٍ صريح، وهو أقوى من قفلٍ آليّ ضدّ التخمين:
        // من يملك تغيير كلمة المرور يملك فتح الحساب من بابٍ آخر أصلاً.
        $wasLocked = $user->locked_until && $user->locked_until->isFuture();

        $user->password = $validated['password'];
        $user->must_change_password = true;
        $user->locked_until = null;
        $user->failed_attempts = 0;
        $user->save();

        // إعادة تعيين كلمة المرور تُبطل كل الجلسات القائمة (طرد أي مُخترِق محتمل)
        $user->tokens()->delete();

        $this->log($request, 'RESET_PASSWORD', $user->id, [
            'username' => $user->username,
            'wasLocked' => $wasLocked,
        ]);

        // يُقال للمسؤول إن الحساب كان مقفلاً: القفل أثرُ تخمينٍ متكرّر، وهو
        // خبرٌ أمنيّ يستحقّ أن يُرى لا أن يُمحى بصمت.
        return response()->json([
            'message' => $wasLocked
                ? 'تم إعادة تعيين كلمة المرور وفكّ قفل الحساب — كان مقفلاً بعد محاولات دخول خاطئة'
                : 'تم إعادة تعيين كلمة المرور',
            'wasLocked' => $wasLocked,
        ]);
    }

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
