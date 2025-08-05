<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Servidor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\AnonymousNotifiable;
use App\Notifications\WorldStatusNotification;
use Exception;

class Limpiza extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'servers:cleanup-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Elimina las carpetas y archivos de mundos de servidores expirados, los servidores procesados atascados y chunks basura';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando la limpieza...');
        $ahora = now();

        $servidoresExpirados = Servidor::where('fecha_expiracion', '<=', $ahora)
                                        ->where('estado', '!=', 'expirado')
                                        ->whereNotLike('estado', '%error%')
                                        ->get();

        if ($servidoresExpirados->isEmpty()) {
            $this->info('No hay servidores expirados para limpiar en este momento.');
            return Command::SUCCESS;
        }

        $this->info("Se encontraron {$servidoresExpirados->count()} servidores expirados para procesar.");

        foreach ($servidoresExpirados as $servidor) {
            $this->line("Procesando Servidor expirado con el ID: {$servidor->id} (Estado actual: {$servidor->estado}, Ruta: {$servidor->ruta}, Expira: {$servidor->fecha_expiracion})");

            try {
                if (!empty($servidor->ruta)) {
                    if (Storage::disk('public')->exists($servidor->ruta)) {
                        Storage::disk('public')->delete($servidor->ruta);
                        $this->info("-> Carpeta '{$servidor->ruta}' eliminado del disco 'public'.");
                    } else {
                        $this->warn("-> Carpeta '{$servidor->ruta}' no encontrado en el disco 'public', pero se procederá a marcar como expirado.");
                    }
                } else {
                    $this->info("-> El servidor no tiene una ruta de carpeta especificada.");
                }

                $servidor->ruta = null;
                $servidor->estado = 'expirado';
                $servidor->save();

                $this->info("-> Servidor con el ID: {$servidor->id} actualizado a estado 'expirado' y ruta vaciada.");
                Log::info("Servidor con el ID {$servidor->id} marcado como expirado.");
                $message = "El mundo '{$servidor->id}' se ha expirado.";
                Notification::send(new AnonymousNotifiable(), new WorldStatusNotification($servidor->id, 'expirado', $message));

            } catch (Exception $e) {
                $this->error("-> Error al procesar servidor con el ID: {$servidor->id}. Mensaje: " . $e->getMessage());
                Log::error("Error limpiando servidor expirado ID {$servidor->id}: " . $e->getMessage(), [
                    'servidor_id' => $servidor->id,
                    'ruta_archivo' => $servidor->ruta,
                    'exception' => $e
                ]);
                $message = "El mundo '{$servidor->id}' ha tiene un error al expirarse: ".$e->getMessage();
                Notification::send(new AnonymousNotifiable(), new WorldStatusNotification($servidor->id, 'error', $message));
            }
            $this->line('');
        }


        $fechaLimite = Carbon::now()->subMinutes(15);
        $servidoresAtascados = Servidor::where('estado', 'procesando')
        ->where('fecha_creacion', '<', $fechaLimite)
        ->get();

        if ($servidoresAtascados->isEmpty()) {
            $this->info('No hay servidores atascados para limpiar en este momento.');
            return Command::SUCCESS;
        }

        $this->info("Se ha encontrado un servidor atascado.");

        foreach ($servidoresAtascados as $servidor) {

            $this->line("Procesando Servidor atascado con el ID: {$servidor->id} y ruta: {$servidor->ruta}");

            try {
                if (!empty($servidor->ruta)) {
                    if (Storage::disk('public')->exists('mundos_pendientes/' . $servidor->ruta)) {
                        Storage::disk('public')->delete('mundos_pendientes/' . $servidor->ruta);
                        $this->info("-> Carpeta '{$servidor->ruta}' eliminado del disco 'public/mundos_pendientes'.");
                    } else {
                        $this->warn("-> Carpeta '{$servidor->ruta}' no encontrado en el disco 'public/mundos_pendientes', pero se procederá a marcar como error_procesamiento_timeout.");
                    }
                } else {
                    $this->info("-> El servidor no tiene una ruta de carpeta especificada.");
                }

                $servidor->ruta = null;
                $servidor->estado = 'error_procesamiento_timeout';
                $servidor->save();

                $this->info("-> Servidor con el ID: {$servidor->id} actualizado a estado 'error_procesamiento_timeout' y ruta vaciada.");
                Log::info("Servidor con el ID {$servidor->id} marcado como error_procesamiento_timeout.");
                $message = "El mundo '{$servidor->id}' se ha marcado como error_procesamiento_timeout.";
                Notification::send(new AnonymousNotifiable(), new WorldStatusNotification($servidor->id, 'error', $message));

            } catch (Exception $e) {
                $this->error("-> Error al procesar servidor con el ID: {$servidor->id}. Mensaje: " . $e->getMessage());
                Log::error("Error limpiando servidor expirado ID {$servidor->id}: " . $e->getMessage(), [
                    'servidor_id' => $servidor->id,
                    'ruta_archivo' => $servidor->ruta,
                    'exception' => $e
                ]);
                $message = "El mundo '{$servidor->id}' ha tiene un error al marcarse como erroneo al intentar marcase como: error_procesamiento_timeout: ".$e->getMessage();
                Notification::send(new AnonymousNotifiable(), new WorldStatusNotification($servidor->id, 'error', $message));
            }
            $this->line('');

        }

        $this->info('Limpieza de servidores completada.');
        return Command::SUCCESS;
    }
}
