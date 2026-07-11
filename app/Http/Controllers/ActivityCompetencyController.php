<?php

namespace App\Http\Controllers;

use App\Models\Competency;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// إدارة ربط الأنشطة (مقابلة/حلقة نقاش) بالكفاءات — بديل الـ seeder الثابت
class ActivityCompetencyController extends Controller
{
    private const ACTIVITIES = ['interview', 'discussion'];

    // كل الكفاءات + الكفاءات المرتبطة حاليًا بكل نشاط
    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::COMPETENCY_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الكفاءات'], 403);
        }

        $competencies = Competency::orderBy('sort_order')->get()->map(fn ($c) => [
            'id' => $c->id,
            'nameAr' => $c->name_ar,
            'type' => $c->type,
        ]);

        $map = [];
        foreach (self::ACTIVITIES as $activity) {
            $map[$activity] = Competency::idsForActivity($activity);
        }

        return response()->json([
            'competencies' => $competencies,
            'map' => $map,
        ]);
    }

    // استبدال كامل لكفاءات نشاط معيّن
    public function update(Request $request, string $activity)
    {
        if (!$request->user()->hasPermission(Permissions::COMPETENCY_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الكفاءات'], 403);
        }

        if (!in_array($activity, self::ACTIVITIES, true)) {
            return response()->json(['error' => 'نشاط غير معروف'], 422);
        }

        $validated = $request->validate([
            'competencyIds' => 'present|array',
            'competencyIds.*' => 'integer|distinct|exists:competencies,id',
        ]);

        $ids = $validated['competencyIds'];
        $previous = Competency::idsForActivity($activity);

        // منع تفريغ كفاءات نشاط له تقييمات نشطة (يكسر إرسال تلك التقييمات لاحقًا)
        if (empty($ids)) {
            $hasActive = \App\Models\Evaluation::where('activity', $activity)
                ->whereIn('status', ['draft', 'submitted'])->exists();
            if ($hasActive) {
                return response()->json([
                    'error' => 'لا يمكن تفريغ كفاءات نشاط له تقييمات نشطة قيد التنفيذ',
                ], 422);
            }
        }

        DB::transaction(function () use ($activity, $ids) {
            DB::table('activity_competency')->where('activity', $activity)->delete();
            if ($ids) {
                $now = now();
                DB::table('activity_competency')->insert(array_map(fn ($id) => [
                    'activity' => $activity,
                    'competency_id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $ids));
            }
        });

        // تدقيق يوثّق التغيير الفعلي (قبل/بعد) بدل قائمة مبهمة
        $this->log($request, 'UPDATE_ACTIVITY_COMPETENCIES', 0, [
            'activity' => $activity,
            'from' => $previous,
            'to' => $ids,
        ]);

        return response()->json([
            'message' => 'تم تحديث كفاءات النشاط',
            'activity' => $activity,
            'competencyIds' => $ids,
        ]);
    }

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'activity_competency',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
