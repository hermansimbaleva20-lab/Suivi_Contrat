<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\UnitScope;
use App\Support\Ctx;
use App\Support\Dates;
use App\Support\FleetMetrics;
use App\Support\Notifier;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Charroi automobile et groupes électrogènes (TDR §7, §8), sur le modèle du carnet de bord papier du projet.
 * Rôles :
 *  - can_view_fleet : consultation (parc, alertes, carnets, entretiens, assurances, rapports) dans son périmètre ;
 *  - can_manage_fleet : logistique — parc, saisie au carnet, entretiens et assurances de son unité (chaque unité gère
 *    son espace de manière autonome) ;
 *  - can_authorize_fleet : autorise la demande logistique qui lui est adressée dans son unité (C.A dans une antenne, HRAF ou
 *    comptable à la coordination nationale), ou y fait objection.

 * Les données ne sont pas filtrées par phase : le parc se poursuit d'une phase à l'autre.
 */
class FleetController extends Controller
{
    use UnitScope;

    private const KINDS = [FleetMetrics::VEHICULE, FleetMetrics::GROUPE];
    private const AUTHORITIES = ['CA' => 'C.A', 'HRAF' => 'HRAF', 'COMPTABLE' => 'Comptable'];


    // ------------------------------------------------------------------ Chargement (tableaux normalisés, comme Loader)

    public static function assetRow(object $a): array
    {
        return [
            'id' => (int) $a->id, 'unit_id' => (int) $a->organizational_unit_id, 'unit_name' => $a->unit_name ?? null,
            'kind' => $a->kind, 'name' => $a->name, 'registration' => $a->registration,
            'tank_capacity' => (float) $a->tank_capacity, 'consumption_rate' => $a->consumption_rate === null ? null : (float) $a->consumption_rate,
            'fuel_price' => (float) $a->fuel_price, 'fuel_alert_level' => $a->fuel_alert_level === null ? null : (float) $a->fuel_alert_level,
            'maintenance_interval' => (int) $a->maintenance_interval, 'maintenance_warning' => (int) $a->maintenance_warning,
            'initial_meter' => (float) $a->initial_meter, 'is_active' => (bool) $a->is_active,
            'maintenance_last_meter' => isset($a->maintenance_last_meter) ? (float) $a->maintenance_last_meter : null,
            'maintenance_next_meter' => isset($a->maintenance_next_meter) ? (float) $a->maintenance_next_meter : null,
        ];
    }

    public static function logRow(object $l): array
    {
        $f = fn ($v) => $v === null ? null : (float) $v;
        return [
            'id' => (int) $l->id, 'asset_id' => (int) $l->fleet_asset_id, 'unit_id' => (int) $l->organizational_unit_id, 'date' => Dates::part($l->log_date),
            'meter_start' => (float) $l->meter_start, 'meter_end' => (float) $l->meter_end, 'fuel_level_before' => $f($l->fuel_level_before),
            'fuel_purchased' => $f($l->fuel_purchased), 'fuel_price' => $f($l->fuel_price), 'lubricant' => $l->lubricant === null ? null : (string) $l->lubricant,
            'cancelled' => $l->cancelled_at !== null, 'authorized' => $l->authorized_at !== null, 'objected' => ($l->objected_at ?? null) !== null,
            'requester' => $l->requester, 'destination' => $l->destination, 'purpose' => $l->purpose,
            'departure_time' => $l->departure_time, 'arrival_time' => $l->arrival_time, 'observation' => (string) $l->observation,
            'created_by' => $l->created_by ? (int) $l->created_by : null, 'created_by_name' => $l->created_by_name ?? null,
            'authorized_by_name' => $l->authorized_by_name ?? null, 'authorized_at' => $l->authorized_at ? substr((string) $l->authorized_at, 0, 16) : null,
            'cancel_reason' => $l->cancel_reason, 'cancelled_by_name' => $l->cancelled_by_name ?? null,
            'assigned_to' => ($l->assigned_to ?? null) ? (int) $l->assigned_to : null, 'assigned_to_name' => $l->assigned_to_name ?? null,
            'objected_by_name' => $l->objected_by_name ?? null, 'objected_at' => ($l->objected_at ?? null) ? substr((string) $l->objected_at, 0, 16) : null,
            'objection_reason' => $l->objection_reason ?? null,
        ];
    }

    /** Engins de l'unité (ou de toutes les unités lisibles), avec le nom de l'unité. */
    private function assets(array $unitIds, ?string $kind = null): Collection
    {
        $q = DB::table('fleet_assets as a')->join('organizational_units as u', 'u.id', '=', 'a.organizational_unit_id')
            ->where('a.project_id', Ctx::projectId())->whereIn('a.organizational_unit_id', $unitIds ?: [0])
            ->select('a.*', 'u.name as unit_name')->orderByDesc('a.is_active')->orderBy('a.kind', 'desc')->orderBy('a.name');
        if ($kind) $q->where('a.kind', $kind);
        return $q->get()->map(fn ($a) => self::assetRow($a));
    }

    /** Lignes du carnet groupées par engin (annulées comprises, pour l'affichage). */
    private function logsByAsset(array $assetIds, ?string $from = null, ?string $to = null): array
    {
        if (!$assetIds) return [];
        $q = DB::table('fleet_logs as l')
            ->leftJoin('users as c', 'c.id', '=', 'l.created_by')
            ->leftJoin('users as v', 'v.id', '=', 'l.authorized_by')
            ->leftJoin('users as x', 'x.id', '=', 'l.cancelled_by')
            ->leftJoin('users as d', 'd.id', '=', 'l.assigned_to')
            ->leftJoin('users as o', 'o.id', '=', 'l.objected_by')
            ->where('l.project_id', Ctx::projectId())->whereIn('l.fleet_asset_id', $assetIds)
            ->select('l.*', 'c.full_name as created_by_name', 'v.full_name as authorized_by_name', 'x.full_name as cancelled_by_name',
                'd.full_name as assigned_to_name', 'o.full_name as objected_by_name');
        if ($from) $q->where('l.log_date', '>=', $from);
        if ($to) $q->where('l.log_date', '<=', $to);
        $out = array_fill_keys($assetIds, []);
        foreach ($q->get() as $l) $out[(int) $l->fleet_asset_id][] = self::logRow($l);
        return $out;
    }

    private function maintenancesByAsset(array $assetIds): array
    {
        $out = array_fill_keys($assetIds, []);
        if (!$assetIds) return $out;
        foreach (DB::table('fleet_maintenances')->where('project_id', Ctx::projectId())->whereIn('fleet_asset_id', $assetIds)->orderBy('maintenance_date')->get() as $m) {
            $out[(int) $m->fleet_asset_id][] = $m;
        }
        return $out;
    }

    private function insurancesByAsset(array $assetIds): array
    {
        $out = array_fill_keys($assetIds, []);
        if (!$assetIds) return $out;
        foreach (DB::table('fleet_insurances')->where('project_id', Ctx::projectId())->whereIn('fleet_asset_id', $assetIds)->orderBy('end_date')->get() as $i) {
            $out[(int) $i->fleet_asset_id][] = ['start_date' => Dates::part($i->start_date), 'end_date' => Dates::part($i->end_date), 'designation' => $i->designation];
        }
        return $out;
    }

