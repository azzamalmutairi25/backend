<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Sector;
use App\Security\Permissions;
use Illuminate\Http\Request;

class SectorController extends Controller
{
    public function index(Request $request)
    {
        // البادئة إعداد إداري — تُعرض لمن يدير الإعدادات فقط
        $canManage = $request->user()->hasPermission(Permissions::SETTINGS_MANAGE);

        $sectors = Sector::orderBy('name_ar')->get()->map(function ($s) use ($canManage) {
            $row = [
                'id' => $s->id,
                'code' => $s->code,
                'nameAr' => $s->name_ar,
                'isMilitary' => $s->is_military,
            ];
            // البادئة تُعرض لمدير الإعدادات فقط — المفتاح غائب لسواه لا فارغ
            if ($canManage) {
                $row['participantPrefix'] = $s->participant_prefix ?: strtoupper(substr($s->code, 0, 2));
            }
            return $row;
        });

        return response()->json(['sectors' => $sectors]);
    }

    // PUT /sectors/{id}/prefix — رمز مشارك القطاع (الأرقام تبقى تلقائية بعده)
    public function updatePrefix(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $validated = $request->validate([
            // حروف لاتينية كبيرة وأرقام، ٢–٤ خانات — تدخل في «PREFIX-001»
            'prefix' => ['required', 'string', 'regex:/^[A-Z0-9]{2,4}$/'],
        ], [
            'prefix.regex' => 'الرمز حرفان إلى أربعة، لاتيني كبير أو أرقام (مثل ED أو HR2)',
        ]);

        $sector = Sector::findOrFail($id);
        $prefix = strtoupper($validated['prefix']);

        // البادئة فريدة: تكرارها يخلط رموز قطاعين في تسلسل واحد
        $clash = Sector::where('participant_prefix', $prefix)->where('id', '!=', $id)->first();
        if ($clash) {
            return response()->json([
                'errors' => ['prefix' => ["الرمز مستعمل في قطاع «{$clash->name_ar}»"]],
            ], 422);
        }

        $old = $sector->participant_prefix;
        $sector->update(['participant_prefix' => $prefix]);

        // تغيير الرمز قرار إداري يمسّ كل رمز جديد بعده — يُدوَّن بحالتيه.
        // الرموز القائمة لا تتغيّر: رمز المشارك مُثبَّت على دورته لا مشتقّ عند العرض.
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'UPDATE_SECTOR_PREFIX',
            'entity_type' => 'sector',
            'entity_id' => (string) $sector->id,
            'details' => ['sector' => $sector->name_ar, 'from' => $old, 'to' => $prefix],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'تم تحديث رمز القطاع']);
    }
}
