# Centro de Seguridad de Acción Honduras

Sistema defensivo para `public_html` con:

- acceso usando la sesión existente del sitio;
- prioridad para la tabla `ah_users` y compatibilidad con `users`;
- acceso exclusivo para administradores, superadministradores o usuarios con permiso de seguridad;
- escaneo heurístico rápido y completo;
- integración opcional con ClamAV si el servidor lo tiene instalado;
- línea base SHA-256 para detectar modificaciones del código;
- cuarentena fuera de `public_html`, sin eliminación automática;
- restauración controlada de archivos;
- respaldo ZIP del código de `public_html`;
- estado del repositorio Git;
- respaldo, commit y push a GitHub;
- historial de escaneos, hallazgos, respaldos y acciones;
- scripts para cron de cPanel.

## Instalación

1. Importe `sql/instalar_seguridad.sql` en phpMyAdmin.
2. Copie `admin/seguridad.php` en:

   `public_html/admin/seguridad.php`

3. Copie la carpeta `security` en:

   `public_html/security`

4. Confirme que ya existen:

   - `public_html/config/Database.php`
   - `public_html/classes/Auth.php`

5. Abra:

   `https://accionhonduras.org/admin/seguridad.php`

6. El sistema intentará crear, fuera de `public_html`:

   `/home/USUARIO/accion_security_storage`

   Si el hosting no permite crearla automáticamente, créela desde cPanel con permisos `750`.

7. Agregue al `.gitignore` del repositorio el contenido de `.gitignore.security`.

8. Configure los cron indicados en `security/cron/README_CRON.txt`.

## Primera ejecución recomendada

1. Abra el panel con una cuenta administradora.
2. Ejecute un **escaneo completo**.
3. Revise los hallazgos críticos y altos.
4. No ponga archivos en cuarentena sin revisar su ruta y evidencia.
5. Cuando el sitio esté verificado, pulse **Actualizar línea base**.
6. Cree el primer **respaldo de código**.
7. Verifique el estado de GitHub y configure SSH en el servidor si aún pide credenciales.

## GitHub

El panel no almacena la contraseña ni el token de GitHub. Usa las credenciales ya configuradas en el servidor, preferiblemente una clave SSH del repositorio.

El botón **Respaldar, commit y push**:

1. bloquea el push si existen hallazgos críticos o altos abiertos;
2. crea un ZIP privado antes del commit;
3. actualiza `.gitignore` para excluir `security/config.local.php`;
4. ejecuta `git add -A`;
5. crea commit solo si existen cambios;
6. hace push a la rama actual y al remoto configurado.

## Respaldos

El respaldo predeterminado conserva código y archivos de configuración. Excluye:

- `.git`;
- `uploads`;
- fotografías y videos;
- `node_modules`;
- `vendor`;
- cachés, logs y temporales.

Los ZIP quedan fuera de `public_html` y se descargan únicamente pasando por el panel autenticado.

## Reglas incluidas

- firmas conocidas de webshells;
- ejecución de código codificado;
- comandos del sistema controlados por parámetros web;
- includes dinámicos desde `GET`, `POST`, `REQUEST` o `COOKIE`;
- archivos PHP en carpetas de cargas;
- dobles extensiones;
- código PHP oculto en imágenes u otros formatos;
- reglas peligrosas en `.htaccess`;
- archivos ejecutables ocultos;
- permisos de escritura global;
- cargas codificadas extensas;
- cambios respecto a la línea base SHA-256.

## Token del cron web

El paquete incluye un token aleatorio en `security/config.local.php`. Puede cambiarlo. El cron CLI es preferible porque no expone el token en una URL.

## Límites reales

Este sistema mejora la detección, trazabilidad y recuperación, pero no sustituye:

- actualización de PHP y dependencias;
- contraseñas fuertes y autenticación segura;
- permisos correctos de archivos;
- revisión de cuentas FTP/cPanel;
- respaldos externos adicionales;
- un antivirus del proveedor de hosting.
