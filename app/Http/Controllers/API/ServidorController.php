<?php

namespace App\Http\Controllers\API;

use App\Models\Servidor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;
use App\Jobs\ProcesarMundoServidorJob;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\AnonymousNotifiable;
use App\Notifications\WorldStatusNotification;


class ServidorController extends Controller
{
    public function getCola(){
        $cantidad = Servidor::where('estado', 'pendiente')->count();
        return response()->json(['cola' => $cantidad]);
    }

    public function subirMundo(Request $request){
        $request->validate([
            'mundo_comprimido' => 'required|file|mimes:zip,tar,gz|max:4194304', // Max 4096MB
        ]);

        try {
            $storedPath = null;
            $uploadedFile = $request->file('mundo_comprimido');

            if (!$uploadedFile->isValid()) {
                throw new Exception("Error en la subida del archivo ZIP: Código " . $uploadedFile->getError());
            }

            $originalClientName = $uploadedFile->getClientOriginalName();
            $fileInfo = pathinfo($originalClientName);
            $baseNameWithoutAnyExt = $fileInfo['filename'];
            $extension = $fileInfo['extension'];

            $fullExtension = strtolower($extension);
            if ($fullExtension === 'gz' && Str::endsWith(strtolower($baseNameWithoutAnyExt), '.tar')) {
                $fullExtension = 'tar.gz';
                $baseNameWithoutAnyExt = Str::beforeLast($baseNameWithoutAnyExt, '.tar');
            } elseif ($fullExtension === 'bz2' && Str::endsWith(strtolower($baseNameWithoutAnyExt), '.tar')) {
                $fullExtension = 'tar.bz2';
                $baseNameWithoutAnyExt = Str::beforeLast($baseNameWithoutAnyExt, '.tar');
            }

            $uniqueId = Str::random(10);
            $fileNameToStore = Str::slug($baseNameWithoutAnyExt) . '_' . $uniqueId . '.' . $fullExtension;
            Storage::disk('public')->makeDirectory('mundos_pendientes');

            $storedPath = $uploadedFile->storeAs('mundos_pendientes', $fileNameToStore, 'public');

            if (!$storedPath) {
                throw new Exception("No se pudo guardar el archivo del mundo en el servidor.");
            }

            $tamanoEnBytes = $uploadedFile->getSize();
            $tamanoInicioEnMB = $tamanoEnBytes / (1024 * 1024);

            $servidor = Servidor::create([
                'ruta' => $storedPath,
                'estado' => 'pendiente',
                'fecha_expiracion' => now()->addDay(),
                'tamano_inicio' => $tamanoInicioEnMB,
                'ip' => $request->ip(),
            ]);

            $message = "El mundo '{$servidor->id}' ha sido subido y está en cola para ser procesado.";
            Notification::send(new AnonymousNotifiable(), new WorldStatusNotification($servidor->id, 'subido', $message));

            ProcesarMundoServidorJob::dispatch();

            return response()->json([
                'message' => 'Mundo subido con éxito. En cola para la compresion...',
                'servidor_id' => $servidor->id,
                //'ruta_almacenada' => $servidor->ruta, 
                'estado' => $servidor->estado,
                //'download_url' => Storage::disk('public')->url($servidor->ruta)
            ], 201);

        } catch (Exception $e) {
            if (isset($storedPath) && $storedPath && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }
            return response()->json(['error' => 'Ocurrió un error al subir el mundo: ' . $e->getMessage()], 500);
        }
    }

    public function getStatus(Request $request){
        $id = $request->route('id');
        $servidor = Servidor::find($id);

        if (!$servidor) {
            return response()->json(['error' => 'Servidor no encontrado'], 404);
        }

        $responseData = [
            'estado' => $servidor->estado,
        ];

        if ($servidor->estado == 'listo') {
            $responseData['download_url'] = Storage::disk('public')->url($servidor->ruta);
            $responseData['fecha_expiracion'] = $servidor->fecha_expiracion;
            $responseData['fecha_creacion'] = $servidor->fecha_creacion;
            $responseData['tamano_inicio'] = $servidor->tamano_inicio;
            $responseData['tamano_final'] = $servidor->tamano_final;
        } elseif ($servidor->estado == 'pendiente') {
            $servidoresPendientes = Servidor::where('estado', 'pendiente')
                                          ->orderBy('fecha_creacion', 'asc')
                                          ->get();
            $totalEnCola = $servidoresPendientes->count();
            $posicionEnCola = 0;

            foreach ($servidoresPendientes as $index => $s) {
                if ($s->id == $servidor->id) {
                    $posicionEnCola = $index + 1;
                    break;
                }
            }
            $responseData['cola'] = "{$posicionEnCola}/{$totalEnCola}";
        } else if ($servidor->estado == 'expirado' || $servidor->estado == 'procesando') {
            $responseData['fecha_expiracion'] = $servidor->fecha_expiracion;
            $responseData['fecha_creacion'] = $servidor->fecha_creacion;
            $responseData['tamano_inicio'] = $servidor->tamano_inicio;
            $responseData['tamano_final'] = $servidor->tamano_final;
        } else {
            $responseData['error'] = 'El servidor no ha podido procesarse o esta en un estado desconocido.';
        }

        return response()->json($responseData);
    }

}
