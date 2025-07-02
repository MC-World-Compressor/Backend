<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Subir archivo ZIP</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

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
        <div id="progress-container" style="display: none;">
            <div class="progress-bar">
                <div id="progress-fill" class="progress-fill"></div>
            </div>
            <p id="progress-text">0%</p>
            <p id="chunk-info"></p>
        </div>
    </div>

    <script src="js/chunked-upload.js"></script>
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

        // Configurar subida por chunks para archivos grandes
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;

            const progressContainer = document.getElementById('progress-container');
            const progressFill = document.getElementById('progress-fill');
            const progressText = document.getElementById('progress-text');
            const chunkInfo = document.getElementById('chunk-info');
            
            // Si el archivo es mayor a 100MB, usar subida por chunks
            if (file.size > 100 * 1024 * 1024) {
                progressContainer.style.display = 'block';
                resultDiv.innerHTML = '<p>Iniciando subida por chunks para archivo grande...</p>';
                
                const uploader = new ChunkedUpload(file, {
                    chunkSize: 50 * 1024 * 1024, // 50MB por chunk?
                    
                    onProgress: (progressData) => {
                        const percent = Math.round(progressData.progress);
                        progressFill.style.width = percent + '%';
                        progressText.textContent = percent + '%';
                        chunkInfo.textContent = `Chunk ${progressData.chunksCompleted} de ${progressData.totalChunks}`;
                    },
                    
                    onComplete: (data) => {
                        progressContainer.style.display = 'none';
                        resultDiv.innerHTML = `
                            <p style="color: green;">${data.message}</p>
                            <p>ID del Servidor: ${data.servidor_id}</p>
                            <p>Estado: ${data.estado}</p>
                            <p>Archivo subido exitosamente por chunks.</p>
                        `;
                    },
                    
                    onError: (error) => {
                        progressContainer.style.display = 'none';
                        resultDiv.innerHTML = `<p style="color: red;">Error: ${error.message}</p>`;
                    }
                });
                
                uploader.upload();
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const file = fileInput.files[0];
            
            // Si el archivo es grande, no usar el formulario tradicional
            if (file && file.size > 100 * 1024 * 1024) {
                resultDiv.innerHTML = '<p>Use el selector de archivos para subir archivos grandes.</p>';
                return;
            }
            
            const formData = new FormData(form);

            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: formData
            });

            const data = await response.json();

            if (response.ok) {
                resultDiv.innerHTML = `
                    <p style="color: green;">${data.message}</p>
                    <p>ID del Servidor: ${data.servidor_id}</p>
                    <p>Estado: ${data.estado}</p>
                `;
            } else {
                let errorMessage = data.message || data.error || 'Error al subir el archivo.';
                if (data.errors) {
                    errorMessage += '<ul>';
                    for (const key in data.errors) {
                        errorMessage += `<li>${data.errors[key].join(', ')}</li>`;
                    }
                    errorMessage += '</ul>';
                }
                resultDiv.innerHTML = `<p style="color: red;">${errorMessage}</p>`;
            }
        });
    </script>
</body>
</html>
