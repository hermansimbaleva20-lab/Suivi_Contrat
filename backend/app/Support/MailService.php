<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Envoi d'e-mails avec les paramètres SMTP propres à chaque projet (table smtp_settings). */
class MailService
{
    public static function password(?string $stored): string
    {
        if (!$stored) return '';
        try { return Crypt::decryptString($stored); } catch (\Throwable $e) { return $stored; }
    }

    /** @return array{success:bool,message:string} */
    public static function send(int $projectId, string $subject, string $text, ?array $recipients = null): array
    {
        $s = DB::table('smtp_settings')->where('project_id', $projectId)->first();
        if (!$s || trim((string) $s->host) === '' || trim((string) $s->sender_email) === '') {
            return ['success' => false, 'message' => 'Paramètres SMTP incomplets : renseignez le serveur et l’adresse d’expédition dans Paramètres.'];
        }
        if ($recipients === null) {
            // Sans destinataire précisé : le personnel du projet (liste gérée dans Paramètres).
            $recipients = DB::table('alert_recipients')->where('project_id', $projectId)->where('is_active', true)->orderBy('email')->pluck('email')->all();
        }
        $recipients = array_values(array_filter(array_map('trim', $recipients), fn ($r) => filter_var($r, FILTER_VALIDATE_EMAIL)));
        if (!$recipients) return ['success' => false, 'message' => 'Aucun destinataire valide n’est configuré.'];

        $port = (int) $s->port ?: 587;
        $scheme = ((bool) $s->use_ssl && $port === 465) ? 'smtps' : 'smtp';
        try {
            $mailer = Mail::build([
                'transport' => 'smtp',
                'host' => $s->host,
                'port' => $port,
                'username' => $s->sender_email,
                'password' => self::password($s->password),
                'scheme' => $scheme,
                'timeout' => 20,
            ]);
            $from = $s->sender_email;
            $mailer->raw($text, function ($m) use ($recipients, $subject, $from) {
                $m->from($from, 'PRISE')->to($recipients)->subject($subject);
            });
            return ['success' => true, 'message' => 'Message envoyé à ' . implode(', ', $recipients) . '.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Échec de l’envoi : ' . $e->getMessage()];
        }
    }
}
