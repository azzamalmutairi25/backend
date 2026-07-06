<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatThread extends Model
{
    protected $fillable = ['entity_type','entity_id','title','is_closed'];
    protected $casts = ['is_closed'=>'boolean'];
    public function messages(): HasMany { return $this->hasMany(ChatMessage::class, 'thread_id'); }
}
