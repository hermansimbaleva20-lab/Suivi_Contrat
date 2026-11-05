<?php
namespace App\Http\Controllers;

use App\Support\AlertEngine;
use App\Support\Backup;
use App\Support\Ctx;
use App\Support\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    private function smtpView(?object $s): array
    {
        return [
            'host' => $s->host ?? 'smtp.gmail.com', 'port' => (int) ($s->port ?? 587), 'senderEmail' => $s->sender_email ?? '', 'useSsl' => (bool) ($s->use_ssl ?? true),
            'hasPassword' => !empty($s->password ?? ''),
        ];
    }

    /** Postes actifs du projet, avec leur unité : un même intitulé (SECA, chauffeur…) existe dans plusieurs unités. */
    public static function positionOptions(int $projectId): array
    {
        return DB::table('positions as p')->leftJoin('organizational_units as u', 'u.id', '=', 'p.organizational_unit_id')->where('p.project_id', $projectId)->where('p.is_active', true)
            ->orderByRaw("CASE WHEN u.type = 'COORDINATION' THEN 0 ELSE 1 END")->orderBy('u.name')->orderBy('p.name')->get(['p.id', 'p.name', 'u.name as unit'])
            ->map(fn ($x) => ['id' => (int) $x->id, 'name' => $x->name, 'unit' => (string) $x->unit])->values()->all();
    }

    /** Le personnel du projet est tenu par la coordination nationale (les contrats et les garanties aussi). */
    private static function canEditRecipients(): bool
    {
        return Ctx::isNationalCoordination();
    }

    private function needEditRecipients(): void
    {
        abort_unless(self::canEditRecipients(), 403, 'Les destinataires du projet sont gérés par la coordination nationale.');
    }

    private function recipientData(Request $r, ?int $ignoreId = null): array
    {
        $pid = Ctx::projectId();
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate([
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('alert_recipients', 'email')->where('project_id', $pid)->ignore($ignoreId)],
            'name' => 'required|string|max:150',
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->where('project_id', $pid)],
        ], ['email.unique' => 'Cette adresse figure déjà parmi les destinataires du projet.', 'position_id.required' => 'Choisissez le poste occupé dans le projet.'],
            ['email' => 'adresse e-mail', 'name' => 'nom', 'position_id' => 'poste']);
        return ['email' => $d['email'], 'name' => trim($d['name']), 'position_id' => (int) $d['position_id']];
    }

    /**
     * Ajoute un destinataire du côté du projet, avec son nom et son poste : il reçoit toutes les catégories d'alertes (le
     * super-admin peut ensuite les restreindre dans Administration > Alertes e-mail). Les décomptes n'en font pas partie :
     * leur rappel va au compte habilité à les valider. Les entreprises sont jointes à l'adresse de leur fiche.
     */
    public function storeRecipient(Request $r)
    {
        $this->needEditRecipients();
        $data = $this->recipientData($r) + ['categories' => implode(',', array_keys(AlertEngine::CATEGORIES))];
        $id = DB::table('alert_recipients')->insertGetId($data + ['project_id' => Ctx::projectId(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Ctx::auditChange('CREATION', 'AlertRecipient', $id, "Destinataire du projet {$data['email']} ajouté.", null, $data);
        return ['message' => "{$data['email']} recevra les alertes du projet."];
    }

    public function updateRecipient(Request $r, int $id)
    {
        $this->needEditRecipients();
        $x = DB::table('alert_recipients')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($x, 404, 'Destinataire introuvable.');
        $data = $this->recipientData($r, $id);
        DB::table('alert_recipients')->where('id', $id)->update($data + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'AlertRecipient', $id, "Destinataire du projet {$data['email']} modifié.", ['email' => $x->email, 'name' => $x->name, 'position_id' => $x->position_id], $data);
        return ['message' => 'Destinataire enregistré.'];
    }

    public function destroyRecipient(int $id)
    {
        $this->needEditRecipients();
        $x = DB::table('alert_recipients')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($x, 404, 'Destinataire introuvable.');
        DB::table('alert_recipients')->where('id', $id)->delete();
        Ctx::auditChange('SUPPRESSION', 'AlertRecipient', $id, "Destinataire du projet {$x->email} supprimé.", (array) $x, null);
        return ['message' => "{$x->email} ne recevra plus les alertes du projet."];
    }

    public function index()
    {
        $s = DB::table('smtp_settings')->where('project_id', Ctx::projectId())->first();
        $super = (bool) Ctx::user()->is_super_admin;
        $pid = Ctx::projectId();
        $recipients = DB::table('alert_recipients as r')->leftJoin('positions as p', 'p.id', '=', 'r.position_id')->where('r.project_id', $pid)->orderBy('r.email')
            ->get(['r.id', 'r.email', 'r.name', 'r.is_active', 'r.position_id', 'p.name as position_name'])
            ->map(fn ($x) => ['id' => $x->id, 'email' => $x->email, 'name' => (string) $x->name, 'isActive' => (bool) $x->is_active, 'positionId' => $x->position_id ? (int) $x->position_id : null, 'position' => $x->position_name])->values();
        return ['smtp' => $this->smtpView($s), 'recipients' => $recipients, 'positions' => self::positionOptions($pid), 'canEditRecipients' => self::canEditRecipients(), 'isSuperAdmin' => $super, 'backups' => $super ? Backup::list() : []];
    }

    public function saveSmtp(Request $r)
    {
        $d = $r->validate([
            'host' => 'required|string|max:255', 'port' => 'required|integer|min:1|max:65535', 'sender_email' => 'required|email|max:320', 'password' => 'nullable|string|max:1000',
            'use_ssl' => 'sometimes|boolean',
        ]);
        $pid = Ctx::projectId();
        $row = DB::table('smtp_settings')->where('project_id', $pid)->first();
        $data = [
            'host' => $d['host'], 'port' => $d['port'], 'sender_email' => $d['sender_email'], 'use_ssl' => (bool) ($d['use_ssl'] ?? false), 'updated_at' => now(),
        ];
        // Le mot de passe est chiffré ; laissé vide, il est conservé tel quel (et chiffré s'il était encore en clair).
        if (!empty($d['password'])) $data['password'] = Crypt::encryptString($d['password']);
        elseif ($row && !empty($row->password)) { try { Crypt::decryptString($row->password); } catch (\Throwable $e) { $data['password'] = Crypt::encryptString($row->password); } }
        if ($row) DB::table('smtp_settings')->where('project_id', $pid)->update($data);
        else DB::table('smtp_settings')->insert($data + ['project_id' => $pid, 'created_at' => now()]);
        Ctx::audit('PARAMETRES_SMTP', 'SmtpSetting', $pid, 'Paramètres SMTP enregistrés.');
        return ['message' => 'Paramètres SMTP enregistrés.'];
    }

    public function testSmtp()
    {
        $res = MailService::send(Ctx::projectId(), 'Test SMTP — ' . Ctx::project()->code . ' PRISE', 'Ceci est un message de test envoyé depuis la plateforme ' . Ctx::project()->code . '.');
        Ctx::audit('TEST_SMTP', 'SmtpSetting', Ctx::projectId(), $res['message']);
        abort_unless($res['success'], 422, $res['message']);
        return ['message' => $res['message']];
    }

    public function backup()
    {
        abort_unless(Ctx::user()->is_super_admin, 403, 'La sauvegarde manuelle est réservée au super-administrateur : elle couvre les deux projets.');
        try { $b = Backup::create(); } catch (\Throwable $e) { abort(500, 'Sauvegarde impossible : ' . $e->getMessage()); }
        Ctx::audit('SAUVEGARDE_SQL', 'Database', Ctx::project()->code, 'Sauvegarde manuelle : ' . $b['name'] . '.');
        return ['message' => 'Sauvegarde créée : ' . $b['name'], 'backups' => Backup::list()];
    }

    public function downloadBackup(string $name)
    {
        abort_unless(Ctx::user()->is_super_admin, 403, 'Réservé au super-administrateur.');
        abort_unless(preg_match('/^prise_\d{8}_\d{6}\.sql(\.gz)?$/', $name), 404);
        $path = Backup::dir() . '/' . $name;
        abort_unless(is_file($path), 404, 'Sauvegarde introuvable.');
        Ctx::audit('TELECHARGEMENT_SAUVEGARDE', 'Database', $name, 'Sauvegarde téléchargée.');
        return response()->download($path);
    }
}

