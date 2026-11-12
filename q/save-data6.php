<?php
/*
 * save-data6.php – speichert Experimentdaten als CSV unter data/.
 *
 * Aufruf (z. B. saveData() in jspsych-8/qpsy.js):
 *   POST, Content-Type: application/json
 *   {"filename": "/studie/study1/vl/studie_2026-11-12T09.00.00.000Z_abc", "filedata": "<csv>"}
 *   -> data/studie/study1/vl/studie_2026-11-12T09.00.00.000Z_abc.csv
 *
 * Sicherheit (Migration 11/2026):
 *  - schreibt ausschliesslich unterhalb von data/, ".." ist verboten
 *  - unerlaubte Zeichen im Dateinamen werden durch "_" ersetzt (Daten gehen nicht verloren)
 *  - Endung ist immer .csv (keine .php-Dateien moeglich)
 *  - gleicher Name ueberschreibt (z. B. *_autosave.csv nach jedem Trial); geschrieben wird atomar
 *  - fehlende Unterordner werden angelegt
 *  - Groessenlimit, nur POST, Fehler landen im Container-Log (docker compose logs php-qpsy)
 */
declare(strict_types=1);

$DATA_DIR  = __DIR__ . '/../data';     // ganzfeld.study: Skript liegt in q/
$MAX_BYTES = 32 * 1024 * 1024;         // max. Groesse einer Datendatei
$MAX_DEPTH = 8;                        // max. Anzahl Unterordner

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(int $code, string $msg): never
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    error_log("save-data6: $msg [client $ip]");
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail(405, 'POST required');
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') fail(400, 'empty request');
if (strlen($raw) > 2 * $MAX_BYTES) fail(413, 'request too large');

$post = json_decode($raw, true);
if (!is_array($post)) fail(400, 'invalid JSON');

$name = $post['filename'] ?? null;
$data = $post['filedata'] ?? null;
if (!is_string($name) || trim($name) === '') fail(400, 'filename missing');
if (!is_string($data)) fail(400, 'filedata missing');
if (strlen($data) > $MAX_BYTES) fail(413, 'filedata too large');

// Dateinamen in sichere Pfadsegmente zerlegen
$segments = [];
foreach (explode('/', str_replace('\\', '/', $name)) as $seg) {
    $seg = mb_scrub($seg, 'UTF-8');
    $seg = preg_replace('/[^\p{L}\p{N} ._,+=@()\-]/u', '_', $seg) ?? '';
    $seg = trim($seg);
    if ($seg === '' || $seg === '.') continue;
    if ($seg === '..') fail(400, 'invalid path');
    $seg = ltrim($seg, '.');                 // keine versteckten Dateien/Ordner
    if ($seg === '') continue;
    if (strlen($seg) > 200) $seg = mb_strcut($seg, 0, 200, 'UTF-8');
    $segments[] = $seg;
}
if (count($segments) === 0) fail(400, 'invalid filename');
if (count($segments) > $MAX_DEPTH + 1) fail(400, 'path too deep');

$base = array_pop($segments);
$dataReal = realpath($DATA_DIR);
if ($dataReal === false) fail(500, 'data directory missing');

umask(0002);
$dir = $dataReal . ($segments ? '/' . implode('/', $segments) : '');
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fail(500, 'cannot create directory ' . substr($dir, strlen($dataReal)));
}
$dirReal = realpath($dir);
if ($dirReal === false || ($dirReal !== $dataReal && !str_starts_with($dirReal, $dataReal . '/'))) {
    fail(400, 'invalid path');
}

// Erst in eine temporaere Datei schreiben, dann atomar umbenennen: eine bestehende Datei
// (z. B. *_autosave.csv) wird ersetzt, ist aber nie nur halb geschrieben.
$target = $dirReal . '/' . $base . '.csv';
if (is_dir($target)) fail(400, 'target is a directory');
$tmp = $dirReal . '/.' . $base . '.' . bin2hex(random_bytes(6)) . '.tmp';
$fh = @fopen($tmp, 'x');
if ($fh === false) fail(500, 'cannot write ' . substr($target, strlen($dataReal)));
$ok = fwrite($fh, $data) === strlen($data);
$ok = fflush($fh) && $ok;
fclose($fh);
@chmod($tmp, 0664);
if (!$ok || !rename($tmp, $target)) {
    @unlink($tmp);
    fail(500, 'write failed ' . substr($target, strlen($dataReal)));
}

echo json_encode(['success' => true, 'file' => 'data' . substr($target, strlen($dataReal))]);
