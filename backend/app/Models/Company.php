<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Company extends Model { protected $fillable=['project_id','name','address','phone','email','observation']; public function project(){return $this->belongsTo(Project::class);} public function contracts(){return $this->hasMany(Contract::class);} }
