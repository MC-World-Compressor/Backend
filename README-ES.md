# MCWCompressor Backend

Un proyecto desarrollado en Laravel 12 que utiliza la librería [thanos](https://github.com/aternosorg/thanos) para comprimir mundos de Minecraft de manera eficiente.

## Tabla de Contenidos

- [Descripción](#descripción)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Contribuciones](#contribuciones)
- [Licencia](#licencia)

## Descripción

MCWCompressor tiene como objetivo proporcionar una herramienta fácil de usar para reducir el tamaño de los archivos de los mundos de Minecraft. Esto es especialmente útil para realizar copias de seguridad, transferir mundos o simplemente ahorrar espacio en disco. La compresión se realiza utilizando la potente librería [thanos](https://github.com/aternosorg/thanos).

## Requisitos

Asegúrate de tener el siguiente software instalado en tu sistema:

- PHP >= 8.2
- Composer
- Node.js >= 18
- La librería de compresión de mundos de Minecraft [Thanos](https://github.com/aternosorg/thanos) en la carpeta `thanos` en la raiz del proyecto

## Instalación

Sigue estos pasos para poner en marcha el proyecto:

1.  **Clonar el repositorio:**
    ```bash
    git clone https://github.com/MC-World-Compressor/Backend.git
    cd Backend
    ```

2.  **Instalar dependencias:**
    ```bash
    npm install && composer install
    ```

3.  **Instalar la librería de compresión:**
    ```bash
    git clone https://github.com/aternosorg/thanos.git
    ```

4.  **Configurar el entorno:**
    Copia el archivo de ejemplo `.env.example` a `.env`:
    ```bash
    cp .env.example .env
    ```
    Abre el archivo `.env` y configura tus variables de entorno, especialmente:
    - `APP_KEY` (se generará en el siguiente paso)
    - `DB_CONNECTION` y las credenciales de base de datos (si usas una DB diferente a SQLite).
    - Cualquier otra configuración específica del proyecto.

5.  **Generar la clave de la aplicación:**
    ```bash
    php artisan key:generate
    ```

6.  **Ejecutar migraciones:**
    ```bash
    php artisan migrate
    ```

9.  **Crear el enlace simbolico entre el storage y public:**
    ```bash
    php artisan storage:link
    ```
    Asegúrate de que los directorios `storage` y `public` tengan permisos de escritura.

10. **Iniciar el servidor:**
    ```bash
    npm start
    ```
    Luego, accede al backend usando: `http://localhost:8000`.

## Contribuciones

¡Las contribuciones son bienvenidas! Si deseas mejorar este proyecto:
1.  Haz un Fork del repositorio.
2.  Crea una nueva rama (`git checkout -b feature/nueva-funcionalidad`).
3.  Realiza tus cambios y haz commit (`git commit -m 'Añade nueva funcionalidad'`).
4.  Sube tus cambios a la rama (`git push origin feature/nueva-funcionalidad`).
5.  Abre un Pull Request.

Por favor, asegúrate de que tu código sigue los estándares del proyecto y incluye pruebas si es aplicable.

## Licencia
[![Static Badssge](https://img.shields.io/badge/CC_BY--NC--SA_4.0-blue?style=for-the-badge&color=gray)](/LICENSE)

`Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International`

### ¿Que significa?

- Este proyecto es de código abierto, puedes usar, mezclar o reutilizar cualquiera de los códigos o recursos.
- No puedes usar ninguno del código fuente ni material de recursos para fines comerciales.
- No puedes monetizar ninguno del trabajo realizado en este repositorio, ni ningún trabajo derivado.
- Debes enlazar cualquier material usado, mezclado o reutilizado a este repositorio.
- Debes preservar la licencia `CC BY-NC-SA 4.0` en todo trabajo derivado.


### Excepciones:

Puedes añadir donaciones para mantener tu propio servidor pero no puede desbloquear nada o diferenciar al usuario bajo ningún concepto o de ninguna manera. ¡Solo eso, sin zonas grises!

---