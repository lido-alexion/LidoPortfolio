<?php
/**
 * Sanity check: PHP + portfolio folder reachable. Delete after test.
 * Upload to public_html/portfolio/cpanel-ping.php
 * Open: https://lidoalexion.com/portfolio/cpanel-ping.php
 */
header('Content-Type: text/plain; charset=utf-8');
echo "OK — PHP works in /portfolio/\n";
echo 'Time: '.date('c')."\n";
echo 'Script: '.__FILE__."\n";
