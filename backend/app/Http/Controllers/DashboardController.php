<?php
namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\Loader;
use App\Support\Metrics;
use App\Support\Views;

class DashboardController extends Controller
{
    private static function norm(string $v): string
    {
        $v = mb_strtolower(trim($v));
        return preg_replace('/[\s\-]+/u', '', strtr($v, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ï' => 'i', 'î' => 'i', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']));
    }

    public function index()
    {
        $pid = Ctx::projectId();
        $cs = Loader::contracts($pid, Ctx::phaseId());
        $today = Dates::today();
        $statuses = array_map(fn ($c) => Metrics::status($c), $cs);
        $count = fn ($s) => count(array_filter($statuses, fn ($x) => $x === $s));

        $isAlert = function ($c) {
            if (Metrics::status($c) === 'Retard' || Metrics::progress($c) >= 100) return true;
            foreach ($c['sites'] as $s) if (Metrics::siteProgress($s) >= 100) return true;
            return false;
        };
        $near = 0;
        foreach ($cs as $c) if (!$c['completed'] && $c['end_date'] && $c['end_date'] >= $today && $c['end_date'] <= Dates::addDays($today, 30)) $near++;
        $alerts = array_values(array_filter($cs, $isAlert));
        usort($alerts, fn ($a, $b) => ((Metrics::status($a) === 'Retard' ? 0 : 1) <=> (Metrics::status($b) === 'Retard' ? 0 : 1)) ?: strcmp((string) $a['end_date'], (string) $b['end_date']));
        $avg = $cs ? array_sum(array_map(fn ($c) => Metrics::progress($c), $cs)) / count($cs) : 0;

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
            $cats = array_values(array_unique(array_filter(array_map(fn ($c) => $c['category'] ?: 'Non renseignée', $list))));
            sort($cats);
            $sites = array_keys($g['sites'] ?? []);
            sort($sites);
            $map[] = [
                'name' => $g['name'], 'contracts' => count($list), 'categories' => implode(' · ', $cats), 'sites' => implode(', ', $sites), 'siteCount' => count($sites),
                'retard' => $st('Retard'), 'vigilance' => $st('Vigilance'), 'termines' => $st('Terminé'), 'progress' => Metrics::pct(array_sum(array_map(fn ($c) => Metrics::progress($c), $list)) / max(1, count($list))),
            ];
        }
        usort($map, fn ($a, $b) => ($b['contracts'] <=> $a['contracts']) ?: strcmp($a['name'], $b['name']));

        // Garanties
        $guarantees = Loader::guaranteeRows(['project_id' => $pid, 'phase_id' => Ctx::phaseId()]);
        $views = array_map(fn ($g) => Views::guarantee($g), $guarantees);
        $attention = array_values(array_filter($views, fn ($g) => $g['needsAttention']));
        usort($views, fn ($a, $b) => ((int) $b['isExpired'] <=> (int) $a['isExpired']) ?: ((int) $b['needsAttention'] <=> (int) $a['needsAttention']) ?: strcmp($a['endDate'], $b['endDate']));
        usort($attention, fn ($a, $b) => strcmp($a['endDate'], $b['endDate']));

        return [
            'total' => count($cs), 'normal' => $count('Normal'), 'vigilance' => $count('Vigilance'), 'retard' => $count('Retard'), 'termines' => $count('Terminé'),
            'resilies' => $count('Résilié'), 'near' => $near, 'average' => Metrics::pct($avg), 'alertCount' => count($alerts) + count($attention),
            'contracts' => array_map(fn ($c) => Views::contract($c, true), $cs),
            'recentAlerts' => array_map(fn ($c) => ['id' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'status' => Metrics::status($c), 'progress' => Metrics::pct(Metrics::progress($c)), 'endDate' => $c['end_date']], array_slice($alerts, 0, 8)),
            'provinceMap' => $map,
            'guarantees' => [
                'total' => count($guarantees), 'expired' => count(array_filter($views, fn ($g) => $g['isExpired'])), 'soon' => count(array_filter($views, fn ($g) => $g['isExpiringSoon'])),
                'over80' => count(array_filter($views, fn ($g) => !$g['isExpired'] && $g['daysConsumedPercent'] > 80)), 'amount' => array_sum(array_column($views, 'amount')),
                'rows' => $views, 'alerts' => array_slice($attention, 0, 4),
            ],
        ];
    }
}
