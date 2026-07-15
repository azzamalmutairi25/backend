<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\MeasurementController;
use App\Http\Controllers\DevelopmentPlanController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\ActivityCompetencyController;
use App\Http\Controllers\CompetencyController;
use App\Http\Controllers\PublicAssessmentController;

// ════════════════════════════════════════════════════════════
//  مسارات الـ API — كلها تحت البادئة /api
// ════════════════════════════════════════════════════════════

// معرّفات DB الرقمية — قيّدها بأرقام فقط: معرّف غير رقمي يخطئ المسار (404) بدل TypeError (500).
// نغطّي كل الأسماء المربوطة بوسيط int في المتحكّمات (لا {entityType}/{activity}/{token} فهي نصّية)
Route::pattern('id', '[0-9]+');
Route::pattern('entityId', '[0-9]+');
Route::pattern('threadId', '[0-9]+');
Route::pattern('scheduleId', '[0-9]+');
Route::pattern('candidateId', '[0-9]+');

// ── عام (بدون مصادقة) ──
// تقييد بمعدّل حسب IP ضدّ رشّ كلمات المرور والتعداد (بالإضافة لقفل الحساب)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// ── بوابة المرشح العامة (رمز فريد في الرسالة النصية) — مقيّدة بالمعدل ضد التخمين ──
// لا تُكشف أي بيانات إلا بعد /verify بمطابقة رقم الهوية (بوابة العامل الثاني)
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/public/assessment/{token}/verify', [PublicAssessmentController::class, 'verify']);
    Route::post('/public/assessment/{token}/confirm', [PublicAssessmentController::class, 'confirm']);
    Route::post('/public/assessment/{token}/arrive', [PublicAssessmentController::class, 'arrive']);
});

