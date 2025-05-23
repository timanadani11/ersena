<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Asistencia;
use App\Models\ProgramaFormacion;
use App\Models\Jornada;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminController extends Controller
{
    /**
     * Muestra la página principal del dashboard administrativo
     */
    public function dashboard()
    {
        // Estadísticas generales
        $estadisticas = $this->obtenerEstadisticasGenerales();
        
        return view('admin.dashboard.index', [
            'estadisticas' => $estadisticas
        ]);
    }

    /**
     * Muestra la página del escáner QR
     */
    public function scanner() 
    {
        return view('admin.scanner.index');
    }

    /**
     * Muestra la página de gestión de aprendices
     */
    public function aprendices()
    {
        $aprendices = User::where('rol', 'aprendiz')
            ->with(['programaFormacion', 'jornada'])
            ->latest()
            ->paginate(15);
        
        return view('admin.aprendices.index', [
            'aprendices' => $aprendices
        ]);
    }

    /**
     * Muestra la página de gestión de programas de formación
     */
    public function programas()
    {
        $programas = ProgramaFormacion::with('user')
            ->latest()
            ->paginate(15);
        
        return view('admin.programas.index', [
            'programas' => $programas
        ]);
    }

    /**
     * Muestra la página de reportes de asistencia
     */
    public function reportes()
    {
        return view('admin.reportes.index');
    }

    /**
     * Muestra la página de configuración
     */
    public function configuracion()
    {
        return view('admin.config.index');
    }

    /**
     * Muestra todas las asistencias
     */
    public function asistencias()
    {
        $asistencias = Asistencia::with(['user.programaFormacion', 'user.jornada'])
            ->latest()
            ->paginate(20);
        
        return view('admin.asistencias.index', [
            'asistencias' => $asistencias
        ]);
    }

    /**
     * Obtiene estadísticas generales del sistema para el dashboard
     */
    private function obtenerEstadisticasGenerales()
    {
        // Fecha actual (Bogotá)
        $hoy = Carbon::now()->setTimezone('America/Bogota')->format('Y-m-d');
        $inicioSemana = Carbon::now()->setTimezone('America/Bogota')->startOfWeek()->format('Y-m-d');
        $inicioMes = Carbon::now()->setTimezone('America/Bogota')->startOfMonth()->format('Y-m-d');

        // Total de usuarios aprendices
        $totalAprendices = User::where('rol', 'aprendiz')->count();

        // Asistencias de hoy
        $asistenciasHoy = DB::table('asistencias')
            ->whereDate('fecha_hora', $hoy)
            ->where('tipo', 'entrada')
            ->count();
        
        $porcentajeAsistenciaHoy = $totalAprendices > 0 ? round(($asistenciasHoy / $totalAprendices) * 100) : 0;

        // Asistencias de la semana
        $asistenciasSemana = DB::table('asistencias')
            ->whereDate('fecha_hora', '>=', $inicioSemana)
            ->where('tipo', 'entrada')
            ->select(DB::raw('DATE(fecha_hora) as fecha'), DB::raw('count(*) as total'))
            ->groupBy('fecha')
            ->get();

        // Asistencias del mes por programa
        $asistenciasPorPrograma = DB::table('asistencias')
            ->join('users', 'asistencias.user_id', '=', 'users.id')
            ->join('programa_formacion', 'programa_formacion.user_id', '=', 'users.id')
            ->whereDate('asistencias.fecha_hora', '>=', $inicioMes)
            ->where('asistencias.tipo', 'entrada')
            ->select(
                'programa_formacion.nombre_programa',
                DB::raw('count(asistencias.id) as total_asistencias')
            )
            ->groupBy('programa_formacion.nombre_programa')
            ->get();

        // Promedio de llegadas tarde (comparando con la hora de entrada de la jornada)
        $llegadasTarde = DB::table('asistencias')
            ->join('users', 'asistencias.user_id', '=', 'users.id')
            ->join('jornadas', 'users.jornada_id', '=', 'jornadas.id')
            ->whereDate('asistencias.fecha_hora', '>=', $inicioMes)
            ->where('asistencias.tipo', 'entrada')
            ->whereRaw("TIME(fecha_hora) > TIME(ADDTIME(jornadas.hora_entrada, SEC_TO_TIME(jornadas.tolerancia * 60)))")
            ->count();

        $totalAsistenciasMes = Asistencia::whereDate('fecha_hora', '>=', $inicioMes)
            ->where('tipo', 'entrada')
            ->count();
        
        $porcentajeTardanzas = $totalAsistenciasMes > 0 ? round(($llegadasTarde / $totalAsistenciasMes) * 100) : 0;

        // Tendencia semanal (comparar con semana anterior)
        $inicioSemanaAnterior = Carbon::now()->setTimezone('America/Bogota')->subWeek()->startOfWeek()->format('Y-m-d');
        $finSemanaAnterior = Carbon::now()->setTimezone('America/Bogota')->subWeek()->endOfWeek()->format('Y-m-d');
        
        $asistenciasSemanaAnterior = Asistencia::whereBetween(DB::raw('DATE(fecha_hora)'), [$inicioSemanaAnterior, $finSemanaAnterior])
            ->where('tipo', 'entrada')
            ->count();
        
        $asistenciasSemanaActual = Asistencia::whereBetween(DB::raw('DATE(fecha_hora)'), [$inicioSemana, $hoy])
            ->where('tipo', 'entrada')
            ->count();
        
        $tendenciaSemanal = $asistenciasSemanaAnterior > 0 
            ? round((($asistenciasSemanaActual - $asistenciasSemanaAnterior) / $asistenciasSemanaAnterior) * 100) 
            : 100;

        return [
            'total_aprendices' => $totalAprendices,
            'asistencias_hoy' => $asistenciasHoy,
            'porcentaje_asistencia_hoy' => $porcentajeAsistenciaHoy,
            'asistencias_semana' => $asistenciasSemana,
            'asistencias_por_programa' => $asistenciasPorPrograma,
            'porcentaje_tardanzas' => $porcentajeTardanzas,
            'tendencia_semanal' => $tendenciaSemanal
        ];
    }

    /**
     * Retorna estadísticas para los gráficos del dashboard mediante AJAX
     */
    public function obtenerEstadisticasGraficos()
    {
        // Últimos 7 días
        $ultimosDias = [];
        for ($i = 6; $i >= 0; $i--) {
            $fecha = Carbon::now()->setTimezone('America/Bogota')->subDays($i)->format('Y-m-d');
            $ultimosDias[] = $fecha;
        }
        
        // Asistencias por día (últimos 7 días)
        $asistenciasPorDia = [];
        foreach ($ultimosDias as $fecha) {
            $asistenciasPorDia[] = [
                'fecha' => $fecha,
                'total' => Asistencia::whereDate('fecha_hora', $fecha)
                    ->where('tipo', 'entrada')
                    ->count()
            ];
        }
        
        // Asistencias por programa (último mes)
        $inicioMes = Carbon::now()->setTimezone('America/Bogota')->startOfMonth()->format('Y-m-d');
        $asistenciasPorPrograma = DB::table('asistencias')
            ->join('users', 'asistencias.user_id', '=', 'users.id')
            ->leftJoin('programa_formacion', 'programa_formacion.user_id', '=', 'users.id')
            ->whereDate('asistencias.fecha_hora', '>=', $inicioMes)
            ->where('asistencias.tipo', 'entrada')
            ->select(
                'programa_formacion.nombre_programa',
                DB::raw('count(asistencias.id) as total')
            )
            ->groupBy('programa_formacion.nombre_programa')
            ->get();
        
        // Puntualidad (% en hora vs tarde)
        $puntualidad = $this->calcularEstadisticasPuntualidad();
        
        return response()->json([
            'asistencias_por_dia' => $asistenciasPorDia,
            'asistencias_por_programa' => $asistenciasPorPrograma,
            'puntualidad' => $puntualidad
        ]);
    }
    
    /**
     * Calcula estadísticas de puntualidad (último mes)
     */
    private function calcularEstadisticasPuntualidad()
    {
        $inicioMes = Carbon::now()->setTimezone('America/Bogota')->startOfMonth()->format('Y-m-d');
        
        // Total de asistencias del mes
        $totalAsistencias = Asistencia::whereDate('fecha_hora', '>=', $inicioMes)
            ->where('tipo', 'entrada')
            ->count();
        
        // Llegadas a tiempo
        $llegadasATiempo = DB::table('asistencias')
            ->join('users', 'asistencias.user_id', '=', 'users.id')
            ->join('jornadas', 'users.jornada_id', '=', 'jornadas.id')
            ->whereDate('asistencias.fecha_hora', '>=', $inicioMes)
            ->where('asistencias.tipo', 'entrada')
            ->whereRaw("TIME(fecha_hora) <= TIME(ADDTIME(jornadas.hora_entrada, SEC_TO_TIME(jornadas.tolerancia * 60)))")
            ->count();
        
        // Llegadas tarde
        $llegadasTarde = $totalAsistencias - $llegadasATiempo;
        
        return [
            'a_tiempo' => $llegadasATiempo,
            'tarde' => $llegadasTarde,
            'porcentaje_puntualidad' => $totalAsistencias > 0 
                ? round(($llegadasATiempo / $totalAsistencias) * 100) 
                : 0
        ];
    }

    public function verificarAsistencia(Request $request)
    {
        $request->validate([
            'documento_identidad' => 'required|string',
        ]);

        $user = User::where('documento_identidad', $request->documento_identidad)
            ->where('rol', 'aprendiz')
            ->with([
                'programaFormacion',
                'jornada',
                'devices' => function($query) {
                    $query->latest()->first();
                }
            ])
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Aprendiz no encontrado'], 404);
        }

        // Obtener todas las asistencias del día para el usuario
        $asistenciasHoy = Asistencia::where('user_id', $user->id)
            ->whereDate('fecha_hora', now()->setTimezone('America/Bogota')->format('Y-m-d'))
            ->orderBy('fecha_hora', 'desc')
            ->get();

        // Contar entradas y salidas
        $entradasHoy = $asistenciasHoy->where('tipo', 'entrada')->count();
        $salidasHoy = $asistenciasHoy->where('tipo', 'salida')->count();

        // Si ya tiene entrada y salida, no puede registrar más
        if ($entradasHoy >= 1 && $salidasHoy >= 1) {
            return response()->json([
                'user' => $user,
                'puede_registrar_entrada' => false,
                'puede_registrar_salida' => false,
                'mensaje' => 'Ya completó los registros de entrada y salida para hoy',
                'asistencias_hoy' => $asistenciasHoy,
                'estadisticas' => [
                    'entradas_totales' => $entradasHoy,
                    'salidas_totales' => $salidasHoy,
                    'hora_jornada' => $user->jornada ? $user->jornada->hora_entrada : null,
                    'tolerancia' => $user->jornada ? $user->jornada->tolerancia : null
                ]
            ]);
        }

        // Solo puede registrar entrada si no tiene entradas hoy
        $puedeRegistrarEntrada = $entradasHoy === 0;
        
        // Solo puede registrar salida si tiene una entrada y no tiene salidas
        $puedeRegistrarSalida = $entradasHoy === 1 && $salidasHoy === 0;

        // Obtener la última asistencia registrada (histórico)
        $ultimaAsistencia = Asistencia::where('user_id', $user->id)
            ->orderBy('fecha_hora', 'desc')
            ->first();

        return response()->json([
            'user' => $user,
            'puede_registrar_entrada' => $puedeRegistrarEntrada,
            'puede_registrar_salida' => $puedeRegistrarSalida,
            'asistencias_hoy' => $asistenciasHoy,
            'ultima_asistencia' => $ultimaAsistencia,
            'estadisticas' => [
                'entradas_totales' => $entradasHoy,
                'salidas_totales' => $salidasHoy,
                'hora_jornada' => $user->jornada ? $user->jornada->hora_entrada : null,
                'tolerancia' => $user->jornada ? $user->jornada->tolerancia : null
            ]
        ]);
    }

    public function buscarPorQR(Request $request)
    {
        try {
            $request->validate([
                'qr_code' => 'required|string',
            ]);

            $codigo = trim($request->qr_code);
            Log::info('Código recibido en buscarPorQR: ' . $codigo);

            // Primero intenta buscar por QR code (para QRs generados por el sistema)
            $user = User::where('qr_code', $codigo)
                ->where('rol', 'aprendiz')
                ->with(['programaFormacion', 'devices', 'jornada'])
                ->first();

            Log::info('Búsqueda por QR: ' . ($user ? 'Usuario encontrado' : 'Usuario no encontrado'));

            // Si no encuentra por QR, intenta buscar por documento de identidad
            if (!$user) {
                // Limpiamos el código para asegurarnos que solo contiene números
                $documento = preg_replace('/[^0-9]/', '', $codigo);
                Log::info('Intentando con documento limpio: ' . $documento);
                
                if (!empty($documento)) {
                    $user = User::where('documento_identidad', $documento)
                        ->where('rol', 'aprendiz')
                        ->with(['programaFormacion', 'devices', 'jornada'])
                        ->first();
                    
                    Log::info('Búsqueda por documento: ' . ($user ? 'Usuario encontrado' : 'Usuario no encontrado'));
                } else {
                    Log::warning('El código no contiene números: ' . $codigo);
                    return response()->json([
                        'error' => 'El código no contiene un documento válido',
                        'codigo_recibido' => $codigo
                    ], 404);
                }
            }

            if (!$user) {
                Log::warning('No se encontró usuario para el código/documento: ' . $codigo);
                return response()->json([
                    'error' => 'Aprendiz no encontrado',
                    'codigo_recibido' => $codigo,
                    'documento_limpio' => $documento ?? null
                ], 404);
            }

            Log::info('Usuario encontrado exitosamente: ' . $user->nombres_completos);
            return $this->verificarAsistencia(new Request(['documento_identidad' => $user->documento_identidad]));

        } catch (\Exception $e) {
            Log::error('Error en buscarPorQR: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'error' => 'Error al procesar el código',
                'mensaje' => $e->getMessage(),
                'codigo_recibido' => $request->qr_code ?? null
            ], 500);
        }
    }

    public function registrarAsistencia(Request $request)
    {
        $request->validate([
            'documento_identidad' => 'required|string',
            'tipo' => 'required|in:entrada,salida',
        ]);

        $user = User::where('documento_identidad', $request->documento_identidad)
            ->where('rol', 'aprendiz')
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Aprendiz no encontrado'], 404);
        }

        // Obtener asistencias del día
        $asistenciasHoy = Asistencia::where('user_id', $user->id)
            ->whereDate('fecha_hora', now()->setTimezone('America/Bogota')->format('Y-m-d'))
            ->get();

        $entradasHoy = $asistenciasHoy->where('tipo', 'entrada')->count();
        $salidasHoy = $asistenciasHoy->where('tipo', 'salida')->count();

        // Validaciones específicas según el tipo de registro
        if ($request->tipo === 'entrada') {
            if ($entradasHoy >= 1) {
                return response()->json([
                    'error' => 'Ya registró su entrada para el día de hoy'
                ], 400);
            }
        } else { // tipo === 'salida'
            if ($entradasHoy === 0) {
                return response()->json([
                    'error' => 'Debe registrar una entrada antes de registrar una salida'
                ], 400);
            }
            if ($salidasHoy >= 1) {
                return response()->json([
                    'error' => 'Ya registró su salida para el día de hoy'
                ], 400);
            }
        }

        try {
            $asistencia = Asistencia::create([
                'user_id' => $user->id,
                'tipo' => $request->tipo,
                'fecha_hora' => now()->setTimezone('America/Bogota'),
                'registrado_por' => Auth::id(),
            ]);

            return response()->json([
                'message' => 'Asistencia registrada correctamente',
                'asistencia' => $asistencia
            ]);
        } catch (\Exception $e) {
            Log::error('Error al registrar asistencia: ' . $e->getMessage());
            return response()->json([
                'error' => 'Error al registrar la asistencia'
            ], 500);
        }
    }
}
