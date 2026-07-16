<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    protected $fillable = ['code', 'name_ar', 'is_military', 'participant_prefix'];
    protected $casts = ['is_military' => 'boolean'];
}
