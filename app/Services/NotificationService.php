<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

// ════════════════════════════════════════════════════════════
//  خدمة الإشعارات الداخلية
//  تنشئ إشعارات للمستخدمين عند الأحداث المهمة
// ════════════════════════════════════════════════════════════

class NotificationService
{
    // ── إشعار لمستخدم واحد ──
    public function notify(
        int $recipientId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?int $createdBy = null
    ): void {
        Notification::create([
            'recipient_id' => $recipientId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_by' => $createdBy,
            'is_read' => false,
        ]);
    }

    // ── إشعار لكل من يحمل دوراً معيّناً ──
    public function notifyRole(
        string $roleCode,
        string $type,
        string $title,
        ?string $body = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?int $createdBy = null
    ): void {
        // جلب كل المستخدمين النشطين بهذا الدور
        $userIds = User::whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->where('is_active', true)
            ->pluck('id');

        foreach ($userIds as $uid) {
            $this->notify($uid, $type, $title, $body, $entityType, $entityId, $createdBy);
        }
    }
}
