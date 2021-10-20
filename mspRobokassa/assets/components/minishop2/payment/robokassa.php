<?php
define('MODX_API_MODE', true);
require dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/index.php';

$modx->getService('error','error.modError');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');

/* @var miniShop2 $miniShop2 */
$miniShop2 = $modx->getService('minishop2');
$miniShop2->loadCustomClasses('payment');

if (!class_exists('Robokassa')) {exit('Error: could not load payment class "Robokassa".');}
$context = '';
$params = array();

/* @var msPaymentInterface|Robokassa $handler */
$handler = new Robokassa($modx->newObject('msOrder'));

if (!empty($_REQUEST['SignatureValue']) && !empty($_REQUEST['InvId']) && empty($_REQUEST['action'])) {
	if ($order = $modx->getObject('msOrder', $_REQUEST['InvId'])) {
		$handler->receive($order, $_REQUEST);
	}
	else {
		$modx->log(modX::LOG_LEVEL_ERROR, '[miniShop2:Robokassa] Could not retrieve order with id '.$_REQUEST['LMI_PAYMENT_NO']);
	}
}

if (!empty($_REQUEST['InvId'])) {$params['msorder'] = $_REQUEST['InvId'];}

$success = $failure = $modx->getOption('site_url');
if ($id = $modx->getOption('ms2_payment_rbks_success_id', null, 0)) {
	$success = $modx->makeUrl($id, $context, $params, 'full');
}
if ($id = $modx->getOption('ms2_payment_rbks_failure_id', null, 0)) {
	$failure = $modx->makeUrl($id, $context, $params, 'full');
}

$redirect = !empty($_REQUEST['action']) && $_REQUEST['action'] == 'success' ? $success : $failure;
header('Location: ' . $redirect);
