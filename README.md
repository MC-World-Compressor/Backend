# MCWCompressor Backend

A project developed in Laravel 12 that uses the [thanos](https://github.com/aternosorg/thanos) library to efficiently compress Minecraft worlds.

> [!NOTE]
> The Spanish version of this README can be found in [README-ES.md](/README-ES.md).

## Table of Contents

- [Description](#description)
- [Requirements](#requirements)
- [Installation](#installation)
- [Contributions](#contributions)
- [License](#license)

## Description

MCWCompressor aims to provide an easy-to-use tool to reduce the file size of Minecraft worlds. This is especially useful for backups, transferring worlds, or simply saving disk space. Compression is performed using the powerful [thanos](https://github.com/aternosorg/thanos) library.

## Requirements

Make sure you have the following software installed on your system:

- PHP >= 8.2
- Composer
- Node.js >= 18
- The Minecraft world compression library Thanos in the `thanos` folder at the root of the project.

## Installation

Follow these steps to get the project up and running:

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/MC-World-Compressor/Backend.git
    cd Backend
    ```

2.  **Intall dependencies:**
    ```bash
    npm install && composer install
    ```

3.  **Clone of compressor library:**
    ```bash
    git clone https://github.com/aternosorg/thanos.git
    ```

4.  **Set up the environment:**
    Copy the example file `.env.example` to `.env`:
    ```bash
    cp .env.example .env
    ```
    Open the `.env` file and configure your environment variables, especially:
    - `APP_KEY` (will be generated in the next step)
    - `DB_CONNECTION` and the database credentials (if you use a DB other than SQLite).
    - Any other project-specific settings.

5.  **Generate the application key:**
    ```bash
    php artisan key:generate
    ```

6.  **Run migrations:**
    ```bash
    php artisan migrate
    ```

9.  **Create the symbolic link between storage and public:**
    ```bash
    php artisan storage:link
    ```
    Make sure the `storage` and `public` directories have write permissions.

10. **Start the server:**
    ```bash
    npm start
    ```
   Then, access the backend using: `http://localhost:8000`.

## Contributions

Contributions are welcome! If you'd like to improve this project:
1. Fork the repository.
2. Create a new branch (`git checkout -b feature/new-feature`).
3. Make your changes and commit (`git commit -m 'Add new feature'`).
4. Push your changes to the branch (`git push origin feature/new-feature`).
5. Open a pull request.

Please ensure your code follows the project standards and include tests if applicable.

## License
[![Static Badssge](https://img.shields.io/badge/CC_BY--NC--SA_4.0-blue?style=for-the-badge&color=gray)](/LICENSE)

`Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International`

### What does that mean?

- This project is open source, you can use, mix or reuse any of the code or assets.
- You cannot use any of the source code or assets material for commercial purposes.
- You cannot monetize any of the work done on this repository, or all the derivative work.
- You must link any of the used, mixed or reused material to this repo.
- You must preserve the `CC BY-NC-SA 4.0` license to all the derivative work.


### Exceptions:

You can add donations to maintain your own server, but it can't unlock anything or differentiate the user in any way. Just that, no gray areas!

---