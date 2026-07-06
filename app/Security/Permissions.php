<?php

namespace App\Security;

// ════════════════════════════════════════════════════════════
//  نظام الصلاحيات الكامل (منقول من نظام .NET)
//  يوثّق كل الأدوار والصلاحيات ويتحقق منها
// ════════════════════════════════════════════════════════════

class Permissions
{
    // ── الصلاحيات المتاحة ──
    const CANDIDATE_VIEW = 'candidate.view';
    const CANDIDATE_CREATE = 'candidate.create';
    const CANDIDATE_EDIT = 'candidate.edit';
    const CANDIDATE_APPROVE = 'candidate.approve';
    const CANDIDATE_VIEW_NAMES = 'candidate.view_names';   // رؤية الأسماء (حساس)
    const CANDIDATE_VIEW_CLASSIFIED = 'candidate.view_classified';   // رؤية المرشحين السرّيين

    const SCHEDULE_VIEW = 'schedule.view';
    const SCHEDULE_MANAGE = 'schedule.manage';

    const ATTENDANCE_VIEW = 'attendance.view';
    const ATTENDANCE_RECORD = 'attendance.record';

    const EVALUATION_VIEW = 'evaluation.view';
    const EVALUATION_INPUT = 'evaluation.input';
    const EVALUATION_APPROVE = 'evaluation.approve';
    const EVALUATION_ASSIST = 'evaluation.assist';

    const MEASUREMENT_VIEW = 'measurement.view';
    const MEASUREMENT_UPLOAD = 'measurement.upload';

    const REPORT_VIEW = 'report.view';
    const REPORT_CREATE = 'report.create';
    const REPORT_APPROVE = 'report.approve';
    const REPORT_RETURN = 'report.return';
    const REPORT_EXPORT = 'report.export';

    const COMPETENCY_VIEW = 'competency.view';
    const COMPETENCY_MANAGE = 'competency.manage';

    const SEND_INVITATION = 'communication.invite';

    const USER_MANAGE = 'user.manage';
    const AUDIT_VIEW = 'audit.view';
    const SETTINGS_MANAGE = 'settings.manage';

    // ════════════════════════════════════════════════════════
    //  مصفوفة الأدوار والصلاحيات
    // ════════════════════════════════════════════════════════
    public static function matrix(): array
    {
        return [
            // مدير النظام — كل الصلاحيات
            'ADMIN' => ['*'],

            // مدير المركز — إشراف عام (عرض)
            'CENTER_MANAGER' => [
                self::CANDIDATE_VIEW, self::SCHEDULE_VIEW, self::ATTENDANCE_VIEW,
                self::EVALUATION_VIEW, self::MEASUREMENT_VIEW, self::REPORT_VIEW,
                self::REPORT_EXPORT, self::COMPETENCY_VIEW, self::AUDIT_VIEW,
            ],

            // مسؤول الجدولة
            'SCHEDULER' => [
                self::CANDIDATE_VIEW, self::CANDIDATE_CREATE, self::CANDIDATE_EDIT,
                self::CANDIDATE_APPROVE, self::CANDIDATE_VIEW_NAMES,
                self::SCHEDULE_VIEW, self::SCHEDULE_MANAGE, self::ATTENDANCE_VIEW,
                self::SEND_INVITATION,
            ],

            // مسؤول الاستقبال
            'RECEPTIONIST' => [
                self::CANDIDATE_VIEW, self::CANDIDATE_VIEW_NAMES,
                self::ATTENDANCE_VIEW, self::ATTENDANCE_RECORD, self::SEND_INVITATION,
            ],

            // مدير إدارة التقييم
            'ASSESS_MANAGER' => [
                self::CANDIDATE_VIEW, self::CANDIDATE_VIEW_NAMES, self::CANDIDATE_VIEW_CLASSIFIED, self::SCHEDULE_VIEW,
                self::ATTENDANCE_VIEW, self::EVALUATION_VIEW, self::EVALUATION_APPROVE,
                self::MEASUREMENT_VIEW, self::REPORT_VIEW, self::REPORT_CREATE,
                self::REPORT_EXPORT, self::COMPETENCY_VIEW,
            ],

            // مستشار المقابلة
            'EVALUATOR' => [
                self::CANDIDATE_VIEW, self::EVALUATION_VIEW, self::EVALUATION_INPUT,
                self::REPORT_VIEW, self::REPORT_CREATE,
            ],

            // مستشار حلقة النقاش
            'DISCUSSION_EVAL' => [
                self::CANDIDATE_VIEW, self::EVALUATION_VIEW, self::EVALUATION_INPUT,
            ],

            // مساعد التقييم — يرصد فقط
            'ASSISTANT' => [
                self::CANDIDATE_VIEW, self::EVALUATION_VIEW, self::EVALUATION_ASSIST,
            ],

            // إدارة تطوير الكفاءات — الاعتماد النهائي
            'DEV_MANAGER' => [
                self::CANDIDATE_VIEW, self::EVALUATION_VIEW, self::MEASUREMENT_VIEW,
                self::REPORT_VIEW, self::REPORT_APPROVE, self::REPORT_RETURN,
                self::REPORT_EXPORT, self::COMPETENCY_VIEW, self::COMPETENCY_MANAGE,
            ],

            // مشرف أدوات القياس
            'MEASURE_SUPER' => [
                self::CANDIDATE_VIEW, self::ATTENDANCE_VIEW, self::ATTENDANCE_RECORD,
                self::MEASUREMENT_VIEW, self::MEASUREMENT_UPLOAD,
            ],

            'EXTERNAL_ADD' => [
                self::CANDIDATE_CREATE,
            ],
        ];
    }

    // ── التحقق: هل الدور يملك الصلاحية؟ ──
    public static function roleHasPermission(string $roleCode, string $permission): bool
    {
        $matrix = self::matrix();
        if (!isset($matrix[$roleCode])) return false;
        $perms = $matrix[$roleCode];
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }
}
