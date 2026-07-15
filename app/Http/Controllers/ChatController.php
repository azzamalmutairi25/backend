<?php

namespace App\Http\Controllers;

use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Models\FinalReport;
use App\Security\Permissions;
use App\Services\NotificationService;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  وحدة التحكم بالمحادثات
// ════════════════════════════════════════════════════════════

class ChatController extends Controller
{
    public function __construct(private NotificationService $notify) {}


    // يتحقق أن للمستخدم صلاحية الوصول لكيان المحادثة (نفس بوابة الكيان الأصلي)
    private function authorizeEntity(Request $request, string $entityType, int $entityId)
    {
        if ($entityType === 'report') {
            if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
                return response()->json(['error' => 'ليس لديك صلاحية الوصول لهذه المحادثة'], 403);
            }
            // نطاق التقرير نفسه لا نصفه: كان يفحص التصنيف وحده، فكانت محادثة
            // تقريرٍ من قطاع آخر مفتوحةً بمعرّفه — والمحادثة تحمل سبب الإرجاع
            // ونقاش المقيّمين، أي مضمون التقرير المحجوب.
            // مصنّف أو خارج النطاق = «غير موجودة» (لا كشف وجود)
            $q = FinalReport::with('candidate');
            $this->scopeReports($request, $q);

            if (!$q->find($entityId)) {
                return response()->json(['error' => 'المحادثة غير موجودة'], 404);
            }
            return null;
        }

        // أنواع كيانات غير مدعومة — منع إنشاء محادثات يتيمة لمدخلات عشوائية
        return response()->json(['error' => 'نوع محادثة غير مدعوم'], 422);
    }

    // ── جلب محادثة كيان ──
    public function thread(Request $request, string $entityType, int $entityId)
    {
        if ($resp = $this->authorizeEntity($request, $entityType, $entityId)) {
            return $resp;
        }

        $thread = ChatThread::firstOrCreate(
            ['entity_type' => $entityType, 'entity_id' => $entityId],
            ['title' => 'محادثة']
        );

        $messages = ChatMessage::with('sender.role')
            ->where('thread_id', $thread->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'message' => $m->message,
                'messageType' => $m->message_type,
                'actionType' => $m->action_type,
                'senderName' => $m->sender->full_name,
                'senderRole' => $m->sender->role->name_ar,
                'senderId' => $m->sender_id,
                'createdAt' => $m->created_at,
            ]);

        return response()->json([
            'threadId' => $thread->id,
            // (bool) صريح — صف firstOrCreate الجديد لا يُحمّل default القاعدة فيرجع is_closed = null بدل false
            'isClosed' => (bool) $thread->is_closed,
            'messages' => $messages,
        ]);
    }

    // ── إرسال رسالة ──
    public function send(Request $request, int $threadId)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $thread = ChatThread::findOrFail($threadId);

        if ($resp = $this->authorizeEntity($request, $thread->entity_type, $thread->entity_id)) {
            return $resp;
        }
        if ($thread->is_closed) {
            return response()->json(['error' => 'المحادثة مغلقة'], 400);
        }

        $userId = $request->user()->id;
        // رسائل المستخدمين دائمًا من نوع 'comment' — منع انتحال رسائل النظام/الإجراءات
        $msg = ChatMessage::create([
            'thread_id' => $threadId,
            'sender_id' => $userId,
            'message' => $validated['message'],
            'message_type' => 'comment',
            'action_type' => null,
        ]);

        // إشعار بقية المشاركين
        $others = ChatMessage::where('thread_id', $threadId)
            ->where('sender_id', '!=', $userId)
            ->distinct()->pluck('sender_id');

        $senderName = $request->user()->full_name;
        foreach ($others as $pid) {
            // لا ننسخ نص الرسالة في الإشعار (لقطة مجمّدة قد تتجاوز التصنيف لاحقًا) — إشعار عام فقط
            $this->notify->notify($pid, 'info',
                "رسالة جديدة من {$senderName}",
                'لديك رسالة جديدة في محادثة — افتحها للاطّلاع',
                $thread->entity_type, (string) $thread->entity_id, $userId);
        }

        return response()->json(['message' => 'تم الإرسال', 'messageId' => $msg->id], 201);
    }
}
