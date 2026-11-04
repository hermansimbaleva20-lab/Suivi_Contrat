<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ContractSite extends Model { protected $fillable=['contract_id','province','work_type','name','description','quantity']; protected $casts=['quantity'=>'decimal:2']; public function contract(){return $this->belongsTo(Contract::class);} public function progress(){return $this->hasMany(ProgressEntry::class);} }
