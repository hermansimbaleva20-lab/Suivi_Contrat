<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Poste occupé dans le projet (logisticien, comptable/RAF…), géré par l'administration (TDR §5). */
class Position extends Model
{
    protected $fillable = ['project_id', 'organizational_unit_id', 'name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
