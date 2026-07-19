// server.js
const express = require('express');
const path = require('path');
const fs = require('fs');

const app = express();

// Para leer JSON del body
app.use(express.json());

// Servir archivos estáticos desde /public
app.use(express.static(path.join(__dirname, 'public')));

// Archivo CSV donde se acumularán los ingresos
const LOG_FILE = path.join(__dirname, 'participacion_chaside_log.csv');

// Campos en el orden en que irán al CSV
const LOG_HEADERS = [
  "fecha_hora",
  "valor_buscado",
  "nombre",
  "dni",
  "nnaj",
  "comunidad_base",
  "sexo",
  "edad",
  "area_principal",
  "resumen_resultado1",
  "ip",
  "url_acceso"
];

// Asegura que el archivo exista y tenga cabecera
function ensureLogFile() {
  if (!fs.existsSync(LOG_FILE)) {
    const headerLine = LOG_HEADERS.join(",") + "\n";
    fs.writeFileSync(LOG_FILE, headerLine, 'utf8');
  }
}

// Endpoint para recibir el log de participación
app.post('/api/log-participacion', (req, res) => {
  try {
    ensureLogFile();

    const data = req.body || {};

    // Construimos la fila en CSV con comillas y escape de comillas internas
    const row = LOG_HEADERS.map(field => {
      let val = data[field] !== undefined ? String(data[field]) : "";
      val = val.replace(/"/g, '""'); // escapar comillas
      return `"${val}"`;
    }).join(",") + "\n";

    fs.appendFileSync(LOG_FILE, row, 'utf8');

    res.json({ ok: true });
  } catch (err) {
    console.error('Error al registrar participación:', err);
    res.status(500).json({ ok: false, error: 'No se pudo registrar la participación' });
  }
});

// Iniciar servidor
const PORT = 3000;
app.listen(PORT, () => {
  console.log(`Servidor CHASIDE escuchando en http://localhost:${PORT}`);
});
