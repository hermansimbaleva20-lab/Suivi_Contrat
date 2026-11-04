<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SmtpSetting extends Model {protected $fillable=['project_id','host','port','sender_email','password','use_ssl','recipient1','recipient2','recipient3','recipient4'];protected $casts=['port'=>'integer','use_ssl'=>'boolean'];}
