<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$request = [
    'OutSum' => '590.000000',
    'InvId' => '44',
    'Shp_label' => 'modx_official',
    'shp_interface' => 'InvoiceService.WebApi',
    'SignatureValue' => '29B65BEC761044BE41BA05DE26D1E2F9',
];
$cases = [
    'invoice' => [$request, 'OK44'],
    'lowercase-signature' => [array_replace($request, [
        'SignatureValue' => '29b65bec761044be41ba05de26d1e2f9',
    ]), 'OK44'],
    'two-decimal-sum' => [array_replace($request, [
        'OutSum' => '590.00',
        'SignatureValue' => '3A4DD79675F3DC9DE9EEA28A2E35979E',
    ]), 'OK44'],
    'wrong-order' => [array_replace($request, [
        'SignatureValue' => '51E9C8F3A2BE54879AA26AF9DFFD8FBF',
    ]), 'ERR: Wrong signature.'],
    'wrong-password' => [array_replace($request, [
        'SignatureValue' => '967EEF62ADE71B588F5C3993A7D4AD21',
    ]), 'ERR: Wrong signature.'],
    'changed-sum' => [array_replace($request, [
        'OutSum' => '591.000000',
    ]), 'ERR: Wrong signature.'],
];
$legacyRequest = $request;
unset($legacyRequest['shp_interface']);
$legacyRequest['SignatureValue'] = '7554C8837626B4FF57ADC4CE9B02610C';
$cases['ru-missing-interface'] = [$legacyRequest, 'ERR: Missing or invalid shp_interface.'];
$cases['ru-country-alias-missing-interface'] = [$legacyRequest, 'ERR: Missing or invalid shp_interface.', 'RU'];
$cases['kz-without-interface'] = [$legacyRequest, 'OK44', 'KZ'];
$cases['kaz-country-alias-without-interface'] = [$legacyRequest, 'OK44', 'KAZ'];
$cases['kz-with-interface'] = [$request, 'OK44', 'KZ'];
foreach (['empty' => '', 'whitespace' => '   ', 'null' => null, 'array' => ['InvoiceService.WebApi']] as $name => $value) {
    $cases['ru-' . $name . '-interface'] = [array_replace($request, [
        'shp_interface' => $value,
    ]), 'ERR: Missing or invalid shp_interface.'];
}
$cases['ru-empty-interface'][0]['SignatureValue'] = '45969DB1000381C344A32A49B046ED8F';
$cases['ru-whitespace-interface'][0]['SignatureValue'] = 'E8079CA7BC8FD9BDF4C9CBF23C81BE29';
$cases['callback-aliases'] = [array_merge($request, [
    'out_summ' => '590.000000',
    'inv_id' => '44',
    'crc' => $request['SignatureValue'],
    'PaymentMethod' => 'BankCard',
    'IncSum' => '590.000000',
    'IncCurrLabel' => 'BankCardPSR',
    'Fee' => '23.020000',
]), 'OK44'];

if (!isset($argv[1])) {
    $failures = 0;
    foreach ($cases as $name => $case) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' ' . escapeshellarg($name) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        $actual = implode("\n", $output);
        $passed = $exitCode === 0 && $actual === $case[1];
        echo ($passed ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
        if (!$passed) {
            $failures++;
            echo 'Expected: ' . $case[1] . '; actual: ' . $actual . PHP_EOL;
        }
    }
    exit($failures === 0 ? 0 : 1);
}

if (!isset($cases[$argv[1]])) {
    exit(1);
}

interface msPaymentInterface {}

class msPaymentHandler
{
    public $ms2;
}

class msOrder
{
    public function get($key)
    {
        return $key === 'id' ? 44 : null;
    }
}

require dirname(__DIR__) . '/core/components/minishop2/custom/payment/robokassa.class.php';

class ResultSignatureHandler extends Robokassa
{
    public function paymentError($text, $request = [])
    {
        throw new RuntimeException($text);
    }
}

$handler = (new ReflectionClass(ResultSignatureHandler::class))->newInstanceWithoutConstructor();
$handler->config = [
    'login' => 'demo',
    'pass1' => 'redirect-regression-test',
    'pass2' => 'result-regression-test',
    'country' => isset($cases[$argv[1]][2]) ? $cases[$argv[1]][2] : 'RUS',
];
$handler->modx = new class {
    public function getOption($key, $options = null, $default = null)
    {
        return $default;
    }
};
$handler->ms2 = new class {
    public $changed = false;

    public function changeOrderStatus($id, $status)
    {
        if ($id !== 44 || $status !== 2) {
            throw new RuntimeException('Unexpected order status change.');
        }
        $this->changed = true;
    }
};
$_POST = $cases[$argv[1]][0];

try {
    $handler->receive(new msOrder());
} catch (RuntimeException $error) {
    if ($handler->ms2->changed) {
        exit('Unexpected order status change before error.');
    }
    echo 'ERR: ' . $error->getMessage();
}
