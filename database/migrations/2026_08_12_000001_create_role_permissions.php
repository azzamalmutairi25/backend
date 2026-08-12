<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  صلاحيات الأدوار — تنتقل من ثابتٍ في الشيفرة إلى بياناتٍ يُحرّرها المدير.
//
//  كانت مصفوفة Permissions::matrix() هي المرجع الوحيد، فأي تعديل يحتاج نشراً.
//  الآن الجدول هو المرجع، والمصفوفة تبقى **القيمة الافتراضية** التي يُبذَر بها
//  كل دور أول مرّة، والمرجعَ الاحتياطي لدورٍ لا صفوف له.
//
//  تحذير الهجرة السابقة (user_permission_overrides) كان في محلّه: تعديل الدور
//  يمسّ كل من يحمله. لذلك لا يُفتح الباب على مصراعيه — الحرّاس في
//  RoleController: لا يُعدَّل دور مدير النظام، ولا يعدّل أحدٌ دور نفسه، ولا
//  يمنح أحدٌ صلاحيةً لا يملكها هو (سقفٌ لا مَنعٌ مطلق).
// ════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission', 60);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // صفٌّ واحد لكل (دور، صلاحية) — تكراره يجعل النتيجة تعتمد على الترتيب
            $table->unique(['role_id', 'permission']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
