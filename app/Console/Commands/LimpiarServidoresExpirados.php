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

class LimpiarServidoresExpirados extends Command
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
    protected $description = 'Elimina los archivos de mundos de servidores expirados, vacía su ruta y actualiza su estado a "expirado".';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando la limpieza de servidores expirados...');
        $ahora = now();

        $servidoresExpirados = Servidor::where('fecha_expiracion', '<=', $ahora)
                                       ->where('estado', '!=', 'expirado')
                                       ->get();

        if ($servidoresExpirados->isEmpty()) {
            $this->info('No hay servidores expirados para limpiar en este momento.');
            return Command::SUCCESS;
        }

        $this->info("Se encontraron {$servidoresExpirados->count()} servidores expirados para procesar.");

        foreach ($servidoresExpirados as $servidor) {
            $this->line("Procesando Servidor ID: {$servidor->id} (Estado actual: {$servidor->estado}, Ruta: {$servidor->ruta}, Expira: {$servidor->fecha_expiracion})");

            try {
                if (!empty($servidor->ruta)) {
                    if (Storage::disk('public')->exists($servidor->ruta)) {
                        Storage::disk('public')->delete($servidor->ruta);
                        $this->info("-> Archivo '{$servidor->ruta}' eliminado del disco 'public'.");
                    } else {
                        $this->warn("-> Archivo '{$servidor->ruta}' no encontrado en el disco 'public', pero se procederá a marcar como expirado.");
                    }
                } else {
                    $this->info("-> El servidor no tiene una ruta de archivo especificada.");
                }

                $servidor->ruta = null;
                $servidor->estado = 'expirado';
                $servidor->save();

                $this->info("-> Servidor ID: {$servidor->id} actualizado a estado 'expirado' y ruta vaciada.");
                Log::info("Servidor ID {$servidor->id} marcado como expirado.");
                $message = "El mundo '{$servidor->id}' se ha expirado.";
                Notification::send(new AnonymousNotifiable(), new WorldStatusNotification($servidor->id, 'expirado', $message));

            } catch (Exception $e) {
                $this->error("-> Error al procesar servidor ID: {$servidor->id}. Mensaje: " . $e->getMessage());
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

        $this->info('Limpieza de servidores expirados completada.');
        return Command::SUCCESS;
    }
}
