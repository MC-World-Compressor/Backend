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
use Aternos\Thanos\Thanos;
use Aternos\Thanos\World\AnvilWorld;
use Exception;
use Throwable;
use Log;

class ProcesarMundoServidorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ?Servidor $servidor = null;

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
                                                ->orderBy('created_at', 'asc') // Usar created_at
                                                ->lockForUpdate()
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

            Log::info("Job ID: {$this->job->getJobId()} - Iniciando procesamiento para servidor ID: {$this->servidor->id}, ruta actual: {$this->servidor->ruta}");

            $originalZipPublicPath = $this->servidor->ruta; // e.g., 'mundos_pendientes/world_xyz.zip'
            $originalZipBaseName = basename($originalZipPublicPath);
            $originalFileNameWithoutExt = pathinfo($originalZipBaseName, PATHINFO_FILENAME);

            $uniqueId = Str::random(10);
            $extractionPath = storage_path('app/temp_job_extractions/' . $this->servidor->id . '_' . $uniqueId);
            $optimizedWorldPath = storage_path('app/temp_job_optimized/' . $this->servidor->id . '_' . $uniqueId);
            
            $finalOptimizedZipDir = 'mundos_procesados';
            $finalOptimizedZipName = Str::slug($originalFileNameWithoutExt) . '_optimizado_' . $uniqueId . '.zip';
            $finalOptimizedZipPublicPath = $finalOptimizedZipDir . '/' . $finalOptimizedZipName;

            try {
                if (!Storage::disk('public')->exists($originalZipPublicPath)) {
                    throw new Exception("El archivo ZIP original '{$originalZipPublicPath}' no se encontró en el disco público.");
                }
                $fullPathOriginalZip = Storage::disk('public')->path($originalZipPublicPath);

                File::makeDirectory($extractionPath, 0755, true, true);
                File::makeDirectory($optimizedWorldPath, 0755, true, true);

                $zip = new ZipArchive;
                if ($zip->open($fullPathOriginalZip) !== TRUE) {
                    throw new Exception("No se pudo abrir el archivo ZIP original: {$fullPathOriginalZip}");
                }
                $zip->extractTo($extractionPath);
                $zip->close();
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: ZIP original extraído a {$extractionPath}");

                $worldSourcePath = $this->findWorldDirectory($extractionPath);
                if (!$worldSourcePath) {
                    throw new Exception("No se pudo encontrar el directorio del mundo (con level.dat) dentro de la extracción en: {$extractionPath}");
                }
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Directorio del mundo encontrado en {$worldSourcePath}");

                Log::info("world path: ".$worldSourcePath." y oworldpath: ".$optimizedWorldPath);

                $world = new AnvilWorld($worldSourcePath, $optimizedWorldPath);
                $thanos = new Thanos();
                $thanos->setMinInhabitedTime(1); // Elimina chunks con InhabitedTime = 0
                Log::info("1");
                $removedChunks = $thanos->snap($world);
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Thanos optimizó el mundo, removió {$removedChunks} chunks. Salida en {$optimizedWorldPath}");

                if (!File::exists($optimizedWorldPath) || count(File::allFiles($optimizedWorldPath)) === 0) {
                    throw new Exception("La optimización con Thanos no produjo un mundo de salida o el directorio de salida está vacío en {$optimizedWorldPath}.");
                }

                Storage::disk('public')->makeDirectory($finalOptimizedZipDir);
                $fullPathFinalOptimizedZip = Storage::disk('public')->path($finalOptimizedZipPublicPath);

                $zipOutput = new ZipArchive();
                if ($zipOutput->open($fullPathFinalOptimizedZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                    throw new Exception("No se pudo crear el archivo ZIP de salida optimizado en: {$fullPathFinalOptimizedZip}");
                }
                $filesToZip = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($optimizedWorldPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($filesToZip as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($optimizedWorldPath) + 1);
                        $zipOutput->addFile($filePath, $relativePath);
                    }
                }
                $zipOutput->close();
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Mundo optimizado comprimido en {$finalOptimizedZipPublicPath}");

                $this->servidor->ruta = $finalOptimizedZipPublicPath;
                $this->servidor->estado = 'listo';
                $this->servidor->fecha_expiracion = now()->addHours(1);
                $this->servidor->save();
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id} actualizado. Nueva ruta: {$finalOptimizedZipPublicPath}, Estado: listo.");

                Storage::disk('public')->delete($originalZipPublicPath);
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: ZIP original {$originalZipPublicPath} eliminado.");

            } catch (Exception $processingException) {
                Log::error("Job ID: {$this->job->getJobId()} - Error durante el procesamiento del Servidor ID {$this->servidor->id}: " . $processingException->getMessage());
                $this->servidor->estado = 'error_procesamiento';
                $this->servidor->save();
                throw $processingException;
            } finally {
                if (File::exists($extractionPath)) File::deleteDirectory($extractionPath);
                if (File::exists($optimizedWorldPath)) File::deleteDirectory($optimizedWorldPath);
                Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id}: Limpieza de directorios temporales completada.");
            }

        } catch (Exception $e) {
            $jobIdForLog = $this->job ? $this->job->getJobId() : 'N/A_JOB_ID';
            $servidorIdForLog = $this->servidor ? $this->servidor->id : 'N/A_SERVIDOR_ID';
            Log::error("Job ID: {$jobIdForLog} - Error general en el job para Servidor ID {$servidorIdForLog}: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
            
            if ($this->servidor && $this->servidor->estado !== 'error_procesamiento') {
                $this->servidor->estado = 'error_procesamiento_no_encontrado';
                $this->servidor->save();
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
        Log::error("Job ID: {$this->job->getJobId()} HA FALLADO. Excepción: " . $exception->getMessage());

        // Si el servidor fue asignado y aún está en estado 'procesando',
        // actualízalo a un estado de error para que no bloquee la cola.
        if ($this->servidor && $this->servidor->estado === 'procesando') {
            $this->servidor->estado = 'error_job_timeout_or_failed'; // O un estado más específico
            // Podrías querer guardar el mensaje de la excepción si tienes un campo para ello
            // $this->servidor->error_message = $exception->getMessage();
            $this->servidor->save();
            Log::info("Job ID: {$this->job->getJobId()} - Servidor ID {$this->servidor->id} marcado como 'error_job_timeout_or_failed' debido a fallo del job.");
        }
    }
}