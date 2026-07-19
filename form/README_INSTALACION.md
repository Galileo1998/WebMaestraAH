# Sistema de Formularios — Acción Honduras

Módulo PHP/MySQL integrado con el estilo administrativo de Acción Honduras. No modifica tablas durante la carga: la estructura se instala una sola vez desde SQL.

## Instalación

1. Copie las carpetas del paquete dentro de la raíz del proyecto, conservando la estructura:

```text
/admin/formularios.php
/admin/editor_formulario.php
/admin/respuestas_formulario.php
/admin/api_formularios.php
/formularios/responder.php
/assets/formularios.css
/assets/form_builder.js
/includes/bootstrap.php
/includes/FormService.php
/uploads/formularios/
```

2. Importe desde phpMyAdmin:

```text
/sql/instalar_formularios.sql
```

3. Conceda escritura al servidor web:

```bash
chmod -R 775 uploads/formularios
```

4. Agregue al menú administrativo:

```php
<a href="formularios.php">
    <i class="fa-solid fa-rectangle-list"></i> Formularios
</a>
```

5. Abra:

```text
/admin/formularios.php
```

## Capacidades incluidas

### Constructor

- Respuesta corta y párrafo.
- Correo electrónico, teléfono y número.
- Opción múltiple, casillas y lista desplegable.
- Escala lineal y calificación.
- Fecha, hora y fecha/hora.
- Subida de archivos con tipo y tamaño permitido.
- Cuadrícula de opción múltiple y cuadrícula de casillas.
- Secciones reordenables.
- Títulos, textos informativos, imágenes y videos.
- Consentimiento obligatorio.
- Preguntas requeridas.
- Validación por expresión regular, mínimo y máximo.
- Puntos por pregunta para evaluaciones.
- Color institucional, mensaje de confirmación y barra de progreso.
- Apertura, cierre y límite de respuestas.
- Requerir inicio de sesión.
- Una respuesta por usuario.
- Recopilar correo.
- Permitir edición mediante enlace privado.
- Notificación por correo con `mail()`.
- Autoguardado del diseño.
- Duplicación y eliminación de formularios.

### Geografía institucional

La pregunta **Cascada geográfica** consulta directamente:

```text
ah_bases_geograficas
ah_centros
```

Flujo:

```text
Municipio → Comunidad base → Caserío → Centro
```

Puede limitarse por tipo de centro:

- Básica.
- Media.
- Preescolar.
- Centro ADN.
- UAPS/CIS.

Al guardar la respuesta del centro se conserva el identificador seleccionado y la cadena territorial completa.

### Respuestas y análisis

- Vista resumen.
- Respuestas individuales.
- Tabla completa.
- Gráfico de respuestas en el tiempo.
- Gráficos automáticos para selección, escalas, calificación y geografía.
- Listado para respuestas abiertas.
- Promedio, mínimo y máximo en variables numéricas.
- Exportación XLSX desde el navegador.
- Archivos adjuntos protegidos contra ejecución de PHP.
- Auditoría de creación, edición y eliminación.

## Publicar un formulario

Desde el editor cambie el estado a **Publicado**. El enlace público tendrá esta forma:

```text
/formularios/responder.php?f=nombre-del-formulario
```

## Dependencias del navegador

Se cargan desde CDN:

- Font Awesome.
- SortableJS.
- Chart.js.
- SheetJS.

El servidor solo necesita PHP, PDO MySQL y permisos de escritura para los archivos adjuntos.

## Seguridad

- Consultas preparadas.
- Tokens CSRF.
- Validación de estado, fechas y límite de respuestas.
- Validación de tipo y tamaño de archivos.
- Nombres aleatorios para archivos.
- Bloqueo de ejecución de scripts dentro de uploads.
- Formularios administrativos protegidos por la sesión existente.

## Observación

Las integraciones propietarias de Google Workspace —por ejemplo, guardar directamente en Google Sheets o usar cuentas Google como proveedor OAuth— no forman parte del núcleo PHP. El sistema cubre el constructor, publicación, captura, geografía, análisis y exportación sin depender de Google.


## Corrección v3: cargas sin carpeta temporal de PHP

La carga de archivos se prepara en el navegador y se envía como contenido codificado. Esto permite guardar fotografías incluso cuando PHP devuelve `UPLOAD_ERR_NO_TMP_DIR`. Las imágenes grandes se optimizan automáticamente hasta 1920 px antes de enviarse.

La carpeta `uploads/formularios` continúa necesitando permisos de escritura (`775`).

## Lógica condicional

La lógica usa una clave estable por pregunta. El autoguardado protege los cambios realizados mientras existe otra petición en curso y valida que toda pregunta dependiente conserve una pregunta de origen válida.

## Compatibilidad de imágenes v4
La pregunta de fotografía admite `image/*` y conserva el archivo original cuando el navegador no puede decodificarlo. Esto incluye, entre otros, JPG, PNG, GIF, WEBP, AVIF, HEIC, HEIF, BMP y TIFF. PHP ya no usa `getimagesize()` como condición obligatoria, porque esa función no reconoce todos los formatos válidos.


## Corrección V5
- La pantalla de respuestas ya no usa JOIN/ORDER BY que obligue a MariaDB a crear tablas temporales en /tmp.
- Las respuestas y sus detalles se consultan por separado y por lotes.
- El editor añade botones Subir/Bajar en cada pregunta y conserva el arrastre cuando SortableJS está disponible.

## Actualización V6 — Imagen de agradecimiento y analítica avanzada

Esta versión no requiere nuevas tablas ni columnas.

### Imagen en la pantalla de agradecimiento

En el editor, cuando no hay una pregunta seleccionada, abra **Configuración del formulario → Pantalla de agradecimiento**. Puede:

- cambiar el título y mensaje final;
- subir una imagen desde el equipo sin depender de la carpeta temporal de PHP;
- usar una URL externa;
- definir texto alternativo y ancho máximo;
- quitar o reemplazar la imagen.

Las imágenes subidas se almacenan en:

```text
uploads/formularios/confirmaciones/ID_FORMULARIO/
```

La carpeta `uploads/formularios` debe tener permisos de escritura, normalmente `775`.

### Analítica de respuestas

La pantalla `admin/respuestas_formulario.php` incluye:

- filtros por fecha, municipio, centro y búsqueda general;
- indicadores de volumen, respondientes únicos, completitud y evidencias;
- tendencia diaria y acumulada;
- análisis por día de semana y hora;
- hallazgos automáticos;
- estadísticas por cada pregunta;
- suma, promedio, mediana, mínimo, máximo e histogramas para números;
- distribución y porcentajes para opciones;
- palabras frecuentes y respuestas recientes para textos;
- panel geográfico por municipio, comunidad base, caserío y centro;
- galería de fotografías y archivos;
- respuestas individuales;
- tabla paginada y exportación completa a XLSX.

Las consultas continúan evitando `JOIN` pesados con columnas `LONGTEXT`, para no crear tablas temporales grandes en `/tmp`.
