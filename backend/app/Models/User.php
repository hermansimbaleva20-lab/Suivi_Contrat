<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable {
 use HasApiTokens, Notifiable;
 protected $fillable=['project_id','organizational_unit_id','position_id','full_name','email','password','password_hash','password_salt','project_position','role','is_active','is_pending','can_view_archives','can_manage_guarantees','can_manage_settings','can_manage_users','can_delete_contracts','can_manage_contracts','can_manage_companies','can_manage_progress','can_manage_payments','can_view_reports','can_view_alerts_contracts','can_view_alerts_guarantees','can_view_alerts_logistics','can_view_situation','can_view_stock','can_manage_stock','can_view_fleet','can_manage_fleet','can_authorize_fleet','can_validate_decomptes','can_follow_new_contracts','can_view_alert_history','can_view_pools','logistics_authority','is_super_admin','failed_login_count','lockout_end','last_login_at'];
 protected $hidden=['password','password_hash','password_salt','remember_token'];
 protected function casts(): array { return ['password'=>'hashed','is_active'=>'boolean','is_pending'=>'boolean','can_view_archives'=>'boolean','can_manage_guarantees'=>'boolean','can_manage_settings'=>'boolean','can_manage_users'=>'boolean','can_delete_contracts'=>'boolean','can_manage_contracts'=>'boolean','can_manage_companies'=>'boolean','can_manage_progress'=>'boolean','can_manage_payments'=>'boolean','can_view_reports'=>'boolean','can_view_alerts_contracts'=>'boolean','can_view_alerts_guarantees'=>'boolean','can_view_alerts_logistics'=>'boolean','can_view_situation'=>'boolean','can_view_stock'=>'boolean','can_manage_stock'=>'boolean','can_view_fleet'=>'boolean','can_manage_fleet'=>'boolean','can_authorize_fleet'=>'boolean','can_validate_decomptes'=>'boolean','can_follow_new_contracts'=>'boolean','can_view_alert_history'=>'boolean','can_view_pools'=>'boolean','is_super_admin'=>'boolean','lockout_end'=>'datetime','last_login_at'=>'datetime']; }
 public function organizationalUnit(){return $this->belongsTo(OrganizationalUnit::class);}
 public function position(){return $this->belongsTo(Position::class);}
}
