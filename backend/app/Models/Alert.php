<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Alert extends Model { protected $fillable=['project_id','contract_id','type','message','sent_at','resolved_at']; protected $casts=['sent_at'=>'datetime','resolved_at'=>'datetime']; public function contract(){return $this->belongsTo(Contract::class);} }
