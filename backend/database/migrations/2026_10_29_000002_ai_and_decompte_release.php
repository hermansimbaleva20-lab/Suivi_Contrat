<?php

use App\Support\DecompteLedger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Décomptes : ligne « RETENUE » (kind = LIBERATION) qui reverse en fin de contrat la retenue de garantie ; chaque ligne
 *   validée par le comptable crée le paiement correspondant (contract_payments.decompte_id). Les décomptes déjà validés
 *   reçoivent leur paiement ici, une seule fois.
 * - IA (TDR §10.3) : paramètres par projet, désactivés par défaut, réglés par le super-admin (clé d'API chiffrée).
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contract_decomptes', 'kind')) {
            Schema::table('contract_decomptes', fn (Blueprint $t) => $t->string('kind', 12)->default('DECOMPTE')->after('number')); // DECOMPTE | LIBERATION
        }
        if (!Schema::hasColumn('contract_payments', 'decompte_id')) {
            Schema::table('contract_payments', fn (Blueprint $t) => $t->foreignId('decompte_id')->nullable()->unique()->constrained('contract_decomptes')->nullOnDelete());
        }
        if (!Schema::hasTable('ai_settings')) {
            Schema::create('ai_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
                $t->boolean('is_enabled')->default(false);
                $t->string('provider', 20)->default('ANTHROPIC'); // ANTHROPIC | OPENAI_COMPATIBLE
                $t->string('model', 100)->default('claude-sonnet-5-5');
                $t->string('endpoint', 255)->nullable();
                $t->text('api_key')->nullable(); // chiffrée
                $t->boolean('feature_comments')->default(false);
                $t->boolean('feature_alerts')->default(false);
                $t->boolean('feature_extraction')->default(false);
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }

        // Paiements des décomptes déjà validés, montant payé calculé comme sur la situation du contrat.
        $contracts = DB::table('contract_decomptes')->where('status', DecompteLedger::VALID)->distinct()->pluck('contract_id');
        foreach ($contracts as $cid) {
            $c = DB::table('contracts')->where('id', $cid)->first();
            if (!$c) continue;
            $advanceRate = strtoupper((string) $c->category) === 'SERVICE' ? 0 : max(0, min(100, (float) $c->initial_financing_rate)) / 100;
            $lines = DB::table('contract_decomptes')->where('contract_id', $cid)->get()->map(fn ($d) => [
                'id' => (int) $d->id, 'line_no' => (int) $d->line_no, 'kind' => $d->kind ?? 'DECOMPTE', 'gross_amount' => (float) $d->gross_amount,
                'advance_rate' => (float) $d->advance_rate, 'guarantee_rate' => (float) $d->guarantee_rate, 'status' => $d->status,
                'number' => $d->number, 'date' => substr((string) $d->operation_date, 0, 10),
            ])->all();
            foreach (DecompteLedger::rows((float) $c->amount, DecompteLedger::advanceReceived((float) $c->amount, $advanceRate), $lines) as $row) {
                if ($row['status'] !== DecompteLedger::VALID || DB::table('contract_payments')->where('decompte_id', $row['id'])->exists()) continue;
                DB::table('contract_payments')->insert([
                    'contract_id' => $cid, 'date' => $row['date'], 'amount' => $row['net'], 'reference' => $row['number'],
                    'observation' => 'Montant payé du décompte validé par le comptable.', 'decompte_id' => $row['id'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Volontairement non destructif (TDR §3).
    }
};
