<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\CandidateUpdateRequestController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SchedulingPeriodController;
use App\Http\Controllers\SchedulingWorkflowController;
use App\Http\Controllers\ExpertiseAreaController;
use App\Http\Controllers\TechnicalAreaController;
use App\Http\Controllers\DiscussionCircleController;
use App\Http\Controllers\GoldenScheduleController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\RankController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\ReceptionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DistributionController;
use App\Http\Controllers\MeasurementController;
use App\Http\Controllers\DevelopmentPlanController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\SetupStatusController;
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

// ── بوابة المشارك العامة (رمز فريد في الرسالة النصية) — مقيّدة بالمعدل ضد التخمين ──
// لا تُكشف أي بيانات إلا بعد /verify بمطابقة رقم الهوية (بوابة العامل الثاني)
//
// مُعطَّلة الآن بمفتاح features.candidate_portal: لا تُسجَّل المسارات أصلاً،
// فالسطح مغلق لا محروس. تسجيلُها ثم ردُّ 403 يترك بوّابةً تُعلن عن نفسها.
if (config('features.candidate_portal')) {
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/public/assessment/{token}/verify', [PublicAssessmentController::class, 'verify']);
        Route::post('/public/assessment/{token}/confirm', [PublicAssessmentController::class, 'confirm']);
        Route::post('/public/assessment/{token}/arrive', [PublicAssessmentController::class, 'arrive']);
        Route::post('/public/assessment/{token}/cv', [PublicAssessmentController::class, 'saveCv']);
    });
}

// ── كشك الاستقبال على الجهاز اللوحي (رمز يوم واحد يفتحه مسؤول المشاركين) ──
// نفس مبدأ البوّابة: لا بيانات قبل مطابقة رقم الهوية. والتقييد هنا أوسع
// لأن الجهاز واحد يخدم طابور اليوم كلَّه، ومحكومٌ بحدٍّ ثانٍ لكل هوية داخل
// المتحكّم يمنع تخمين شخصٍ بعينه من وراء سعة الكشك.
if (config('features.reception_kiosk')) {
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/kiosk/{token}', [KioskController::class, 'show']);
        Route::post('/kiosk/{token}/identify', [KioskController::class, 'identify']);
        Route::post('/kiosk/{token}/arrive', [KioskController::class, 'arrive']);
        Route::post('/kiosk/{token}/sign', [KioskController::class, 'sign']);
        Route::post('/kiosk/{token}/badge', [KioskController::class, 'badge']);
    });
}