// ── محمي (يتطلب رمز Sanctum) ──
Route::middleware('auth:sanctum')->group(function () {

    // ═══ المصادقة ═══
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // ═══ المرشحون ═══
    Route::get('/candidates', [CandidateController::class, 'index']);
    Route::post('/candidates', [CandidateController::class, 'store']);
    Route::get('/candidates/stats', [CandidateController::class, 'stats']);
    Route::get('/candidates/export', [CandidateController::class, 'export']);
    Route::get('/candidates/{id}', [CandidateController::class, 'show']);
    Route::put('/candidates/{id}', [CandidateController::class, 'update']);
    Route::delete('/candidates/{id}', [CandidateController::class, 'destroy']);
    Route::post('/candidates/{id}/approve', [CandidateController::class, 'approve']);
    Route::post('/candidates/import', [ImportController::class, 'import']);
    Route::patch('/candidates/{id}/classify', [CandidateController::class, 'reclassify']);
    Route::get('/candidates/{id}/assessments', [CandidateController::class, 'assessments']);
    Route::get('/candidates/{id}/journey', [CandidateController::class, 'journey']);
    Route::post('/candidates/{id}/reassess', [CandidateController::class, 'reassess']);
    Route::get('/candidates/{id}/history', [AuditController::class, 'candidateHistory']);
    Route::get('/audit/log', [AuditController::class, 'systemLog']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/roles', [UserController::class, 'roles']);
    Route::get('/users/role-permissions', [UserController::class, 'rolePermissions']);
    // استثناءات صلاحيات المستخدم فوق دوره
    Route::get('/users/{id}/permissions', [UserController::class, 'permissions']);
    Route::put('/users/{id}/permissions', [UserController::class, 'savePermissions']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}/toggle', [UserController::class, 'toggleActive']);
    Route::patch('/users/{id}/password', [UserController::class, 'resetPassword']);
    Route::get('/settings/ldap', [SettingsController::class, 'getLdap']);
    Route::put('/settings/ldap', [SettingsController::class, 'saveLdap']);
    Route::post('/settings/ldap/test', [SettingsController::class, 'testLdap']);

    Route::get('/settings/sms', [SettingsController::class, 'getSms']);
    Route::put('/settings/sms', [SettingsController::class, 'saveSms']);
    // اختبار يرسل رسالة فعلية بتكلفة — يُخنق لمنع الاستنزاف
    Route::post('/settings/sms/test', [SettingsController::class, 'testSms'])->middleware('throttle:5,1');

    // سير العمل: ترتيب مراحل الاعتماد وتفعيلها
    Route::get('/workflow/report', [WorkflowController::class, 'show']);
    Route::put('/workflow/report', [WorkflowController::class, 'update']);

    Route::get('/settings/smtp', [SettingsController::class, 'getSmtp']);
    Route::put('/settings/smtp', [SettingsController::class, 'saveSmtp']);
    // اختبار يفتح اتصالاً خارجياً — يُخنق كنظيره في الرسائل
    Route::post('/settings/smtp/test', [SettingsController::class, 'testSmtp'])->middleware('throttle:5,1');
    Route::get('/sectors', [SectorController::class, 'index']);

    // ═══ التقييم ═══
    Route::get('/competencies', [EvaluationController::class, 'competencies']);
    Route::get('/competencies/framework', [CompetencyController::class, 'framework']);
    Route::put('/competencies/{id}', [CompetencyController::class, 'update']);
    Route::get('/activity-competencies', [ActivityCompetencyController::class, 'index']);
    Route::put('/activity-competencies/{activity}', [ActivityCompetencyController::class, 'update']);
    Route::post('/evaluations/start', [EvaluationController::class, 'start']);
    Route::get('/evaluations', [EvaluationController::class, 'index']);
    Route::get('/evaluations/{id}', [EvaluationController::class, 'show']);
    Route::post('/evaluations/{id}/scores', [EvaluationController::class, 'saveScores']);
    Route::post('/evaluations/{id}/submit', [EvaluationController::class, 'submit']);
    Route::post('/evaluations/{id}/approve', [EvaluationController::class, 'approve']);
    Route::post('/evaluations/{id}/return', [EvaluationController::class, 'returnEvaluation']);

    // ═══ التحليلات ═══
    Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
    Route::get('/analytics/by-sector', [AnalyticsController::class, 'bySector']);
    Route::get('/analytics/competency-gaps', [AnalyticsController::class, 'competencyGaps']);
    Route::get('/analytics/trends', [AnalyticsController::class, 'trends']);

    // ═══ الجدولة ═══
    Route::get('/schedules', [ScheduleController::class, 'index']);
    Route::post('/schedules', [ScheduleController::class, 'store']);
    Route::put('/schedules/{id}', [ScheduleController::class, 'update']);
    Route::delete('/schedules/{id}', [ScheduleController::class, 'destroy']);

    // ═══ أدوات القياس ═══
    Route::get('/measurements/{candidateId}', [MeasurementController::class, 'show']);
    Route::post('/measurements', [MeasurementController::class, 'store']);

    // ═══ خطة التطوير الفردية ═══
    Route::get('/development-plans/{candidateId}', [DevelopmentPlanController::class, 'index']);
    Route::post('/development-plans', [DevelopmentPlanController::class, 'store']);
    Route::post('/development-plans/seed', [DevelopmentPlanController::class, 'seed']);
    Route::put('/development-plan-items/{id}', [DevelopmentPlanController::class, 'update']);
    Route::delete('/development-plan-items/{id}', [DevelopmentPlanController::class, 'destroy']);

    // ═══ الحضور ═══
    Route::get('/attendance/today', [AttendanceController::class, 'today']);
    Route::get('/attendance/stats', [AttendanceController::class, 'stats']);
    Route::post('/attendance/{scheduleId}/checkin', [AttendanceController::class, 'checkIn']);
    Route::post('/attendance/{scheduleId}/absence', [AttendanceController::class, 'recordAbsence']);

    // ═══ التقارير ═══
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/stats', [ReportController::class, 'stats']);
    Route::get('/reports/eligible-candidates', [ReportController::class, 'eligibleCandidates']);
    Route::get('/reports/score-preview', [ReportController::class, 'scorePreview']);
    Route::get('/reports/competency-gap', [ReportController::class, 'competencyGap']);
    Route::get('/reports/export', [ReportController::class, 'exportCsv']);
    Route::post('/reports', [ReportController::class, 'store']);
    Route::get('/reports/{id}', [ReportController::class, 'show']);
    Route::get('/reports/{id}/document', [ReportController::class, 'document']);
    Route::put('/reports/{id}', [ReportController::class, 'update']);
    Route::post('/reports/{id}/approve', [ReportController::class, 'approve']);
    Route::post('/reports/{id}/return', [ReportController::class, 'returnReport']);
    Route::post('/reports/{id}/cancel', [ReportController::class, 'cancel']);
    Route::post('/reports/{id}/resubmit', [ReportController::class, 'resubmit']);

    // ═══ الإشعارات ═══
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // ═══ المحادثات ═══
    Route::get('/chat/{entityType}/{entityId}', [ChatController::class, 'thread']);
    Route::post('/chat/{threadId}/message', [ChatController::class, 'send']);

    // ═══ الاتصالات (دعوات) ═══
    Route::post('/communications/invite', [CommunicationController::class, 'invite']);
    Route::get('/communications/history/{candidateId}', [CommunicationController::class, 'history']);

    // ═══ الاستيراد ═══
    Route::post('/import/candidates', [ImportController::class, 'import']);
});
