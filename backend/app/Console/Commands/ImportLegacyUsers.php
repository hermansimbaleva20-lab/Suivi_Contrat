<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
class ImportLegacyUsers extends Command {
 protected $signature='prise:import-legacy-users {file : CSV exported from the original SQL Server database}';
 protected $description='Importe les comptes utilisateurs de l’ancienne application PRISE sans perdre leurs empreintes de mots de passe.';
 public function handle(): int {
  $path=$this->argument('file');if(!is_file($path)){ $this->error("Fichier introuvable: {$path}");return self::FAILURE; }
  $fp=fopen($path,'r');$headers=fgetcsv($fp,0,',','"','\\');if(!$headers){$this->error('CSV vide ou illisible.');fclose($fp);return self::FAILURE;}
  $headers=array_map(fn($x)=>strtolower(trim(preg_replace('/^\\xEF\\xBB\\xBF/','',(string)$x))),$headers);$count=0;$skip=0;
  while(($row=fgetcsv($fp,0,',','"','\\'))!==false){if(count($row)!==count($headers)){$skip++;continue;}$r=array_combine($headers,$row);$get=fn($k,$default=null)=>$r[strtolower($k)]??$default;
   $code=strtoupper(trim((string)$get('ProjectCode','PRISE')));$project=DB::table('projects')->where('code',$code)->first();if(!$project){$this->warn("Projet {$code} introuvable; utilisateur ignoré.");$skip++;continue;}
   $email=strtolower(trim((string)$get('Email','')));$name=trim((string)$get('FullName',''));if(!$email||!filter_var($email,FILTER_VALIDATE_EMAIL)||!$name){$skip++;continue;}
   $hash=trim((string)$get('PasswordHash',''));$salt=trim((string)$get('PasswordSalt',''));$active=$this->bool($get('IsActive','1'));$pending=$this->bool($get('IsPending','0'));
   $data=['project_id'=>$project->id,'full_name'=>$name,'email'=>$email,'project_position'=>$get('ProjectPosition')?:($get('Function')?:null),'role'=>$get('Role','UTILISATEUR')?:'UTILISATEUR','is_active'=>$active,'is_pending'=>$pending,'can_manage_settings'=>$this->bool($get('CanManageSettings','0')),'can_manage_users'=>$this->bool($get('CanManageUsers','0')),'can_delete_contracts'=>$this->bool($get('CanDeleteContracts','0')),'can_manage_contracts'=>$this->bool($get('CanManageContracts','0')),'can_manage_companies'=>$this->bool($get('CanManageCompanies','0')),'can_manage_progress'=>$this->bool($get('CanManageProgress','0')),'can_manage_payments'=>$this->bool($get('CanManagePayments','0')),'can_view_reports'=>$this->bool($get('CanViewReports','1')),'can_view_alerts_contracts'=>$this->bool($get('CanViewAlerts','1')),'can_view_alerts_guarantees'=>$this->bool($get('CanViewAlerts','1')),'can_view_situation'=>$this->bool($get('CanViewSituation','1')),'can_view_archives'=>$this->bool($get('CanViewArchives','0')),'can_manage_guarantees'=>$this->bool($get('CanManageGuarantees','0')),'is_super_admin'=>$this->bool($get('IsSuperAdmin','0')),'failed_login_count'=>(int)$get('FailedLoginCount','0'),'lockout_end'=>$get('LockoutEnd')?:null,'last_login_at'=>$get('LastLoginAt')?:null,'password_hash'=>$hash?:null,'password_salt'=>$salt?:null];
   // The old password verifier is used on first login. This random bcrypt value is never shown to the user.
   $existing=User::where('project_id',$project->id)->whereRaw('LOWER(email)=?',[$email])->first();
   if($existing){$existing->fill($data);$existing->password=Hash::make(Str::random(80));$existing->save();}else{$data['password']=Hash::make(Str::random(80));User::create($data);} $count++;
  }
  fclose($fp);$this->info("Import terminé: {$count} compte(s) importé(s)/mis à jour; {$skip} ligne(s) ignorée(s).");$this->warn('Vérifiez que chaque compte conserve son ProjectCode, PasswordHash et PasswordSalt avant la première connexion.');return self::SUCCESS;
 }
 private function bool($v): bool {return in_array(strtolower(trim((string)$v)),['1','true','yes','oui','on'],true);}
}
