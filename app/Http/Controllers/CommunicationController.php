<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\EmailLog;
use App\Models\SmsLog;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Services\CommunicationService;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  وحدة التحكم بالاتصالات — الدعوات (بريد/رسائل)
// ════════════════════════════════════════════════════════════

class CommunicationController extends Controller
{
    public function __construct(private CommunicationService $comm) {}

    // ── إرسال دعوة للمرشح (بريد و/أو رسالة) ──
    public function invite(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SEND_INVITATION)) {
            return response()->json(['error' => 'ليس لديك صلاحية إرسال الدعوات'], 403);
        }

        $validated = $request->validate([
            'candidateId' => 'required|exists:candidates,id',
            'date' => 'required|string',
            'time' => 'required|string',
            'location' => 'required|string',
            'requirements' => 'nullable|string',
            'sendEmail' => 'boolean',
            'sendSms' => 'boolean',
        ]);

        $candidate = Candidate::findOrFail($validated['candidateId']);
        // بوابة التصنيف — لا تُرسَل دعوة لمرشح مصنّف لمن لا يملك صلاحيته (مصنّف = «غير موجود»)
        if ($candidate->classification !== 'normal'
            && !$request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        $data = [
            'date' => $validated['date'],
            'time' => $validated['time'],
            'location' => $validated['location'],
            'requirements' => $validated['requirements'] ?? '',
        ];

        $results = [];
        $userId = $request->user()->id;

        // إرسال البريد
        if (($validated['sendEmail'] ?? false) && $candidate->email) {
            $ok = $this->comm->sendInvitationEmail($candidate->id, $candidate->email, $candidate->full_name, $data, $userId);
            $results[] = $ok ? 'تم إرسال البريد' : 'فشل إرسال البريد';
        }

        // إرسال الرسالة النصية (الجوال يُفكّ تشفيره تلقائياً عبر الموديل)
        if (($validated['sendSms'] ?? false) && $candidate->mobile) {
            $ok = $this->comm->sendInvitationSms($candidate->id, $candidate->mobile, $data, $userId);
            $results[] = $ok ? 'تم إرسال الرسالة النصية' : 'فشل إرسال الرسالة';
        }

        // لا شيء أُرسل فعلاً — لا تكتب سجل «دعوة أُرسلت» (كان يُكتب قبل هذا الفحص فيُوثّق دعوة وهمية)
        if (empty($results)) {
            return response()->json(['error' => 'لا يوجد بريد أو جوال للمرشح، أو لم تحدد طريقة إرسال'], 400);
        }

        AuditLog::create([
            'user_id' => $userId,
            'action' => 'SEND_INVITATION',
            'entity_type' => 'candidate',
            'entity_id' => (string) $candidate->id,
            'details' => [
                'email' => $validated['sendEmail'] ?? false,
                'sms' => $validated['sendSms'] ?? false,
                'results' => $results, // النتيجة الفعلية لكل قناة لا مجرّد الأعلام المطلوبة
            ],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'تمت معالجة الدعوة', 'results' => $results]);
    }

    // ── سجل المراسلات لمرشح ──
    public function history(Request $request, int $candidateId)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض سجل المراسلات'], 403);
        }
        $candidate = Candidate::find($candidateId);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        // بوابة التصنيف الأمني (كما في show/export/journey)
        if ($candidate->classification !== 'normal'
            && !$user->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }

        // نص الرسائل يحوي الاسم — يُكشف فقط لمن يملك رؤية الأسماء، ورابط التأكيد يُحجب دائماً
        $canSeeContent = $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);

        $emails = EmailLog::where('candidate_id', $candidateId)
            ->orderByDesc('created_at')
            ->get(['subject', 'email_type', 'status', 'created_at'])
            ->map(fn ($e) => [
                'subject' => $canSeeContent ? $e->subject : null,
                'emailType' => $e->email_type,
                'status' => $e->status,
                'createdAt' => $e->created_at,
            ]);

        $sms = SmsLog::where('candidate_id', $candidateId)
            ->orderByDesc('created_at')
            ->get(['message', 'sms_type', 'status', 'created_at'])
            ->map(fn ($s) => [
                'message' => $canSeeContent ? $this->redactLink($this->safeMessage($s)) : null,
                'smsType' => $s->sms_type,
                'status' => $s->status,
                'createdAt' => $s->created_at,
            ]);

        return response()->json(['emails' => $emails, 'sms' => $sms]);
    }

    // فك تشفير آمن — صفوف قديمة كُتبت نصًّا صريحًا قبل تفعيل التشفير ترمي DecryptException فتعطّل السجل كله (500)
    private function safeMessage(SmsLog $s): ?string
    {
        try {
            return $s->message; // يمرّ عبر cast التشفير
        } catch (\Throwable $e) {
            return '[تعذّر فك تشفير الرسالة]';
        }
    }

    // حجب رابط التأكيد من نص الرسالة (رمز حيّ يجب ألا يُكشف حتى للموظفين المخوّلين)
    private function redactLink(?string $msg): ?string
    {
        if (!$msg) return $msg;
        return preg_replace('#https?://\S+/confirm/\S+#u', '[رابط تأكيد محجوب]', $msg);
    }
}
