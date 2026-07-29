<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// أوقات جلسات اليوم — تُحرّرها إدارة المرشحين من شاشة الإعدادات.
//
// كانت الأوقات قبل هذا غير موجودة أصلاً كمفهوم: schedule_time حقل حرّ يُملأ
// يدوياً أو يُترك فارغاً، فلا يمكن بناء أعمدة الكشف المطبوع عليه. هذه القائمة
// هي المرجع الذي تُقيَّد به الجلسات الجديدة وتُبنى منه رؤوس أعمدة الكشف.
//
// القيم الابتدائية من النموذج الورقي المعتمد: القياس ثم فترتا المقابلة والنقاش.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'schedule.session_times'],
            [
                'value' => '10:15,12:30,14:30',
                'description' => 'أوقات جلسات اليوم — تُبنى منها أعمدة كشف الحضور',
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'schedule.session_times')->delete();
    }
};
