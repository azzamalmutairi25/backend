<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Rules\StrongPassword;
use App\Services\ActiveDirectoryService;
use App\Security\Permissions;

// ════════════════════════════════════════════════════════════
//  وحدة التحكم بالمصادقة
// ════════════════════════════════════════════════════════════

class AuthController extends Controller
{
    // تجزئة وهمية ثابتة لمعادلة زمن التحقّق حين لا وجود للمستخدم — وإلا كشف الفرق
    // الزمني (تخطّي bcrypt) أن الاسم غير موجود، فأمكن تعداد أسماء المستخدمين.
    private const DUMMY_HASH = '$2y$12$YwqTC.sPbtHwy.Ov/nBvwOqNK2ft9pirSGve.bnpW/3zLFjzdduTS';

    // ردّ عام موحّد — لا يفرّق بين اسم غير موجود، كلمة مرور خاطئة، أو حساب مقفل،
    // كي لا يصير الردّ أداةَ تعداد لأسماء المستخدمين أو كشفٍ لحالة القفل.
    private function invalidCredentials(): ValidationException
    {
        return ValidationException::withMessages([
            'username' => ['اسم المستخدم أو كلمة المرور غير صحيحة'],
        ]);
    }

    // ── تسجيل الدخول ──
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)->where('is_active', true)->first();

        // القفل يبقى مُنفَّذاً لكن بردّ عام — لا يُكشف أن الحساب موجود/مقفل ولا المدّة المتبقّية
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            throw $this->invalidCredentials();
        }

        // التحقق من كلمة المرور: مستخدم داخلي عبر Active Directory، وإلا محليًا
        $passwordOk = false;
        if ($user) {
            if ($user->user_type === 'internal') {
                $adResult = ActiveDirectoryService::authenticate((string) $user->ad_username, $request->password);
                if ($adResult === null) {
                    // خلل تهيئة الدليل يُسجَّل خادمياً ولا يُكشف للعميل (تفادي كشف وجود/نوع الحساب)
                    \Illuminate\Support\Facades\Log::warning('AD authentication unavailable', ['username' => $user->username]);
                    throw $this->invalidCredentials();
                }
                $passwordOk = $adResult === true;
            } else {
                $passwordOk = Hash::check($request->password, $user->password);
            }
        } else {
            // اسم غير موجود: نُشغّل bcrypt على تجزئة وهمية لمعادلة الزمن مع «موجود بكلمة خاطئة»
            Hash::check($request->password, self::DUMMY_HASH);
        }

        if (!$user || !$passwordOk) {
            if ($user) {
                $user->increment('failed_attempts');
                // اقرأ العدّاد الحقيقي من القاعدة بعد الزيادة الذرّية (لا القيمة المحلية) — يمنع تجاوز القفل بدفعة متزامنة
                if ($user->fresh()->failed_attempts >= 5) {
                    $user->update([
                        'locked_until' => now()->addMinutes(15),
                        'failed_attempts' => 0,
                    ]);
                    AuditLog::create([
                        'user_id' => $user->id,
                        'action' => 'ACCOUNT_LOCKED',
                        'entity_type' => 'user',
                        'entity_id' => (string) $user->id,
                        'ip_address' => $request->ip(),
                        'created_at' => now(),
                    ]);
                    // ردّ عام حتى عند القفل — لا يُكشف أن الحساب موجود وقُفل الآن
                    throw $this->invalidCredentials();
                }
            }
            throw $this->invalidCredentials();
        }

        $user->update([
            'last_login_at' => now(),
            'failed_attempts' => 0,
            'locked_until' => null,
        ]);

        // إصدار رمز Sanctum
        $token = $user->createToken('auth-token')->plainTextToken;

        // تسجيل في سجل التدقيق
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'LOGIN',
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'fullName' => $user->full_name,
                'role' => $user->role->code,
                'roleName' => $user->role->name_ar,
                'mustChangePassword' => $user->must_change_password,
                'permissions' => $user->effectivePermissions(),
            ],
        ]);
    }

    // ── بيانات المستخدم الحالي ──
    public function me(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'fullName' => $user->full_name,
            'role' => $user->role->code,
            'roleName' => $user->role->name_ar,
            'permissions' => $user->effectivePermissions(),
        ]);
    }

    // ── تسجيل الخروج ──
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'تم تسجيل الخروج']);
    }

    // ── تغيير كلمة المرور ──
    public function changePassword(Request $request)
    {
        $request->validate([
            'currentPassword' => 'required|string',
            'newPassword' => ['required', 'string', new StrongPassword()],
        ]);

        $user = $request->user();
        if (!Hash::check($request->currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['كلمة المرور الحالية غير صحيحة'],
            ]);
        }

        if (Hash::check($request->newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'newPassword' => ['كلمة المرور الجديدة يجب أن تختلف عن الحالية'],
            ]);
        }

        $user->update([
            'password' => $request->newPassword,
            'must_change_password' => false,
        ]);

        // تغيير كلمة المرور يُبطل الجلسات الأخرى (يطرد أي جلسة مسروقة)، ويبقي الحالية
        $current = $request->user()->currentAccessToken();
        $user->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current->id))->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'CHANGE_OWN_PASSWORD',
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح']);
    }
}
