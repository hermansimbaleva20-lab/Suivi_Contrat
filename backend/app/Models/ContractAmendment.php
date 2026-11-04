<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ContractAmendment extends Model { protected $fillable=['contract_id','date','duration_days','reason','new_end_date']; protected $casts=['date'=>'date','new_end_date'=>'date']; }
