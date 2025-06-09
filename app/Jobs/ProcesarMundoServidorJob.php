<?php

namespace App\Jobs;

use App\Models\Servidor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use ZipArchive;
use Illuminate\Support\Facades\DB;
use PharData;
use Aternos\Thanos\Thanos;
use Aternos\Thanos\World\AnvilWorld;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\AnonymousNotifiable;
use App\Notifications\WorldStatusNotification;
use Exception;
use Symfony\Component\Process\Process;
use Throwable;
use Log;

class ProcesarMundoServidorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ?Servidor $servidor = null;
    protected ?string $originalZipPublicPath = null;
    protected ?string $extractionPath = null;
    protected ?string $optimizedWorldPath = null;

    /**
     * El número de segundos que el job puede ejecutarse antes de que se agote el tiempo de espera.
     * Ajusta este valor según lo que consideres un tiempo máximo razonable para el procesamiento.
     */
    public int $timeout = 300; // 5 Minutos

    /**
     * Create a new job instance.
     *
     */
    public function __construct()
    {
    }

    /**
     * Encuentra el directorio principal del mundo dentro de una ruta de extracción.
     */
    private function findWorldDirectory(string $extractionPath): ?string
    {
        if (File::exists($extractionPath . DIRECTORY_SEPARATOR . 'level.dat')) {
            return $extractionPath;
        }

        $items = File::directories($extractionPath);
        if (count($items) === 1) {
            $potentialWorldPath = $items[0];
            if (File::exists($potentialWorldPath . DIRECTORY_SEPARATOR . 'level.dat')) {
                return $potentialWorldPath;
            }
        }
        
        foreach ($items as $dir) {
            if (File::exists($dir . DIRECTORY_SEPARATOR . 'level.dat')) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * Limpia el archivo ZIP original de la carpeta 'mundos_pendientes'.
     */
    private function cleanupOriginalZip(): void
    {
        $jobId = $this->job ? $this->job->getJobId() : 'N/A_JOB_ID_CLEANUP';
        $servidorId = $this->servidor ? $this->servidor->id : 'N/A_SERVIDOR_ID_CLEANUP';

        if ($this->originalZipPublicPath && Storage::disk('public')->exists($this->originalZipPublicPath)) {
            try {
                Storage::disk('public')->delete($this->originalZipPublicPath);
                Log::info("Job ID: {$jobId} - Servidor ID {$servidorId}: Archivo original {$this->originalZipPublicPath} eliminado de 'mundos_pendientes' debido a error.");
            } catch (Throwable $e) {
                Log::error("Job ID: {$jobId} - Servidor ID {$servidorId}: Error al intentar eliminar el archivo original {$this->originalZipPublicPath} de 'mundos_pendientes': " . $e->getMessage());
            }
        } else if ($this->originalZipPublicPath) {
            Log::info("Job ID: {$jobId} - Servidor ID {$servidorId}: Archivo original {$this->originalZipPublicPath} no encontrado en 'mundos_pendientes' para eliminar (posiblemente ya procesado/eliminado o nunca existió allí con este nombre).");
        }
    }
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            if (Servidor::where('estado', 'procesando')->exists()) {
                Log::info("Otro servidor ya está en estado 'procesando'. Este job (ID: {$this->job->getJobId()}) se reintentará más tarde.");
                $this->release(60);
                return;
            }

            DB::transaction(function () {
                $servidorParaProcesar = Servidor::where('estado', 'pendiente')
                                                ->orderBy('fecha_creacion', 'asc')
                                                //->lockForUpdate()
                                                ->first();

                if ($servidorParaProcesar) {
                    $servidorParaProcesar->estado = 'procesando';
                    $servidorParaProcesar->save();
                    $this->servidor = $servidorParaProcesar;
                }
            });

            if (!$this->servidor) {
                Log::info("No hay servidores en estado 'pendiente' para procesar en este momento (Job ID: {$this->job->getJobId()}).");
                return;
            }
            $message = "El mundo '{$this->servidor->id}' esta siendo procesado.";
            Notification::send(new AnonymousNotifiable(), new WorldStatusNotification($this->servidor->id, 'procesando', $message));

            $this->originalZipPublicPath = $this->servidor->ruta; // Ej: 'mundos_pendientes/world_xyz.zip' o 'mundos_pendientes/world_xyz.tar.gz'
            Log::info("Job ID: {$this->job->getJobId()} - Iniciando procesamiento para servidor ID: {$this->servidor->id}, ruta original archivo: {$this->originalZipPublicPath}");

            $originalZipBaseName = basename($this->originalZipPublicPath);
            $originalFileNameWithoutExt = pathinfo($originalZipBaseName, PATHINFO_FILENAME);
            $uniqueId = Str::random(10);
            $this->extractionPath = storage_path('app/temp_job_extractions/' . $this->servidor->id . '_' . $uniqueId);
            $this->optimizedWorldPath = storage_path('app/temp_job_optimized/' . $this->servidor->id . '_' . $uniqueId);
            
            $finalOptimizedZipDir = 'mundos_procesados';
            $finalOptimizedZipName = Str::slug($originalFileNameWithoutExt) . '_compressed.zip';
            $finalOptimizedZipPublicPath = $finalOptimizedZipDir . '/' . $finalOptimizedZipName;

            try {
                if (!Storage::disk('public')->exists($this->originalZipPublicPath)) {
                    throw new Exception("El archivo original '{$this->originalZipPublicPath}' no se encontró en el disco público.");
                }
                $fullPathOriginalZip = Storage::disk('public')->path($this->originalZipPublicPath);

                File::makeDirectory($this->extractionPath, 0755, true, true);
                File::makeDirectory($this->optimizedWorldPath, 0755, true, true);

                $fileExtension = '';
                $currentPathForExtDetection = $this->originalZipPublicPath;
                $lowerPathForExtDetection = strtolower($currentPathForExtDetection);

                if (Str::endsWith($lowerPathForExtDetection, '.tar.gz')) {
                    $fileExtension = 'tar.gz';
                } elseif (Str::endsWith($lowerPathForExtDetection, '.tar')) {
                    $fileExtension = 'tar';
                } elseif (Str::endsWith($lowerPathForExtDetection, '.zip')) {
                    $fileExtension = 'zip';
                } else {
                    Log::error("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Path '{$lowerPathForExtDetection}' no tiene ningun sufijo de extension de archivo soportado.");
                    throw new Exception("Tipo de archivo no soportado para el archivo: {$currentPathForExtDetection}");
                }
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Extrayendo archivo {$fullPathOriginalZip} (tipo: {$fileExtension}) a {$this->extractionPath}");
                if ($fileExtension === 'zip') {
                    $zip = new ZipArchive;
                    if ($zip->open($fullPathOriginalZip) !== TRUE) {
                        throw new Exception("No se pudo abrir el archivo ZIP: {$fullPathOriginalZip}");
                    }
                    $zip->extractTo($this->extractionPath);
                    $zip->close();
                } elseif ($fileExtension === 'tar' || $fileExtension === 'tar.gz') {
                    $phar = new PharData($fullPathOriginalZip);
                    $phar->extractTo($this->extractionPath, null, true);
                } else {
                    throw new Exception("Extracción no implementada para el tipo de archivo: {$fileExtension}");
                }
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Archivo original extraído a {$this->extractionPath}");
                $worldSourcePath = $this->findWorldDirectory($this->extractionPath);
                if (!$worldSourcePath) {
                    throw new Exception("No se pudo encontrar el directorio del mundo (con level.dat)."); // en: {$this->extractionPath}
                }
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Directorio del mundo encontrado en {$worldSourcePath}");

                $thanosCommand = [
                    'php', // O la ruta completa de PHP CLI si es necesario
                    base_path('thanos/thanos.php'), // Ruta al script CLI de Thanos
                    $worldSourcePath,
                    $this->optimizedWorldPath
                ];

                Log::info("Job ID: {$this->job->getJobId()} - Ejecutando comando Thanos: " . implode(' ', $thanosCommand));

                $process = new Process($thanosCommand);
                $process->setTimeout(null); // Permitir que el proceso de Thanos se ejecute sin el timeout de Symfony Process, el timeout del job de Laravel lo controlará.
                $process->setWorkingDirectory(base_path()); // Ejecutar desde la raíz del proyecto

                $process->run(); // Ejecución síncrona

                if (!$process->isSuccessful()) {
                    throw new Exception("El proceso de Thanos falló: " . $process->getErrorOutput() . " Salida: " . $process->getOutput());
                }
                // Nos conformaremos con el log de éxito o la ausencia de error porque no hay respuesta de chunks.
                $removedChunks = "N/A (ejecutado como proceso externo)"; // O intenta parsear la salida si es necesario
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Thanos optimizó el mundo, removió {$removedChunks} chunks. Salida en {$this->optimizedWorldPath}");

                if (!File::exists($this->optimizedWorldPath) || count(File::allFiles($this->optimizedWorldPath)) === 0) {
                    throw new Exception("La optimización con Thanos no produjo un mundo de salida o el directorio de salida está vacío en {$this->optimizedWorldPath}.");
                }

                Storage::disk('public')->makeDirectory($finalOptimizedZipDir);
                $fullPathFinalOptimizedZip = Storage::disk('public')->path($finalOptimizedZipPublicPath);

                if ($worldSourcePath === $this->extractionPath) {
                    $baseName = $originalFileNameWithoutExt;

                    if (Str::contains($baseName, '_')) {
                        $potentialFolderName = Str::beforeLast($baseName, '_');
                        if ($potentialFolderName !== '') {
                            $outputZipRootFolderName = $potentialFolderName;
                        } else {
                            $outputZipRootFolderName = $baseName;
                        }
                    } else {
                        $outputZipRootFolderName = $baseName;
                    }
                } else {
                    $outputZipRootFolderName = basename($worldSourcePath);
                }
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Nombre de la carpeta raíz para el ZIP de salida determinado: '{$outputZipRootFolderName}'");

                $zipOutput = new ZipArchive();
                if ($zipOutput->open($fullPathFinalOptimizedZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                    throw new Exception("No se pudo crear el archivo ZIP de salida optimizado en: {$fullPathFinalOptimizedZip}");
                }
                $filesToZip = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($this->optimizedWorldPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($filesToZip as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePathInOptimizedDir = substr($filePath, strlen($this->optimizedWorldPath) + 1);
                        $pathInZip = $outputZipRootFolderName . DIRECTORY_SEPARATOR . $relativePathInOptimizedDir;
                        $zipOutput->addFile($filePath, $pathInZip);
                    }
                }
                $zipOutput->close();
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Mundo optimizado comprimido en {$finalOptimizedZipPublicPath} con estructura interna bajo '{$outputZipRootFolderName}'.");
                $tamanoFinalEnBytes = Storage::disk('public')->size($finalOptimizedZipPublicPath);
                $tamanoFinalEnMB = $tamanoFinalEnBytes / (1024 * 1024);

                $this->servidor->ruta = $finalOptimizedZipPublicPath;
                $this->servidor->estado = 'listo';
                $this->servidor->fecha_expiracion = now()->addHours(1);
                $this->servidor->tamano_final = $tamanoFinalEnMB;
                $this->servidor->save();
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id} actualizado. Nueva ruta: {$finalOptimizedZipPublicPath}, Estado: listo, Tamaño final: {$tamanoFinalEnMB} MB.");

                $message = "El mundo '{$this->servidor->id}' ha sido procesado.";
                Notification::send(new AnonymousNotifiable(), new WorldStatusNotification($this->servidor->id, 'listo', $message));

                // Eliminar el archivo original de 'mundos_pendientes' solo después de un procesamiento exitoso
                if ($this->originalZipPublicPath && Storage::disk('public')->exists($this->originalZipPublicPath)) {
                    Storage::disk('public')->delete($this->originalZipPublicPath);
                    Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Archivo original {$this->originalZipPublicPath} eliminado de 'mundos_pendientes' tras éxito.");
                }

            } catch (Exception $processingException) {
                Log::error("Job ID: {$this->job->getJobId()} - Error durante el procesamiento del Servidor ID {$this->servidor->id}: " . $processingException->getMessage());
                $message = "El mundo '{$this->servidor->id}' ha tenido un error: ".$processingException->getMessage();
                Notification::send(new AnonymousNotifiable(), new WorldStatusNotification($this->servidor->id, 'error', $message));

                $this->servidor->estado = 'error_procesamiento';
                $this->servidor->ruta = null;
                $this->servidor->save();
                $this->cleanupOriginalZip();
                throw $processingException;
            } finally {
                $jobIdForFinallyLog = $this->job ? $this->job->getJobId() : 'N/A_JOB_ID';
                $servidorIdForFinallyLog = $this->servidor ? $this->servidor->id : 'N/A_SERVIDOR_ID';
                
                if ($this->extractionPath && File::exists($this->extractionPath)) File::deleteDirectory($this->extractionPath);
                if ($this->optimizedWorldPath && File::exists($this->optimizedWorldPath)) File::deleteDirectory($this->optimizedWorldPath);
                Log::info("Job ID: {$jobIdForFinallyLog} - Servidor ID {$servidorIdForFinallyLog}: Limpieza de directorios temporales (desde finally) completada.");
            }

        } catch (Exception $e) {
            $jobIdForLog = $this->job ? $this->job->getJobId() : 'N/A_JOB_ID';
            $servidorIdForLog = $this->servidor ? $this->servidor->id : 'N/A_SERVIDOR_ID';
            Log::error("Job ID: {$jobIdForLog} - Error general en el job para Servidor ID {$servidorIdForLog}: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
            
            if ($this->servidor && $this->servidor->estado !== 'error_procesamiento') {
                $this->servidor->estado = 'error_procesamiento_no_encontrado';
                $this->servidor->ruta = null;
                $this->servidor->save();
                $this->cleanupOriginalZip();
            }
            throw $e;
        }
    }

    /**
     * Manejar un fallo del job.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $jobId = $this->job ? $this->job->getJobId() : 'N/A_JOB_ID_IN_FAILED';
        $servidorId = $this->servidor ? $this->servidor->id : 'N/A_SERVIDOR_ID_IN_FAILED';

        Log::error("Job ID: {$jobId} HA FALLADO para Servidor ID: {$servidorId}. Excepción: " . $exception->getMessage());

        // Si el servidor fue asignado y aún está en estado 'procesando',
        // actualízalo a un estado de error para que no bloquee la cola.
        if ($this->servidor && $this->servidor->estado === 'procesando') {
            $this->servidor->estado = 'error_job_timeout_or_failed'; // O un estado más específico
            $this->servidor->ruta = null; // Asegurarse que la ruta no apunte a un archivo procesado inexistente
            $this->servidor->save();
            Log::info("Job ID: {$jobId} - Servidor ID {$this->servidor->id} marcado como 'error_job_timeout_or_failed' debido a fallo del job.");
        }

        // Limpieza de archivos y directorios temporales y el archivo original
        $this->cleanupOriginalZip();

        try {
            if ($this->extractionPath && File::exists($this->extractionPath)) {
                Log::info("Job ID: {$jobId} - Servidor ID: {$servidorId} - Attempting cleanup of extraction path in failed(): {$this->extractionPath}");
                File::deleteDirectory($this->extractionPath);
            }
        } catch (Throwable $e) {
            Log::error("Job ID: {$jobId} - Servidor ID: {$servidorId} - Error during cleanup of extractionPath in failed(): " . $e->getMessage());
        }

        try {
            if ($this->optimizedWorldPath && File::exists($this->optimizedWorldPath)) {
                Log::info("Job ID: {$jobId} - Servidor ID: {$servidorId} - Attempting cleanup of optimized world path in failed(): {$this->optimizedWorldPath}");
                File::deleteDirectory($this->optimizedWorldPath);
            }
        } catch (Throwable $e) {
            Log::error("Job ID: {$jobId} - Servidor ID: {$servidorId} - Error during cleanup of optimizedWorldPath in failed(): " . $e->getMessage());
        }
    }
}