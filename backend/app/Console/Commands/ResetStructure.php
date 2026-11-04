<?php
namespace App\Console\Commands;

use App\Support\Backup;
use App\Support\Structure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ResetStructure extends Command
{
    protected $signature = 'prise:reset-structure {--force : Ne pas demander de confirmation} {--no-backup : Ne pas créer de sauvegarde SQL avant la réinitialisation}';
    protected $description = 'Repart d’une structure propre : toutes les tables métier sont recréées VIDES ; les comptes utilisateurs (table users) sont conservés.';

    public function handle(): int
    {
        // Les comptes et la configuration doivent déjà exister : « php artisan migrate » doit avoir été exécuté.
        foreach (['users', 'projects', 'project_phases'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("La table « {$table} » est absente. Exécutez d'abord : php artisan migrate");
                return self::FAILURE;
            }
        }
        foreach (['project_id', 'full_name', 'is_pending', 'is_super_admin'] as $col) {
            if (!Schema::hasColumn('users', $col)) {
                $this->error("La table users n'est pas à jour (colonne « {$col} » manquante). Exécutez d'abord : php artisan migrate");
                return self::FAILURE;
            }
        }

        $this->warn('Cette opération SUPPRIME définitivement toutes les données métier :');
        $this->line('  contrats, sites, avancements, paiements, garanties, avenants, documents, entreprises, journal d’audit, demandes de réinitialisation.');
        $this->info('Conservés : ' . implode(', ', Structure::KEPT_TABLES) . ' (comptes, projets, phases, SMTP, provinces).');
        $this->line('Les tables métier seront recréées VIDES avec la structure de la V9.');

        if (!$this->option('force') && $this->ask('Tapez RESET pour confirmer') !== 'RESET') {
            $this->comment('Opération annulée : rien n’a été modifié.');
            return self::SUCCESS;
        }

        if (!$this->option('no-backup')) {
            try {
                $b = Backup::create();
                $this->info('Sauvegarde de sécurité créée : ' . Backup::dir() . DIRECTORY_SEPARATOR . $b['name']);
            } catch (\Throwable $e) {
                $this->error('Sauvegarde impossible : ' . $e->getMessage() . ' — opération annulée (utilisez --no-backup pour passer outre).');
                return self::FAILURE;
            }
        }

        Structure::dropBusinessTables();
        Structure::createBusinessTables();
        Structure::seedReference();
        Storage::disk('local')->deleteDirectory('contract_documents');

        $this->info('Structure propre créée. Tables métier vides, utilisateurs conservés (' . DB::table('users')->count() . ' compte(s)).');
        $this->line('Étape suivante : connectez-vous, créez vos entreprises puis vos contrats. Pour (ré)initialiser le super-administrateur : php artisan prise:create-super-admin');
        return self::SUCCESS;
    }
}
