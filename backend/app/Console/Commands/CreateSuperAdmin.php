<?php
namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateSuperAdmin extends Command
{
    protected $signature = 'prise:create-super-admin {email? : Adresse du super-administrateur} {--name=Super-administrateur}';
    protected $description = 'Crée (ou promeut) le super-administrateur dans PRISE et PREDIRE et affiche un lien d’initialisation du mot de passe (24 h).';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?: config('prise.super_admin_email'))));
        $flags = ['can_manage_settings', 'can_manage_users', 'can_delete_contracts', 'can_manage_contracts', 'can_manage_companies', 'can_manage_progress', 'can_manage_payments', 'can_view_reports', 'can_view_alerts_contracts', 'can_view_alerts_guarantees', 'can_view_alerts_logistics', 'can_view_situation', 'can_view_archives', 'can_manage_guarantees', 'can_view_stock', 'can_manage_stock', 'can_view_fleet', 'can_manage_fleet', 'can_authorize_fleet', 'can_validate_decomptes'];
        foreach (Project::all() as $project) {
            $u = User::where('project_id', $project->id)->whereRaw('LOWER(email)=?', [$email])->first();
            if (!$u) $u = new User(['project_id' => $project->id, 'email' => $email, 'password' => Hash::make(Str::random(60))]);
           
            $u->full_name = $u->full_name ?: $this->option('name');
            $u->role = 'ADMINISTRATEUR'; 
            $u->is_active = true; 
            $u->is_pending = false; 
            $u->is_super_admin = true;
            foreach ($flags as $f) $u->$f = true;
            $u->save();
            $raw = bin2hex(random_bytes(32));
            DB::table('user_reset_tokens')->insert(['project_id' => $project->id, 'user_id' => $u->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
            $this->info($project->code . ' : ' . config('prise.frontend_url') . '/reset-password?project=' . $project->code . '&token=' . $raw);
        }
        $this->warn('Ouvrez chaque lien (valable 24 h, usage unique) pour définir le mot de passe du super-administrateur.');
        return self::SUCCESS;
    }
}
