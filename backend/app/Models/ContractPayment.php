<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ContractPayment extends Model { protected $fillable=['contract_id','date','amount','reference','observation']; protected $casts=['date'=>'date','amount'=>'decimal:2']; public function contract(){return $this->belongsTo(Contract::class);} }
