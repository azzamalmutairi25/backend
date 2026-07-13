<?php

namespace App\Http\Controllers;

use App\Models\Competency;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  إطار الكفاءات المرجعي — الأوزان والمستويات المطلوبة حسب الفئة
// ════════════════════════════════════════════════════════════

class CompetencyController extends Controller
{
    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'competency',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    // GET /competencies/framework — كل الكفاءات بإعداداتها المرجعية
    public function framework(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::COMPETENCY_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الكفاءات'], 403);
        }

        $competencies = Competency::orderBy('sort_order')->get()->map(fn ($c) => [
            'id' => $c->id,
            'nameAr' => $c->name_ar,
            'type' => $c->type,
            'maxLevel' => (int) $c->max_level,
            'weight' => (float) $c->weight,
            'targetUpper' => $c->target_upper,
            'targetMiddle' => $c->target_middle,
        ]);

        return response()->json(['competencies' => $competencies]);
    }

    // PUT /competencies/{id} — تعديل الوزن/المستوى الأقصى/المستويات المطلوبة
    public function update(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::COMPETENCY_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الكفاءات'], 403);
        }

        $competency = Competency::find($id);
        if (!$competency) {
            return response()->json(['error' => 'الكفاءة غير موجودة'], 404);
        }

        $validated = $request->validate([
            'nameAr' => 'required|string|max:150',
            'maxLevel' => 'required|integer|min:1|max:10',
            'weight' => 'required|numeric|min:0|max:10',
            // المطلوب لا يتجاوز الحد الأقصى للكفاءة
            'targetUpper' => 'nullable|integer|min:0|lte:maxLevel',
            'targetMiddle' => 'nullable|integer|min:0|lte:maxLevel',
        ]);

        $competency->name_ar = $validated['nameAr'];
        $competency->max_level = $validated['maxLevel'];
        $competency->weight = $validated['weight'];
        $competency->target_upper = $validated['targetUpper'] ?? null;
        $competency->target_middle = $validated['targetMiddle'] ?? null;
        $competency->save();

        $this->log($request, 'UPDATE_COMPETENCY', $competency->id, [
            'name' => $competency->name_ar,
            'weight' => $competency->weight,
            'targetUpper' => $competency->target_upper,
            'targetMiddle' => $competency->target_middle,
        ]);

        return response()->json(['message' => 'تم تحديث الكفاءة']);
    }
}
