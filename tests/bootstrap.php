<?php

/**
 * Test bootstrap.
 *
 * The plugin's own classes are autoloaded from ../classes through the test
 * composer.json, so a unit test needs nothing but this file. The end-to-end
 * suite finds a Grav installation at run time (see Support\GravSite) and boots
 * it in its own process; nothing here touches the plugin's runtime vendor/.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

date_default_timezone_set('UTC');

define('PDF_TESTS_DIR', __DIR__);
define('PDF_PLUGIN_DIR', dirname(__DIR__));
