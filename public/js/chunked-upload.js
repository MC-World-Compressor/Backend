class ChunkedUpload {
    constructor(file, options = {}) {
        this.file = file;
        this.chunkSize = options.chunkSize || 5 * 1024 * 1024; // 5MB por chunk
        this.totalChunks = Math.ceil(file.size / this.chunkSize);
        this.uploadId = null;
        this.onProgress = options.onProgress || (() => {});
        this.onComplete = options.onComplete || (() => {});
        this.onError = options.onError || (() => {});
        this.baseUrl = options.baseUrl || '/api';
    }

    async upload() {
        try {
            // Iniciar subida usando el endpoint unificado
            const initResponse = await fetch(`${this.baseUrl}/subir`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                body: JSON.stringify({
                    filename: this.file.name,
                    total_chunks: this.totalChunks,
                    file_size: this.file.size
                })
            });

            if (!initResponse.ok) {
                throw new Error('Error al iniciar la subida');
            }

            const initData = await initResponse.json();
            this.uploadId = initData.upload_id;

            // Subir chunks
            for (let i = 0; i < this.totalChunks; i++) {
                await this.uploadChunk(i);
            }

        } catch (error) {
            this.onError(error);
        }
    }

    async uploadChunk(chunkIndex) {
        const start = chunkIndex * this.chunkSize;
        const end = Math.min(start + this.chunkSize, this.file.size);
        const chunk = this.file.slice(start, end);

        const formData = new FormData();
        formData.append('upload_id', this.uploadId);
        formData.append('chunk_index', chunkIndex);
        formData.append('total_chunks', this.totalChunks);
        formData.append('chunk', chunk, `chunk_${chunkIndex}`);
        formData.append('filename', this.file.name);

        const response = await fetch(`${this.baseUrl}/subir`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            },
            body: formData
        });

        if (!response.ok) {
            throw new Error(`Error al subir chunk ${chunkIndex}`);
        }

        const data = await response.json();
        
        this.onProgress({
            chunkIndex,
            totalChunks: this.totalChunks,
            progress: data.progress || ((chunkIndex + 1) / this.totalChunks * 100),
            chunksCompleted: data.chunks_completed || chunkIndex + 1
        });

        // Si es el último chunk y se completó todo
        if (data.servidor_id) {
            this.onComplete(data);
        }
    }
}

// Función para usar con un input file
function setupChunkedUpload(fileInputId, options = {}) {
    const fileInput = document.getElementById(fileInputId);
    
    if (!fileInput) {
        console.error(`Input file con ID '${fileInputId}' no encontrado`);
        return;
    }

    fileInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;

        // Validar tipo de archivo
        const validTypes = ['application/zip', 'application/x-tar', 'application/gzip'];
        const validExtensions = ['.zip', '.tar', '.gz', '.tar.gz'];
        
        const isValidType = validTypes.includes(file.type) || 
                           validExtensions.some(ext => file.name.toLowerCase().endsWith(ext));
        
        if (!isValidType) {
            alert('Tipo de archivo no válido. Solo se permiten archivos ZIP, TAR y GZ.');
            return;
        }

        const uploader = new ChunkedUpload(file, {
            chunkSize: options.chunkSize || 5 * 1024 * 1024, // 5MB
            baseUrl: options.baseUrl || '/api',
            
            onProgress: (progressData) => {
                console.log(`Progreso: ${progressData.progress.toFixed(1)}%`);
                if (options.onProgress) {
                    options.onProgress(progressData);
                }
            },
            
            onComplete: (data) => {
                console.log('Subida completada:', data);
                if (options.onComplete) {
                    options.onComplete(data);
                }
            },
            
            onError: (error) => {
                console.error('Error en la subida:', error);
                if (options.onError) {
                    options.onError(error);
                }
            }
        });

        uploader.upload();
    });
}

// Exportar para uso global
window.ChunkedUpload = ChunkedUpload;
window.setupChunkedUpload = setupChunkedUpload;