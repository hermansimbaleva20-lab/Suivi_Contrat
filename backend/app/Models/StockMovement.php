<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ligne de fiche de stock : entrée ou sortie d'un article dans une unité. Jamais modifiée : annulée avec motif. */
class StockMovement extends Model
{
    protected $fillable = ['project_id', 'organizational_unit_id', 'stock_article_id', 'operation_no', 'movement_date', 'type', 'quantity', 'origin_destination', 'company_id', 'reference', 'observation', 'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'movement_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];
    }
}
