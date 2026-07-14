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
    // يجب أن تطابق قيد CHECK على notifications.type — القاعدة هي المرجع
    public const TYPES = ['info', 'action', 'approval', 'return', 'report', 'system'];

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
        // ارفض مبكراً برسالة واضحة: انتهاك CHECK يُجهض المعاملة المحيطة كاملة في Postgres،
        // فيسقط الفعل الأصلي بسبب إشعار — والخطأ يظهر بعيداً عن سببه
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                "نوع إشعار غير معروف: '{$type}'. المسموح: " . implode(', ', self::TYPES)
            );
        }

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
