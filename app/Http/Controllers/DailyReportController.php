<?php

namespace App\Http\Controllers;

use App\Security\Permissions;
use App\Services\DailyReportService;
use Illuminate\Http\Request;

// تقرير اليوم لمدير المركز — عرض في الصفحة ومستند للطباعة.
// نفس الخدمة التي تُرسله بالبريد ليلاً، فلا يتباعد المعروض عن المُرسَل.
class DailyReportController extends Controller
{
    public function __construct(private DailyReportService $service) {}

    // من يرى التقرير: حامل التحليلات (مدير المركز والمديرون)
    private function authorize(Request $request): bool
    {
        return $request->user()->hasPermission(Permissions::ANALYTICS_VIEW);
    }

    private function date(Request $request): string
    {
        $validated = $request->validate(['date' => 'nullable|date']);
        return $validated['date'] ?? now()->toDateString();
    }

    // GET /daily-report — بيانات مجمّعة للعرض في الصفحة
    public function show(Request $request)
    {
        if (!$this->authorize($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقرير اليومي'], 403);
        }
        // حصر بتصنيفات المستخدم: مَن مُنِح ANALYTICS_VIEW عبر استثناء (بلا تصريح مصنّف)
        // لا يرى وجود المصنّفين — لا يعتمد الإخفاء على اقتران الأدوار الذي يكسره التجاوز.
        return response()->json($this->service->gather($this->date($request), $this->allowedClassifications($request)));
    }

    // GET /daily-report/document — مستند HTML للطباعة (المتصفّح → PDF)
    public function document(Request $request)
    {
        if (!$this->authorize($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقرير اليومي'], 403);
        }
        $html = $this->service->renderHtml($this->service->gather($this->date($request), $this->allowedClassifications($request)));
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
