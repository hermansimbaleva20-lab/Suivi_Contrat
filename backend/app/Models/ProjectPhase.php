<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProjectPhase extends Model { protected $fillable=['project_id','number','name','description','is_current','is_archived']; protected $casts=['is_current'=>'boolean','is_archived'=>'boolean']; public function project(){return $this->belongsTo(Project::class);} public function contracts(){return $this->hasMany(Contract::class,'phase_id');} }
