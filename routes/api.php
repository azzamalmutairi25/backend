<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\ActivityCompetencyController;
use App\Http\Controllers\PublicAssessmentController;

// ════════════════════════════════════════════════════════════
//  مسارات الـ API — كلها تحت البادئة /api
// ════════════════════════════════════════════════════════════

// ── عام (بدون مصادقة) ──
Route::post('/login', [AuthController::class, 'login']);

// ── بوابة المرشح العامة (رمز فريد في الرسالة النصية) — مقيّدة بالمعدل ضد التخمين ──
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/public/assessment/{token}', [PublicAssessmentController::class, 'show']);
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
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}/toggle', [UserController::class, 'toggleActive']);
    Route::patch('/users/{id}/password', [UserController::class, 'resetPassword']);
    Route::get('/settings/ldap', [SettingsController::class, 'getLdap']);
    Route::put('/settings/ldap', [SettingsController::class, 'saveLdap']);
    Route::post('/settings/ldap/test', [SettingsController::class, 'testLdap']);
    Route::get('/sectors', [SectorController::class, 'index']);

    // ═══ التقييم ═══
    Route::get('/competencies', [EvaluationController::class, 'competencies']);
    Route::get('/activity-competencies', [ActivityCompetencyController::class, 'index']);
    Route::put('/activity-competencies/{activity}', [ActivityCompetencyController::class, 'update']);
    Route::post('/evaluations/start', [EvaluationController::class, 'start']);
    Route::get('/evaluations', [EvaluationController::class, 'index']);
    Route::get('/evaluations/{id}', [EvaluationController::class, 'show']);
    Route::post('/evaluations/{id}/scores', [EvaluationController::class, 'saveScores']);
    Route::post('/evaluations/{id}/submit', [EvaluationController::class, 'submit']);
    Route::post('/evaluations/{id}/approve', [EvaluationController::class, 'approve']);
    Route::post('/evaluations/{id}/return', [EvaluationController::class, 'returnEvaluation']);

    // ═══ الحضور ═══
    Route::get('/attendance/today', [AttendanceController::class, 'today']);
    Route::get('/attendance/stats', [AttendanceController::class, 'stats']);
    Route::post('/attendance/{scheduleId}/checkin', [AttendanceController::class, 'checkIn']);
    Route::post('/attendance/{scheduleId}/absence', [AttendanceController::class, 'recordAbsence']);

    // ═══ التقارير ═══
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/stats', [ReportController::class, 'stats']);
    Route::post('/reports/{id}/approve', [ReportController::class, 'approve']);
    Route::post('/reports/{id}/return', [ReportController::class, 'returnReport']);
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
