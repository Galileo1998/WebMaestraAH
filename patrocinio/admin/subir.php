
<div class="container">
    <h2>Cargar Boletas desde PDF</h2>
    <form action="procesar_pdf.php" method="POST" enctype="multipart/form-data">
        <label>Seleccione el archivo PDF:</label>
        <input type="file" name="pdf_file" accept=".pdf" required>
        <br><br>
        <button type="submit" class="btn-primary">Procesar Documento</button>
    </form>
</div>