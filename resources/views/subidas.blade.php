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

        <form id="uploadForm" method="POST" action="https://8000--main--mccompressor--markos--ki1jluq18oa3g.pit-1.try.coder.app/api/comprimir" enctype="multipart/form-data">
            @csrf
            <div id="dropZone" class="drop-zone">
                <p>Arrastra aquí un archivo ZIP o haz clic para seleccionar</p>
                <input type="file" name="zipfile" id="zipfile" accept=".zip" hidden />
            </div>
            <button type="submit">Comprimir Mundo</button>
        </form>

        <div id="result"></div>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('zipfile');
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
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: formData
            });

            const data = await response.json();

            if (response.ok) {
                resultDiv.innerHTML = `<p>Archivo comprimido: <a href="${data.download_url}" target="_blank">Descargar aquí</a></p>`;
            } else {
                resultDiv.textContent = data.error || 'Error al comprimir';
            }
        });
    </script>
</body>
</html>
