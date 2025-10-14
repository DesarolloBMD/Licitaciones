<?php
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo "❌ No se recibió archivo.";
  exit;
}

$tmp = $_FILES['archivo']['tmp_name'];
$fh = fopen($tmp, 'r');
$header = fgetcsv($fh, 0, ',', '"', '"');
fclose($fh);

echo "<pre>";
print_r($header);
echo "</pre>";
?>
