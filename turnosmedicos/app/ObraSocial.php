<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ObraSocial extends Model
{
	public $timestamps = false;
    protected $primaryKey = 'id';
    protected $table = 'obras_sociales';
    protected $fillable = [
        'nombre',
        'pagina_web',
        'email',
        'telefono',
        'codigo'
    ];

    public function medicos()
    {
        return $this->belongsToMany('App\Medico');
    }

    public function pacientes()
    {
        return $this->hasMany('App\Paciente');
    }
}
