<?php
// api/conexion.php
$host = "127.0.0.1"; 
$user = "Usuario";
$pass = "Contraseña";
$db   = "licitaciones";
$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(["error" => "DB fallo: " . $mysqli->connect_error]);
  exit;
}
$mysqli->set_charset("utf8mb4");
