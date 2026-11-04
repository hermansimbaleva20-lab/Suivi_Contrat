<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PasswordResetRequest extends Model {protected $fillable=['project_id','email','requested_at','is_read_by_admin'];protected $casts=['requested_at'=>'datetime','is_read_by_admin'=>'boolean'];}
