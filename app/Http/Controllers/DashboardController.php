<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Evaluation;
use App\Models\FinalReport;
use App\Models\Schedule;
use App\Security\Permissions;
use App\Services\DashboardService;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  لوحة البداية — صفحة الهبوط لكل دور.
//
//  لا بوّابة صلاحية واحدة على المسار كلّه: من لا يملك التحليلات يبقى له
//  جدوله وحضوره. فالمنع هنا قسمٌ قسم (القسم = null) لا 403 على الصفحة،
//  وإلا صار لبعض الأدوار نظامٌ بلا مدخل.
//
//  الحصر يُبنى هنا لا في الخدمة: الحرّاس المشتركة (scopeCandidateQuery /
//  scopeViaCandidate / scopeReports) هي نفسها التي تحصر شاشات القوائم،
//  فما تعدّه اللوحة لا يتجاوز ما تعرضه القائمة لصاحبها أبداً.
// ════════════════════════════════════════════════════════════

class DashboardController extends Controller
{
    // GET /dashboard/overview
    public function overview(Request $request, DashboardService $service)
    {
        $user = $request->user();

        $scope = [
            'classifications' => $this->allowedClassifications($request),
            // القطاع حدٌّ أعلى للمحصور — وnull لغيره (بلا حصر)
            'sectorId' => $user->isSectorBound() ? $user->sector_id : null,
            'sectorBound' => $user->isSectorBound(),

            // مغلّفات: كلٌّ ترجع استعلاماً جديداً محصوراً (لا clone لحالة مشتركة)
            'candidates' => function () use ($request) {
                $q = Candidate::query();
                $this->scopeCandidateQuery($request, $q);
                return $q;
            },
            'reports' => function () use ($request) {
                $q = FinalReport::query();
                $this->scopeReports($request, $q);   // القطاع + التصنيف + تضييق المقيّم
                return $q;
            },
            'evaluations' => function () use ($request, $user) {
                $q = Evaluation::query();
                $this->scopeViaCandidate($request, $q);
                // نفس تضييق scopeReports: المقيّم المحصور لا يعدّ إلا تقييماته هو —
                // وإلا أفشى العدّادُ حجمَ ما تخفيه عنه شاشة تقييماته
                if ($user->isSectorBound() && $user->hasRole('EVALUATOR', 'DISCUSSION_EVAL')) {
                    $q->where('evaluator_id', $user->id);
                }
                return $q;
            },
            'schedules' => function () use ($request) {
                $q = Schedule::query();
                $this->scopeViaCandidate($request, $q);
                return $q;
            },
        ];

        $can = [
            'candidate' => $user->hasPermission(Permissions::CANDIDATE_VIEW),
            'attendance' => $user->hasPermission(Permissions::ATTENDANCE_VIEW),
            'evaluation' => $user->hasPermission(Permissions::EVALUATION_VIEW),
            'report' => $user->hasPermission(Permissions::REPORT_VIEW),
            'analytics' => $user->hasPermission(Permissions::ANALYTICS_VIEW),
            'schedule' => $user->hasPermission(Permissions::SCHEDULE_VIEW),
        ];

        return response()->json($service->overview($scope, $can));
    }
}
