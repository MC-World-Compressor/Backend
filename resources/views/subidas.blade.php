<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Subir archivo por chunks</title>
  <style>
    body {
      font-family: sans-serif;
      background: #f4f4f4;
      padding: 2rem;
    }

    .container {
      max-width: 500px;
      margin: auto;
      background: white;
      padding: 2rem;
      border-radius: 10px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    .drop-zone {
      border: 2px dashed #888;
      padding: 2rem;
      text-align: center;
      cursor: pointer;
      margin-bottom: 1rem;
    }

    .drop-zone.dragover {
      background-color: #e0f7fa;
      border-color: #00bcd4;
    }

    .progress-bar {
      width: 100%;
      height: 20px;
      background: #ddd;
      border-radius: 10px;
      overflow: hidden;
      margin-top: 1rem;
    }

    .progress-fill {
      height: 100%;
      background: #4caf50;
      width: 0%;
      transition: width 0.2s ease-in-out;
    }

    #status {
      margin-top: 1rem;
    }

    input[type="file"] {
      display: none;
    }
  </style>
</head>
<body>
  <div class="container">
    <h2>Subir archivo por chunks</h2>
    <div class="drop-zone" id="dropZone">
      <p>Haz clic o arrastra tu archivo ZIP/TAR aquí</p>
      <input type="file" id="fileInput" accept=".zip,.tar,.gz,.tar.gz" />
    </div>
    <button id="uploadBtn">Subir Archivo</button>

    <div class="progress-bar">
      <div class="progress-fill" id="progressFill"></div>
    </div>
    <div id="status"></div>
  </div>

  <script>
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const uploadBtn = document.getElementById('uploadBtn');
    const progressFill = document.getElementById('progressFill');
    const status = document.getElementById('status');

    let selectedFile = null;

    dropZone.addEventListener('click', () => fileInput.click());

    dropZone.addEventListener('dragover', e => {
      e.preventDefault();
      dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', e => {
      e.preventDefault();
      dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', e => {
      e.preventDefault();
      dropZone.classList.remove('dragover');
      if (e.dataTransfer.files.length) {
        selectedFile = e.dataTransfer.files[0];
        dropZone.querySelector('p').textContent = selectedFile.name;
      }
    });

    fileInput.addEventListener('change', () => {
      if (fileInput.files.length) {
        selectedFile = fileInput.files[0];
        dropZone.querySelector('p').textContent = selectedFile.name;
      }
    });

    uploadBtn.addEventListener('click', async () => {
      if (!selectedFile) {
        alert('Selecciona un archivo primero.');
        return;
      }

      const chunkSize = 50 * 1024 * 1024; // 50MB
      const totalChunks = Math.ceil(selectedFile.size / chunkSize);
      const uploadId = Date.now().toString() + Math.floor(Math.random() * 1000);

      status.textContent = 'Iniciando subida por chunks...';
      progressFill.style.width = '0%';

      for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
        const start = chunkIndex * chunkSize;
        const end = Math.min(start + chunkSize, selectedFile.size);
        const chunk = selectedFile.slice(start, end);

        const formData = new FormData();
        formData.append('mundo_comprimido', chunk);
        formData.append('fileName', selectedFile.name);
        formData.append('uploadId', uploadId);
        formData.append('chunkIndex', chunkIndex);
        formData.append('totalChunks', totalChunks);
        formData.append('isLastChunk', chunkIndex === totalChunks - 1 ? 'true' : 'false');

        try {
          const res = await fetch('/api/subir', {
            method: 'POST',
            body: formData
          });

          const resData = await res.json();

          if (!res.ok) {
            throw new Error(resData.error || resData.message || 'Error en subida');
          }

          const percent = Math.round(((chunkIndex + 1) / totalChunks) * 100);
          progressFill.style.width = percent + '%';
          status.textContent = `Subido ${chunkIndex + 1} de ${totalChunks} chunks (${percent}%)`;

          if (chunkIndex === totalChunks - 1) {
            status.textContent = `✅ Archivo completo subido y procesado. ID: ${resData.servidor_id || '-'}`;
          }

        } catch (err) {
          status.textContent = `❌ Error en el chunk ${chunkIndex}: ${err.message}`;
          break;
        }
      }
    });
  </script>
</body>
</html>