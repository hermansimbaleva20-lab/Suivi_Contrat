<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Notifications adressées à un compte, affichées dans son espace (cloche de l'en-tête) : décompte à valider, course à
 * autoriser, décision rendue. Comme l'audit, une notification ne fait jamais échouer l'opération qui la produit.
 */
class Notifier
{
    /** @param int[] $userIds */
    public static function send(array $userIds, string $kind, string $title, string $body, string $link, string $entity, $entityId, ?int $projectId = null): void
    {
        try {
            $me = Ctx::user();
            $rows = [];
            foreach (array_unique(array_map('intval', $userIds)) as $uid) {
                if ($uid <= 0 || ($me && $uid === (int) $me->id)) continue;
                $rows[] = [
                    'project_id' => $projectId ?? Ctx::projectId(), 'user_id' => $uid, 'kind' => $kind,
                    'title' => mb_substr($title, 0, 190), 'body' => mb_substr($body, 0, 500), 'link' => $link,
                    'entity_name' => $entity, 'entity_id' => (string) $entityId, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            if ($rows) DB::table('user_notifications')->insert($rows);
        } catch (\Throwable $e) {
            // La notification ne doit jamais bloquer l'opération métier.
        }
    }

    /** Une fois l'élément traité (validé, rejeté, autorisé…), ses notifications « à traiter » sont closes pour tous. */
    public static function resolve(string $entity, $entityId, string $kind): void
    {
        try {
            DB::table('user_notifications')->where('entity_name', $entity)->where('entity_id', (string) $entityId)->where('kind', $kind)
                ->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Comptes actifs du projet titulaires d'une habilitation accordée explicitement (pas seulement par le rôle) ;
     * à défaut, le super-admin, pour qu'une demande ne reste jamais sans destinataire.
     */
    public static function holders(int $projectId, string $permission): array
    {
        $base = DB::table('users')->where('project_id', $projectId)->where('is_active', true)->where('is_pending', false);
        $ids = (clone $base)->where($permission, true)->pluck('id')->all();
        return array_map('intval', $ids ?: (clone $base)->where('is_super_admin', true)->pluck('id')->all());
    }
}
