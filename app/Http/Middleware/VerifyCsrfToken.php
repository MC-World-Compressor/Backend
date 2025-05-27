<?php

namespace App\Http\Middleware;

// Importa la clase base del middleware CSRF de Laravel
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

// Asegúrate de que tu clase extienda la clase base de Laravel
class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be exempted from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/*',
    ];
}
