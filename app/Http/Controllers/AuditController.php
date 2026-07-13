<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    private array $actionLabels = [
        'CREATE_CANDIDATE' => 'إضافة المرشح',
        'UPDATE_CANDIDATE' => 'تعديل البيانات',
        'DELETE_CANDIDATE' => 'حذف المرشح',
        'APPROVE_CANDIDATE' => 'اعتماد المرشح',
        'VIEW_CANDIDATE_PII' => 'الاطلاع على البيانات الشخصية',
        'IMPORT_CANDIDATES' => 'استيراد جماعي',
        'EXPORT_CANDIDATES' => 'تصدير القائمة',
        'RECLASSIFY_CANDIDATE' => 'تغيير تصنيف السرّية',
        'DENIED_CLASSIFIED_ACCESS' => 'محاولة وصول مرفوضة (مصنّف)',
        'CREATE_USER' => 'إضافة مستخدم',
        'UPDATE_USER' => 'تعديل مستخدم',
        'ENABLE_USER' => 'تفعيل مستخدم',
        'DISABLE_USER' => 'تعطيل مستخدم',
        'RESET_PASSWORD' => 'إعادة تعيين كلمة المرور',
        'CHANGE_OWN_PASSWORD' => 'تغيير كلمة المرور الذاتية',
        'ACCOUNT_LOCKED' => 'قفل حساب (محاولات فاشلة)',
        'UPDATE_LDAP_SETTINGS' => 'تحديث إعدادات LDAP',
        'START_EVALUATION' => 'بدء تقييم',
        'VIEW_EVALUATION' => 'عرض درجات تقييم',
        'SAVE_SCORES' => 'حفظ درجات',
        'SUBMIT_EVALUATION' => 'إرسال تقييم للاعتماد',
        'APPROVE_EVALUATION' => 'اعتماد تقييم',
        'RETURN_EVALUATION' => 'إرجاع تقييم للمقيّم',
        'DENIED_EVAL_CLASSIFIED' => 'محاولة تقييم مرفوضة (مصنّف)',
        'VIEW_REPORTS' => 'عرض قائمة التقارير',
        'APPROVE_REPORT' => 'اعتماد تقرير نهائي',
        'RETURN_REPORT' => 'إرجاع تقرير للتعديل',
        'RESUBMIT_REPORT' => 'إعادة إرسال تقرير',
        'EXPORT_REPORT' => 'طباعة تقرير رسمي',
        'EXPORT_REPORTS' => 'تصدير التقارير',
        'DENIED_REPORT_CLASSIFIED' => 'محاولة وصول لتقرير مصنّف',
        'CREATE_SCHEDULE' => 'جدولة جلسة',
        'UPDATE_SCHEDULE' => 'تعديل جلسة',
        'DELETE_SCHEDULE' => 'حذف جلسة',
        'RECORD_ATTENDANCE' => 'تسجيل حضور',
        'RECORD_ABSENCE' => 'تسجيل غياب',
        'DENIED_ATTENDANCE_CLASSIFIED' => 'محاولة تسجيل حضور مصنّف',
        'LOGIN' => 'تسجيل دخول',
    ];

    private function label(string $action): string
    {
        return $this->actionLabels[$action] ?? $action;
    }

    public function candidateHistory(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض سجل المرشح'], 403);
        }
        // احترام تصنيف المرشح — فشل مغلق: مرشح محذوف قد يكون كان مصنّفاً
        $candidate = \App\Models\Candidate::find($id);
        if (!$user->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)) {
            if (!$candidate || $candidate->classification !== 'normal') {
                return response()->json(['error' => 'المرشح غير موجود'], 404);
            }
        }

        $logs = AuditLog::where('entity_type', 'candidate')
            ->where('entity_id', (string) $id)
            ->orderBy('created_at', 'desc')
            ->get();

        $userIds = $logs->pluck('user_id')->unique()->filter();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $history = $logs->map(function ($log) use ($users) {
            $user = $users->get($log->user_id);
            return [
                'action' => $this->label($log->action),
                'actionCode' => $log->action,
                'userName' => $user ? $user->full_name : 'مستخدم محذوف',
                'userRole' => $user && $user->role ? $user->role->name_ar : null,
                'createdAt' => $log->created_at,
                'isSensitive' => $log->action === 'VIEW_CANDIDATE_PII',
            ];
        });

        return response()->json(['history' => $history]);
    }

    public function systemLog(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::AUDIT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض سجل التدقيق'], 403);
        }

        // تحقّق من فلاتر الإدخال (مدخلات محمية — لا تُمرَّر خامًا)
        $request->validate([
            'action' => 'nullable|string|max:64',
            'userId' => 'nullable|integer|exists:users,id',
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date|after_or_equal:dateFrom',
        ]);

        $query = AuditLog::query();
        if ($request->filled('action'))   { $query->where('action', $request->action); }
        if ($request->filled('userId'))   { $query->where('user_id', $request->userId); }
        if ($request->filled('dateFrom')) { $query->whereDate('created_at', '>=', $request->dateFrom); }
        if ($request->filled('dateTo'))   { $query->whereDate('created_at', '<=', $request->dateTo); }

        $logs = $query->orderBy('created_at', 'desc')->limit(200)->get();

        $userIds = $logs->pluck('user_id')->unique()->filter();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        // إخفاء تفاصيل المرشحين المصنّفين عمّن لا يملك التصريح (منع تسريب التصنيف عبر السجل)
        // فشل مغلق: نُظهر فقط سجلّات المرشحين «العاديين الموجودين»؛ المصنّف أو المحذوف يُحجب
        $canSeeClassified = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED);
        $visibleCandidateIds = [];
        if (!$canSeeClassified) {
            $candIds = $logs->where('entity_type', 'candidate')->pluck('entity_id')->unique()->filter();
            $visibleCandidateIds = \App\Models\Candidate::whereIn('id', $candIds)
                ->where('classification', 'normal')
                ->pluck('id')->map(fn ($i) => (string) $i)->all();
        }

        $sensitive = ['VIEW_CANDIDATE_PII', 'RECLASSIFY_CANDIDATE', 'DELETE_CANDIDATE',
            'EXPORT_CANDIDATES', 'RESET_PASSWORD', 'DISABLE_USER'];

        $entries = $logs->map(function ($log) use ($users, $canSeeClassified, $visibleCandidateIds, $sensitive) {
            $user = $users->get($log->user_id);
            // لا تُعامَل الإجراءات الجماعية (entity_id='0'/null مثل EXPORT_CANDIDATES) كسجل مرشح مصنّف فتُحجب دائماً
            $redact = !$canSeeClassified
                && $log->entity_type === 'candidate'
                && $log->entity_id !== null
                && $log->entity_id !== '0'
                && !in_array((string) $log->entity_id, $visibleCandidateIds, true);
            return [
                'id' => $log->id,
                'action' => $this->label($log->action),
                'actionCode' => $log->action,
                'userName' => $user ? $user->full_name : 'مستخدم محذوف',
                'entityType' => $log->entity_type,
                'entityId' => $redact ? null : $log->entity_id,
                'details' => $redact ? null : $log->details,
                'redacted' => $redact,
                'ipAddress' => $log->ip_address,
                'createdAt' => $log->created_at,
                'isSensitive' => in_array($log->action, $sensitive, true) || str_starts_with($log->action, 'DENIED_'),
            ];
        });

        $allUsers = User::select('id', 'full_name')->get()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->full_name,
        ]);

        return response()->json([
            'entries' => $entries,
            'users' => $allUsers,
            'actionTypes' => array_map(fn ($k, $v) => ['code' => $k, 'label' => $v],
                array_keys($this->actionLabels), array_values($this->actionLabels)),
        ]);
    }
}
