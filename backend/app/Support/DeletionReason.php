<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Motif de la suppression d'un article de stock (décision du 08/11/2026) : obligatoire dès que l'article a des mouvements,
 * supprimés avec lui ; facultatif sinon, et pour un engin sans historique.
 */
class DeletionReason
{
    public static function from(Request $r, bool $required): ?string
    {
        $d = $r->validate(
            ['reason' => [$required ? 'required' : 'nullable', 'string', 'min:5', 'max:500']],
            ['reason.required' => 'Indiquez le motif : son historique sera supprimé avec lui.'],
            ['reason' => 'motif'],
        );
        $reason = trim((string) ($d['reason'] ?? ''));

        return $reason === '' ? null : $reason;
    }
}
