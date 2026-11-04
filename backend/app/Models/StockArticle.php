<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Article de stock (« prix unitaires évalués » : article, unité, PU en USD). organizational_unit_id NULL : catalogue
 * commun tenu par la coordination ; sinon article propre à une antenne. contract_id : article importé d'un contrat.
 */
class StockArticle extends Model
{
    protected $fillable = ['project_id', 'organizational_unit_id', 'contract_id', 'number', 'designation', 'unit', 'pack_size', 'pack_unit', 'unit_price', 'alert_threshold', 'is_active'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'unit_price' => 'float', 'alert_threshold' => 'float', 'pack_size' => 'float', 'is_active' => 'boolean'];
    }
}
