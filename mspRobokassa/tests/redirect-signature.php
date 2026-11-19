<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

interface msPaymentInterface {}

class msPaymentHandler {}

require dirname(__DIR__) . '/core/components/minishop2/custom/payment/robokassa.class.php';

$handler = (new ReflectionClass(Robokassa::class))->newInstanceWithoutConstructor();
$handler->config = [
    'pass1' => 'redirect-regression-test',
    'pass2' => 'result-regression-test',
    'country' => 'RUS',
];
$request = [
    'OutSum' => '590.00',
    'InvId' => '44',
    'Shp_label' => 'modx_official',
    'shp_interface' => 'InvoiceService.WebApi',
    'SignatureValue' => 'A6C35353BC71257334FFCCCFDD132F52',
];
$cases = [
    'invoice' => [$request, true],
    'lowercase-signature' => [array_replace($request, [
        'SignatureValue' => 'a6c35353bc71257334ffcccfdd132f52',
    ]), true],
    'wrong-order' => [array_replace($request, [
        'SignatureValue' => '1671A5AEBBF88D3C33F92744FF159A88',
    ]), false],
    'wrong-password' => [array_replace($request, [
        'SignatureValue' => 'F80F4D83AC025B04D856CF20781CA29E',
    ]), false],
    'password2-instead-of-password1' => [array_replace($request, [
        'SignatureValue' => '3A4DD79675F3DC9DE9EEA28A2E35979E',
    ]), false],
    'changed-sum' => [array_replace($request, ['OutSum' => '591.00']), false],
    'changed-invoice' => [array_replace($request, ['InvId' => '45']), false],
    'changed-interface' => [array_replace($request, ['shp_interface' => 'Other']), false],
    'unrelated-params' => [array_merge($request, ['Culture' => 'ru', 'action' => 'success']), true],
];
$lowercaseRequest = $request;
unset($lowercaseRequest['Shp_label']);
$lowercaseRequest['shp_label'] = 'modx_official';
$lowercaseRequest['SignatureValue'] = 'F605EAEC191E6B288069F55CDF331FA0';
$cases['lowercase-params'] = [$lowercaseRequest, true];
$cases['original-key-case-preserved'] = [array_replace($lowercaseRequest, [
    'SignatureValue' => $request['SignatureValue'],
]), false];
$cases['additional-mixed-case-params'] = [array_merge($request, [
    'sHp_z' => 'last',
    'Shp_account' => 'customer-1',
    'SignatureValue' => 'DB48E6F0287DA4A1593E377F8D1ADD6C',
]), true];
$legacyRequest = $request;
unset($legacyRequest['shp_interface']);
$legacyRequest['SignatureValue'] = '4393A1F5D4E2DAF9C196A454F47BE441';
$cases['ru-missing-interface'] = [$legacyRequest, false];
$cases['ru-country-alias-missing-interface'] = [$legacyRequest, false, 'RU'];
$cases['kz-without-interface'] = [$legacyRequest, true, 'KZ'];
$cases['kaz-country-alias-without-interface'] = [$legacyRequest, true, 'KAZ'];
$cases['kz-with-interface'] = [$request, true, 'KZ'];
$cases['ru-empty-interface'] = [array_replace($request, [
    'shp_interface' => '',
    'SignatureValue' => '0694FFB4D08BC30A6573E57E36E8EE1D',
]), false];
foreach (['whitespace' => '   ', 'null' => null, 'array' => ['InvoiceService.WebApi']] as $name => $value) {
    $cases['ru-' . $name . '-interface'] = [array_replace($request, [
        'shp_interface' => $value,
    ]), false];
}
$cases['ru-whitespace-interface'][0]['SignatureValue'] = '3364DA4DE1D0BD785988DFE856DE64C0';
foreach (['OutSum', 'InvId', 'SignatureValue'] as $key) {
    $missingRequest = $request;
    unset($missingRequest[$key]);
    $cases['missing-' . $key] = [$missingRequest, false];
    $cases['array-' . $key] = [array_replace($request, [$key => ['invalid']]), false];
}

$failures = 0;
foreach ($cases as $name => $case) {
    $handler->config['country'] = isset($case[2]) ? $case[2] : 'RUS';
    $actual = $handler->validateRedirectSignature($case[0]);
    $passed = $actual === $case[1];
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
    if (!$passed) {
        $failures++;
    }
}
exit($failures === 0 ? 0 : 1);
