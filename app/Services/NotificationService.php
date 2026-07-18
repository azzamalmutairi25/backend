<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\FinalReport;
use App\Security\Permissions;

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

        // notifications.title = varchar(200). عنوان محادثة «رسالة جديدة من {full_name}»
        // مع اسم طويل (full_name يبلغ 200) يتجاوز الحدّ فيرمي Postgres 22001 ⇒ 500،
        // والرسالة تُنشأ قبل حلقة الإشعار فتبقى يتيمة. نقصّ دفاعياً لكل المنادين.
        $title = mb_substr($title, 0, 200);

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
        // جلب كل المستخدمين النشطين بهذا الدور (مع الدور لفحص الحصر/الصلاحية)
        $recipients = User::whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->where('is_active', true)
            ->with('role')
            ->get();

        // حصر إشعار «تقرير» على من يرى المرشّح فعلاً. بدونه كانت notifyRole تُذيع رمز
        // المشارك (participant_code داخل المتن) لكل حاملي الدور بلا حدّ قطاع/تصنيف — فمرحلة
        // المقيّم (EVALUATOR، محصور قطاعياً وبلا رؤية للمصنّفين) تُسرّب رمز مرشّح خارج
        // القطاع أو مصنّفاً لمقيّمين يُحجب عنهم التقرير نفسه (scopeReports ⇒ 404).
        // نُطابق حصر resolveCandidateInScope: التصنيف + القطاع للمحصورين.
        if ($entityType === 'report' && $entityId !== null) {
            $candidate = FinalReport::with('candidate')->find((int) $entityId)?->candidate;
            if ($candidate) {
                $recipients = $recipients->filter(function (User $u) use ($candidate) {
                    // المصنّف يتطلّب صلاحية رؤية المصنّفين
                    if ($candidate->classification !== 'normal'
                        && !$u->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)) {
                        return false;
                    }
                    // المحصور قطاعياً لا يُشعَر بمرشّح خارج قطاعه
                    if ($u->isSectorBound() && $u->sector_id !== $candidate->sector_id) {
                        return false;
                    }
                    return true;
                });
            }
        }

        foreach ($recipients as $u) {
            $this->notify($u->id, $type, $title, $body, $entityType, $entityId, $createdBy);
        }
    }
}
