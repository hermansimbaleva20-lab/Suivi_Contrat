<?php
namespace App\Http\Controllers;

use App\Support\Ctx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entreprises du projet. Chacune a une catégorie (décision du 18/11/2026) : TRAVAUX, BIENS ou SERVICE, la même que celle des
 * contrats ; le formulaire du contrat ne propose que les entreprises de la catégorie choisie. Une entreprise antérieure
 * peut rester sans catégorie (« non renseignée ») jusqu'à sa prochaine modification, qui la rend obligatoire.
 */
class CompanyController extends Controller
{
    public const CATEGORIES = ['TRAVAUX' => 'Travaux', 'BIENS' => 'Biens', 'SERVICE' => 'Services'];

    private static ?bool $hasCategory = null;

    /** Colonne companies.category présente (mise à jour du 18/11/2026). */
    public static function hasCategory(): bool
    {
        return self::$hasCategory ??= Schema::hasColumn('companies', 'category');
    }

    private function view($c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'category' => (string) ($c->category ?? ''), 'address' => (string) $c->address, 'phone' => (string) $c->phone, 'email' => (string) $c->email,
            'observation' => (string) ($c->observation ?? ''),
            'contracts' => (int) DB::table('contracts')->where('company_id', $c->id)->count(),
        ];
    }

    public function index()
    {
        return DB::table('companies')->where('project_id', Ctx::projectId())->orderBy('name')->get()->map(fn ($c) => $this->view($c))->values();
    }

    private function data(Request $r): array
    {
        $name = trim((string) $r->input('name', ''));
        abort_if($name === '', 422, 'Le nom de l’entreprise est obligatoire.');
        $d = [
            'name' => $name, 'address' => trim((string) $r->input('address', '')), 'phone' => trim((string) $r->input('phone', '')), 'email' => trim((string) $r->input('email', '')),
            'observation' => trim((string) $r->input('observation', '')),
        ];
        if (self::hasCategory()) {
            $category = strtoupper(trim((string) $r->input('category', '')));
            abort_unless(isset(self::CATEGORIES[$category]), 422, 'Choisissez la catégorie de l’entreprise : Travaux, Biens ou Services.');
            $d['category'] = $category;
        }
        return $d;
    }

    public function store(Request $r)
    {
        $d = $this->data($r);
        abort_if(DB::table('companies')->where('project_id', Ctx::projectId())->where('name', $d['name'])->exists(), 422, 'Cette entreprise existe déjà.');
        $id = DB::table('companies')->insertGetId($d + ['project_id' => Ctx::projectId(), 'created_at' => now(), 'updated_at' => now()]);
        $cat = isset($d['category']) ? ' (' . self::CATEGORIES[$d['category']] . ')' : '';
        Ctx::audit('CREATION', 'Company', $id, "Entreprise {$d['name']}{$cat} créée.");
        return response()->json(['id' => $id, 'message' => 'Entreprise enregistrée.'], 201);
    }

    public function update(Request $r, int $id)
    {
        $c = DB::table('companies')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($c, 404, 'Entreprise introuvable.');
        $d = $this->data($r);
        abort_if(DB::table('companies')->where('project_id', Ctx::projectId())->where('name', $d['name'])->where('id', '!=', $id)->exists(), 422, 'Une autre entreprise porte déjà ce nom.');
        DB::table('companies')->where('id', $id)->update($d + ['updated_at' => now()]);
        Ctx::audit('COORDONNEES', 'Company', $id, "Coordonnées de l’entreprise {$d['name']} mises à jour.");
        return ['message' => 'Entreprise mise à jour.'];
    }

    public function destroy(int $id)
    {
        $c = DB::table('companies')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($c, 404, 'Entreprise introuvable.');
        abort_if(DB::table('contracts')->where('company_id', $id)->exists(), 422, 'Cette entreprise est liée à des contrats : suppression impossible.');
        DB::table('companies')->where('id', $id)->delete();
        Ctx::audit('SUPPRESSION', 'Company', $id, "Entreprise {$c->name} supprimée.");
        return ['message' => 'Entreprise supprimée.'];
    }
}
