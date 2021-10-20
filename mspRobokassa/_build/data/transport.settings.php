<?php
/**
 * Loads system settings into build
 * @var modX $modx
 * @package msprobokassa
 * @subpackage build
 */
$settings = [];

$tmp = [
    'login' => [
        'xtype' => 'textfield',
        'value' => 'your robokassa login',
    ],
    'pass1' => [
        'xtype' => 'text-password',
        'value' => 'password1',
    ],
    'pass2' => [
        'xtype' => 'text-password',
        'value' => 'password2',
    ],
    'country' => [
        'xtype' => 'textfield',
        'value' => 'RUS',
    ],
    'currency' => [
        'xtype' => 'textfield',
        'value' => '',
    ],
    'culture' => [
        'xtype' => 'textfield',
        'value' => 'ru',
    ],
    'success_id' => [
        'xtype' => 'numberfield',
        'value' => 0,
    ],
    'failure_id' => [
        'xtype' => 'numberfield',
        'value' => 0,
    ],
    'debug' => [
        'type' => 'combo-boolean',
        'value' => false,
    ],
    'receipt' => [
        'xtype' => 'combo-boolean',
        'value' => true,
    ],
    'tax' => [
        'type' => 'textfield',
        'value' => 'none',
    ],
    'payment_method' => [
        'type' => 'textfield',
        'value' => 'full_payment',
    ],
    'payment_object' => [
        'type' => 'textfield',
        'value' => 'commodity',
    ],
    'test_mode' => [
        'xtype' => 'combo-boolean',
        'value' => false,
    ],
];

foreach ($tmp as $k => $v) {
    /* @var modSystemSetting $setting */
    $setting = $modx->newObject('modSystemSetting');
    $setting->fromArray(array_merge(
        [
            'key' => 'ms2_payment_rbks_' . $k,
            'namespace' => 'minishop2',
            'area' => 'ms2_payment',
        ], $v
    ), '', true, true);

    $settings[] = $setting;
}

unset($tmp);
return $settings;
