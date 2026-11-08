<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E-mails des demandes d'accès (décision du 08/11/2026) : le super-administrateur est prévenu de chaque demande, même s'il
 * n'est pas connecté ; le demandeur est prévenu de l'approbation, avec le lien de la page de connexion.
 * Comme l'audit et les notifications, un e-mail ne fait jamais échouer l'opération : le résultat est seulement journalisé.
 */
class AccessMail
{
    /** Adresses des super-administrateurs actifs du projet ; à défaut, SUPER_ADMIN_EMAIL. */
    public static function superAdminEmails(int $projectId): array
    {
        $emails = DB::table('users')->where('project_id', $projectId)->where('is_super_admin', true)->where('is_active', true)->pluck('email')->all();
        if (! $emails && config('prise.super_admin_email')) {
            $emails = [config('prise.super_admin_email')];
        }

        return array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $emails))));
    }

    public static function newRequest(User $u, Project $project, string $unit, string $position): array
    {
        $link = rtrim((string) config('prise.frontend_url'), '/').'/users';
        $body = "Bonjour,\n\nUne nouvelle demande d’accès au projet {$project->code} attend votre validation.\n\n"
            ."Nom : {$u->full_name}\nAdresse e-mail : {$u->email}\nUnité : ".($unit ?: '—')."\nPoste : ".($position ?: '—')."\nDate : ".now()->format('d/m/Y H:i')."\n\n"
            ."Pour approuver ou refuser cette demande, ouvrez l’écran Utilisateurs :\n{$link}\n\n"
            .'Ce message est envoyé automatiquement par l’application de suivi des contrats.';

        return self::send((int) $project->id, "{$project->code} — Nouvelle demande d’accès : {$u->full_name}", $body, self::superAdminEmails((int) $project->id));
    }

    public static function approved(User $u, Project $project): array
    {
        $link = rtrim((string) config('prise.frontend_url'), '/').'/login?project='.$project->code;
        $body = "Bonjour {$u->full_name},\n\nVotre demande d’accès au projet {$project->code} a été approuvée : votre compte est actif.\n\n"
            ."Connectez-vous avec votre adresse e-mail et le mot de passe choisi lors de votre demande :\n{$link}\n\n"
            .'Si vous n’êtes pas à l’origine de cette demande, signalez-le à l’administrateur du projet.';

        return self::send((int) $project->id, "{$project->code} — Votre accès est approuvé", $body, [$u->email]);
    }

    /** @return array{success:bool,message:string} */
    private static function send(int $projectId, string $subject, string $body, array $to): array
    {
        try {
            return MailService::send($projectId, $subject, $body, $to);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Échec de l’envoi : '.$e->getMessage()];
        }
    }
}
