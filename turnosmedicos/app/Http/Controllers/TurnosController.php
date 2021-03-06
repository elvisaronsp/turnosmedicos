<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Medico;
use App\Paciente;
use App\Dia;
use App\Turno;
use App\Especialidad;
use App\Empresa;

use Session;
use Auth;
use Carbon\Carbon;
use DB;
use Mail;

class TurnosController extends Controller
{

    private function validarTurno(Request $request){
        $this->validate($request, [
            'paciente_id' => 'required',
            'especialidad_id' => 'required',
            'medico_id' => 'required',
            'fecha' => 'required',
            'hora' => 'required'
        ]);
    }

    public function diaDisponible(Request $request)
    {
        $fecha=Carbon::createFromFormat('d-m-Y', $request->get('fecha'));
        $medico_id=$request->get('medico_id');
        //seteamos los datos necesarios para los cálculos: el médico, su horario en el día solicitado,
        //la cantidad en minutos que reporesenta ese horario, la duracion del turno, y la cantidad de 
        //turnos por día
        $medico = Medico::findOrFail($medico_id);
        $horario = Medico::join('dia_medico', 'medicos.id', '=', 'dia_medico.medico_id')->where('medicos.id', '=', $medico_id)->where('dia_medico.dia_id', '=', $fecha->dayOfWeek)->get();

        //1º banda horaria del médico
        $minutosAtencion1 = (new Carbon($horario[0]->hasta))->diffInMinutes(new Carbon($horario[0]->desde));
        $duracionTurno = (new Carbon($medico->duracionTurno))->minute;
        $turnos_primera_banda = $minutosAtencion1 / $duracionTurno;

        $horarios = [];
        $hora = Carbon::createFromFormat('Y-m-d H:i:s', $horario[0]->desde);
        for($i = 0; $i < $turnos_primera_banda; $i++){
            $horarios[$hora->toTimeString()] = $this->verificarDisponibilidad($medico_id, $fecha, $hora);

            $hora = $hora->addMinutes($duracionTurno);
        }

        if (isset($horario[1])){
            //2º banda horaria del médico (si existe)
            $minutosAtencion2 = (new Carbon($horario[1]->hasta))->diffInMinutes(new Carbon($horario[1]->desde));
            $turnos_segunda_banda = $minutosAtencion2 / $duracionTurno;

            $hora = Carbon::createFromFormat('Y-m-d H:i:s', $horario[1]->desde);
            for($i = 0; $i < $turnos_segunda_banda; $i++){
                $horarios[$hora->toTimeString()] = $this->verificarDisponibilidad($medico_id, $fecha, $hora);

                $hora = $hora->addMinutes($duracionTurno);
            }
        }

        return $horarios;
    }

    private function verificarDisponibilidad($medico_id, Carbon $fecha, Carbon $hora)
    {
        $turno = Turno::whereDate('fecha', '=', $fecha->startOfDay())->where('medico_id', '=', $medico_id)->whereraw('hour(hora) = '.(string)$hora->hour)->whereraw('minute(hora) = '.(string)$hora->minute)->where('cancelado', false)->get();

        return $turno->isEmpty();
    }

    public function turnosMedicoDia(Request $request)
    {
        $fecha = $request->get('dia');
        $fecha = Carbon::createFromFormat('d-m-Y', $fecha);
        $medico_id = $request->get('medico');

        $turnos = Turno::whereDate('fecha', '=', $fecha->startOfDay())->where('medico_id', '=', $medico_id)->orderBy('hora')->get();

        //ésto se hace para llamar, por cada objeto "turno", de la colección al paciente correspondiente
        //si no se hace, el json viaja a la vista sin los pacientes
        foreach($turnos as $turno){
            $turno->paciente->obra_social();
        }

        return response()->json(
            $turnos->toArray()
        );
    }

