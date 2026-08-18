<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// ════════════════════════════════════════════════════════════
//  إدارة الأدوار وصلاحياتها.
//
//  تعديل الدور يمسّ **كل من يحمله** — بخلاف استثناء المستخدم الذي يمسّ واحداً.
//  ولهذا كانت المصفوفة ثابتةً في الشيفرة أوّلاً. فتحُها هنا مقصود (صاحب المنصّة
//  يريد ضبط الصلاحيات بنفسه)، لكن بحرّاس أربعة لا يُتجاوَز أيٌّ منها:
//
//   ١) دور مدير النظام لا يُعدَّل ولا يُحذف — لو سُحبت منه USER_MANAGE لأُغلق
//      باب الإدارة على الجميع ولا سبيل للعودة إلا من قاعدة البيانات.
//   ٢) لا أحد يعدّل الدور الذي يحمله هو — وإلا منح نفسه كل شيء.
//   ٣) لا يُمنح دورٌ صلاحيةً لا يملكها المُعدِّل نفسه — سقفٌ لا مَنعٌ مطلق،
//      وهو نفس سقف إنشاء المستخدمين في UserController.
//   ٤) الصلاحيات غير القابلة للتفويض تبقى كما هي — تُدار بالدور المبذور لا
//      بالتحرير، فمنحُها بالتحرير يلتفّ على كونها غير قابلة للتفويض أصلاً.
// ════════════════════════════════════════════════════════════

class RoleController extends Controller
{
    // أدوار لا تُمسّ بنيتها: رمزها مقترن بمنطق في الشيفرة
    private const PROTECTED_CODES = ['ADMIN'];

    private function deny(string $m)
    {
        return response()->json(['error' => $m], 403);
    }

    private function log(Request $request, string $action, $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'role',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    // الصلاحيات الفعلية للمُعدِّل، مفرودةً — أساس السقف في الحارس الثالث
    private function actorPermissions(User $actor): array
    {
        $perms = $actor->effectivePermissions();
        return in_array('*', $perms, true) ? Permissions::all() : $perms;
    }

    // ── قائمة الأدوار مع عدد حامليها ──
    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return $this->deny('ليس لديك صلاحية إدارة الأدوار');
        }

        $counts = User::selectRaw('role_id, count(*) as n')->groupBy('role_id')
            ->pluck('n', 'role_id');

        $rows = Role::orderBy('name_ar')->get()->map(fn (Role $r) => [
            'id' => $r->id,
            'code' => $r->code,
            'nameAr' => $r->name_ar,
            'description' => $r->description,
            'users' => (int) ($counts[$r->id] ?? 0),
            'permissionCount' => count(Permissions::forRole($r->code)),
            // مبنيّ في الشيفرة: لا يُحذف ولا يُعدَّل رمزه
            'protected' => in_array($r->code, self::PROTECTED_CODES, true),
            // هل حُرِّرت صلاحياته أم ما زالت الافتراضية؟
            'customised' => Permissions::roleIsCustomised($r->code),
        ]);

