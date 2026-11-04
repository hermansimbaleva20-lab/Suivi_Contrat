<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditLog extends Model { protected $fillable=['project_id','user_id','action','entity_name','entity_id','details','machine_name','organizational_unit_id','old_values','new_values']; public function user(){return $this->belongsTo(User::class);} }