    public function listado(Request $request)
    {
        //Para mosrar las fechas en castellano
        Carbon::setLocale('es');
        setlocale(LC_TIME, config('app.locale'));
        //si el submit button es el que tiene "value"='pdf' 
        //es decir, si quiero exportar a pdf
        if($request->get('aceptar') == 'pdf'){
            //seteamos el médico y la fecha para buscar los turnos
            $request->get('medico_id_sel')? $medico = Medico::findOrFail($request->get('medico_id_sel')) : $medico = Medico::first();
            $request->get('fecha')? $fecha = Carbon::createFromFormat('d-m-Y', $request->get('fecha')) : $fecha = new Carbon();

            //buscamos los turnos correspondientes al médico y fecha dados (o todos)
            if($request->get('medico_id_sel') != 0){
                $turnos = Turno::where('medico_id', '=', $medico->id)->whereIn('paciente_id', function($query){
                    $query->select(DB::raw('id'))
                        ->from('pacientes')
                        ->whereRaw('pacientes.confirmado = 1');
                })->whereDate('fecha', '=', $fecha->startOfDay())->orderBy('hora')->get();
            }else{
                $turnos = Turno::whereIn('paciente_id', function($query){
                    $query->select(DB::raw('id'))
                        ->from('pacientes')
                        ->whereRaw('pacientes.confirmado = 1');
                })->whereDate('fecha', '=', $fecha->startOfDay())->orderBy('hora')->get();
            }

            //preparamos el pdf con una vista separada (para evitar errores de markup validation)
            $pdf = \PDF::loadView('pdf.listado_turnos', ['medico' => $medico, 'turnos' => $turnos, 'fecha' => $fecha, 'medico_id' => $request->get('medico_id_sel')]);

            //iniciamos la descarga
            return $pdf->download('listado_turnos.pdf');
        //si el submit button es el que tiene el "value"='buscar'
        }else{
            //preparamos los médico en formato arreglo para el dropdown en la vista
            $medicos = Medico::select('id', DB::raw('concat(apellido, ", ", nombre) as apellido'))->orderBy('apellido')->lists('apellido', 'id')->prepend('--Todos--', '0');

            //seteamos el medico_id y la fecha según los inputs e la vista o con valores por defecto
            $request->get('medico_id_sel')? $medico_id = $request->get('medico_id_sel') : $medico_id = 0;
            $request->get('fecha')? $fecha = Carbon::createFromFormat('d-m-Y', $request->get('fecha')) : $fecha = new Carbon();


            //buscamos los turnos correspondientes al médico y fecha dados (o todos)
            if($request->get('medico_id_sel') != 0){
                $turnos = Turno::with('paciente')->with('especialidad')->with('medico')->where('medico_id', '=', $medico_id)->whereIn('paciente_id', function($query){
                    $query->select(DB::raw('id'))
                        ->from('pacientes')
                        ->whereRaw('pacientes.confirmado = 1');
                })->whereDate('fecha', '=', $fecha->startOfDay())->orderBy('hora')->get();
            }else{
                $turnos = Turno::with('paciente')->with('especialidad')->with('medico')->whereIn('paciente_id', function($query){
                    $query->select(DB::raw('id'))
                        ->from('pacientes')
                        ->whereRaw('pacientes.confirmado = 1');
                })->whereDate('fecha', '=', $fecha->startOfDay())->orderBy('hora')->get();  
            }

            return view('turnos.listado', ['medicos' => $medicos, 'turnos' => $turnos, 'medico_id' => $medico_id, 'fecha' => $fecha]);
        }
    }

    public function misTurnos()
    {
        $paciente_id = Auth::user()->pacienteAsociado()->first()->id;
        $turnos = Turno::with('medico')->with('especialidad')->where('paciente_id', '=', $paciente_id)->where('cancelado', false)->orderBy('fecha', 'DESC')->get();

        return view('turnos.misturnos', ['turnos' => $turnos]);
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $pacientes = Paciente::select('id', DB::raw('concat(apellido, ", ", nombre) as apellido'))->orderBy('apellido')->lists('apellido', 'id');
        $medicos = Medico::select('id', DB::raw('concat(apellido, ", ", nombre) as apellido'))->orderBy('apellido')->lists('apellido', 'id');

        return view('turnos.create', ['medicos' => $medicos, 'pacientes' => $pacientes]);
    }


