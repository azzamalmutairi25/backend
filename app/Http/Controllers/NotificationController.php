<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  وحدة التحكم بالإشعارات
// ════════════════════════════════════════════════════════════

class NotificationController extends Controller
{
    // ── إشعاراتي ──
    public function index(Request $request)
    {
        $request->validate([
            'unreadOnly' => 'nullable|boolean',
            'perPage' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $userId = $request->user()->id;
        $query = Notification::where('recipient_id', $userId);
        if ($request->boolean('unreadOnly')) {
            $query->where('is_read', false);
        }

        $paginated = $query->orderByDesc('created_at')->paginate((int) $request->input('perPage', 50));

        $list = collect($paginated->items())->map(fn ($n) => [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'entityType' => $n->entity_type,
            'entityId' => $n->entity_id,
            'isRead' => $n->is_read,
            'createdAt' => $n->created_at,
        ]);

        $unreadCount = Notification::where('recipient_id', $userId)->where('is_read', false)->count();

        return response()->json([
            'notifications' => $list,
            'unreadCount' => $unreadCount,
            'total' => $paginated->total(),
            'page' => $paginated->currentPage(),
            'lastPage' => $paginated->lastPage(),
        ]);
    }

    // ── عدد غير المقروء ──
    public function unreadCount(Request $request)
    {
        $count = Notification::where('recipient_id', $request->user()->id)
            ->where('is_read', false)->count();
        return response()->json(['count' => $count]);
    }

    // ── تعليم كمقروء ──
    public function markRead(Request $request, int $id)
    {
        $notif = Notification::where('id', $id)
            ->where('recipient_id', $request->user()->id)->firstOrFail();
        $notif->update(['is_read' => true, 'read_at' => now()]);
        return response()->json(['message' => 'تم']);
    }

    // ── تعليم الكل كمقروء ──
    public function markAllRead(Request $request)
    {
        $count = Notification::where('recipient_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
        return response()->json(['message' => "تم تعليم {$count} إشعاراً"]);
    }
}
