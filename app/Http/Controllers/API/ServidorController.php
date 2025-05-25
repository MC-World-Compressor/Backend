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
            'mundo_comprimido' => 'required|file|mimes:zip|max:2097152', // Max 2048MB
        ]);

        try {
            $storedPath = null;
            $uploadedFile = $request->file('mundo_comprimido');

            if (!$uploadedFile->isValid()) {
                throw new Exception("Error en la subida del archivo ZIP: Código " . $uploadedFile->getError());
            }

            $originalFileName = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
            $uniqueId = Str::random(10);
            $fileNameToStore = Str::slug($originalFileName) . '_' . $uniqueId . '.zip';

            Storage::disk('public')->makeDirectory('mundos_pendientes');

            $storedPath = $uploadedFile->storeAs('mundos_pendientes', $fileNameToStore, 'public');

            if (!$storedPath) {
                throw new Exception("No se pudo guardar el archivo del mundo en el servidor.");
            }

            $servidor = Servidor::create([
                'ruta' => $storedPath,
                'estado' => 'pendiente',
                'fecha_expiracion' => now()->addDay(),
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

        if($servidor->estado == 'listo')
            return response()->json([
                'estado' => $servidor->estado,
                //'ruta' => $servidor->ruta,
                'download_url' => Storage::disk('public')->url($servidor->ruta),
                'fecha_expiracion' => $servidor->fecha_expiracion,
                'fecha_creacion' => $servidor->fecha_creacion,
            ]);
        else if($servidor->estado == 'expirado' || $servidor->estado == 'pendiente')
            return response()->json([
                'estado' => $servidor->estado,]);
        else
            return response()->json(['error' => 'Error al procesar el servidor'], 404);
    }

}
