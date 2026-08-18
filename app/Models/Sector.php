<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    protected $fillable = ['code', 'name_ar', 'full_name_ar', 'participant_prefix'];
}