    public function create_por_especialidad()
    {
        $especialidades = Especialidad::orderBy('descripcion')->lists('descripcion', 'id');
        return view('turnos.create_por_especialidad', ['especialidades' => $especialidades]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if(! Auth::user()->hasRole('admin')){
            $request['paciente_id'] = Auth::user()->pacienteAsociado()->first()->id;
            $this->validarTurno($request);

            $input = $request->all();
            $input['fecha'] = Carbon::createFromFormat('d-m-Y', $input['fecha'])->startOfDay();
            $input['hora'] = Carbon::createFromFormat('H:i', $input['hora']);

            $this->emailAltaTurno(Turno::create($input), Auth::user()->email);

            Session::flash('flash_message', 'Se ha solicitado un turno de manera exitosa. Se ha enviado un email a '. Auth::user()->email);
            return redirect('/turnos.misturnos');
        }else{
            $paciente = Paciente::findOrFail($request->get('paciente_id'));
            $request['paciente_id'] = $paciente->id;

            $this->validarTurno($request);

            $input = $request->all();
            $input['fecha'] = Carbon::createFromFormat('d-m-Y', $input['fecha'])->startOfDay();
            $input['hora'] = Carbon::createFromFormat('H:i', $input['hora']);
            $input['sobre_turno'] = ($input['sobre_turno'] == '1');

            $this->emailAltaTurno(Turno::create($input), $paciente->user->email);

            Session::flash('flash_message', 'Se ha solicitado un turno de manera exitosa. Se ha enviado un email a '. $paciente->user->email);
            return redirect('turnos.listado');
        }
    }

    private function emailAltaTurno($turno, $email){
        $medico = Medico::findOrFail($turno->medico_id);

        $data['turno'] = $turno;
        $data['especialidad'] = Especialidad::findOrFail($turno->especialidad_id);
        $data['medico'] = $medico;
        $data['empresa'] = Empresa::findOrFail(1);

        Mail::send('emails.altaturno', $data, function ($message) use($medico, $turno, $email){
            $message->subject(
                Empresa::findOrFail(1)->nombre . ' - Turno: ' . $medico->apellido .
                ', ' . $medico->nombre . ' - ' . $turno->fecha->format('d-m-Y') .
                ' a las ' . $turno->hora->format('H:i')
            );

            $message->to($email);
        });
    }

    public function cancel($id)
    {
        $turno = Turno::findOrFail($id);
        $turno->fill(['cancelado' => true])->save();

        $this->emailCancelaTurno($turno);

        Session::flash('flash_message', 'Se ha cancelado el turno de manera exitosa');

        return redirect('/turnos.misturnos');
    }

    private function emailCancelaTurno($turno){
        $data['turno'] = $turno;
        $data['especialidad'] = Especialidad::findOrFail($turno->especialidad_id);
        $data['medico'] = Medico::findOrFail($turno->medico_id);
        $data['paciente'] = Paciente::findOrFail($turno->paciente_id);

        Mail::send('emails.cancelaturno', $data, function ($message) {
            $message->subject(Empresa::findOrFail(1)->nombre);
            $message->to(Empresa::findOrFail(1)->email);
        });
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //como update es "moverTurno", lo único que cambia es fecha, hora y sobreturno
        //lo demás lo buscamos y dejamos como estaba
        $turno = Turno::findOrFail($id);

        $input = $request->all();
        $input['fecha'] = Carbon::createFromFormat('d-m-Y', $input['fecha'])->startOfDay();
        $input['hora'] = Carbon::createFromFormat('H:i', $input['hora']);
        $input['sobre_turno'] = ($input['sobre_turno'] == '1');
        $input['paciente_id'] = $turno->paciente_id;
        $input['medico_id'] = $turno->medico_id;
        $input['especialidad_id'] = $turno->especialidad_id;

        $turno->fill($input)->save();

        $this->emailAltaTurno($turno, $turno->paciente->user->email);

        Session::flash('flash_message', 'Se ha editado un turno de manera exitosa. Se ha enviado un email a '. $turno->paciente->user->email);
        return redirect('turnos.listado');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $turno = Turno::findOrFail($id);
        $turno->delete();
        return redirect('/turnos.misturnos');
    }
}
