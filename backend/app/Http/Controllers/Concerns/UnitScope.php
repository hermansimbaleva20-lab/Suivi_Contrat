<?php

namespace App\Http\Controllers\Concerns;

use App\Models\OrganizationalUnit;
use App\Support\Ctx;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Périmètre des modules rattachés à une unité (stocks, charroi…) : lecture limitée par Ctx::readableUnitIds(),
 * écriture par Ctx::canWriteUnit() et l'habilitation de gestion du module.
 */
trait UnitScope
{
    /** Unités consultables par l'utilisateur (coordination d'abord), y compris désactivées pour l'historique. */
    protected function units(): Collection
    {
        $q = OrganizationalUnit::where('project_id', Ctx::projectId());
        $ids = Ctx::readableUnitIds();
        if ($ids !== null) $q->whereIn('id', $ids);
        return $q->orderByRaw("CASE WHEN type = 'COORDINATION' THEN 0 ELSE 1 END")->orderBy('name')->get();
    }

    protected function writableUnitIds(Collection $units, string $permission): array
    {
        if (!Ctx::can($permission)) return [];
        return $units->filter(fn ($u) => $u->is_active && Ctx::canWriteUnit((int) $u->id))->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Unité demandée (?unit_id), contrôlée contre le périmètre ; null = toutes les unités lisibles (vue nationale seulement). */
    protected function requestedUnit(Request $r, Collection $units, bool $required): ?OrganizationalUnit
    {
        $id = (int) $r->query('unit_id', 0);
        if ($id) {
            $unit = $units->firstWhere('id', $id);
            abort_unless($unit, 403, 'Cette unité ne fait pas partie de votre périmètre.');
            return $unit;
        }
        if (!$required && Ctx::readableUnitIds() === null) return null;
        abort_if(Ctx::readableUnitIds() === [], 403, 'Votre compte n’est rattaché à aucune unité : demandez votre affectation au super-administrateur.');
        $unit = $units->firstWhere('id', (int) Ctx::user()->organizational_unit_id) ?: $units->first();
        abort_unless($unit, 422, 'Aucune unité organisationnelle n’est définie pour ce projet.');
        return $unit;
    }

    protected static function unitView(OrganizationalUnit $u): array
    {
        return ['id' => $u->id, 'type' => $u->type, 'code' => $u->code, 'name' => $u->name, 'isActive' => (bool) $u->is_active];
    }

    /** Vue de toutes les unités (super-admin et comptes désignés par lui) : choix libre de l'unité consultée. */
    protected function hasNationalView(): bool
    {
        return Ctx::readableUnitIds() === null;
    }

    /** Référentiels communs au projet (catalogue des articles) : tenus par la coordination nationale. */
    protected function holdsCommonReferentials(): bool
    {
        return Ctx::isNationalCoordination();
    }
}
