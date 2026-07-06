<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['recipient_id','type','title','body','entity_type','entity_id','is_read','read_at','created_by'];
    protected $casts = ['is_read'=>'boolean','read_at'=>'datetime'];
}
