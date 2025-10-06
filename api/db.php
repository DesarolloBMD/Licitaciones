<?php
// /API/db.php
declare(strict_types=1);

// No imprimir nada aquí (evita romper el JSON en los endpoints)
ini_set('display_errors', '0');
error_reporting(E_ALL);

$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  // Fallback: URL externa de Render con SSL
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

$parts = parse_url($DATABASE_URL);
if (!$parts || !isset($parts['host'], $parts['user'], $parts['pass'], $parts['path'])) {
  throw new RuntimeException('DATABASE_URL inválida');
}
$host   = $parts['host'];
$port   = isset($parts['port']) ? (int)$parts['port'] : 5432;
$user   = $parts['user'];
$pass   = $parts['pass'];
$dbname = ltrim($parts['path'], '/');

$qs = [];
if (!empty($parts['query'])) parse_str($parts['query'], $qs);
$sslmode = $qs['sslmode'] ?? 'require';

$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $dbname, $sslmode);

$pdo = new PDO($dsn, $user, $pass, [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
]);
