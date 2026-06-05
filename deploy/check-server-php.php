<?php
/**
 * Upload to public_html/portfolio/check-server-php.php — DELETE after reading output.
 * Diagnoses PHP version and open_basedir (no Laravel).
 */
header('Content-Type: text/plain; charset=utf-8');
echo "PHP version: ".PHP_VERSION."\n";
echo "open_basedir: ".(ini_get('open_basedir') ?: '(empty)')."\n";
echo "Loaded ini: ".(php_ini_loaded_file() ?: '(none)')."\n";
echo "Scanned ini: ".(php_ini_scanned_files() ?: '(none)')."\n";
echo "Script path: ".__FILE__."\n";
echo "Document root: ".($_SERVER['DOCUMENT_ROOT'] ?? '?')."\n";
