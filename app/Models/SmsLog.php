<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $fillable = ['to_mobile','message','sms_type','candidate_id',
        'status','provider_ref','error_message','sent_at','created_by'];
    // تشفير عند التخزين: الرسالة تحوي الاسم + رابط التأكيد، والجوال بيانات شخصية
    // (يماثل تشفير جدول المرشحين — لا تُترك PII واضحة في السجلّات/النسخ الاحتياطية)
    protected $casts = [
        'sent_at' => 'datetime',
        'message' => 'encrypted',
        'to_mobile' => 'encrypted',
    ];
}
