<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Aternos\Thanos\Thanos;
use Aternos\Thanos\World\AnvilWorld; // Importar la clase AnvilWorld
use App\Http\Controllers\Controller;
use ZipArchive;
use Exception;

class CompressionController extends Controller
{
    /**
     * Encuentra el directorio principal del mundo dentro de una ruta de extracción.
     * Asume que el mundo está en una subcarpeta o directamente en la raíz de la extracción
     * y contiene un archivo 'level.dat'.
     */
    private function findWorldDirectory(string $extractionPath): ?string
    {
        // Caso 1: level.dat está directamente en la ruta de extracción
        if (File::exists($extractionPath . DIRECTORY_SEPARATOR . 'level.dat')) {
            return $extractionPath;
        }

        // Caso 2: El mundo está en una única subcarpeta
        $items = File::directories($extractionPath);
        if (count($items) === 1) {
            $potentialWorldPath = $items[0];
            if (File::exists($potentialWorldPath . DIRECTORY_SEPARATOR . 'level.dat')) {
                return $potentialWorldPath;
            }
        }
        
        // Caso 3 (más general): Buscar recursivamente o en el primer nivel por level.dat
        // Para simplificar, buscaremos solo en el primer nivel de subdirectorios
        foreach ($items as $dir) {
            if (File::exists($dir . DIRECTORY_SEPARATOR . 'level.dat')) {
                return $dir;
            }
        }

        return null; // No se encontró el directorio del mundo
    }

