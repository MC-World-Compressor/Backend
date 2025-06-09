<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Subir archivo ZIP</title>

    <!-- Vincular CSS desde public/css/styles.css -->
    <link href="css/styles.css" rel="stylesheet" />
</head>
<body>
    <div class="upload-container">
        <h2>Sube o arrastra tu archivo ZIP</h2>

        {{-- Usar url() helper para generar la URL es más flexible --}}
        <form id="uploadForm" method="POST" action="{{ env('APP_URL') }}/api/subir" enctype="multipart/form-data">
            @csrf
            <div id="dropZone" class="drop-zone">
                <p>Arrastra aquí un archivo ZIP o haz clic para seleccionar</p>
                <input type="file" name="mundo_comprimido" id="mundo_comprimido" accept=".zip,.tar,.tar.gz,.gz" hidden />
            </div>
            <button type="submit">Comprimir Mundo</button>
        </form>

        <div id="result"></div>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('mundo_comprimido');
        const form = document.getElementById('uploadForm');
        const resultDiv = document.getElementById('result');

        dropZone.addEventListener('click', () => fileInput.click());

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(form);

            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json' // Importante para recibir errores de validación como JSON
                },
                body: formData
            });

            const data = await response.json();

            if (response.ok) {
                // Ajustado para mostrar más información del servidor subido
                resultDiv.innerHTML = `<p>${data.message}</p>
                                       <p>ID del Servidor: ${data.servidor_id}</p>
                                       <p>Ruta: ${data.ruta_almacenada}</p>
                                       <p>Enlace de descarga: <a href="${data.download_url}" target="_blank">Descargar aquí</a></p>`;
            } else {
                let errorMessage = data.message || data.error || 'Error al subir el archivo.';
                if (data.errors) { // Para mostrar errores de validación de Laravel
                    errorMessage += '<ul>';
                    for (const key in data.errors) {
                        errorMessage += `<li>${data.errors[key].join(', ')}</li>`;
                    }
                    errorMessage += '</ul>';
                }
                resultDiv.innerHTML = errorMessage; // Usar innerHTML para que las etiquetas <ul><li> se rendericen
            }
        });
    </script>
</body>
</html>
