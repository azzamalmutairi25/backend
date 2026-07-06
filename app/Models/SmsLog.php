<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $fillable = ['to_mobile','message','sms_type','candidate_id',
        'status','provider_ref','error_message','sent_at','created_by'];
    protected $casts = ['sent_at'=>'datetime'];
}
