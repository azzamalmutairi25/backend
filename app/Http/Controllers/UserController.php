<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Rules\StrongPassword;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة المستخدمين'], 403);
        }

        $users = User::with(['role', 'sector', 'manager'])->orderBy('full_name')->get()->map(fn ($u) => [
            'id' => $u->id,
            'username' => $u->username,
            'fullName' => $u->full_name,
            'email' => $u->email,
            'roleCode' => $u->role->code,
            'roleName' => $u->role->name_ar,
            'sectorId' => $u->sector_id,
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

        return response()->json(['users' => $users]);
    }

    // GET /users/{id}/permissions — الصلاحيات الفعلية + مصدر كل واحدة
    public function permissions(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
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
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
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
                    'استثناء يطابق الدور بلا فائدة: ' . $redundant->pluck('permission')->implode('، '),
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
                'هذه الصلاحيات تُدار بالدور لا بالاستثناء الفردي: ' . $nonDelegable->implode('، '),
            ]]], 422);
        }

        // (٢) لا يُمنح ما لا يملكه المانح نفسه — لا يُفوَّض ما لا تملكه
        $cannotGrant = $incoming->filter(fn ($o) => $o['granted'] && !$actor->hasPermission($o['permission']));
        if ($cannotGrant->isNotEmpty()) {
            return response()->json(['errors' => ['overrides' => [
                'لا يمكنك منح صلاحية لا تملكها: ' . $cannotGrant->pluck('permission')->implode('، '),
            ]]], 403);
        }

        // (٣) لا يُعدّل صلاحيات حامل '*' (مدير النظام) إلا حاملٌ لـ'*' مثله — منع قفل المدراء
        if (in_array('*', Permissions::forRole($user->role->code), true) && !$actorHasStar) {
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

    public function roles(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
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
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $labels = [
            'candidate.view' => 'عرض المرشحين',
            'candidate.create' => 'إضافة مرشح',
            'candidate.edit' => 'تعديل مرشح',
            'candidate.approve' => 'اعتماد مرشح',
            'candidate.view_names' => 'رؤية الأسماء الحساسة',
            'candidate.view_classified' => 'رؤية المرشحين المصنّفين',
            'candidate.cv_view' => 'عرض السيرة الذاتية',
            'schedule.view' => 'عرض الجدولة',
            'schedule.manage' => 'إدارة الجدولة',
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

        $matrix = \App\Security\Permissions::matrix();
        $result = [];
        foreach ($matrix as $roleCode => $perms) {
            if (in_array('*', $perms)) {
                $result[$roleCode] = ['isAdmin' => true, 'permissions' => array_values($labels)];
            } else {
                $result[$roleCode] = [
                    'isAdmin' => false,
                    'permissions' => array_map(fn($p) => $labels[$p] ?? $p, $perms),
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

        if ($bound && !$sectorId) {
            return ['errors' => ['sectorId' => ['القطاع مطلوب لهذا الدور — كل مقيّم ومساعد مخصَّص لقطاع']]];
        }
        if (!$bound && $sectorId) {
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

        if ($managed && !$managerId) {
            return ['errors' => ['managerId' => ['المدير مطلوب لهذا الدور — تقاريره يعتمدها مديره']]];
        }
        if (!$managed && $managerId) {
            return ['errors' => ['managerId' => ['هذا الدور لا يُسنَد لمدير']]];
        }
        if ($managerId) {
            $manager = User::with('role')->find($managerId);
            if (!$manager || !$manager->hasPermission(Permissions::REPORT_APPROVE_MANAGER)) {
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
            if (!$actor->hasPermission($p)) {
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
            if ($target->hasPermission($p) && !$actor->hasPermission($p)) {
                return true;
            }
        }
        return false;
    }

    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
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
            $rules['password'] = ['required', 'string', new StrongPassword()];
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

        $user = new User();
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
            $user->password = \Illuminate\Support\Str::random(40); // غير مستخدمة — الدخول عبر AD
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
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
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
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
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

        $user->is_active = !$user->is_active;
        $user->save();

        // تعطيل الحساب يُنهي جلساته فورًا (لا يبقى token فعّالًا لموظف مُوقَف)
        if (!$user->is_active) {
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
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', new StrongPassword()],
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
        $user->password = $validated['password'];
        $user->must_change_password = true;
        $user->save();

        // إعادة تعيين كلمة المرور تُبطل كل الجلسات القائمة (طرد أي مُخترِق محتمل)
        $user->tokens()->delete();

        $this->log($request, 'RESET_PASSWORD', $user->id, ['username' => $user->username]);

        return response()->json(['message' => 'تم إعادة تعيين كلمة المرور']);
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
