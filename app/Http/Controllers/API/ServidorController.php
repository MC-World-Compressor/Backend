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
        if ($request->hasFile('mundo_comprimido')) {
            return $this->procesarArchivoCompleto($request);
        } elseif ($request->has('chunk')) {
            return $this->procesarChunk($request);
        } elseif ($request->has('filename') && $request->has('total_chunks')) {
            return $this->iniciarSubidaChunked($request);
        } else {
            return response()->json(['error' => 'Parámetros de subida inválidos'], 400);
        }
    }

    private function iniciarSubidaChunked(Request $request){
        $request->validate([
            'filename' => 'required|string',
            'total_chunks' => 'required|integer|min:1',
            'file_size' => 'required|integer|min:1'
        ]);

        $uniqueId = Str::random(10);
        $originalName = $request->input('filename');
        $fileInfo = pathinfo($originalName);
        $extension = strtolower($fileInfo['extension'] ?? '');
        
        if (!in_array($extension, ['zip', 'tar', 'gz'])) {
            return response()->json(['error' => 'Tipo de archivo no permitido'], 400);
        }

        return response()->json([
            'upload_id' => $uniqueId,
            'message' => 'Subida iniciada'
        ]);
    }

    private function procesarChunk(Request $request){
        $request->validate([
            'upload_id' => 'required|string',
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1',
            'chunk' => 'required|file'
        ]);

        try {
            $uploadId = $request->input('upload_id');
            $chunkIndex = $request->input('chunk_index');
            $totalChunks = $request->input('total_chunks');
            $chunkFile = $request->file('chunk');

            $tempDir = storage_path('app/temp_uploads/' . $uploadId);
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $chunkFile->move($tempDir, 'chunk_' . $chunkIndex);

            $completedChunks = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                if (file_exists($tempDir . '/chunk_' . $i)) {
                    $completedChunks++;
                }
            }

            if ($completedChunks === $totalChunks) {
                return $this->combinarChunks($uploadId, $totalChunks, $request);
            }

            return response()->json([
                'message' => 'Chunk subido correctamente',
                'progress' => ($completedChunks / $totalChunks) * 100,
                'chunks_completed' => $completedChunks,
                'total_chunks' => $totalChunks
            ]);

        } catch (Exception $e) {
            return response()->json(['error' => 'Error al subir chunk: ' . $e->getMessage()], 500);
        }
    }

    private function combinarChunks($uploadId, $totalChunks, $request){
        try {
            $tempDir = storage_path('app/temp_uploads/' . $uploadId);
            
            // Obtener nombre original del primer chunk (metadata)
            $metadataFile = $tempDir . '/metadata.json';
            $filename = $request->input('filename', 'mundo_' . $uploadId . '.zip');
            
            $fileInfo = pathinfo($filename);
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

            $fileNameToStore = Str::slug($baseNameWithoutAnyExt) . '_' . $uploadId . '.' . $fullExtension;
            Storage::disk('public')->makeDirectory('mundos_pendientes');
            
            $finalPath = storage_path('app/public/mundos_pendientes/' . $fileNameToStore);
            $finalFile = fopen($finalPath, 'wb');
            
            if (!$finalFile) {
                throw new Exception('No se pudo crear el archivo final');
            }

            // Combinar chunks en orden
            $totalSize = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $tempDir . '/chunk_' . $i;
                if (!file_exists($chunkPath)) {
                    throw new Exception("Chunk {$i} no encontrado");
                }
                
                $chunkData = file_get_contents($chunkPath);
                fwrite($finalFile, $chunkData);
                $totalSize += strlen($chunkData);
            }
            
            fclose($finalFile);
            
            $this->limpiarChunksTemporales($tempDir);
            
            $tamanoInicioEnMB = $totalSize / (1024 * 1024);
            
            $servidor = Servidor::create([
                'ruta' => 'mundos_pendientes/' . $fileNameToStore,
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
                'estado' => $servidor->estado,
            ], 201);
            
        } catch (Exception $e) {
            return response()->json(['error' => 'Error al combinar chunks: ' . $e->getMessage()], 500);
        }
    }
    
    private function limpiarChunksTemporales($tempDir){
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($tempDir);
        }
    }

    private function procesarArchivoCompleto(Request $request){
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
