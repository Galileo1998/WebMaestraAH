# Formulario PHP – Momento Mágico

Aplicación PHP sencilla para capturar, editar, consultar e imprimir registros en tamaño Carta. No utiliza frameworks ni librerías externas.

## Requisitos

- PHP 8.1 o superior con extensiones `pdo_mysql` y `fileinfo`.
- MySQL 5.7+ o MariaDB 10.4+.
- Apache o Nginx.

## Instalación

1. Copie la carpeta al servidor web, por ejemplo `public_html/momento-magico/`.
2. Importe `database.sql` desde phpMyAdmin o por consola.
3. Edite `config.php` con host, base de datos, usuario y contraseña.
4. Dé permiso de escritura a `uploads/`:

   ```bash
   chmod 755 uploads
   ```

   En algunos alojamientos puede requerirse `chmod 775 uploads`.

5. Abra `index.php` en el navegador.

## Uso

- **Nuevo:** abre un formulario vacío.
- **Registros:** consulta los formularios guardados.
- **Editar:** actualiza un registro existente.
- **Imprimir:** abre una versión limpia en papel Carta; en Chrome o Edge seleccione **Guardar como PDF**.

## Seguridad y límites

- Consultas preparadas con PDO.
- Token CSRF en el guardado.
- Validación MIME de archivos.
- Máximo 15 MB por archivo.
- Se aceptan JPG, PNG, WEBP, MP4, MP3/M4A, PDF, DOCX y XLSX.
- El archivo `.htaccess` dentro de `uploads/` bloquea la ejecución de scripts en Apache.

## Ajustes rápidos

- Colores y diseño: `assets/style.css`.
- Opciones de combobox: `index.php`.
- Tamaño máximo de archivo: `config.php`.
- Campos y estructura de base de datos: `database.sql`.
