<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Rules\StrongPassword;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة المستخدمين'], 403);
        }

        $users = User::with('role')->orderBy('full_name')->get()->map(fn ($u) => [
            'id' => $u->id,
            'username' => $u->username,
            'fullName' => $u->full_name,
            'email' => $u->email,
            'roleCode' => $u->role->code,
            'roleName' => $u->role->name_ar,
            'userType' => $u->user_type,
            'adUsername' => $u->ad_username,
            'isActive' => $u->is_active,
            'mustChangePassword' => $u->must_change_password,
            'lastLoginAt' => $u->last_login_at,
            'isSelf' => $u->id === $request->user()->id,
        ]);

        return response()->json(['users' => $users]);
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

        $user = new User();
        $user->username = $validated['username'];
        $user->full_name = $validated['fullName'];
        $user->email = $validated['email'] ?? null;
        $user->role_id = $validated['roleId'];
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
        ]);

        if ($user->id === $request->user()->id && $user->role_id != $validated['roleId']) {
            return response()->json(['error' => 'لا يمكنك تغيير دورك الخاص'], 422);
        }

        $roleChanged = $user->role_id != $validated['roleId'];
        $user->full_name = $validated['fullName'];
        $user->email = $validated['email'] ?? null;
        $user->role_id = $validated['roleId'];
        $user->save();

        // تغيير الدور يغيّر الصلاحيات — أبطل جلسات المستخدم ليعيد الدخول بصلاحياته الجديدة
        if ($roleChanged) {
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
