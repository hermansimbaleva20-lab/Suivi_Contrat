<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Sauvegarde logique de la base (structure + données) en PHP pur : sans mysqldump ni accès SSH. */
class Backup
{
    public static function create(): array
    {
        $pdo = DB::connection()->getPdo();
        $name = 'prise_' . date('Ymd_His') . (function_exists('gzopen') ? '.sql.gz' : '.sql');
        $dir = storage_path('app/private/backups');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $path = $dir . '/' . $name;
        $gz = str_ends_with($name, '.gz');
        $fp = $gz ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if (!$fp) throw new \RuntimeException('Impossible d’écrire la sauvegarde dans ' . $dir);
        $w = fn ($s) => $gz ? gzwrite($fp, $s) : fwrite($fp, $s);

        $w("-- Sauvegarde PRISE/PREDIRE générée le " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as $row) {
            $t = $row[0];
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(\PDO::FETCH_NUM);
            $w("DROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n\n");
            $offset = 0;
            $chunk = 300;
            do {
                $data = $pdo->query('SELECT * FROM `' . $t . '` LIMIT ' . $chunk . ' OFFSET ' . $offset)->fetchAll(\PDO::FETCH_NUM);
                if (!$data) break;
                $values = [];
                foreach ($data as $r) {
                    $vals = [];
                    foreach ($r as $v) $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
                    $values[] = '(' . implode(',', $vals) . ')';
                }
                $w('INSERT INTO `' . $t . '` VALUES ' . implode(",\n", $values) . ";\n");
                $offset += $chunk;
            } while (count($data) === $chunk);
            $w("\n");
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\n");
        $gz ? gzclose($fp) : fclose($fp);
        self::prune(14);
        return ['name' => $name, 'size' => filesize($path)];
    }

    public static function dir(): string
    {
        $dir = storage_path('app/private/backups');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/prise_*.sql*') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'date' => date('Y-m-d H:i:s', filemtime($f))];
        }
        usort($out, fn ($a, $b) => strcmp($b['date'], $a['date']));
        return $out;
    }

    public static function prune(int $days): void
    {
        $limit = time() - $days * 86400;
        foreach (glob(self::dir() . '/prise_*.sql*') ?: [] as $f) if (filemtime($f) < $limit) @unlink($f);
    }
}
