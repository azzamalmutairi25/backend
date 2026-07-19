<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Sector;
use App\Models\User;
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

    // POST /sectors — إضافة قطاع جديد (رمز + اسم + بادئة مشارك)
    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{2,10}$/', 'unique:sectors,code'],
            'nameAr' => 'required|string|max:100',
            'isMilitary' => 'boolean',
            'participantPrefix' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{2,4}$/'],
        ], [
            'code.regex' => 'الرمز حرفان إلى عشرة، لاتيني أو أرقام',
            'code.unique' => 'الرمز مستعمل لقطاع آخر',
            'participantPrefix.regex' => 'بادئة المشارك حرفان إلى أربعة (مثل ED)',
        ]);

        $code = strtoupper($validated['code']);
        $prefix = strtoupper($validated['participantPrefix'] ?? substr($code, 0, 2));

        // بادئة المشارك فريدة — تكرارها يخلط رموز قطاعين في تسلسل واحد
        if (Sector::where('participant_prefix', $prefix)->exists()) {
            return response()->json(['errors' => ['participantPrefix' => ['البادئة مستعملة — اختر غيرها']]], 422);
        }

        $sector = Sector::create([
            'code' => $code,
            'name_ar' => $validated['nameAr'],
            'is_military' => $request->boolean('isMilitary'),
            'participant_prefix' => $prefix,
        ]);

        $this->audit($request, 'CREATE_SECTOR', $sector);

        return response()->json(['message' => 'تمت إضافة القطاع', 'sectorId' => $sector->id], 201);
    }

    // PUT /sectors/{id} — تعديل الاسم/التصنيف العسكري (الرمز ثابت: هويّة القطاع)
    public function update(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $sector = Sector::find($id);
        if (!$sector) {
            return response()->json(['error' => 'القطاع غير موجود'], 404);
        }

        $validated = $request->validate([
            'nameAr' => 'required|string|max:100',
            'isMilitary' => 'boolean',
        ]);

        // الرمز والبادئة لا يُعدَّلان هنا: رمز المشارك مُثبَّت على دورته، وتغيير الرمز
        // يُيتّم المرجعية (البادئة تُعدَّل عبر updatePrefix بحارسها الخاص).
        $sector->update([
            'name_ar' => $validated['nameAr'],
            'is_military' => $request->boolean('isMilitary', $sector->is_military),
        ]);

        $this->audit($request, 'UPDATE_SECTOR', $sector);

        return response()->json(['message' => 'تم تحديث القطاع']);
    }

    // DELETE /sectors/{id} — حذف قطاع (يُمنع إن ارتبط بمرشحين أو مستخدمين)
    public function destroy(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $sector = Sector::find($id);
        if (!$sector) {
            return response()->json(['error' => 'القطاع غير موجود'], 404);
        }

        // حذف قطاع مرتبط يُيتّم مرشحيه/مستخدميه — يُمنع بدل كسر المرجعية
        if (Candidate::where('sector_id', $id)->exists() || User::where('sector_id', $id)->exists()) {
            return response()->json(['error' => 'لا يمكن حذف قطاع مرتبط بمرشحين أو مستخدمين'], 422);
        }

        $code = $sector->code;
        $sector->delete();
        $this->audit($request, 'DELETE_SECTOR', $sector, ['code' => $code]);

        return response()->json(['message' => 'تم حذف القطاع']);
    }

    private function audit(Request $request, string $action, Sector $sector, array $extra = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'sector',
            'entity_id' => (string) $sector->id,
            'details' => array_merge(['name' => $sector->name_ar, 'code' => $sector->code], $extra),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
