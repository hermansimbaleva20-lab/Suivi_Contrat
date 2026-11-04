<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class GuaranteeExtension extends Model { protected $fillable=['performance_guarantee_id','start_date','end_date','type','reason']; protected $casts=['start_date'=>'date','end_date'=>'date']; public function guarantee(){return $this->belongsTo(PerformanceGuarantee::class,'performance_guarantee_id');} }
