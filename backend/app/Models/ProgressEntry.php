<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProgressEntry extends Model { protected $fillable=['contract_id','contract_site_id','situation_date','progress','observation','paid_amount','realization_physical']; protected $casts=['situation_date'=>'date','progress'=>'decimal:2','paid_amount'=>'decimal:2']; public function site(){return $this->belongsTo(ContractSite::class,'contract_site_id');} }