    /** Situation d'un engin : compteur, réservoir, autonomie, entretien, assurance, alertes. */
    public static function status(array $asset, array $logs, array $maintenances, array $insurances, string $today): array
    {
        $records = array_map(fn ($m) => ['reading' => (float) $m->meter_reading, 'next' => $m->next_meter === null ? null : (float) $m->next_meter], $maintenances);
        $readings = array_column($records, 'reading');
        if ($asset['maintenance_last_meter'] !== null) $readings[] = $asset['maintenance_last_meter'];
        $meter = FleetMetrics::currentMeter($asset, $logs, $readings);
        $level = FleetMetrics::fuelLevel($asset, $logs);
        $maintenance = FleetMetrics::maintenance($asset, $meter, $records);
        $last = null;
        foreach ($maintenances as $m) if ($last === null || (float) $m->meter_reading >= (float) $last->meter_reading) $last = $m;
        $maintenance['lastDate'] = $last && $maintenance['source'] === 'RECORD' ? Dates::part($last->maintenance_date) : null;
        // L'assurance n'est exigée que pour les véhicules ; un groupe n'est suivi que s'il a une police enregistrée.
        $insurance = ($asset['kind'] === FleetMetrics::VEHICULE || $insurances) ? FleetMetrics::insurance($insurances, $today) : null;
        $active = array_values(array_filter($logs, fn ($l) => !$l['cancelled']));
        $lastLog = $active ? max(array_column($active, 'date')) : null;
        $rate = $asset['consumption_rate'];
        return [
            'currentMeter' => $meter,
            'fuelLevel' => $level,
            'autonomy' => ($level !== null && $rate) ? round($level / $rate, 0) : null,
            'maintenance' => $maintenance,
            'insurance' => $insurance,
            'lastLogDate' => $lastLog,
            'pending' => count(array_filter($active, fn ($l) => !$l['authorized'] && empty($l['objected']))),
            'alerts' => $asset['is_active'] ? FleetMetrics::alerts($asset, $level, $maintenance, $insurance, $lastLog, $today) : [],
        ];
    }

    private static function assetView(array $a): array
    {
        return [
            'id' => $a['id'], 'unitId' => $a['unit_id'], 'unitName' => $a['unit_name'], 'kind' => $a['kind'], 'name' => $a['name'], 'registration' => $a['registration'],
            'tankCapacity' => $a['tank_capacity'], 'consumptionRate' => $a['consumption_rate'], 'fuelPrice' => $a['fuel_price'], 'fuelAlertLevel' => $a['fuel_alert_level'],
            'maintenanceInterval' => $a['maintenance_interval'], 'maintenanceWarning' => $a['maintenance_warning'], 'initialMeter' => $a['initial_meter'], 'isActive' => $a['is_active'],
        ];
    }

    /** Engin du projet, lisible par l'utilisateur ; 404 hors projet, 403 hors périmètre. */
    private function assetOr404(int $id): array
    {
        $a = DB::table('fleet_assets as a')->join('organizational_units as u', 'u.id', '=', 'a.organizational_unit_id')
            ->where('a.id', $id)->where('a.project_id', Ctx::projectId())->select('a.*', 'u.name as unit_name')->first();
        abort_unless($a, 404, 'Engin introuvable.');
        $ids = Ctx::readableUnitIds();
        abort_if($ids !== null && !in_array((int) $a->organizational_unit_id, $ids, true), 403, 'Cet engin appartient à une autre unité que la vôtre.');
        return self::assetRow($a);
    }

    private function canManageAssets(): bool
    {
        return Ctx::can('can_manage_fleet') && $this->writableUnitIds($this->units(), 'can_manage_fleet') !== [];
    }

    private function needWrite(array $asset): void
    {
        Ctx::need('can_manage_fleet');
        Ctx::needUnit($asset['unit_id']);
    }

    private function permissions(Collection $units): array
    {
        return [
            'canChooseUnit' => $this->hasNationalView(),
            'canManageAssets' => $this->canManageAssets(),
            'canAuthorize' => Ctx::can('can_authorize_fleet'),
            'writableUnitIds' => $this->writableUnitIds($units, 'can_manage_fleet'),
        ];
    }

