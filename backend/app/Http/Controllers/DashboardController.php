<?php
namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Loader;
use App\Support\Metrics;
use App\Support\Views;

class DashboardController extends Controller
{
    /** Article devant le nom de la province quand ce n'est pas « DU » (PROVINCE DU KWILU). */
    private const PROVINCE_ARTICLES = ['equateur' => "DE L'", 'ituri' => "DE L'", 'kinshasa' => 'DE ', 'maindombe' => 'DE ', 'mongala' => 'DE LA ', 'tshopo' => 'DE LA ', 'tshuapa' => 'DE LA '];

    private static function norm(string $v): string
    {
        $v = mb_strtolower(trim($v));
        return preg_replace('/[\s\-]+/u', '', strtr($v, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ï' => 'i', 'î' => 'i', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']));
    }

    /**
     * Répartition des contrats par province, sur le modèle du tableau du rapport du projet. Un contrat présent dans
     * plusieurs provinces figure sous chacune, avec les ouvrages de ses sites de cette province ; aucun contrat n'est omis.
     */
    private static function byProvince(array $cs, array $views): array
    {
        $groups = [];
        foreach ($cs as $i => $c) {
            $places = [];
            foreach ($c['sites'] as $s) {
                $name = trim($s['province']);
                if ($name === '') continue;
                $places[self::norm($name)]['name'] = $name;
                if (trim($s['work_type']) !== '') $places[self::norm($name)]['types'][trim($s['work_type'])] = true;
            }
            if (!$places) $places[self::norm((string) $c['province'])] = ['name' => trim((string) $c['province'])];
            foreach ($places as $key => $place) {
                $groups[$key]['name'] = $groups[$key]['name'] ?? $place['name'];
                $groups[$key]['contracts'][] = [
                    'id' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'category' => $c['category'],
                    'workType' => isset($place['types']) ? implode('; ', array_keys($place['types'])) : $c['work_type_text'],
                    'startDate' => $views[$i]['periodStart'], 'months' => $views[$i]['periodMonths'],
                ];
            }
        }
        // Provinces par ordre alphabétique, la mention « non renseignée » en dernier ; contrats par numéro.
        uksort($groups, fn ($a, $b) => (($a === '') <=> ($b === '')) ?: strcmp($a, $b));
        $out = [];
        foreach ($groups as $key => $g) {
            usort($g['contracts'], fn ($a, $b) => strnatcmp($a['number'], $b['number']));
            $title = $key === '' ? 'PROVINCE NON RENSEIGNÉE' : 'PROVINCE ' . (self::PROVINCE_ARTICLES[$key] ?? 'DU ') . mb_strtoupper($g['name']);
            $out[] = ['key' => $key, 'title' => $title, 'contracts' => $g['contracts']];
        }
        return $out;
    }

    public function index()
    {
        $pid = Ctx::projectId();
        $cs = Loader::contracts($pid, Ctx::phaseId());
        $statuses = array_map(fn ($c) => Metrics::status($c), $cs);
        $count = fn ($s) => count(array_filter($statuses, fn ($x) => $x === $s));

        $isAlert = function ($c) {
            if (Metrics::status($c) === 'Retard' || Metrics::progress($c) >= 100) return true;
            foreach ($c['sites'] as $s) if (Metrics::siteProgress($s) >= 100) return true;
            return false;
        };
        $alerts = array_values(array_filter($cs, $isAlert));

        // Synthèse par province (carte)
        $groups = [];
        foreach ($cs as $c) {
            $names = [];
            foreach ($c['sites'] as $s) if (trim($s['province']) !== '') $names[self::norm($s['province'])] = $s['province'];
            if (!$c['sites'] && trim($c['province']) !== '') $names[self::norm($c['province'])] = $c['province'];
            foreach ($names as $key => $display) {
                $groups[$key]['name'] = $groups[$key]['name'] ?? $display;
                $groups[$key]['contracts'][$c['id']] = $c;
                foreach ($c['sites'] as $s) if (self::norm($s['province']) === $key && trim($s['name']) !== '') $groups[$key]['sites'][$s['name']] = true;
            }
        }
        $map = [];
        foreach ($groups as $g) {
            $list = array_values($g['contracts']);
            $st = fn ($x) => count(array_filter($list, fn ($c) => Metrics::status($c) === $x));
            $map[] = [
                'name' => $g['name'], 'contracts' => count($list), 'siteCount' => count($g['sites'] ?? []),
                'retard' => $st('Retard'), 'vigilance' => $st('Vigilance'), 'termines' => $st('Terminé'), 'progress' => Metrics::pct(array_sum(array_map(fn ($c) => Metrics::progress($c), $list)) / max(1, count($list))),
            ];
        }
        usort($map, fn ($a, $b) => ($b['contracts'] <=> $a['contracts']) ?: strcmp($a['name'], $b['name']));

        // Garanties
        $guarantees = Loader::guaranteeRows(['project_id' => $pid, 'phase_id' => Ctx::phaseId()]);
        $views = array_map(fn ($g) => Views::guarantee($g), $guarantees);
        $attention = array_values(array_filter($views, fn ($g) => $g['needsAttention']));
        usort($views, fn ($a, $b) => ((int) $b['isExpired'] <=> (int) $a['isExpired']) ?: ((int) $b['needsAttention'] <=> (int) $a['needsAttention']) ?: strcmp($a['endDate'], $b['endDate']));

        $contractViews = array_map(fn ($c) => Views::contract($c, true), $cs);
        return [
            'total' => count($cs), 'normal' => $count('Normal'), 'vigilance' => $count('Vigilance'), 'alertCount' => count($alerts) + count($attention),
            'contracts' => $contractViews,
            'provinceContracts' => self::byProvince($cs, $contractViews),
            'provinceMap' => $map,
            'guarantees' => [
                'total' => count($guarantees), 'expired' => count(array_filter($views, fn ($g) => $g['isExpired'])), 'soon' => count(array_filter($views, fn ($g) => $g['isExpiringSoon'])),
                'over80' => count(array_filter($views, fn ($g) => !$g['isExpired'] && $g['daysConsumedPercent'] > 80)), 'amount' => array_sum(array_column($views, 'amount')),
                'rows' => $views,
            ],
        ];
    }
}
