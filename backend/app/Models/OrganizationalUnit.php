<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Coordination nationale ou pool d'un projet (TDR §5). */
class OrganizationalUnit extends Model
{
    public const COORDINATION = 'COORDINATION';
    public const POOL = 'POOL';

    protected $fillable = ['project_id', 'type', 'code', 'name', 'province', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
