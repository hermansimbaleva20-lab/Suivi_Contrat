<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ContractDocument extends Model {
 protected $fillable=['contract_id','original_name','storage_path','mime_type','file_size','uploaded_by'];
 public function contract(){return $this->belongsTo(Contract::class);}
 public function uploader(){return $this->belongsTo(User::class,'uploaded_by');}
}
