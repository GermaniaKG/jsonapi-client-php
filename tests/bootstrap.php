<?php
/**
 * test suite bootstrap.
 *
 * Tries to include Composer vendor/autoload.php; dies if it does not exist.
 *
 * @category  Location
 * @author    Carsten Witt <tomkyle@posteo.de>
 */

$autoloader_file = __DIR__ . '/../vendor/autoload.php';
if (!is_readable( $autoloader_file )) {
    die("\nMissing Composer's vendor/autoload.php; run 'composer install' first.\n\n");
}
