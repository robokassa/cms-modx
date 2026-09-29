<?php

/**
 * Authenticated web installer for mspRobokassa.
 *
 * @package msprobokassa
 */

if (PHP_SAPI === 'cli') {
    exit('This script must be opened from a web browser.' . PHP_EOL);
}

define('MODX_API_MODE', true);
define('MSP_ROBOKASSA_INSTALL_BOOTSTRAP', true);

require_once __DIR__ . '/_build/build.config.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('mgr');

/**
 * Sends a generic authorization error without revealing installation details.
 *
 * @return void
 */
function mspRobokassaInstallerForbidden()
{
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/html; charset=UTF-8');
    exit('<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Доступ запрещён</title></head><body><h1>Доступ запрещён</h1><p>Для установки модуля войдите в MODX Manager под пользователем с правом управления пакетами.</p></body></html>');
}

/**
 * Renders the installer page.
 *
 * @param string $content
 * @param bool $closeDocument
 * @return void
 */
function mspRobokassaInstallerPage($content, $closeDocument = true)
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Установка mspRobokassa</title></head><body>';
    echo '<h1>Установка mspRobokassa</h1>';
    echo $content;
    if ($closeDocument) {
        echo '</body></html>';
    }
}

if (!$modx->user || !$modx->user->hasSessionContext('mgr') || !$modx->hasPermission('packages')) {
    mspRobokassaInstallerForbidden();
}

$contextKey = $modx->context->get('key');
$token = $modx->user->getUserToken($contextKey);
$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($requestMethod === 'GET') {
    $tokenEscaped = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    mspRobokassaInstallerPage(
        '<p>Нажмите кнопку, чтобы собрать и установить модуль. Во время установки могут быть перезаписаны файлы модуля.</p>' .
        '<form method="post" action="">' .
        '<input type="hidden" name="HTTP_MODAUTH" value="' . $tokenEscaped . '">' .
        '<button type="submit">Установить модуль</button>' .
        '</form>'
    );
    exit;
}

if ($requestMethod !== 'POST') {
    header('Allow: GET, POST');
    header('HTTP/1.1 405 Method Not Allowed');
    exit('Method Not Allowed');
}

$submittedToken = $modx->getOption('HTTP_MODAUTH', $_POST, '');
if (!is_string($submittedToken) || !is_string($token) || !mspRobokassaInstallerTokensEqual($submittedToken, $token)) {
    mspRobokassaInstallerForbidden();
}

define('MSP_ROBOKASSA_BUILD_CONTEXT', 'installer');
require_once __DIR__ . '/_build/includes/build.package.php';

mspRobokassaInstallerPage('<p>Выполняется сборка и установка модуля...</p><pre>', false);

try {
    $result = mspRobokassaBuildTransportPackage($modx);
    $package = mspRobokassaGetTransportPackage($modx, $result['signature']);

    if (!$package->install()) {
        throw new RuntimeException('Could not install the transport package.');
    }

    $modx->log(modX::LOG_LEVEL_INFO, "\n<br />Execution time: {$result['execution_time']}\n");
    echo '</pre><p>Модуль успешно установлен.</p>';
} catch (Exception $exception) {
    $modx->log(modX::LOG_LEVEL_ERROR, 'mspRobokassa installation failed: ' . $exception->getMessage());
    echo '</pre><p>Не удалось установить модуль. Проверьте журнал ошибок MODX.</p>';
}

echo '</body></html>';

/**
 * Compares the MODX session token in PHP versions without hash_equals().
 *
 * @param string $knownToken
 * @param string $submittedToken
 * @return bool
 */
function mspRobokassaInstallerTokensEqual($knownToken, $submittedToken)
{
    if (function_exists('hash_equals')) {
        return hash_equals($knownToken, $submittedToken);
    }

    return $knownToken === $submittedToken;
}
