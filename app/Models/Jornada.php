<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Jornada extends Model
{
    use HasFactory;

    protected $fillable = ['nombre', 'hora_entrada', 'hora_salida', 'tolerancia'];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}