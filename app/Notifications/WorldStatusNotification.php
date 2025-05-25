<?php

namespace App\Notifications;

use App\Channels\DiscordWebhookChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WorldStatusNotification extends Notification // Opcionalmente implementa ShouldQueue si quieres que la notificación se encolé
{
    use Queueable;

    protected $worldId;
    protected string $statusType;
    protected string $messageText;
    protected ?string $detailsText;

    /**
     * Create a new notification instance.
     *
     * @param mixed $world // Información del mundo (modelo, ID, array)
     * @param string $statusType // El tipo de estado (processing, error, etc.)
     * @param string $messageText // El mensaje principal para la notificación
     * @param string|null $detailsText // Detalles adicionales, como un mensaje de error
     * @return void
     */
    public function __construct($worldId, string $statusType, string $messageText, ?string $detailsText = null)
    {
        $this->worldId = $worldId;
        $this->statusType = $statusType;
        $this->messageText = $messageText;
        $this->detailsText = $detailsText;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [DiscordWebhookChannel::class];
    }

    /**
     * Get the Discord webhook representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDiscordWebhook($notifiable)
    {
        $worldIdentifier = ($this->worldId) ? $this->worldId : 'Desconocido';
        $title = "Actualización del Mundo";
        $color = 0x7289DA; // Discord Blurple (default)

        switch ($this->statusType) {
            case 'subido':
                $title = "📤 Mundo Subido: " . $worldIdentifier;
                $color = 0x3498DB; // Azul
                break;
            case 'procesando':
                $title = "⏳ Procesando Mundo: " . $worldIdentifier;
                $color = 0x2980B9; // Azul más oscuro
                break;
            case 'listo':
                $title = "✅ Mundo Procesado: " . $worldIdentifier;
                $color = 0x2ECC71; // Verde
                break;
            case 'error':
                $title = "❌ Error en Mundo: " . $worldIdentifier;
                $color = 0xE74C3C; // Rojo
                break;
            case 'expirado':
                $title = "⏰ Mundo Expirado: " . $worldIdentifier;
                $color = 0xF39C12; // Naranja
                break;
        }

        $embed = [
            'title' => $title,
            'description' => $this->messageText,
            'color' => $color,
            'timestamp' => now()->toIso8601String(),
            'footer' => ['text' => config('app.name', 'MCCompressor')],
        ];

        if ($this->detailsText) {
            $embed['fields'][] = ['name' => ($this->statusType === 'error' ? 'Detalles del Error' : 'Detalles Adicionales'), 'value' => $this->detailsText, 'inline' => false];
        }

        return ['embeds' => [$embed], 'username' => config('app.name') . ' Notificaciones'];
    }
}