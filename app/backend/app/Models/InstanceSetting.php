<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstanceSetting extends Model
{
    protected $fillable = ['id', 'values'];

    protected $hidden = ['values'];

    protected function casts(): array
    {
        return ['values' => 'encrypted:array'];
    }
}
