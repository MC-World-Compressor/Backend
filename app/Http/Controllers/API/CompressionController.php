<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Aternos\Thanos\Thanos;

class CompressionController extends Controller
{
    public function compressWorld(Request $request)
    {
        $request->validate([
            'zipfile' => 'required|file|mimes:zip',
        ]);

        $file = $request->file('zipfile');

        // Guardar temporalmente el archivo subido
        $path = $file->store('temp');

        // Instancia de Thanos para comprimir el mundo (ajusta según la documentación del paquete)
        $thanos = new Thanos();

        // Suponiendo que el método compressWorld recibe la ruta y devuelve la ruta del zip comprimido
        $compressedPath = $thanos->compressWorld(storage_path('app/' . $path));

        // Generar URL para descargar el archivo comprimido
        $downloadUrl = url('storage/' . basename($compressedPath));

        return response()->json([
            'download_url' => $downloadUrl,
        ]);
    }
}
