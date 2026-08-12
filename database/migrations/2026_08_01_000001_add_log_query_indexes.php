<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  فهارس استعلامات السجلّات — تسدّ فحوصاً كاملة (seq scan) على مسارات
//  الاستعلام الفعلية:
//   • audit_logs: systemLog يفلتر على created_at (whereDate)، وتاريخ الكيان
//     الواحد يفلتر على (entity_type, entity_id) — والفهارس الحالية على
//     user_id و action فقط، فلا يخدمانِ أيّاً من المسارين.
//   • sms_logs: بلا أي فهرس سوى المفتاح، ومراقبة «وضع التطوير» تفلتر على
//     created_at آلاف المرّات يومياً؛ والبحث الوحيد المتبقّي candidate_id
//     (نصّ الرسالة مشفّر فلا يُستعلم به).
//  ملاحظة: الجداول فارغة عند الإطلاق الأول فالإنشاء لحظي. لو أُضيفت هذه
//  الفهارس لاحقاً على جدول حيّ كبير فاستخدم CREATE INDEX CONCURRENTLY يدوياً
//  خارج المهاجرة (لا يعمل داخل معاملة).
// ════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at', 'audit_logs_created_at_index');
            $table->index(
                ['entity_type', 'entity_id', 'created_at'],
                'audit_logs_entity_created_index'
            );
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->index('created_at', 'sms_logs_created_at_index');
            $table->index('candidate_id', 'sms_logs_candidate_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_created_at_index');
            $table->dropIndex('audit_logs_entity_created_index');
        });

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropIndex('sms_logs_created_at_index');
            $table->dropIndex('sms_logs_candidate_id_index');
        });
    }
};
