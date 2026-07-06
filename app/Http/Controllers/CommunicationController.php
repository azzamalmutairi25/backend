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
            $ok = $this->comm->sendInvitationEmail($candidate->id, $candidate->email, null, $data, $userId);
            $results[] = $ok ? 'تم إرسال البريد' : 'فشل إرسال البريد';
        }

        // إرسال الرسالة النصية (الجوال يُفكّ تشفيره تلقائياً عبر الموديل)
        if (($validated['sendSms'] ?? false) && $candidate->mobile) {
            $ok = $this->comm->sendInvitationSms($candidate->id, $candidate->mobile, $data, $userId);
            $results[] = $ok ? 'تم إرسال الرسالة النصية' : 'فشل إرسال الرسالة';
        }

        AuditLog::create([
            'user_id' => $userId,
            'action' => 'SEND_INVITATION',
            'entity_type' => 'candidate',
            'entity_id' => (string) $candidate->id,
            'details' => ['email' => $validated['sendEmail'] ?? false, 'sms' => $validated['sendSms'] ?? false],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        if (empty($results)) {
            return response()->json(['error' => 'لا يوجد بريد أو جوال للمرشح، أو لم تحدد طريقة إرسال'], 400);
        }

        return response()->json(['message' => 'تمت معالجة الدعوة', 'results' => $results]);
    }

    // ── سجل المراسلات لمرشح ──
    public function history(Request $request, int $candidateId)
    {
        $emails = EmailLog::where('candidate_id', $candidateId)
            ->orderByDesc('created_at')
            ->get(['subject', 'email_type', 'status', 'created_at']);

        $sms = SmsLog::where('candidate_id', $candidateId)
            ->orderByDesc('created_at')
            ->get(['message', 'sms_type', 'status', 'created_at']);

        return response()->json(['emails' => $emails, 'sms' => $sms]);
    }
}