        return response()->json(['roles' => $rows]);
    }

    // ── صلاحيات دور بعينه، مجمّعة كما تُعرض ──
    public function permissions(Request $request, int $id)
    {
        $actor = $request->user();
        if (!$actor->hasPermission(Permissions::USER_MANAGE)) {
            return $this->deny('ليس لديك صلاحية إدارة الأدوار');
        }

        $role = Role::find($id);
        if (!$role) {
            return response()->json(['error' => 'الدور غير موجود'], 404);
        }

        $current = Permissions::forRole($role->code);
        $hasStar = in_array('*', $current, true);
        $actorPerms = $this->actorPermissions($actor);
        $isSelf = $actor->role_id === $role->id;
        $isProtected = in_array($role->code, self::PROTECTED_CODES, true);

        $groups = [];
        foreach (Permissions::grouped() as $key => $group) {
            $perms = [];
            foreach ($group['permissions'] as $p) {
                $perms[] = [
                    'permission' => $p,
                    'label' => Permissions::label($p),
                    'granted' => $hasStar || in_array($p, $current, true),
                    // الواجهة تُعطّل ما لا يجوز تبديله، والخادم يفرضه على أي حال
                    'lockedReason' => $this->lockReason($p, $actorPerms, $isSelf, $isProtected),
                ];
            }
            $groups[] = ['key' => $key, 'label' => $group['label'], 'permissions' => $perms];
        }

        return response()->json([
            'role' => [
                'id' => $role->id,
                'code' => $role->code,
                'nameAr' => $role->name_ar,
                'description' => $role->description,
                'protected' => $isProtected,
                'isOwnRole' => $isSelf,
                'customised' => Permissions::roleIsCustomised($role->code),
                'users' => User::where('role_id', $role->id)->count(),
            ],
            'groups' => $groups,
        ]);
    }

    // سبب قفل صلاحية عن التعديل — أو null إن كانت قابلة للتبديل
    private function lockReason(string $p, array $actorPerms, bool $isSelf, bool $isProtected): ?string
    {
        if ($isProtected) return 'دور مدير النظام لا يُعدَّل';
        if ($isSelf) return 'لا تعدّل صلاحيات دورك';
        if (in_array($p, Permissions::NON_DELEGABLE, true)) {
            return 'سلطة نظام تُدار بالدور المبذور لا بالتحرير';
        }
        if (!in_array($p, $actorPerms, true)) {
            return 'لا تملك هذه الصلاحية بنفسك';
        }
        return null;
    }

    // ── حفظ صلاحيات دور ──
    public function savePermissions(Request $request, int $id)
    {
        $actor = $request->user();
        if (!$actor->hasPermission(Permissions::USER_MANAGE)) {
            return $this->deny('ليس لديك صلاحية إدارة الأدوار');
        }

        $validated = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $role = Role::find($id);
        if (!$role) {
            return response()->json(['error' => 'الدور غير موجود'], 404);
        }
        if (in_array($role->code, self::PROTECTED_CODES, true)) {
            return response()->json(['error' => 'دور مدير النظام لا تُعدَّل صلاحياته'], 422);
        }
        if ($actor->role_id === $role->id) {
            return response()->json(['error' => 'لا يمكنك تعديل صلاحيات دورك'], 422);
        }

        $wanted = collect($validated['permissions'])->unique()->values();
        $before = Permissions::forRole($role->code);
        $actorPerms = $this->actorPermissions($actor);

        // الحارس الثالث: لا يُمنح ما لا يملكه المُعدِّل. يُفحص المضاف فقط —
        // صلاحيةٌ كانت للدور من قبل ولا يملكها المُعدِّل تبقى، ولا تُعدّ تصعيداً.
        $added = $wanted->diff($before);
        $beyond = $added->reject(fn ($p) => in_array($p, $actorPerms, true))->values();
        if ($beyond->isNotEmpty()) {
            $this->log($request, 'DENIED_ROLE_ESCALATION', $role->id, ['attempted' => $beyond->all()]);
            return response()->json([
                'error' => 'لا تُمنح صلاحيةً لا تملكها: ' . $beyond->implode('، '),
            ], 422);
        }

        // الحارس الرابع: غير القابلة للتفويض لا تُضاف ولا تُسحب من هنا
        $nonDelegableChanged = collect(Permissions::NON_DELEGABLE)
            ->filter(fn ($p) => in_array($p, $before, true) !== $wanted->contains($p))
            ->values();
        if ($nonDelegableChanged->isNotEmpty()) {
            return response()->json([
                'error' => 'سلطات النظام لا تُعدَّل من هنا: ' . $nonDelegableChanged->implode('، '),
            ], 422);
        }

        DB::transaction(function () use ($role, $wanted, $actor) {
            RolePermission::where('role_id', $role->id)->delete();

            // الفراغ المقصود يُمثَّل بصفٍّ واحد. لولاه لحذفت الصفوف كلها فيقع
            // الدور على المصفوفة ويستعيد صلاحياته الافتراضية — أي أن التجريد
            // الكامل ينقلب إلى استعادة صامتة.
            $rows = ($wanted->isEmpty() ? collect([Permissions::PLACEHOLDER]) : $wanted)
                ->map(fn ($p) => [
                    'role_id' => $role->id,
                    'permission' => $p,
                    'updated_by' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

            RolePermission::insert($rows);
        });

        Permissions::forgetCache();

        $after = Permissions::forRole($role->code);
        $this->log($request, 'ROLE_PERMISSIONS_SAVED', $role->id, [
            'role' => $role->code,
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
            'affectedUsers' => User::where('role_id', $role->id)->count(),
        ]);

        return response()->json([
            'saved' => true,
            'permissionCount' => count($after),
            'affectedUsers' => User::where('role_id', $role->id)->count(),
        ]);
    }

    // ── إنشاء دور جديد ──
    public function store(Request $request)
    {
        $actor = $request->user();
        if (!$actor->hasPermission(Permissions::USER_MANAGE)) {
            return $this->deny('ليس لديك صلاحية إدارة الأدوار');
        }

        $validated = $request->validate([
            // الرمز مفاتيحُ منطقٍ في الشيفرة — لاتيني كبير بلا فراغات
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z][A-Z0-9_]*$/', 'unique:roles,code'],
            'nameAr' => 'required|string|max:100',
            'description' => 'nullable|string|max:300',
        ], [
            'code.regex' => 'الرمز بأحرف لاتينية كبيرة وأرقام وشرطة سفلية فقط',
            'code.unique' => 'هذا الرمز مستعمل لدورٍ آخر',
        ]);

        $role = Role::create([
            'code' => $validated['code'],
            'name_ar' => $validated['nameAr'],
            'description' => $validated['description'] ?? null,
        ]);

        // يُولد بلا صلاحية إطلاقاً — وبعلامة الفراغ المقصود كي لا يقع على
        // المصفوفة (لا مدخل له فيها أصلاً، لكن الصراحة أسلم من الاعتماد على ذلك)
        RolePermission::create([
            'role_id' => $role->id,
            'permission' => Permissions::PLACEHOLDER,
            'updated_by' => $actor->id,
        ]);
        Permissions::forgetCache();

        $this->log($request, 'ROLE_CREATED', $role->id, ['code' => $role->code]);

        return response()->json(['role' => [
            'id' => $role->id, 'code' => $role->code, 'nameAr' => $role->name_ar,
        ]], 201);
    }

    // ── تعديل اسم الدور ووصفه (لا رمزه: الرمز مفتاح منطق) ──
    public function update(Request $request, int $id)
    {
        $actor = $request->user();
        if (!$actor->hasPermission(Permissions::USER_MANAGE)) {
            return $this->deny('ليس لديك صلاحية إدارة الأدوار');
        }

        $validated = $request->validate([
            'nameAr' => 'required|string|max:100',
            'description' => 'nullable|string|max:300',
        ]);

        $role = Role::find($id);
        if (!$role) {
            return response()->json(['error' => 'الدور غير موجود'], 404);
        }

        $role->update(['name_ar' => $validated['nameAr'], 'description' => $validated['description'] ?? null]);
        $this->log($request, 'ROLE_UPDATED', $role->id, ['code' => $role->code]);

        return response()->json(['saved' => true]);
    }

    // ── حذف دور ──
    public function destroy(Request $request, int $id)
    {
        $actor = $request->user();
        if (!$actor->hasPermission(Permissions::USER_MANAGE)) {
            return $this->deny('ليس لديك صلاحية إدارة الأدوار');
        }

        $role = Role::find($id);
        if (!$role) {
            return response()->json(['error' => 'الدور غير موجود'], 404);
        }
        if (in_array($role->code, self::PROTECTED_CODES, true)) {
            return response()->json(['error' => 'دور مدير النظام لا يُحذف'], 422);
        }
        if ($actor->role_id === $role->id) {
            return response()->json(['error' => 'لا يمكنك حذف دورك'], 422);
        }

        // حاملوه أولاً: حذفه وهم عليه يترك حساباتٍ بلا دور، وكلُّ فحص صلاحية
        // يمرّ بـ$user->role->code فيسقط بخطأ 500 عند أول طلب لهم
        $holders = User::where('role_id', $role->id)->count();
        if ($holders > 0) {
            return response()->json([
                'error' => "الدور مُسنَد إلى {$holders} مستخدماً — انقلهم إلى دورٍ آخر أولاً",
            ], 422);
        }

        $code = $role->code;
        $role->delete();   // role_permissions تتبعه بـcascade
        Permissions::forgetCache();
        $this->log($request, 'ROLE_DELETED', $id, ['code' => $code]);

        return response()->json(['deleted' => true]);
    }

    // ── إعادة دور إلى صلاحياته الافتراضية ──
    public function reset(Request $request, int $id)
    {
        $actor = $request->user();
        if (!$actor->hasPermission(Permissions::USER_MANAGE)) {
            return $this->deny('ليس لديك صلاحية إدارة الأدوار');
        }

        $role = Role::find($id);
        if (!$role) {
            return response()->json(['error' => 'الدور غير موجود'], 404);
        }
        if ($actor->role_id === $role->id) {
            return response()->json(['error' => 'لا يمكنك تعديل صلاحيات دورك'], 422);
        }
        if (!isset(Permissions::matrix()[$role->code])) {
            return response()->json(['error' => 'هذا الدور لا افتراضي له — أُنشئ من الشاشة'], 422);
        }

        $before = Permissions::forRole($role->code);
        DB::transaction(function () use ($role, $actor) {
            RolePermission::where('role_id', $role->id)->delete();
            $rows = collect(Permissions::matrix()[$role->code])->map(fn ($p) => [
                'role_id' => $role->id, 'permission' => $p, 'updated_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ])->all();
            RolePermission::insert($rows);
        });
        Permissions::forgetCache();

        $this->log($request, 'ROLE_PERMISSIONS_RESET', $role->id, [
            'code' => $role->code, 'before' => count($before),
        ]);

        return response()->json(['reset' => true, 'permissionCount' => count(Permissions::forRole($role->code))]);
    }
}
