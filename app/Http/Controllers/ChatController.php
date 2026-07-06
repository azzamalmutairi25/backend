<?php

namespace App\Http\Controllers;

use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Services\NotificationService;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  وحدة التحكم بالمحادثات
// ════════════════════════════════════════════════════════════

class ChatController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    // ── جلب محادثة كيان ──
    public function thread(Request $request, string $entityType, int $entityId)
    {
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
            'isClosed' => $thread->is_closed,
            'messages' => $messages,
        ]);
    }

    // ── إرسال رسالة ──
    public function send(Request $request, int $threadId)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'messageType' => 'nullable|string',
            'actionType' => 'nullable|string',
        ]);

        $thread = ChatThread::findOrFail($threadId);
        if ($thread->is_closed) {
            return response()->json(['error' => 'المحادثة مغلقة'], 400);
        }

        $userId = $request->user()->id;
        $msg = ChatMessage::create([
            'thread_id' => $threadId,
            'sender_id' => $userId,
            'message' => $validated['message'],
            'message_type' => $validated['messageType'] ?? 'comment',
            'action_type' => $validated['actionType'] ?? null,
        ]);

        // إشعار بقية المشاركين
        $others = ChatMessage::where('thread_id', $threadId)
            ->where('sender_id', '!=', $userId)
            ->distinct()->pluck('sender_id');

        $senderName = $request->user()->full_name;
        foreach ($others as $pid) {
            $this->notify->notify($pid, 'info',
                "رسالة جديدة من {$senderName}",
                mb_substr($validated['message'], 0, 80),
                $thread->entity_type, (string) $thread->entity_id, $userId);
        }

        return response()->json(['message' => 'تم الإرسال', 'messageId' => $msg->id], 201);
    }
}
