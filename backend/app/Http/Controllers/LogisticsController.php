<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\UnitScope;
use App\Support\Ctx;
use App\Support\Dates;
use App\Support\FleetMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Logistique > Tableau de bord : synthèse du stock, du charroi et des groupes électrogènes dans le périmètre de
 * l'utilisateur (son unité, ou l'ensemble pour la vue nationale), pour le mois en cours. Chaque bloc n'apparaît qu'avec
 * l'habilitation du module ; les calculs sont ceux des modules eux-mêmes.
 */
class LogisticsController extends Controller
{
    use UnitScope;

    public function dashboard(Request $r)
    {
        $units = $this->units();
        $unit = $this->requestedUnit($r, $units, false);
        $params = $unit ? ['unit_id' => $unit->id] : [];
        $today = Dates::today();
        $from = substr($today, 0, 8) . '01';
        $unitIds = $unit ? [$unit->id] : $units->pluck('id')->all();

        $out = [
            'units' => $units->map(fn ($u) => self::unitView($u))->values(),
            'unitId' => $unit ? $unit->id : null,
            'canChooseUnit' => $this->hasNationalView(),
            'period' => ['from' => $from, 'to' => $today],
            'stock' => null, 'vehicles' => null, 'generators' => null,
        ];

        if (Ctx::can('can_view_stock') || Ctx::can('can_manage_stock')) {
            $s = app(StockController::class)->overview(Request::create('/', 'GET', $params));
            $active = collect($s['articles'])->where('isActive', true);
            $flows = DB::table('stock_movements as m')->join('stock_articles as a', 'a.id', '=', 'm.stock_article_id')
                ->where('m.project_id', Ctx::projectId())->whereIn('m.organizational_unit_id', $unitIds ?: [0])->whereNull('m.cancelled_at')
                ->whereBetween('m.movement_date', [$from, $today])->groupBy('m.type')
                ->selectRaw('m.type, COUNT(*) AS n, SUM(m.quantity * a.unit_price) AS amount')->get()->keyBy('type');
            $out['stock'] = [
                'value' => $s['totals']['value'],
                'articles' => $active->count(),
                'low' => $active->where('level', 'LOW')->count(),
                'out' => $active->where('level', 'OUT')->count(),
                'monthIn' => ['count' => (int) ($flows['ENTREE']->n ?? 0), 'amount' => round((float) ($flows['ENTREE']->amount ?? 0), 2)],
                'monthOut' => ['count' => (int) ($flows['SORTIE']->n ?? 0), 'amount' => round((float) ($flows['SORTIE']->amount ?? 0), 2)],
                'alerts' => $active->whereIn('level', ['OUT', 'LOW'])->sortBy(fn ($a) => [$a['level'] === 'OUT' ? 0 : 1, $a['stock']])->take(8)
                    ->map(fn ($a) => ['id' => $a['id'], 'number' => $a['number'], 'designation' => $a['designation'], 'unit' => $a['unit'], 'stock' => $a['stock'], 'alertThreshold' => $a['alertThreshold'], 'level' => $a['level']])->values(),
                'recent' => DB::table('stock_movements as m')->join('stock_articles as a', 'a.id', '=', 'm.stock_article_id')
                    ->join('organizational_units as u', 'u.id', '=', 'm.organizational_unit_id')
                    ->where('m.project_id', Ctx::projectId())->whereIn('m.organizational_unit_id', $unitIds ?: [0])->whereNull('m.cancelled_at')
                    ->orderByDesc('m.movement_date')->orderByDesc('m.id')->limit(6)
                    ->get(['m.id', 'm.movement_date', 'm.type', 'm.quantity', 'm.origin_destination', 'a.id as article_id', 'a.designation', 'a.unit', 'u.id as unit_id', 'u.name as unit_name'])
                    ->map(fn ($m) => ['id' => $m->id, 'date' => Dates::part($m->movement_date), 'type' => $m->type, 'quantity' => (float) $m->quantity, 'originDestination' => $m->origin_destination,
                        'articleId' => $m->article_id, 'designation' => $m->designation, 'unit' => $m->unit, 'unitId' => $m->unit_id, 'unitName' => $m->unit_name])->values(),
            ];
        }

        if (Ctx::can('can_view_fleet') || Ctx::can('can_manage_fleet') || Ctx::can('can_authorize_fleet')) {
            $fleet = app(FleetController::class);
            $o = $fleet->overview(Request::create('/', 'GET', $params));
            foreach ([FleetMetrics::VEHICULE => 'vehicles', FleetMetrics::GROUPE => 'generators'] as $kind => $key) {
                $assets = collect($o['assets'])->where('kind', $kind)->where('isActive', true);
                $alerts = collect($o['alerts'])->where('kind', $kind)->values();
                $report = $fleet->report(Request::create('/', 'GET', $params + ['from' => $from, 'to' => $today, 'kind' => $kind]));
                $out[$key] = [
                    'count' => $assets->count(),
                    'danger' => $alerts->where('level', 'danger')->count(),
                    'warning' => $alerts->where('level', 'warning')->count(),
                    'alerts' => $alerts->take(6)->values(),
                    'month' => $report['grand'],
                    'maintenanceDue' => $assets->filter(fn ($a) => $a['maintenance']['level'] !== 'OK')->count(),
                    'lowFuel' => $alerts->where('code', 'FUEL')->count(),
                    'insurance' => $kind === FleetMetrics::VEHICULE ? $assets->filter(fn ($a) => in_array($a['insurance']['level'] ?? 'OK', ['EXPIRED', 'SOON', 'NONE'], true))->count() : null,
                ];
            }
        }

        return $out;
    }
}
