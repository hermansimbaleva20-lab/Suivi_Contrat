<?php
namespace App\Http\Controllers;

use App\Support\Cautions;
use App\Support\ComponentTree;
use App\Support\Ctx;
use App\Support\GoodsProcedure;
use App\Support\Loader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Référentiels du formulaire contrat : catégories, provinces, types d'ouvrages, entreprises. */
class ReferenceController extends Controller
{
    public function index()
    {
        $pid = Ctx::projectId();
        $provinces = [];
        if (Schema::hasTable('provinces')) {
            $col = Schema::hasColumn('provinces', 'name') ? 'name' : (Schema::hasColumn('provinces', 'Name') ? 'Name' : null);
            if ($col) $provinces = DB::table('provinces')->orderBy($col)->pluck($col)->filter()->values()->all();
        }
        return [
            'categories' => ['TRAVAUX', 'BIENS', 'SERVICE'],
            'goodsProcedures' => GoodsProcedure::catalog(),
            'provinces' => $provinces,
            'workTypes' => DB::table('work_types')->where('project_id', $pid)->orderBy('name')->get(['id', 'category', 'name'])->map(fn ($w) => ['id' => $w->id, 'category' => $w->category, 'name' => $w->name])->values(),
            // Catégorie de l'entreprise (18/11/2026) : le formulaire ne propose que celles de la catégorie du contrat.
            'companies' => DB::table('companies')->where('project_id', $pid)->orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'category' => (string) ($c->category ?? ''),
            ])->values(),
            'companyCategoriesReady' => CompanyController::hasCategory(),
            // Composantes du projet (Administration › Composantes), avec catégories et activités : le contrat choisit une activité
            // (décision du 16/11/2026) ; sans la mise à jour de la base, il choisit encore une composante.
            'components' => Schema::hasTable('project_components') ? ComponentTree::load($pid) : [],
            'activitiesReady' => ComponentTree::ready(),
            // Mode d'acquisition et caution d'avance (22/11/2026), une fois la base mise à jour (V40).
            'acquisitionModes' => array_map(fn ($code) => ['code' => $code, 'label' => Cautions::MODES[$code]], array_keys(Cautions::MODES)),
            'cautionsReady' => Loader::hasAdvanceGuarantees(),
        ];
    }
}
