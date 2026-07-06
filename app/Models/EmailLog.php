<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $fillable = ['to_email','to_name','subject','body','email_type',
        'candidate_id','status','error_message','sent_at','created_by'];
    protected $casts = ['sent_at'=>'datetime'];
}
