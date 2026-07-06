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
        'DENIED_REPORT_CLASSIFIED' => 'محاولة وصول لتقرير مصنّف',
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

        $query = AuditLog::query();

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('userId')) {
            $query->where('user_id', $request->userId);
        }
        if ($request->filled('dateFrom')) {
            $query->whereDate('created_at', '>=', $request->dateFrom);
        }
        if ($request->filled('dateTo')) {
            $query->whereDate('created_at', '<=', $request->dateTo);
        }

        $logs = $query->orderBy('created_at', 'desc')->limit(200)->get();

        $userIds = $logs->pluck('user_id')->unique()->filter();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $entries = $logs->map(function ($log) use ($users) {
            $user = $users->get($log->user_id);
            return [
                'id' => $log->id,
                'action' => $this->label($log->action),
                'actionCode' => $log->action,
                'userName' => $user ? $user->full_name : 'مستخدم محذوف',
                'entityType' => $log->entity_type,
                'entityId' => $log->entity_id,
                'details' => $log->details,
                'ipAddress' => $log->ip_address,
                'createdAt' => $log->created_at,
                'isSensitive' => $log->action === 'VIEW_CANDIDATE_PII',
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
