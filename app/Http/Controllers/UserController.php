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

        $users = User::with(['role', 'sector'])->orderBy('full_name')->get()->map(fn ($u) => [
            'id' => $u->id,
            'username' => $u->username,
            'fullName' => $u->full_name,
            'email' => $u->email,
            'roleCode' => $u->role->code,
            'roleName' => $u->role->name_ar,
            'sectorId' => $u->sector_id,
            'sectorName' => $u->sector?->name_ar,
            'sectorBound' => $u->isSectorBound(),
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

        $user = new User();
        $user->username = $validated['username'];
        $user->full_name = $validated['fullName'];
        $user->email = $validated['email'] ?? null;
        $user->role_id = $validated['roleId'];
        $user->sector_id = $validated['sectorId'] ?? null;
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
        ]);

        if ($user->id === $request->user()->id && $user->role_id != $validated['roleId']) {
            return response()->json(['error' => 'لا يمكنك تغيير دورك الخاص'], 422);
        }

        // تغيير الدور قد يجعله محصوراً بقطاع أو يخرجه من الحصر — تُعاد القاعدة على
        // الدور الجديد لا القديم، وإلا نشأ مقيّم بلا قطاع بترقية مستخدم قائم
        if ($err = $this->sectorRuleError($validated['roleId'], $validated['sectorId'] ?? null)) {
            return response()->json($err, 422);
        }

        $roleChanged = $user->role_id != $validated['roleId'];
        $sectorChanged = $user->sector_id != ($validated['sectorId'] ?? null);
        $user->full_name = $validated['fullName'];
        $user->email = $validated['email'] ?? null;
        $user->role_id = $validated['roleId'];
        $user->sector_id = $validated['sectorId'] ?? null;
        $user->save();

        // تغيير الدور أو القطاع يغيّر ما يراه ويقيّمه — أبطل جلساته ليعيد الدخول بنطاقه الجديد
        if ($roleChanged || $sectorChanged) {
            $user->tokens()->delete();
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
