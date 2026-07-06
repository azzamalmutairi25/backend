<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = ['thread_id','sender_id','message','message_type','action_type'];
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_id'); }
}
