<?php

/**
 * mspRobokassa transport package builder.
 *
 * This entry point is intended for developers and release tooling only.
 * Installation through HTTP is handled by /install.php after MODX Manager
 * authentication and authorization checks.
 *
 * @package msprobokassa
 * @subpackage build
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This script can only be run from the command line.' . PHP_EOL);
}

define('MSP_ROBOKASSA_BUILD_CONTEXT', 'cli');

require_once __DIR__ . '/build.config.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
require_once __DIR__ . '/includes/build.package.php';

$modx = new modX();
$modx->initialize('mgr');

echo '<pre>';

$result = mspRobokassaBuildTransportPackage($modx);
$modx->log(modX::LOG_LEVEL_INFO, "\n<br />Execution time: {$result['execution_time']}\n");

echo '</pre>';
