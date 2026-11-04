<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Project extends Model { protected $fillable=['code','name']; public function phases(){return $this->hasMany(ProjectPhase::class);} }