// ── محمي (يتطلب رمز Sanctum) ──
Route::middleware('auth:sanctum')->group(function () {

    // ═══ المصادقة ═══
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // ═══ لوحة البداية — صفحة الهبوط لكل دور (لا بوّابة صلاحية: الأقسام تُحجب فرادى) ═══
    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);

    // ═══ المشاركون ═══
    Route::get('/candidates', [CandidateController::class, 'index']);
    Route::post('/candidates', [CandidateController::class, 'store']);
    // فحص تكرار الهوية قبل ملء النموذج — POST لا GET كي لا تُكتب الهوية في
    // سجلّات المسارات، ومخنوقٌ بالمعدّل لأنه سطح تعدادٍ بأرقام الهوية
    Route::post('/candidates/lookup', [CandidateController::class, 'lookup'])
        ->middleware('throttle:20,1');
    Route::get('/candidates/stats', [CandidateController::class, 'stats']);
    Route::get('/candidates/export', [CandidateController::class, 'export']);
    // GET /candidates/cards — بطاقات المشاركين للطباعة. قبل {id} وإلا ابتلعها
    Route::get('/candidates/cards', [CandidateController::class, 'cards']);
    Route::get('/candidates/{id}', [CandidateController::class, 'show']);
    Route::put('/candidates/{id}', [CandidateController::class, 'update']);
    Route::delete('/candidates/{id}', [CandidateController::class, 'destroy']);
    Route::post('/candidates/{id}/approve', [CandidateController::class, 'approve']);
    Route::post('/candidates/import', [ImportController::class, 'import']);
    // ── الاستيراد الضخم: رفعةٌ تُجمَّع ثمّ تُعالَج في الخلفية ──
    // الواجهة تُقطّع الملفّ (حتى ١٠٠٠ صفّ للنداء) لأن عشرة آلاف صفٍّ بسيَرها
    // تتجاوز حدّ الحمولة قبل أن تبلغ الشيفرة. مخنوقٌ بالمعدّل: كل نداء يكتب.
    Route::post('/candidates/import/batch', [ImportController::class, 'startBatch'])
        ->middleware('throttle:60,1');
    Route::get('/candidates/import/batch/{id}', [ImportController::class, 'batchStatus']);
    Route::patch('/candidates/{id}/classify', [CandidateController::class, 'reclassify']);
    // الملاحظات وحدها — لا تشترط الهوية والاسم كما يشترطهما التعديل الكامل
    Route::patch('/candidates/{id}/notes', [CandidateController::class, 'updateNotes']);
    Route::get('/candidates/{id}/assessments', [CandidateController::class, 'assessments']);
    Route::get('/candidates/{id}/journey', [CandidateController::class, 'journey']);
    // السيرة الذاتية — مسار الإدارة (قراءة بصلاحية CANDIDATE_CV_VIEW، تعديل بـ CANDIDATE_EDIT)
    Route::get('/candidates/{id}/cv', [CandidateController::class, 'showCv']);
    Route::put('/candidates/{id}/cv', [CandidateController::class, 'saveCv']);
    // GET /candidates/{id}/cv/document — نموذج السيرة المطبوع (المتصفّح → PDF)
    Route::get('/candidates/{id}/cv/document', [CandidateController::class, 'cvDocument']);
    // مستشارو المقابلة المؤهّلون — لاختيار المستشار عند الجدولة بعد مراجعة السيرة
    Route::get('/candidates/{id}/interviewers', [ScheduleController::class, 'interviewers']);
    // نظيره المعمَّم: أي نشاط وأي مقعد (مقيّم/مساعد)، ومع الموجة يعود النصاب والحمل
    Route::get('/candidates/{id}/assessors', [ScheduleController::class, 'assessors']);
    Route::post('/candidates/{id}/reassess', [CandidateController::class, 'reassess']);
    Route::get('/candidates/{id}/history', [AuditController::class, 'candidateHistory']);

    // ═══ طلبات تحديث بيانات المشاركين ═══
    // يرفعها المستخدم الخارجي حين يجد المشارك مسجّلاً مسبقاً، ويبتّ فيها صاحب صلاحية.
    // «mine» قبل «{id}» وإلا ابتلعها المسار ذو المعرّف.
    Route::get('/candidate-update-requests', [CandidateUpdateRequestController::class, 'index']);
    Route::get('/candidate-update-requests/mine', [CandidateUpdateRequestController::class, 'mine']);
    // الرفع مفتوح لجهة خارجية ويحمل وثيقة كاملة — يُخنق بالمعدّل كبقية المسارات المكلفة
    Route::post('/candidate-update-requests', [CandidateUpdateRequestController::class, 'store'])
        ->middleware('throttle:30,1');
    Route::get('/candidate-update-requests/{id}', [CandidateUpdateRequestController::class, 'show']);
    Route::post('/candidate-update-requests/{id}/approve', [CandidateUpdateRequestController::class, 'approve']);
    Route::post('/candidate-update-requests/{id}/reject', [CandidateUpdateRequestController::class, 'reject']);

    Route::get('/audit/log', [AuditController::class, 'systemLog']);
    // ═══ الأدوار وصلاحياتها — يحرّرها مدير النظام من الشاشة ═══
    // «roles» هنا لا في UserController: الدور كيانٌ قائم بذاته يُنشأ ويُعدَّل
    // ويُحذف، لا حقلٌ في نموذج المستخدم.
    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::get('/roles/{id}/permissions', [RoleController::class, 'permissions']);
    Route::put('/roles/{id}/permissions', [RoleController::class, 'savePermissions']);
    Route::post('/roles/{id}/reset', [RoleController::class, 'reset']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/roles/{id}', [RoleController::class, 'destroy']);

    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/roles', [UserController::class, 'roles']);
    Route::get('/users/role-permissions', [UserController::class, 'rolePermissions']);
    Route::get('/users/permission-catalog', [UserController::class, 'permissionCatalog']);
    // وصولٌ واحد على مجموعة موظفين — قبل /users/{id} كي لا يبتلعها المعرّف
    Route::post('/users/bulk-permissions', [UserController::class, 'bulkPermissions']);
    // استثناءات صلاحيات المستخدم فوق دوره
    Route::get('/users/{id}/permissions', [UserController::class, 'permissions']);
    Route::put('/users/{id}/permissions', [UserController::class, 'savePermissions']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}/toggle', [UserController::class, 'toggleActive']);
    Route::patch('/users/{id}/password', [UserController::class, 'resetPassword']);
    Route::get('/settings/ldap', [SettingsController::class, 'getLdap']);
    Route::put('/settings/ldap', [SettingsController::class, 'saveLdap']);
    // يفتح اتصالاً خارجياً بمضيفٍ يختاره الطالب — يُخنق كأخواته الثلاث.
    // المنفذ محصور بمنافذ LDAP والمحاولة مُدقَّقة، وكان الحدّ وحده ناقصاً:
    // بلا سقفٍ يصير الاختبار ماسحَ مضيفاتٍ داخلية، يكشف الموجود من المعدوم
    // بفارق زمن الردّ. الدفاع الثالث من ثلاثة.
    Route::post('/settings/ldap/test', [SettingsController::class, 'testLdap'])
        ->middleware('throttle:5,1');

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
    // حالة التهيئة الأولى — تُرشد اللوحة إلى ما بقي من خطوات على منصّة جديدة
    Route::get('/setup-status', [SetupStatusController::class, 'show']);

    Route::get('/sectors', [SectorController::class, 'index']);
    Route::put('/sectors/{id}/prefix', [SectorController::class, 'updatePrefix']);

    // الرتب والمراتب — مرجعٌ يقرؤه كل من يملأ نموذج مشارك، والإدارة داخل
    // RankController على `settings.manage`. كان الصنف مكتوباً كاملاً بلا مسار
    // يبلغه، والتوثيق يذكره — فالميزة موجودة ولا سبيل إليها.
    // مجالات الخبرة — مرجعٌ يُدار من الإعدادات، تُوسَم به حسابات المقيّمين
    // فتُقترح أقربهم إلى سيرة المشارك عند الجدولة («حسب الخبرات»).
    Route::get('/expertise-areas', [ExpertiseAreaController::class, 'index']);
    Route::post('/expertise-areas', [ExpertiseAreaController::class, 'store']);
    Route::put('/expertise-areas/{id}', [ExpertiseAreaController::class, 'update']);
    Route::delete('/expertise-areas/{id}', [ExpertiseAreaController::class, 'destroy']);
    // وسم حساب مقيّم بمجالاته — بصلاحية إدارة المستخدمين
    Route::put('/users/{id}/expertise', [ExpertiseAreaController::class, 'setUserExpertise']);

    // المجالات الفنية — مرجعٌ يُدار من الإعدادات، يُوسَم به المشارك ويُرشَّح
    // عليه. القراءة أوسع من نظيرتها: نموذج الإضافة يعرضها وشاشة الترشيح تفلتر بها.
    Route::get('/technical-areas', [TechnicalAreaController::class, 'index']);
    Route::post('/technical-areas', [TechnicalAreaController::class, 'store']);
    Route::put('/technical-areas/{id}', [TechnicalAreaController::class, 'update']);
    Route::delete('/technical-areas/{id}', [TechnicalAreaController::class, 'destroy']);

    Route::get('/ranks', [RankController::class, 'index']);
    Route::post('/ranks', [RankController::class, 'store']);
    Route::put('/ranks/{id}', [RankController::class, 'update']);
    Route::delete('/ranks/{id}', [RankController::class, 'destroy']);

    Route::get('/settings/distribution', [SettingsController::class, 'getDistribution']);
    Route::put('/settings/distribution', [SettingsController::class, 'saveDistribution']);
    // أوقات جلسات اليوم — خيارات حقل الوقت وأعمدة كشف الحضور
    // سير عمل الجدولة — الخطوات الاثنتا عشرة بياناتٍ تُحرَّر لا شيفرة.
    // القراءة تكفيها schedule.view (شاشة الموجة تعرضها)، والتحرير بـsettings.manage.
    Route::get('/settings/scheduling-workflow', [SchedulingWorkflowController::class, 'index']);
    Route::post('/settings/scheduling-workflow', [SchedulingWorkflowController::class, 'store']);
    Route::put('/settings/scheduling-workflow/reorder', [SchedulingWorkflowController::class, 'reorder']);
    Route::put('/settings/scheduling-workflow/{id}', [SchedulingWorkflowController::class, 'update']);
    Route::delete('/settings/scheduling-workflow/{id}', [SchedulingWorkflowController::class, 'destroy']);

    Route::get('/settings/session-times', [SettingsController::class, 'getSessionTimes']);
    Route::put('/settings/session-times', [SettingsController::class, 'saveSessionTimes']);
    Route::get('/settings/tier', [SettingsController::class, 'getTier']);
    Route::put('/settings/tier', [SettingsController::class, 'saveTier']);

    // بوّابة التحقق من الهوية (تكامل خارجي) — الاختبار يفتح اتصالاً خارجياً فيُخنق
    Route::get('/settings/idverify', [SettingsController::class, 'getIdVerify']);
    Route::put('/settings/idverify', [SettingsController::class, 'saveIdVerify']);
    Route::post('/settings/idverify/test', [SettingsController::class, 'testIdVerify'])->middleware('throttle:5,1');
    Route::get('/settings/idverify/log', [SettingsController::class, 'idVerifyLog']);

    // ═══ التقييم ═══
    Route::get('/competencies', [EvaluationController::class, 'competencies']);
    Route::get('/competencies/framework', [CompetencyController::class, 'framework']);
    Route::post('/competencies', [CompetencyController::class, 'store']);
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
    // سيرة المشارك للمقيّم — بلا اسم، من لقطة الدورة المجمَّدة
    Route::get('/evaluations/{id}/cv', [EvaluationController::class, 'cv']);

    // ═══ التحليلات ═══
    // التقرير اليومي — عرض ومستند للطباعة
    Route::get('/daily-report', [DailyReportController::class, 'show']);
    Route::get('/daily-report/document', [DailyReportController::class, 'document']);

    // القيادة التنفيذية للمركز — ثلاثة تبويبات، ثلاثة نداءات
    Route::get('/analytics/executive', [AnalyticsController::class, 'executive']);
    Route::get('/analytics/executive/overview', [AnalyticsController::class, 'executiveOverview']);
    Route::get('/analytics/executive/reports', [AnalyticsController::class, 'executiveReports']);
    Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
    Route::get('/analytics/by-sector', [AnalyticsController::class, 'bySector']);
    Route::get('/analytics/competency-gaps', [AnalyticsController::class, 'competencyGaps']);
    Route::get('/analytics/trends', [AnalyticsController::class, 'trends']);

    // ═══ الجدولة ═══
    // موجات الجدولة — التواريخ ولوحة المقيّمين والنصاب ومسار اعتماد مدير المركز.
    // العرض بـschedule.view، والبناء بـschedule.manage، والاعتماد والرفض
    // بـschedule.approve_center وحدها (فصل مهام: من يبني لا يعتمد).
    Route::get('/scheduling-periods', [SchedulingPeriodController::class, 'index']);
    Route::post('/scheduling-periods', [SchedulingPeriodController::class, 'store']);
    Route::put('/scheduling-periods/{id}', [SchedulingPeriodController::class, 'update']);
    Route::delete('/scheduling-periods/{id}', [SchedulingPeriodController::class, 'destroy']);
    Route::get('/scheduling-periods/{id}/eligible', [SchedulingPeriodController::class, 'eligible']);
    Route::get('/scheduling-periods/{id}/assessors', [SchedulingPeriodController::class, 'assessors']);
    Route::put('/scheduling-periods/{id}/assessors', [SchedulingPeriodController::class, 'saveAssessors']);
    Route::post('/scheduling-periods/{id}/submit', [SchedulingPeriodController::class, 'submit']);
    Route::post('/scheduling-periods/{id}/approve', [SchedulingPeriodController::class, 'approve']);
    Route::post('/scheduling-periods/{id}/reject', [SchedulingPeriodController::class, 'reject']);
    Route::post('/scheduling-periods/{id}/close', [SchedulingPeriodController::class, 'close']);
    // سير عمل الجدولة على هذه الموجة — قراءةٌ بـschedule.view وتأشيرٌ بـschedule.manage
    Route::get('/scheduling-periods/{id}/workflow', [SchedulingWorkflowController::class, 'periodWorkflow']);
    Route::post('/scheduling-periods/{id}/workflow/{stepId}', [SchedulingWorkflowController::class, 'markStep']);

    Route::get('/schedules', [ScheduleController::class, 'index']);
    Route::post('/schedules', [ScheduleController::class, 'store']);
    Route::put('/schedules/{id}', [ScheduleController::class, 'update']);
    Route::delete('/schedules/{id}', [ScheduleController::class, 'destroy']);
    // تصاريح دخول مشاركي اليوم — الاسم بطلبٍ صريح ولحامل candidate.view_names
    Route::get('/schedules/permits', [ScheduleController::class, 'permits']);
    Route::get('/schedules/absences/{candidateId}', [ScheduleController::class, 'absences']);
    Route::post('/schedules/{id}/reschedule', [ScheduleController::class, 'reschedule']);

    // الجدول الذهبي — سجلُّ (التاريخ × رمز المشارك) لكل موجة. المزامنة تُرحّل
    // جلسات الموجة إليه ولا تمسّ ما أُضيف يدوياً.
    Route::get('/golden-schedule', [GoldenScheduleController::class, 'index']);
    Route::post('/golden-schedule', [GoldenScheduleController::class, 'store']);
    Route::get('/golden-schedule/document', [GoldenScheduleController::class, 'document']);
    Route::post('/golden-schedule/{id}/sync', [GoldenScheduleController::class, 'sync']);
    Route::delete('/golden-schedule/{id}', [GoldenScheduleController::class, 'destroy']);

    // حلقات النقاش — جلسةُ مجموعةٍ بسعة ومستشار. الإسناد يُنشئ صفوف `schedules`
    // عادية، فالحضور وكشف اليوم والتقييم تلتقطها بلا تعديل.
    Route::get('/discussion-circles', [DiscussionCircleController::class, 'index']);
    Route::post('/discussion-circles', [DiscussionCircleController::class, 'store']);
    Route::put('/discussion-circles/{id}', [DiscussionCircleController::class, 'update']);
    Route::delete('/discussion-circles/{id}', [DiscussionCircleController::class, 'destroy']);
    Route::post('/discussion-circles/{id}/attach', [DiscussionCircleController::class, 'attach']);
    Route::delete('/discussion-circles/{id}/detach', [DiscussionCircleController::class, 'detach']);

    // مجموعتا كشف اليوم + الكشف المطبوع
    // الإسناد وحده يلزمه roster.manage؛ العرض والطباعة تكفيهما schedule.view
    Route::get('/roster', [RosterController::class, 'index']);
    Route::post('/roster/assign', [RosterController::class, 'assign']);
    Route::delete('/roster/assign', [RosterController::class, 'unassign']);
    // GET /roster/document — كشف حضور المشاركين جاهز للطباعة (المتصفّح → PDF)
    Route::get('/roster/document', [RosterController::class, 'document']);
    // قطاعات اليوم وأعدادها — لفتح ملفٍّ لكل قطاع على حدة
    Route::get('/roster/sectors', [RosterController::class, 'sectors']);

    // التوزيع الأسبوعي
    Route::get('/distribution', [DistributionController::class, 'index']);
    Route::post('/distribution/propose', [DistributionController::class, 'propose']);
    Route::post('/distribution/{id}/approve', [DistributionController::class, 'approve']);
    Route::delete('/distribution/{id}', [DistributionController::class, 'destroy']);

    // ═══ تسليم الجدولة للجهات ═══
    // التقسيم على فئة المشارك (مدني/عسكري/متعاقد)، والربط بالجهة بيانٌ يُحرَّر.
    // العرض بـschedule.view، والتسليم بـschedule.dispatch لمدير المركز.
    Route::get('/dispatch/authorities', [DispatchController::class, 'authorities']);
    Route::get('/dispatch/preview', [DispatchController::class, 'preview']);
    Route::post('/dispatch/send', [DispatchController::class, 'send']);
    Route::get('/dispatch/document', [DispatchController::class, 'document']);
    Route::get('/dispatches', [DispatchController::class, 'index']);

    // ═══ استقبال الموظفين ═══
    // كل مسار يفرض صلاحية مرحلته وحدها (reception.view/record/assign/decide/approve)
    Route::get('/reception', [ReceptionController::class, 'index']);
    // «evaluators» قبل أي مسار بمعرّف على المستوى نفسه
    Route::get('/reception/evaluators', [ReceptionController::class, 'evaluators']);
    Route::post('/reception/arrive', [ReceptionController::class, 'arrive']);
    Route::patch('/reception/visits/{id}/arrival', [ReceptionController::class, 'updateArrival']);
    // التوقيع يحمل صورة — يُخنق بالمعدّل كبقية المسارات المكلفة
    Route::post('/reception/visits/{id}/sign', [ReceptionController::class, 'sign'])
        ->middleware('throttle:60,1');
    Route::get('/reception/visits/{id}/cv', [ReceptionController::class, 'visitCv']);
    Route::post('/reception/visits/{id}/assign', [ReceptionController::class, 'assign']);
    Route::post('/reception/visits/{id}/approve', [ReceptionController::class, 'approve']);
    Route::delete('/reception/assignments/{id}', [ReceptionController::class, 'withdraw']);
    Route::post('/reception/assignments/{id}/accept', [ReceptionController::class, 'accept']);
    Route::post('/reception/assignments/{id}/reject', [ReceptionController::class, 'reject']);
    Route::get('/reception/assignments/{id}/cv', [ReceptionController::class, 'assignmentCv']);
    // كشك الجهاز اللوحي وطابور طباعة البطاقات — كلها تفرض reception.record
    Route::get('/reception/kiosks', [ReceptionController::class, 'kiosks']);
    Route::post('/reception/kiosks', [ReceptionController::class, 'createKiosk']);
    Route::delete('/reception/kiosks/{id}', [ReceptionController::class, 'revokeKiosk']);
    Route::get('/reception/print-queue', [ReceptionController::class, 'printQueue']);
    Route::post('/reception/visits/{id}/badge-printed', [ReceptionController::class, 'markBadgePrinted']);
    Route::post('/reception/visits/{id}/badge-reprint', [ReceptionController::class, 'reprintBadge']);

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
    Route::get('/reports/analytics', [ReportController::class, 'analytics']);
    Route::get('/reports/eligible-candidates', [ReportController::class, 'eligibleCandidates']);
    Route::get('/reports/score-preview', [ReportController::class, 'scorePreview']);
    Route::get('/reports/competency-gap', [ReportController::class, 'competencyGap']);
    Route::get('/reports/export', [ReportController::class, 'exportCsv']);
    Route::post('/reports', [ReportController::class, 'store']);
    Route::get('/reports/{id}', [ReportController::class, 'show']);
    Route::get('/reports/{id}/document', [ReportController::class, 'document']);
    Route::get('/reports/{id}/brief', [ReportController::class, 'briefDocument']);
    Route::post('/reports/{id}/executive-summary', [ReportController::class, 'saveExecutiveSummary']);
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
