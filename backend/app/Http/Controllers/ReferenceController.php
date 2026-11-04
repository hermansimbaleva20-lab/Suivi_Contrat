<?php
namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\GoodsProcedure;
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
            'companies' => DB::table('companies')->where('project_id', $pid)->orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name,
            ])->values(),
        ];
    }
}
