<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\OrganizationalUnit;

/** Contexte de la requête : projet, phase active et traçabilité (renseignés par le middleware ProjectContext). */
class Ctx
{
    public static function project(): object
    {
        return request()->attributes->get('project');
    }

    public static function projectId(): int
    {
        return (int) self::project()->id;
    }

    public static function phaseId(): int
    {
        return (int) request()->attributes->get('phaseId', 0);
    }

    public static function user()
    {
        return request()->user();
    }

    public static function isAdmin($user = null): bool
    {
        $user = $user ?: self::user();
        if (!$user) return false;
        return (bool) $user->is_super_admin || in_array(strtoupper((string) $user->role), ['ADMIN', 'ADMINISTRATEUR', 'SUPER-ADMIN', 'SUPER_ADMIN'], true);
    }

    public static function can(string $permission, $user = null): bool
    {
        $user = $user ?: self::user();
        if (!$user) return false;
        return self::isAdmin($user) || (bool) ($user->{$permission} ?? false);
    }

    public static function need(string $permission): void
    {
        abort_unless(self::can($permission), 403, 'Vous ne disposez pas de l’habilitation nécessaire pour cette opération.');
    }

    public static function audit(string $action, string $entity, $entityId, string $details, ?int $projectId = null, ?int $userId = null): void
    {
        self::auditChange($action, $entity, $entityId, $details, null, null, $projectId, $userId);
    }

    /** Audit avec valeurs avant/après (TDR §12). Les colonnes ajoutées par la migration du socle ne sont écrites que si renseignées. */
    public static function auditChange(string $action, string $entity, $entityId, string $details, ?array $before, ?array $after, ?int $projectId = null, ?int $userId = null): void
    {
        try {
            $u = self::user();
            $row = [
                'project_id' => $projectId ?? ($u ? (int) $u->project_id : null),
                'user_id' => $userId ?? ($u ? $u->id : null),
                'action' => mb_substr($action, 0, 100),
                'entity_name' => $entity,
                'entity_id' => (string) $entityId,
                'details' => $details,
                'machine_name' => substr((string) gethostname(), 0, 190),
            ];
            if ($u && $u->organizational_unit_id) $row['organizational_unit_id'] = (int) $u->organizational_unit_id;
            if ($before !== null) $row['old_values'] = json_encode($before, JSON_UNESCAPED_UNICODE);
            if ($after !== null) $row['new_values'] = json_encode($after, JSON_UNESCAPED_UNICODE);
            AuditLog::create($row);
        } catch (\Throwable $e) {
            // La traçabilité ne doit jamais bloquer l'opération métier.
        }
    }

    // ------------------------------------------------------------------ Isolation par unité organisationnelle (TDR §3, §5)


    /** null = toutes les unités : réservé au super-admin et aux comptes qu'il désigne (can_view_pools). Sinon, sa seule unité. */
    public static function readableUnitIds($user = null): ?array
    {
        $user = $user ?: self::user();
        if (!$user || $user->is_super_admin || $user->can_view_pools) return null;
        return $user->organizational_unit_id ? [(int) $user->organizational_unit_id] : [];
    }

    /** Hors super-admin, seule l'unité d'affectation est modifiable, y compris pour un administrateur. */
    public static function canWriteUnit(int $unitId, $user = null): bool
    {
        $user = $user ?: self::user();
        if (!$user) return false;
        if ($user->is_super_admin) return true;
        return (int) $user->organizational_unit_id === $unitId && $unitId > 0;
    }

    /** Coordination nationale (ou super-admin) : seule à tenir les référentiels communs au projet. */
    public static function isNationalCoordination($user = null): bool
    {
        $user = $user ?: self::user();
        if (!$user) return false;
        if ($user->is_super_admin) return true;
        $unit = $user->organizational_unit_id ? $user->organizationalUnit : null;
        return $unit && $unit->type === OrganizationalUnit::COORDINATION;
    }

    /** Intitulé affiché du compte : le poste occupé dans le projet tient lieu de rôle. */
    public static function title($user): string
    {
        $position = $user->position_id ? $user->position : null;
        $name = $position ? $position->name : trim((string) $user->project_position);
        if ($name !== '') return $name;
        return $user->is_super_admin ? 'Super-administrateur' : 'Poste non renseigné';
    }

    public static function needUnit(int $unitId): void
    {
        abort_unless(self::canWriteUnit($unitId), 403, 'Cette opération appartient à une autre unité organisationnelle que la vôtre.');
    }
}