    public function compressWorld(Request $request)
    {
        $request->validate([
            'zipfile' => 'required|file|mimes:zip',
        ]);

        $uniqueId = Str::random(10);

        $tempUploadPathRelative = null; // Ruta relativa al disco de almacenamiento para la limpieza
        $fullZipPathForOpen = null;    // Ruta absoluta para ZipArchive
        $extractionPath = storage_path('app/temp_extractions/' . $uniqueId);
        $optimizedWorldPath = storage_path('app/temp_optimized/' . $uniqueId);
        $outputZipPath = null;

        try {
            $uploadedFile = $request->file('zipfile');

            if (!$uploadedFile) {
                throw new Exception("No se recibió ningún archivo ZIP. Asegúrese de que el campo del formulario se llame 'zipfile'.");
            }

            if (!$uploadedFile->isValid()) {
                $errorMessage = "Error en la subida del archivo ZIP: ";
                switch ($uploadedFile->getError()) {
                    case UPLOAD_ERR_INI_SIZE:
                        $errorMessage .= "El archivo excede la directiva upload_max_filesize en php.ini.";
                        break;
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMessage .= "El archivo excede la directiva MAX_FILE_SIZE especificada en el formulario HTML.";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errorMessage .= "El archivo solo se subió parcialmente.";
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMessage .= "No se subió ningún archivo (esto no debería ocurrir si !$uploadedFile fue falso).";
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $errorMessage .= "Falta una carpeta temporal en el servidor.";
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $errorMessage .= "No se pudo escribir el archivo en el disco en el servidor (problema de permisos en la carpeta temporal de PHP).";
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $errorMessage .= "Una extensión de PHP detuvo la subida del archivo.";
                        break;
                    default:
                        $errorMessage .= "Error desconocido durante la subida (código: " . $uploadedFile->getError() . ").";
                        break;
                }
                throw new Exception($errorMessage);
            }

            $originalFileName = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);

            // 1. Guardar temporalmente el archivo subido
            // storeAs devuelve la ruta relativa al disco, e.g., 'temp_uploads/randomid.zip'
            $tempUploadPathRelative = $uploadedFile->storeAs('temp_uploads', $uniqueId . '.zip', 'local');

            if (!$tempUploadPathRelative) {
                throw new Exception("No se pudo guardar el archivo ZIP subido en el servidor. Verifique los permisos del directorio 'storage/app/temp_uploads' y la configuración del disco 'local'.");
            }

            // 2. Crear directorios temporales
            File::makeDirectory($extractionPath, 0755, true, true);
            File::makeDirectory($optimizedWorldPath, 0755, true, true);

            // 3. Extraer el ZIP
            $zip = new ZipArchive;
            $fullZipPathForOpen = Storage::disk('local')->path($tempUploadPathRelative);

            if (!File::exists($fullZipPathForOpen)) {
                // Esto sería muy extraño si storeAs tuvo éxito.
                throw new Exception("El archivo ZIP parece haberse guardado (ruta relativa: {$tempUploadPathRelative}) pero no se encuentra en la ruta absoluta esperada: {$fullZipPathForOpen}. Verifique la configuración de almacenamiento.");
            }

            $zipOpenResult = $zip->open($fullZipPathForOpen);
            if ($zipOpenResult !== TRUE) {
                $zipErrorMsg = "No se pudo abrir el archivo ZIP (ruta: {$fullZipPathForOpen}). ";
                switch ($zipOpenResult) {
                    case ZipArchive::ER_EXISTS: $zipErrorMsg .= "El archivo ya existe."; break;
                    case ZipArchive::ER_INCONS: $zipErrorMsg .= "Archivo ZIP inconsistente o dañado."; break;
                    case ZipArchive::ER_INVAL: $zipErrorMsg .= "Argumento inválido."; break;
                    case ZipArchive::ER_MEMORY: $zipErrorMsg .= "Fallo de memoria."; break;
                    case ZipArchive::ER_NOENT: $zipErrorMsg .= "El archivo no existe (a pesar de la verificación previa)."; break;
                    case ZipArchive::ER_NOZIP: $zipErrorMsg .= "No es un archivo ZIP válido."; break;
                    case ZipArchive::ER_OPEN: $zipErrorMsg .= "No se puede abrir el archivo (podría ser un problema de permisos o el archivo está bloqueado)."; break;
                    case ZipArchive::ER_READ: $zipErrorMsg .= "Error de lectura."; break;
                    case ZipArchive::ER_SEEK: $zipErrorMsg .= "Error de búsqueda."; break;
                    default: $zipErrorMsg .= "Código de error desconocido de ZipArchive: " . $zipOpenResult; break;
                }
                throw new Exception($zipErrorMsg);
            }
            $zip->extractTo($extractionPath);
            $zip->close();

            // 4. Encontrar el directorio del mundo dentro de la extracción
            $worldSourcePath = $this->findWorldDirectory($extractionPath);
            if (!$worldSourcePath) {
                throw new Exception("No se pudo encontrar el directorio del mundo (que contenga level.dat) dentro del ZIP.");
            }

            // 5. Crear instancia de AnvilWorld y Thanos, luego optimizar (snap)
            $world = new AnvilWorld($worldSourcePath, $optimizedWorldPath);
            $thanos = new Thanos();

            // Opcional: Configurar el tiempo mínimo habitado para que un chunk NO sea eliminado.
            // Para eliminar chunks que nunca han sido habitados (InhabitedTime == 0),
            // establece minInhabitedTime a 1 (ya que se eliminan si InhabitedTime < minInhabitedTime).
            // InhabitedTime está en ticks (20 ticks = 1 segundo).
            $thanos->setMinInhabitedTime(1); // Elimina chunks con InhabitedTime = 0

            // El método snap() devuelve el número de chunks eliminados o lanza una excepción en caso de error.
            // No devuelve un booleano de éxito directamente.
            $removedChunks = $thanos->snap($world);
            // Si llegamos aquí sin excepciones, la operación fue exitosa.
            // Puedes loggear $removedChunks si lo deseas: \Log::info("Thanos removió {$removedChunks} chunks.");

            // Verificar si el directorio optimizado se creó y no está vacío (como una comprobación adicional)
            if (!File::exists($optimizedWorldPath) || count(File::allFiles($optimizedWorldPath)) === 0) {
                // Esto podría suceder si Thanos no pudo escribir nada o el mundo de origen estaba vacío/inválido
                // y snap() no lanzó una excepción pero tampoco produjo salida.
                throw new Exception("La optimización con Thanos no produjo un mundo de salida o el directorio de salida está vacío.");
            }

            // 6. Comprimir el mundo optimizado
            $outputZipName = 'optimized_' . Str::slug($originalFileName) . '_' . $uniqueId . '.zip';
            Storage::disk('public')->makeDirectory('compressed_worlds'); // Asegura que el directorio exista
            $outputZipPath = Storage::disk('public')->path('compressed_worlds/' . $outputZipName);

            $zipOutput = new ZipArchive();
            if ($zipOutput->open($outputZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("No se pudo crear el archivo ZIP de salida.");
            }

            $filesToZip = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($optimizedWorldPath),
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

            // 7. Generar URL de descarga
            $downloadUrl = Storage::url('compressed_worlds/' . $outputZipName);

            return response()->json(['download_url' => $downloadUrl]);

        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            // 8. Limpieza
            if ($tempUploadPathRelative) {
                Storage::disk('local')->delete($tempUploadPathRelative);
            }
            if (File::exists($extractionPath)) File::deleteDirectory($extractionPath);
            if (File::exists($optimizedWorldPath)) File::deleteDirectory($optimizedWorldPath);
        }
    }
}
