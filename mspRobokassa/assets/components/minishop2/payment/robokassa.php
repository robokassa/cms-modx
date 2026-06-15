<?php

const MODX_API_MODE = true;
require dirname(__FILE__, 5) . '/index.php';

/** @var modX $modx */
$modx->getService('error', 'error.modError');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');

/* @var miniShop2 $miniShop2 */
$miniShop2 = $modx->getService('minishop2');
$miniShop2->loadCustomClasses('payment');

if (!class_exists('Robokassa')) {
    exit('Error: could not load payment class "Robokassa".');
}

/* @var msPaymentInterface|Robokassa $handler */
$handler = new Robokassa($modx->newObject(msPayment::class));

if (!empty($_GET['action'])) {
    $action = (string)$_GET['action'];
    $request = !empty($_POST['SignatureValue']) ? $_POST : $_GET;

    if (!in_array($action, ['success', 'failure'], true)) {
        $handler->paymentError('Unknown redirect action.', $request);
    }

    if (!$handler->validateRedirectSignature($request)) {
        $handler->paymentError('Wrong redirect signature.', $request);
    }

    $context = 'web';
    $params = array();
    $invId = isset($request['InvId']) ? (int)$request['InvId'] : 0;

    if ($invId) {
        $params['msorder'] = $invId;

        if ($order = $modx->getObject(msOrder::class, array('id' => $invId))) {
            $context = $order->get('context');
        }
    }

    $success = $failure = $modx->getOption('site_url');
    if ($id = $modx->getOption('ms2_payment_rbks_success_id', null, 0)) {
        $success = $modx->makeUrl($id, $context, $params, 'full');
    }
    if ($id = $modx->getOption('ms2_payment_rbks_failure_id', null, 0)) {
        $failure = $modx->makeUrl($id, $context, $params, 'full');
    }

    $redirect = $action === 'success' ? $success : $failure;
    $modx->sendRedirect($redirect);
    die();
}

if (!empty($_POST['SignatureValue']) && !empty($_POST['InvId'])) {
    /** @var msOrder $order */
    $order = $modx->getObject(msOrder::class, ['id' => $_POST['InvId']]);
    if ($order) {
        $handler->receive($order);
    } else {
        $modx->log(modX::LOG_LEVEL_ERROR, '[miniShop2:Robokassa] Could not retrieve order with id ' . $_REQUEST['LMI_PAYMENT_NO']);
    }
}