    private static function qty($n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, ',', ' '), '0'), ',');
    }

    private static function decimalField(Request $r, string $field, string $label, bool $required, ?float $max = null): ?float
    {
        $raw = trim((string) $r->input($field, ''));
        if ($raw === '') {
            if ($required) throw ValidationException::withMessages([$field => "Saisissez $label."]);
            return null;
        }
        $v = Dates::decimal($raw);
        if ($v === null || $v < 0) throw ValidationException::withMessages([$field => ucfirst($label) . ' doit être un nombre positif.']);
        if ($max !== null && $v > $max) throw ValidationException::withMessages([$field => ucfirst($label) . ' ne peut pas dépasser ' . self::qty($max) . '.']);
        return $v;
    }

    // ------------------------------------------------------------------ Parc et alertes

    public function overview(Request $r)
    {
        $units = $this->units();
        $unit = $this->requestedUnit($r, $units, false);
        $assets = $this->assets($unit ? [$unit->id] : $units->pluck('id')->all());
        $ids = $assets->pluck('id')->all();
        $logs = $this->logsByAsset($ids);
        $maint = $this->maintenancesByAsset($ids);
        $ins = $this->insurancesByAsset($ids);
        $today = Dates::today();

        $rows = [];
        $alerts = [];
        foreach ($assets as $a) {
            $s = self::status($a, $logs[$a['id']], $maint[$a['id']], $ins[$a['id']], $today);
            $rows[] = self::assetView($a) + $s;
            foreach ($s['alerts'] as $al) $alerts[] = $al + ['assetId' => $a['id'], 'assetName' => $a['name'], 'registration' => $a['registration'], 'kind' => $a['kind'], 'unitName' => $a['unit_name']];
        }
        usort($alerts, fn ($x, $y) => ($x['level'] === 'danger' ? 0 : 1) <=> ($y['level'] === 'danger' ? 0 : 1));

        return [
            'units' => $units->map(fn ($u) => self::unitView($u))->values(),
            'unitId' => $unit ? $unit->id : null,
            'assets' => $rows,
            'alerts' => $alerts,
            'pending' => array_sum(array_column($rows, 'pending')),
        ] + $this->permissions($units);
    }

    private function assetData(Request $r, ?int $ignoreId = null): array
    {
        $pid = Ctx::projectId();
        $d = $r->validate([
            'kind' => ['required', Rule::in(self::KINDS)],
            'name' => ['required', 'string', 'max:150', Rule::unique('fleet_assets', 'name')->where('project_id', $pid)->ignore($ignoreId)],
            'registration' => 'nullable|string|max:40',
            'organizational_unit_id' => ['required', 'integer', Rule::exists('organizational_units', 'id')->where('project_id', $pid)],
            'maintenance_interval' => 'required|integer|min:1|max:1000000',
            'maintenance_warning' => 'nullable|integer|min:0|lte:maintenance_interval',
        ], [
            'name.unique' => 'Un engin porte déjà ce nom dans le projet : ajoutez par exemple l’immatriculation.',
            'maintenance_warning.lte' => 'L’avertissement doit arriver avant l’échéance de l’entretien.',
        ], ['name' => 'désignation', 'organizational_unit_id' => 'unité', 'maintenance_interval' => 'intervalle d’entretien', 'maintenance_warning' => 'seuil d’avertissement']);

        $capacity = self::decimalField($r, 'tank_capacity', 'la capacité du réservoir', true);
        if ($capacity <= 0) throw ValidationException::withMessages(['tank_capacity' => 'La capacité du réservoir doit être supérieure à zéro.']);
        return [
            'kind' => $d['kind'], 'name' => trim($d['name']), 'registration' => isset($d['registration']) ? trim($d['registration']) : null,
            'organizational_unit_id' => (int) $d['organizational_unit_id'],
            'tank_capacity' => $capacity,
            'consumption_rate' => self::decimalField($r, 'consumption_rate', 'le taux de consommation', false),
            'fuel_price' => self::decimalField($r, 'fuel_price', 'le prix du litre', true),
            'fuel_alert_level' => self::decimalField($r, 'fuel_alert_level', 'le seuil d’alerte du réservoir', false, $capacity),
            'maintenance_interval' => (int) $d['maintenance_interval'], 'maintenance_warning' => (int) ($d['maintenance_warning'] ?? ceil((int) $d['maintenance_interval'] * FleetMetrics::MAINTENANCE_WARNING_SHARE)),
            'initial_meter' => self::decimalField($r, 'initial_meter', 'le compteur initial', false) ?? 0,
        ];
    }

    /** Chaque unité tient son propre parc ; la vue nationale ne donne pas le droit de modifier celui des pools. */
    private function needAssets(int $unitId): void
    {
        Ctx::need('can_manage_fleet');
        Ctx::needUnit($unitId);
    }

    public function storeAsset(Request $r)
    {
        $data = $this->assetData($r);
        $this->needAssets($data['organizational_unit_id']);
        $id = DB::table('fleet_assets')->insertGetId($data + ['project_id' => Ctx::projectId(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Ctx::auditChange('CREATION', 'FleetAsset', $id, ($data['kind'] === FleetMetrics::VEHICULE ? 'Véhicule' : 'Groupe électrogène') . " « {$data['name']} » ajouté au parc.", null, $data);
        return ['message' => 'Engin ajouté au parc.', 'id' => $id];
    }

    public function updateAsset(Request $r, int $id)
    {
        $this->needAssets($this->assetOr404($id)['unit_id']);
        $before = (array) DB::table('fleet_assets')->where('id', $id)->first();
        $data = $this->assetData($r, $id);
        Ctx::needUnit($data['organizational_unit_id']);
        DB::table('fleet_assets')->where('id', $id)->update($data + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'FleetAsset', $id, "Engin « {$data['name']} » modifié.", $before, (array) DB::table('fleet_assets')->where('id', $id)->first());
        return ['message' => 'Engin enregistré.'];
    }

    public function toggleAsset(int $id)
    {
        $a = $this->assetOr404($id);
        $this->needAssets($a['unit_id']);
        DB::table('fleet_assets')->where('id', $id)->update(['is_active' => !$a['is_active'], 'updated_at' => now()]);
        Ctx::auditChange($a['is_active'] ? 'DESACTIVATION' : 'ACTIVATION', 'FleetAsset', $id, "Engin « {$a['name']} » " . ($a['is_active'] ? 'retiré du parc actif.' : 'remis en service.'), ['is_active' => $a['is_active']], ['is_active' => !$a['is_active']]);
        return ['message' => $a['is_active'] ? 'Engin désactivé : son historique reste consultable.' : 'Engin remis en service.'];
    }

    public function destroyAsset(int $id)
    {
        $a = $this->assetOr404($id);
        $this->needAssets($a['unit_id']);
        foreach (['fleet_logs', 'fleet_maintenances', 'fleet_insurances'] as $t) {
            abort_if(DB::table($t)->where('fleet_asset_id', $id)->exists(), 422, 'Cet engin a déjà un historique : désactivez-le au lieu de le supprimer.');
        }
        DB::table('fleet_assets')->where('id', $id)->delete();
        Ctx::auditChange('SUPPRESSION', 'FleetAsset', $id, "Engin « {$a['name']} » supprimé du parc.", $a, null);
        return ['message' => 'Engin supprimé.'];
    }

    // ------------------------------------------------------------------ Carnet de bord

    /** Carnet du mois (?month=AAAA-MM), au format du carnet papier, avec totaux mensuels et repères d'entretien et d'assurance. */
    public function logbook(Request $r, int $id)
    {
        $asset = $this->assetOr404($id);
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $r->query('month')) ? $r->query('month') : substr(Dates::today(), 0, 7);
        $from = "$month-01";
        $to = Dates::addDays(Dates::addMonths($from, 1), -1);

        $all = $this->logsByAsset([$id])[$id];
        $levels = FleetMetrics::levels($asset, $all);
        $monthLogs = array_values(array_filter(FleetMetrics::sort($all), fn ($l) => $l['date'] >= $from && $l['date'] <= $to));
        $maint = $this->maintenancesByAsset([$id])[$id];
        $status = self::status($asset, $all, $maint, $this->insurancesByAsset([$id])[$id], Dates::today());
        $units = $this->units();
        $canWrite = $asset['is_active'] && in_array($asset['unit_id'], $this->writableUnitIds($units, 'can_manage_fleet'), true);
        $user = Ctx::user();

        $lines = array_map(function ($l) use ($asset, $levels, $user, $canWrite) {
            return [
                'id' => $l['id'], 'date' => $l['date'], 'requester' => $l['requester'], 'destination' => $l['destination'], 'purpose' => $l['purpose'],
                'departureTime' => $l['departure_time'], 'arrivalTime' => $l['arrival_time'],
                'meterStart' => $l['meter_start'], 'meterEnd' => $l['meter_end'], 'fuelLevelBefore' => $l['fuel_level_before'],
                'fuelPurchased' => $l['fuel_purchased'], 'lubricant' => $l['lubricant'], 'levelAfter' => $l['cancelled'] ? null : ($levels[$l['id']] ?? null),
                'observation' => $l['observation'], 'createdBy' => $l['created_by_name'], 'isMine' => $l['created_by'] === (int) $user->id,
                'isAuthorized' => $l['authorized'], 'authorizedBy' => $l['authorized_by_name'], 'authorizedAt' => $l['authorized_at'],
                'isCancelled' => $l['cancelled'], 'cancelReason' => $l['cancel_reason'], 'cancelledBy' => $l['cancelled_by_name'],
                'assignedTo' => $l['assigned_to'], 'assignedToName' => $l['assigned_to_name'],
                'isObjected' => $l['objected'], 'objectedBy' => $l['objected_by_name'], 'objectedAt' => $l['objected_at'], 'objectionReason' => $l['objection_reason'],
                'canDecide' => $this->canDecide($l), 'canReassign' => $canWrite && self::isOpen($l),
            ] + FleetMetrics::line($asset, $l);
        }, $monthLogs);

        $siblings = $this->assets($this->units()->pluck('id')->all(), $asset['kind']);
        $suggest = fn (string $col) => DB::table('fleet_logs')->where('project_id', Ctx::projectId())->where('organizational_unit_id', $asset['unit_id'])
            ->whereNotNull($col)->select($col)->groupBy($col)->orderByRaw('COUNT(*) DESC')->limit(40)->pluck($col);

        return [
            'asset' => self::assetView($asset),
            'assets' => $siblings->map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'registration' => $a['registration'], 'unitName' => $a['unit_name'], 'isActive' => $a['is_active']])->values(),
            'month' => $month, 'from' => $from, 'to' => $to,
            'status' => $status,
            'lines' => $lines,
            'totals' => FleetMetrics::totals($asset, $monthLogs),
            'canWrite' => $canWrite,
            // Carnet d'une autre unité, consulté par la logistique à vue nationale : lecture seule.
            'otherUnit' => Ctx::can('can_manage_fleet') && $asset['unit_id'] !== (int) Ctx::user()->organizational_unit_id && !Ctx::user()->is_super_admin,

            'canAuthorize' => Ctx::can('can_authorize_fleet'),
            'authorizers' => $canWrite ? $this->authorizers($asset['unit_id']) : [],
            'suggestions' => $asset['kind'] === FleetMetrics::VEHICULE
                ? ['requesters' => $suggest('requester'), 'destinations' => $suggest('destination'), 'purposes' => $suggest('purpose')]
                : ['requesters' => [], 'destinations' => [], 'purposes' => $suggest('purpose')],
        ];
    }

    public function storeLog(Request $r)
    {
        $d = $r->validate([
            'fleet_asset_id' => 'required|integer',
            'log_date' => 'required|date_format:Y-m-d',
            'requester' => 'nullable|string|max:150',
            'destination' => 'nullable|string|max:190',
            'purpose' => 'nullable|string|max:255',
            'departure_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'arrival_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'observation' => 'nullable|string|max:500',
            'lubricant' => 'nullable|string|max:50',
            'assigned_to' => 'required|integer',
        ], [
            'departure_time.regex' => 'Heure au format HH:MM.', 'arrival_time.regex' => 'Heure au format HH:MM.',
            'assigned_to.required' => 'Choisissez à qui adresser la saisie pour autorisation (le C.A dans une antenne, le HRAF ou le comptable à la coordination).',
        ], ['log_date' => 'date', 'lubricant' => 'lubrifiant']);
        $asset = $this->assetOr404((int) $d['fleet_asset_id']);
        $this->needWrite($asset);
        abort_unless($asset['is_active'], 422, 'Cet engin est désactivé : aucune saisie n’est possible.');
        if ($d['log_date'] > Dates::today()) throw ValidationException::withMessages(['log_date' => 'La date ne peut pas être postérieure à aujourd’hui.']);
        $isVehicle = $asset['kind'] === FleetMetrics::VEHICULE;
        if ($isVehicle) {
            $missing = [];
            if (trim((string) ($d['requester'] ?? '')) === '') $missing['requester'] = 'Indiquez le demandeur de la course.';
            if (trim((string) ($d['destination'] ?? '')) === '') $missing['destination'] = 'Indiquez la destination.';
            if ($missing) throw ValidationException::withMessages($missing);
        }

        $unitLabel = $isVehicle ? 'le kilométrage' : 'le compteur horaire';
        $start = self::decimalField($r, 'meter_start', "$unitLabel de départ", true);
        $end = self::decimalField($r, 'meter_end', "$unitLabel d’arrivée", true);
        if ($end < $start) throw ValidationException::withMessages(['meter_end' => ucfirst($unitLabel) . ' d’arrivée doit être supérieur ou égal à celui du départ.']);
        $row = [
            'log_date' => $d['log_date'], 'meter_start' => $start, 'meter_end' => $end,
            'fuel_level_before' => self::decimalField($r, 'fuel_level_before', 'la quantité avant départ', false, $asset['tank_capacity']),
            'fuel_purchased' => self::decimalField($r, 'fuel_purchased', 'le carburant acheté', false),
            // Quantité de lubrifiant en texte libre : « PLEIN » par défaut, ou une quantité (« 2 L »).
            'lubricant' => trim((string) ($d['lubricant'] ?? '')) === '' ? 'PLEIN' : mb_strtoupper(trim($d['lubricant'])),
            'assigned_to' => $this->needAuthorizer((int) $d['assigned_to'], $asset['unit_id']),
            // Le prix effectivement retenu est conservé : un changement ultérieur du prix par défaut ne réécrit pas l'historique.
            'fuel_price' => self::decimalField($r, 'fuel_price', 'le prix du litre', false) ?? $asset['fuel_price'],
        ];
        foreach (['requester', 'destination', 'purpose', 'departure_time', 'arrival_time', 'observation'] as $k) {
            $row[$k] = isset($d[$k]) && trim($d[$k]) !== '' ? trim($d[$k]) : null;
        }

        return DB::transaction(function () use ($asset, $row, $isVehicle) {
            DB::table('fleet_assets')->where('id', $asset['id'])->lockForUpdate()->first(); // sérialise les saisies d'un même engin
            $logs = $this->logsByAsset([$asset['id']])[$asset['id']];
            $logs[] = ['id' => PHP_INT_MAX, 'date' => $row['log_date'], 'meter_start' => $row['meter_start'], 'meter_end' => $row['meter_end'], 'cancelled' => false];
            if ($c = FleetMetrics::firstMeterConflict($logs)) {
                $u = $isVehicle ? 'km' : 'h';
                $new = $c['log']['id'] === PHP_INT_MAX;
                $text = $new
                    ? 'Le compteur de départ (' . self::qty($row['meter_start']) . " $u) est inférieur à l’arrivée de la saisie du " . Dates::fr($c['previous']['date']) . ' (' . self::qty($c['previous']['meter_end']) . " $u)."
                    : 'Cette saisie chevauche celle du ' . Dates::fr($c['log']['date']) . ' (départ à ' . self::qty($c['log']['meter_start']) . " $u). Vérifiez le compteur et la date.";
                throw ValidationException::withMessages(['meter_start' => $text]);
            }
            $id = DB::table('fleet_logs')->insertGetId($row + [
                'project_id' => Ctx::projectId(), 'organizational_unit_id' => $asset['unit_id'], 'fleet_asset_id' => $asset['id'],
                'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $what = $isVehicle ? 'Course ' . ($row['destination'] ? "vers {$row['destination']} " : '') : 'Relevé ';
            $summary = "{$what}du " . Dates::fr($row['log_date']) . " — {$asset['name']} : " . self::qty($row['meter_end'] - $row['meter_start']) . ($isVehicle ? ' km' : ' h')
                . ($row['fuel_purchased'] ? ', ' . self::qty($row['fuel_purchased']) . ' L achetés' : '') . '.';
            Ctx::auditChange('CREATION', 'FleetLog', $id, $summary, null, $row);
            $this->notifyAssignee($row['assigned_to'], $id, $summary);
            return ['message' => $isVehicle ? 'Course enregistrée au carnet de bord.' : 'Relevé enregistré.', 'id' => $id];
        });
    }

    private function logOr404(int $id): object
    {
        $l = DB::table('fleet_logs')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($l, 404, 'Saisie introuvable.');
        return $l;
    }

    public function cancelLog(Request $r, int $id)
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:500'], [], ['reason' => 'motif']);
        $l = $this->logOr404($id);
        Ctx::need('can_manage_fleet');
        Ctx::needUnit((int) $l->organizational_unit_id);
        abort_if($l->cancelled_at !== null, 422, 'Cette saisie est déjà annulée.');
        $after = ['cancelled_at' => now()->toDateTimeString(), 'cancelled_by' => Ctx::user()->id, 'cancel_reason' => trim($d['reason'])];
        DB::table('fleet_logs')->where('id', $id)->update($after + ['updated_at' => now()]);
        Ctx::auditChange('ANNULATION', 'FleetLog', $id, 'Saisie du carnet de bord du ' . Dates::fr(Dates::part($l->log_date)) . " annulée. Motif : {$after['cancel_reason']}", (array) $l, $after);
        return ['message' => 'Saisie annulée. Elle reste visible, barrée, sur le carnet.'];
    }

    // ------------------------------------------------------------------ Autorisation des saisies, dans chaque unité

    /** En attente : ni annulée, ni autorisée, ni objectée. */
    private static function isOpen(array $l): bool
    {
        return !$l['cancelled'] && !$l['authorized'] && empty($l['objected']);
    }

    /**
     * Valideurs proposés à la saisie : chaque unité gère sa logistique de manière autonome, la demande est donc autorisée
     * dans l'unité de l'engin — par le C.A dans une antenne, par le HRAF ou le comptable à la coordination nationale —,
     * c'est-à-dire par les comptes de cette unité à qui le super-admin a accordé l'autorisation du carnet. À défaut, le
     * super-admin. L'auteur de la saisie choisit selon leur disponibilité.
     */
    private function authorizers(int $unitId): array
    {
        $me = (int) Ctx::user()->id;
        $base = DB::table('users')->where('project_id', Ctx::projectId())->where('is_active', true)->where('is_pending', false)->where('id', '!=', $me);
        $ids = (clone $base)->where('can_authorize_fleet', true)->where('organizational_unit_id', $unitId)->pluck('id')->all()
            ?: (clone $base)->where('is_super_admin', true)->pluck('id')->all();
        if (!$ids) return [];
        return DB::table('users as u')->leftJoin('positions as p', 'p.id', '=', 'u.position_id')->leftJoin('organizational_units as o', 'o.id', '=', 'u.organizational_unit_id')
            ->whereIn('u.id', $ids)->orderBy('u.full_name')->select('u.id', 'u.full_name', 'u.project_position', 'u.logistics_authority', 'p.name as position_name', 'o.name as unit_name')->get()
            ->map(fn ($u) => [
                'id' => (int) $u->id, 'name' => $u->full_name, 'unit' => (string) $u->unit_name,
                // La qualité d'autorisation (C.A, HRAF, Comptable) prime sur l'intitulé du poste.
                'position' => self::AUTHORITIES[$u->logistics_authority ?? ''] ?? (string) ($u->position_name ?: $u->project_position),
            ])->all();
    }

    private function needAuthorizer(int $userId, int $unitId): int
    {
        if (!in_array($userId, array_column($this->authorizers($unitId), 'id'), true)) {
            throw ValidationException::withMessages(['assigned_to' => 'Cette personne n’est pas habilitée à autoriser le carnet de bord de cette unité.']);
        }
        return $userId;
    }

    private function notifyAssignee(int $userId, int $logId, string $summary): void
    {
        Notifier::send([$userId], 'CARNET_A_AUTORISER', 'Saisie du carnet de bord à autoriser', $summary . ' Saisie par ' . Ctx::user()->full_name . '.', '/logistique/autorisations', 'FleetLog', $logId);
    }

    /** Seule la personne désignée décide (le super-admin aussi) ; jamais sur sa propre saisie. */
    private function canDecide(array $l): bool
    {
        $me = Ctx::user();
        if (!self::isOpen($l) || !Ctx::can('can_authorize_fleet') || $l['created_by'] === (int) $me->id) return false;
        if ($me->is_super_admin) return true;
        if ($l['assigned_to']) return $l['assigned_to'] === (int) $me->id;
        $ids = Ctx::readableUnitIds();
        return $ids === null || in_array($l['unit_id'], $ids, true);
    }

    private function decidableLog(int $id): array
    {
        Ctx::need('can_authorize_fleet');
        $row = self::logRow($this->logOr404($id));
        abort_if($row['cancelled'], 422, 'Cette saisie est annulée.');
        abort_if($row['authorized'], 422, 'Cette saisie est déjà autorisée.');
        abort_if($row['objected'], 422, 'Une objection a déjà été faite sur cette saisie.');
        abort_if($row['created_by'] === (int) Ctx::user()->id, 422, 'Vous ne pouvez pas autoriser une saisie que vous avez faite vous-même.');
        abort_unless($this->canDecide($row), 403, 'Cette saisie a été adressée à une autre personne pour autorisation.');
        return $row;
    }

    private function closeAndTell(array $l, string $title, string $body): void
    {
        Notifier::resolve('FleetLog', $l['id'], 'CARNET_A_AUTORISER');
        $kind = DB::table('fleet_assets')->where('id', $l['asset_id'])->value('kind');
        $base = $kind === FleetMetrics::GROUPE ? '/generators' : '/fleet';
        if ($l['created_by']) Notifier::send([$l['created_by']], 'CARNET_DECISION', $title, $body, "$base/logbook?engin={$l['asset_id']}&mois=" . substr($l['date'], 0, 7), 'FleetLog', $l['id']);
    }

    public function authorizeLog(int $id)
    {
        $l = $this->decidableLog($id);
        $after = ['authorized_by' => Ctx::user()->id, 'authorized_at' => now()->toDateTimeString()];
        DB::table('fleet_logs')->where('id', $id)->update($after + ['updated_at' => now()]);
        Ctx::auditChange('AUTORISATION', 'FleetLog', $id, 'Saisie du carnet de bord du ' . Dates::fr($l['date']) . ' autorisée.', ['authorized_by' => null, 'authorized_at' => null], $after);
        $this->closeAndTell($l, 'Saisie autorisée', 'Votre saisie du ' . Dates::fr($l['date']) . ' a été autorisée par ' . Ctx::user()->full_name . '.');
        return ['message' => 'Saisie autorisée.'];
    }

    /** Objection motivée : la saisie n'entre pas dans les totaux ; la logistique l'annule et la ressaisit corrigée. */
    public function objectLog(Request $r, int $id)
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:500'], [], ['reason' => 'motif']);
        $l = $this->decidableLog($id);
        $after = ['objected_by' => Ctx::user()->id, 'objected_at' => now()->toDateTimeString(), 'objection_reason' => trim($d['reason'])];
        DB::table('fleet_logs')->where('id', $id)->update($after + ['updated_at' => now()]);
        Ctx::auditChange('OBJECTION', 'FleetLog', $id, 'Objection sur la saisie du carnet de bord du ' . Dates::fr($l['date']) . ". Motif : {$after['objection_reason']}", ['objected_by' => null, 'objected_at' => null], $after);
        $this->closeAndTell($l, 'Objection sur votre saisie', Ctx::user()->full_name . ' fait objection à votre saisie du ' . Dates::fr($l['date']) . " : {$after['objection_reason']}");
        return ['message' => 'Objection enregistrée : la saisie n’entre pas dans les totaux.'];
    }

    /** Si la personne désignée n'est pas disponible, la saisie est adressée à une autre. */
    public function reassignLog(Request $r, int $id)
    {
        $d = $r->validate(['assigned_to' => 'required|integer'], ['assigned_to.required' => 'Choisissez la personne à qui adresser la saisie.']);
        $l = self::logRow($this->logOr404($id));
        Ctx::need('can_manage_fleet');
        Ctx::needUnit($l['unit_id']);
        abort_unless(self::isOpen($l), 422, 'Cette saisie n’est plus en attente d’autorisation.');
        $to = $this->needAuthorizer((int) $d['assigned_to'], $l['unit_id']);
        abort_if($to === $l['assigned_to'], 422, 'La saisie est déjà adressée à cette personne.');
        DB::table('fleet_logs')->where('id', $id)->update(['assigned_to' => $to, 'updated_at' => now()]);
        Ctx::auditChange('REAFFECTATION', 'FleetLog', $id, 'Saisie du carnet de bord du ' . Dates::fr($l['date']) . ' adressée à une autre personne pour autorisation.', ['assigned_to' => $l['assigned_to']], ['assigned_to' => $to]);
        Notifier::resolve('FleetLog', $id, 'CARNET_A_AUTORISER');
        $this->notifyAssignee($to, $id, 'Saisie du carnet de bord du ' . Dates::fr($l['date']) . '.');
        return ['message' => 'La saisie est adressée à la nouvelle personne.'];
    }

    /** Saisies à autoriser par le compte connecté (toutes unités confondues), et ses dernières décisions. */
    public function authorizations()
    {
        Ctx::need('can_authorize_fleet');
        $me = Ctx::user();
        $q = fn () => DB::table('fleet_logs as l')->join('fleet_assets as a', 'a.id', '=', 'l.fleet_asset_id')->join('organizational_units as u', 'u.id', '=', 'l.organizational_unit_id')
            ->leftJoin('users as c', 'c.id', '=', 'l.created_by')->leftJoin('users as d', 'd.id', '=', 'l.assigned_to')
            ->where('l.project_id', Ctx::projectId())->whereNull('l.cancelled_at')
            ->select('l.*', 'a.name as asset_name', 'a.kind', 'a.registration', 'a.fuel_price as asset_fuel_price', 'u.name as unit_name',
                'c.full_name as created_by_name', 'd.full_name as assigned_to_name');
        $pending = $q()->whereNull('l.authorized_at')->whereNull('l.objected_at')->where('l.created_by', '!=', $me->id);
        if (!$me->is_super_admin) $pending->where('l.assigned_to', $me->id);
        $done = $q()->where(fn ($w) => $w->where('l.authorized_by', $me->id)->orWhere('l.objected_by', $me->id));
        $readable = Ctx::readableUnitIds();
        $view = function ($l) use ($readable) {
            $price = (float) ($l->fuel_price ?? $l->asset_fuel_price);
            return [
                'id' => (int) $l->id, 'kind' => $l->kind, 'assetId' => (int) $l->fleet_asset_id, 'assetName' => $l->asset_name, 'registration' => $l->registration,
                'unitName' => $l->unit_name, 'canOpenLogbook' => $readable === null || in_array((int) $l->organizational_unit_id, $readable, true), 'date' => Dates::part($l->log_date),
 'requester' => $l->requester, 'destination' => $l->destination, 'purpose' => $l->purpose,
                'meterStart' => (float) $l->meter_start, 'meterEnd' => (float) $l->meter_end, 'distance' => round((float) $l->meter_end - (float) $l->meter_start, 1),
                'fuelPurchased' => $l->fuel_purchased === null ? null : (float) $l->fuel_purchased,
                'purchaseCost' => $l->fuel_purchased === null ? null : round((float) $l->fuel_purchased * $price, 2),
                'lubricant' => $l->lubricant, 'observation' => (string) $l->observation,
                'createdBy' => $l->created_by_name, 'createdAt' => substr((string) $l->created_at, 0, 16), 'assignedToName' => $l->assigned_to_name,
                'decision' => $l->authorized_at ? 'AUTORISEE' : ($l->objected_at ? 'OBJECTION' : null),
                'decidedAt' => substr((string) ($l->authorized_at ?? $l->objected_at ?? ''), 0, 16), 'objectionReason' => $l->objection_reason,
            ];
        };
        return [
            'pending' => $pending->orderBy('l.log_date')->orderBy('l.id')->limit(200)->get()->map($view)->values(),
            'recent' => $done->orderByDesc('l.updated_at')->limit(20)->get()->map($view)->values(),
        ];
    }

    // ------------------------------------------------------------------ Entretiens et assurances

    /** Entretiens : situation de chaque engin (échéance) et historique. */
    public function maintenances(Request $r)
    {
        $units = $this->units();
        $unit = $this->requestedUnit($r, $units, false);
        $assets = $this->assets($unit ? [$unit->id] : $units->pluck('id')->all());
        $ids = $assets->pluck('id')->all();
        $logs = $this->logsByAsset($ids);
        $maint = $this->maintenancesByAsset($ids);
        $today = Dates::today();
        $byId = $assets->keyBy('id');

        $history = [];
        foreach ($maint as $assetId => $rows) {
            foreach ($rows as $m) {
                $a = $byId[$assetId];
                $history[] = [
                    'id' => $m->id, 'assetId' => $assetId, 'assetName' => $a['name'], 'registration' => $a['registration'], 'kind' => $a['kind'], 'unitName' => $a['unit_name'],
                    'date' => Dates::part($m->maintenance_date), 'meterReading' => (float) $m->meter_reading, 'nextMeter' => $m->next_meter === null ? null : (float) $m->next_meter, 'designation' => $m->designation,
                    'cost' => $m->cost === null ? null : (float) $m->cost, 'provider' => $m->provider, 'observation' => (string) $m->observation,
                    'canEdit' => Ctx::can('can_manage_fleet') && Ctx::canWriteUnit((int) $m->organizational_unit_id),
                ];
            }
        }
        usort($history, fn ($x, $y) => strcmp($y['date'], $x['date']) ?: ($y['id'] <=> $x['id']));

        return [
            'units' => $units->map(fn ($u) => self::unitView($u))->values(),
            'unitId' => $unit ? $unit->id : null,
            'assets' => $assets->map(function ($a) use ($logs, $maint, $today) {
                $s = self::status($a, $logs[$a['id']], $maint[$a['id']], [], $today);
                return self::assetView($a) + ['currentMeter' => $s['currentMeter'], 'maintenance' => $s['maintenance']];
            })->values(),
            'history' => $history,
            'totalCost' => round(array_sum(array_map(fn ($h) => (float) $h['cost'], $history)), 2),
        ] + $this->permissions($units);
    }

    private function maintenanceData(Request $r, array $asset): array
    {
        $d = $r->validate([
            'maintenance_date' => 'required|date_format:Y-m-d',
            'designation' => 'required|string|max:255',
            'provider' => 'nullable|string|max:190',
            'observation' => 'nullable|string|max:500',
        ], [], ['maintenance_date' => 'date', 'designation' => 'désignation', 'provider' => 'garage ou prestataire']);
        if ($d['maintenance_date'] > Dates::today()) throw ValidationException::withMessages(['maintenance_date' => 'La date ne peut pas être postérieure à aujourd’hui.']);
        $isVehicle = $asset['kind'] === FleetMetrics::VEHICULE;
        $reading = self::decimalField($r, 'meter_reading', $isVehicle ? 'le kilométrage' : 'le compteur horaire', true);
        $next = self::decimalField($r, 'next_meter', $isVehicle ? 'le kilométrage du prochain entretien' : 'le compteur du prochain entretien', true);
        if ($next <= $reading) throw ValidationException::withMessages(['next_meter' => 'Le prochain entretien doit être au-delà du compteur de cet entretien.']);
        return [
            'maintenance_date' => $d['maintenance_date'], 'designation' => trim($d['designation']),
            'meter_reading' => $reading, 'next_meter' => $next,
            'cost' => self::decimalField($r, 'cost', 'le coût', false),
            'provider' => isset($d['provider']) ? trim($d['provider']) : null, 'observation' => isset($d['observation']) ? trim($d['observation']) : null,
        ];
    }

    /** Programmation du prochain entretien d'un engin : compteur du dernier entretien et compteur du prochain. */
    public function planMaintenance(Request $r, int $id)
    {
        $asset = $this->assetOr404($id);
        $this->needWrite($asset);
        $isVehicle = $asset['kind'] === FleetMetrics::VEHICULE;
        $last = self::decimalField($r, 'last_meter', $isVehicle ? 'le kilométrage du dernier entretien' : 'le compteur du dernier entretien', true);
        $next = self::decimalField($r, 'next_meter', $isVehicle ? 'le kilométrage du prochain entretien' : 'le compteur du prochain entretien', true);
        if ($next <= $last) throw ValidationException::withMessages(['next_meter' => 'Le prochain entretien doit être au-delà du dernier.']);
        $before = ['maintenance_last_meter' => $asset['maintenance_last_meter'], 'maintenance_next_meter' => $asset['maintenance_next_meter']];
        $after = ['maintenance_last_meter' => $last, 'maintenance_next_meter' => $next];
        DB::table('fleet_assets')->where('id', $id)->update($after + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'FleetAsset', $id, "Prochain entretien de {$asset['name']} programmé à {$next} (dernier entretien à {$last}).", $before, $after);
        return ['message' => 'Prochain entretien programmé.'];
    }

    public function storeMaintenance(Request $r)
    {
        $asset = $this->assetOr404((int) $r->input('fleet_asset_id'));
        $this->needWrite($asset);
        $data = $this->maintenanceData($r, $asset);
        $id = DB::transaction(function () use ($data, $asset) {
            // L'entretien effectué remplace la programmation : l'alerte s'arrête, le suivi repart de ce compteur.
            if ($asset['maintenance_last_meter'] !== null && (float) $data['meter_reading'] >= $asset['maintenance_last_meter']) {
                DB::table('fleet_assets')->where('id', $asset['id'])->update(['maintenance_last_meter' => null, 'maintenance_next_meter' => null, 'updated_at' => now()]);
            }
            return DB::table('fleet_maintenances')->insertGetId($data + [
                'project_id' => Ctx::projectId(), 'organizational_unit_id' => $asset['unit_id'], 'fleet_asset_id' => $asset['id'],
                'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        Ctx::auditChange('CREATION', 'FleetMaintenance', $id, "Entretien « {$data['designation']} » de {$asset['name']} le " . Dates::fr($data['maintenance_date']) . '.', null, $data);
        return ['message' => 'Entretien enregistré.', 'id' => $id];
    }

    private function recordOr404(string $table, int $id): object
    {
        $m = DB::table($table)->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($m, 404, 'Enregistrement introuvable.');
        Ctx::need('can_manage_fleet');
        Ctx::needUnit((int) $m->organizational_unit_id);
        return $m;
    }

    public function updateMaintenance(Request $r, int $id)
    {
        $m = $this->recordOr404('fleet_maintenances', $id);
        $asset = $this->assetOr404((int) $m->fleet_asset_id);
        $data = $this->maintenanceData($r, $asset);
        DB::table('fleet_maintenances')->where('id', $id)->update($data + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'FleetMaintenance', $id, "Entretien de {$asset['name']} du " . Dates::fr($data['maintenance_date']) . ' corrigé.', (array) $m, $data);
        return ['message' => 'Entretien enregistré.'];
    }

    public function destroyMaintenance(Request $r, int $id)
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:500'], [], ['reason' => 'motif']);
        $m = $this->recordOr404('fleet_maintenances', $id);
        DB::table('fleet_maintenances')->where('id', $id)->delete();
        Ctx::auditChange('SUPPRESSION', 'FleetMaintenance', $id, "Entretien « {$m->designation} » du " . Dates::fr(Dates::part($m->maintenance_date)) . " supprimé. Motif : {$d['reason']}", (array) $m, null);
        return ['message' => 'Entretien supprimé.'];
    }

    public function insurances(Request $r)
    {
        $units = $this->units();
        $unit = $this->requestedUnit($r, $units, false);
        $assets = $this->assets($unit ? [$unit->id] : $units->pluck('id')->all());
        $ids = $assets->pluck('id')->all();
        $today = Dates::today();
        $byAsset = $this->insurancesByAsset($ids);
        $byId = $assets->keyBy('id');
        $rows = DB::table('fleet_insurances')->where('project_id', Ctx::projectId())->whereIn('fleet_asset_id', $ids ?: [0])->orderByDesc('end_date')->get();

        return [
            'units' => $units->map(fn ($u) => self::unitView($u))->values(),
            'unitId' => $unit ? $unit->id : null,
            'assets' => $assets->map(fn ($a) => self::assetView($a) + [
                'insurance' => ($a['kind'] === FleetMetrics::VEHICULE || $byAsset[$a['id']]) ? FleetMetrics::insurance($byAsset[$a['id']], $today) : null,
            ])->values(),
            'history' => $rows->map(fn ($i) => [
                'id' => $i->id, 'assetId' => (int) $i->fleet_asset_id, 'assetName' => $byId[(int) $i->fleet_asset_id]['name'], 'registration' => $byId[(int) $i->fleet_asset_id]['registration'],
                'unitName' => $byId[(int) $i->fleet_asset_id]['unit_name'], 'startDate' => Dates::part($i->start_date), 'endDate' => Dates::part($i->end_date),
                'designation' => $i->designation, 'cost' => $i->cost === null ? null : (float) $i->cost, 'observation' => (string) $i->observation,
                'canEdit' => Ctx::can('can_manage_fleet') && Ctx::canWriteUnit((int) $i->organizational_unit_id),
            ])->values(),
            'totalCost' => round((float) $rows->sum('cost'), 2),
        ] + $this->permissions($units);
    }

    private function insuranceData(Request $r): array
    {
        $d = $r->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'designation' => 'required|string|max:255',
            'observation' => 'nullable|string|max:500',
        ], ['end_date.after_or_equal' => 'La date de fin doit suivre la date de début.'], ['start_date' => 'date de début', 'end_date' => 'date de fin', 'designation' => 'désignation']);
        return [
            'start_date' => $d['start_date'], 'end_date' => $d['end_date'], 'designation' => trim($d['designation']),
            'cost' => self::decimalField($r, 'cost', 'le coût', false), 'observation' => isset($d['observation']) ? trim($d['observation']) : null,
        ];
    }

    public function storeInsurance(Request $r)
    {
        $asset = $this->assetOr404((int) $r->input('fleet_asset_id'));
        $this->needWrite($asset);
        $data = $this->insuranceData($r);
        $id = DB::table('fleet_insurances')->insertGetId($data + [
            'project_id' => Ctx::projectId(), 'organizational_unit_id' => $asset['unit_id'], 'fleet_asset_id' => $asset['id'],
            'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Ctx::auditChange('CREATION', 'FleetInsurance', $id, "Assurance « {$data['designation']} » de {$asset['name']} du " . Dates::fr($data['start_date']) . ' au ' . Dates::fr($data['end_date']) . '.', null, $data);
        return ['message' => 'Assurance enregistrée.', 'id' => $id];
    }

    public function updateInsurance(Request $r, int $id)
    {
        $i = $this->recordOr404('fleet_insurances', $id);
        $data = $this->insuranceData($r);
        DB::table('fleet_insurances')->where('id', $id)->update($data + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'FleetInsurance', $id, "Assurance « {$data['designation']} » corrigée.", (array) $i, $data);
        return ['message' => 'Assurance enregistrée.'];
    }

    public function destroyInsurance(Request $r, int $id)
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:500'], [], ['reason' => 'motif']);
        $i = $this->recordOr404('fleet_insurances', $id);
        DB::table('fleet_insurances')->where('id', $id)->delete();
        Ctx::auditChange('SUPPRESSION', 'FleetInsurance', $id, "Assurance « {$i->designation} » supprimée. Motif : {$d['reason']}", (array) $i, null);
        return ['message' => 'Assurance supprimée.'];
    }

    // ------------------------------------------------------------------ Rapport carburant

    /** Rapport de la période (?from, ?to), par engin et par mois, avec totaux par unité et total général. */
    private function reportData(Request $r): array
    {
        $today = Dates::today();
        $from = Dates::valid($r->query('from')) ? Dates::part($r->query('from')) : substr($today, 0, 8) . '01';
        $to = Dates::valid($r->query('to')) ? Dates::part($r->query('to')) : $today;
        if ($to < $from) [$from, $to] = [$to, $from];
        $kind = $r->query('kind') === FleetMetrics::GROUPE ? FleetMetrics::GROUPE : FleetMetrics::VEHICULE;

        $units = $this->units();
        $unit = $this->requestedUnit($r, $units, false);
        $assets = $this->assets($unit ? [$unit->id] : $units->pluck('id')->all(), $kind);
        $logs = $this->logsByAsset($assets->pluck('id')->all(), $from, $to);

        $sum = fn (array $rows) => FleetMetrics::sum($kind, $rows);

        $rows = [];
        $byUnit = [];
        foreach ($assets as $a) {
            $months = [];
            foreach ($logs[$a['id']] as $l) $months[substr($l['date'], 0, 7)][] = $l;
            ksort($months);
            $monthRows = [];
            foreach ($months as $m => $ls) $monthRows[] = ['month' => $m] + FleetMetrics::totals($a, $ls);
            $monthRows = array_values(array_filter($monthRows, fn ($x) => $x['entries'] + $x['pending'] > 0));
            if (!$monthRows && !$a['is_active']) continue;
            $total = FleetMetrics::totals($a, $logs[$a['id']]);
            $rows[] = ['id' => $a['id'], 'name' => $a['name'], 'registration' => $a['registration'], 'unitId' => $a['unit_id'], 'unitName' => $a['unit_name'], 'months' => $monthRows, 'total' => $total];
            $byUnit[$a['unit_id']]['name'] = $a['unit_name'];
            $byUnit[$a['unit_id']]['rows'][] = $total;
        }
        $unitTotals = [];
        foreach ($byUnit as $id => $u) $unitTotals[] = ['unitId' => $id, 'unitName' => $u['name']] + $sum($u['rows']);

        return [
            'from' => $from, 'to' => $to, 'kind' => $kind, 'unit' => $unit,
            'units' => $units->map(fn ($u) => self::unitView($u))->values(), 'unitId' => $unit ? $unit->id : null,
            'canChooseUnit' => $this->hasNationalView(),
            'assets' => $rows, 'unitTotals' => $unitTotals, 'grand' => $sum(array_column($rows, 'total')),
        ];
    }

    public function report(Request $r)
    {
        $d = $this->reportData($r);
        unset($d['unit']);
        return $d;
    }

    public function exportReport(Request $r)
    {
        $d = $this->reportData($r);
        $isVehicle = $d['kind'] === FleetMetrics::VEHICULE;
        $H = Xlsx::HEADER; $M = Xlsx::MONEY; $T = Xlsx::TEXT; $B = Xlsx::BOLD;
        $scope = $d['unit'] ? $d['unit']->name : 'toutes les unités';
        $title = ($isVehicle ? 'Rapport carburant des véhicules' : 'Rapport des groupes électrogènes') . " — {$scope} — du " . Dates::fr($d['from']) . ' au ' . Dates::fr($d['to']);
        $head = ['Unité', $isVehicle ? 'Véhicule' : 'Groupe', $isVehicle ? 'Immatriculation' : 'N° de série', 'Mois', $isVehicle ? 'Km parcourus' : 'Heures de fonctionnement',
            'Carburant acheté (L)', 'Montant des achats (USD)', 'Carburant estimé (L)', 'Montant estimé (USD)', $isVehicle ? 'Consommation moyenne (L/100 km)' : 'Consommation moyenne (L/h)',
            'Appoints de lubrifiant', 'Saisies', 'En attente d’autorisation'];
        $line = function (array $t, array $labels, int $style) use ($isVehicle, $M, $T) {
            $avg = $isVehicle ? $t['per100Km'] : $t['perHour'];
            return array_merge(array_map(fn ($v) => ['v' => $v, 's' => $style], $labels), [
                ['v' => $t['distance'], 's' => $M], ['v' => $t['litresPurchased'], 's' => $M], ['v' => $t['purchaseCost'], 's' => $M],
                ['v' => $t['estimatedLitres'], 's' => $M], ['v' => $t['estimatedCost'], 's' => $M], ['v' => $avg ?? '—', 's' => $avg === null ? $T : $M],
                ['v' => $t['lubricantEntries'], 's' => $T], ['v' => $t['entries'], 's' => $T], ['v' => $t['pending'], 's' => $T],
            ]);
        };
        $monthName = fn (string $m) => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) substr($m, 5, 2) - 1] . ' ' . substr($m, 0, 4);

        $rows = [[['v' => $title, 's' => Xlsx::TITLE]], [], array_map(fn ($h) => ['v' => $h, 's' => $H], $head)];
        foreach ($d['assets'] as $a) {
            foreach ($a['months'] as $m) $rows[] = $line($m, [$a['unitName'], $a['name'], (string) $a['registration'], $monthName($m['month'])], $T);
            $rows[] = $line($a['total'], [$a['unitName'], $a['name'], (string) $a['registration'], 'Total de la période'], $B);
        }
        foreach ($d['unitTotals'] as $u) $rows[] = $line($u, [$u['unitName'], 'Total de l’unité', '', ''], $B);
        $rows[] = $line($d['grand'], ['Total général', '', '', ''], $B);

        $bin = Xlsx::build([['name' => $isVehicle ? 'Carburant véhicules' : 'Groupes électrogènes', 'rows' => $rows, 'widths' => [24, 26, 16, 18, 14, 14, 16, 14, 16, 18, 12, 9, 14], 'freezeRows' => 3]]);
        Ctx::audit('EXPORT_RAPPORT', 'FleetReport', $d['kind'], "Export Excel : $title.");
        $file = 'rapport_' . ($isVehicle ? 'carburant' : 'groupes') . '_' . $d['from'] . '_' . $d['to'] . '.xlsx';
        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
        ]);
    }
}
