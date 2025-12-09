<?php
// Minimal runner to include index.php as if requested with ?page=teacher_crud
// Run from repo root via: php tools_render_teacher_crud.php
chdir(__DIR__);
// Clear previous output buffers
while (ob_get_level()) ob_end_clean();
// Set GET/REQUEST_METHOD
$_GET['page'] = 'teacher_crud';
$_SERVER['REQUEST_METHOD'] = 'GET';
// Ensure session superglobal exists
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
// Turn on error display for CLI run
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Include index.php
include __DIR__ . '/index.php';
