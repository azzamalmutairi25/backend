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
    // ── تسجيل الدخول ──
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)->where('is_active', true)->first();

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            $minutes = ceil(now()->diffInSeconds($user->locked_until) / 60);
            throw ValidationException::withMessages([
                'username' => ["الحساب مقفل مؤقتاً بسبب محاولات فاشلة. حاول بعد {$minutes} دقيقة"],
            ]);
        }

        // التحقق من كلمة المرور: مستخدم داخلي عبر Active Directory، وإلا محليًا
        $passwordOk = false;
        if ($user) {
            if ($user->user_type === 'internal') {
                $adResult = ActiveDirectoryService::authenticate((string) $user->ad_username, $request->password);
                if ($adResult === null) {
                    throw ValidationException::withMessages([
                        'username' => ['مصادقة الدليل (AD) غير مُهيّأة — راجع الإعدادات أو تواصل مع المشرف'],
                    ]);
                }
                $passwordOk = $adResult === true;
            } else {
                $passwordOk = Hash::check($request->password, $user->password);
            }
        }

        if (!$user || !$passwordOk) {
            if ($user) {
                $user->increment('failed_attempts');
                if ($user->failed_attempts >= 5) {
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
                    throw ValidationException::withMessages([
                        'username' => ['تم قفل الحساب ١٥ دقيقة بسبب ٥ محاولات فاشلة'],
                    ]);
                }
            }
            throw ValidationException::withMessages([
                'username' => ['اسم المستخدم أو كلمة المرور غير صحيحة'],
            ]);
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
                'permissions' => Permissions::forRole($user->role->code),
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
            'permissions' => Permissions::forRole($user->role->code),
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
