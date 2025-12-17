<?php
if (!uid()) redirect('./');
if (is_admin()) redirect('./'); // Admin tidak perlu akses ini

require 'views/download_soal.php';
