<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id','action','entity_type','entity_id','details','ip_address','created_at'];
    protected $casts = ['details'=>'array','created_at'=>'datetime'];
}
