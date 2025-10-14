<?php
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo "❌ No se recibió archivo.";
  exit;
}

$tmp = $_FILES['archivo']['tmp_name'];
$fh = fopen($tmp, 'r');
if (!$fh) { echo "❌ No se pudo abrir el archivo."; exit; }

$firstLine = fgets($fh);
rewind($fh);

// Detectar delimitador automáticamente
$delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
arsort($delims);
$delimiter = array_key_first($delims);
if (!$delimiter) $delimiter = ';'; // fallback

// Leer encabezados correctamente
$header = fgetcsv($fh, 0, $delimiter);
fclose($fh);

echo "<pre>";
echo "Delimitador detectado: " . json_encode($delimiter) . "\n\n";
print_r($header);
echo "</pre>";
?>
