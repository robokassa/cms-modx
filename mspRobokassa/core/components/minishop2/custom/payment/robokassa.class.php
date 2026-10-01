<?php

$newBasePaymentHandler = dirname(__FILE__, 3) . '/handlers/mspaymenthandler.class.php';
$oldBasePaymentHandler = dirname(__FILE__, 3) . '/model/minishop2/mspaymenthandler.class.php';

if (!interface_exists('msPaymentInterface')) {
    if (file_exists($newBasePaymentHandler)) {
        require_once $newBasePaymentHandler;
    } else {
        require_once $oldBasePaymentHandler;
    }
}

class Robokassa extends msPaymentHandler implements msPaymentInterface
{
    public $config;
    /** @var modX */
    public $modx;

    const LOG_NAME = '[miniShop2:Robokassa]';

    public function __construct(xPDOObject $object, $config = [])
    {
        parent::__construct($object, $config);

        $this->modx = $object->xpdo;

        $siteUrl = $this->modx->getOption('site_url');
        $assetsUrl = $this->modx->getOption(
            'minishop2.assets_url',
            $config,
            $this->modx->getOption('assets_url') . 'components/minishop2/'
        );
        $paymentUrl = $siteUrl . substr($assetsUrl, 1) . 'payment/robokassa.php';

        $country = strtoupper($this->modx->getOption('ms2_payment_rbks_country'));
        switch ($country) {
            case 'KAZ':
            case 'KZ':
                $checkoutUrl = 'https://auth.robokassa.kz/Merchant/Index/';
                $postUrl = 'https://auth.robokassa.kz/Merchant/Indexjson.aspx';
                break;
            case 'RUS':
            case 'RU':
            default:
                $checkoutUrl = 'https://auth.robokassa.ru/Merchant/Index/';
                $postUrl = 'https://services.robokassa.ru/InvoiceServiceWebApi/api/CreateInvoice';
                break;
        }

        $this->config = array_merge([
            'paymentUrl' => $paymentUrl,
            'checkoutUrl' => $checkoutUrl,
            'postUrl' => $postUrl,
            'login' => $this->modx->getOption('ms2_payment_rbks_login'),
            'pass1' => $this->modx->getOption('ms2_payment_rbks_pass1'),
            'pass2' => $this->modx->getOption('ms2_payment_rbks_pass2'),
            'country' => $country,
            'culture' => $this->modx->getOption('ms2_payment_rbks_culture', null, 'ru'),
            'receipt' => $this->modx->getOption('ms2_payment_rbks_receipt', null, false),
            'debug' => $this->modx->getOption('ms2_payment_rbks_debug', null, false),
            'payment_method' => $this->modx->getOption('ms2_payment_rbks_payment_method', null, 'none'),
            'payment_object' => $this->modx->getOption('ms2_payment_rbks_payment_object', null, 'none'),
            'tax' => $this->modx->getOption('ms2_payment_rbks_tax', null, 'none'),
            'test_mode' => $this->modx->getOption('ms2_payment_rbks_test_mode', null, false),
            'shp_label' => 'modx_official'
        ], $config);
    }

    /* @inheritdoc} */
    public function send(msOrder $order)
    {
        $link = $this->getPaymentLink($order);

        if ($link) {
            return $this->success('', ['redirect' => $link]);
        }

        return $this->error('', []);
    }

    /**
     * Метод получения ссылки на оплату
     * @param msOrder $order
     * @return string
     */
    public function getPaymentLink(msOrder $order)
    {
        $configurationError = $this->getConfigurationError();
        if ($configurationError !== '') {
            return $this->paymentLinkError($configurationError);
        }

        if ($this->isKazakhstan()) {
            return $this->getLegacyPaymentLink($order);
        }

        $storedUrl = $this->getStoredInvoiceUrl($order);
        if ($storedUrl !== false) {
            return $storedUrl;
        }

        $request = [
            'MerchantLogin' => (string)$this->config['login'],
            'InvId' => (int)$order->get('id'),
            'InvoiceType' => 'OneTime',
            'Culture' => (string)$this->config['culture'],
            'OutSum' => (float)$this->formatSum($order->get('cost')),
            'Description' => 'Payment #' . $order->get('id'),
            'UserFields' => [
                'Shp_label' => (string)$this->config['shp_label'],
            ],
        ];

        $additionalParameters = [];
        $email = $this->getOrderEmail($order);
        if ($email !== '') {
            $additionalParameters['Email'] = $email;
        }
        if ($this->config['test_mode']) {
            $additionalParameters['IsTest'] = '1';
        }
        if ($additionalParameters) {
            $request['AdditionalParameters'] = $additionalParameters;
        }

        if ($this->config['receipt']) {
            $items = $this->getInvoiceItems($order);
            if ($items === false) {
                return false;
            }
            if ($items) {
                $request['InvoiceItems'] = $items;
            }
        }

        $response = $this->gateway($request);
        if ($response === false) {
            return false;
        }
        if (!isset($response['isSuccess']) || $response['isSuccess'] !== true) {
            $message = 'Invoice API rejected CreateInvoice.';
            if (isset($response['message']) && is_scalar($response['message'])) {
                $apiMessage = trim(preg_replace('/\s+/', ' ', (string)$response['message']));
                if ($apiMessage !== '') {
                    $message .= ' Robokassa: ' . substr($apiMessage, 0, 500);
                }
            }
            return $this->paymentLinkError($message);
        }

        if (!$this->isRussianPaymentUrl(isset($response['url']) ? $response['url'] : null)) {
            return $this->paymentLinkError('Invoice API returned an invalid payment URL.');
        }

        $this->storeInvoice($order, $response);

        return $response['url'];
    }

    private function isKazakhstan()
    {
        return in_array($this->config['country'], ['KAZ', 'KZ'], true);
    }

    private function getLegacyPaymentLink(msOrder $order)
    {
        $id = $order->get('id');
        $sum = $order->get('cost');
        $hashData = $this->getRequestHashData($order);

        $request = [
            'MrchLogin' => $this->config['login'],
            'OutSum' => $sum,
            'InvId' => $id,
            'Desc' => 'Payment #' . $id,
            'SignatureValue' => $this->getHash($hashData),
            'Shp_label' => $this->config['shp_label'],
            'Culture' => $this->config['culture'],
        ];

        $email = $this->getOrderEmail($order);
        if (!empty($email)) {
            $request['Email'] = $email;
        }

        if ($this->config['receipt']) {
            $receipt = $this->getReceipt($order);
            $receipt = $this->receiptEncode($receipt);
            $request['Receipt'] = $receipt;
        }

        if ($this->config['test_mode']) {
            $request['isTest'] = 1;
        }
        $response = $this->gateway($request);

        if ($response === false || !is_array($response)
            || !empty($response['error']) || empty($response['invoiceID'])
            || !is_scalar($response['invoiceID'])
        ) {
            return $this->paymentLinkError('Could not obtain a Kazakhstan payment link.');
        }

        return $this->config['checkoutUrl'] . $response['invoiceID'];
    }

    public function validateRedirectSignature(array $request)
    {
        foreach (['OutSum', 'InvId', 'SignatureValue'] as $key) {
            if (!isset($request[$key]) || !is_scalar($request[$key])) {
                return false;
            }
        }

        $hashData = [
            (string)$request['OutSum'],
            (string)$request['InvId'],
            $this->config['pass1'],
        ];
        $userParams = [];

        foreach ($request as $key => $value) {
            if (strpos($key, 'Shp_') === 0 && is_scalar($value)) {
                $userParams[$key] = (string)$value;
            }
        }

        ksort($userParams, SORT_STRING);
        foreach ($userParams as $key => $value) {
            $hashData[] = $key . '=' . $value;
        }

        $expected = $this->getHash($hashData);
        $received = strtoupper((string)$request['SignatureValue']);

        return hash_equals($expected, $received);
    }

    private function getOrderEmail(msOrder $order)
    {
        $email = trim((string)$order->get('email'));

        if (empty($email)) {
            $address = $order->getOne('Address');
            if ($address) {
                $email = trim((string)$address->get('email'));
            }
        }

        if (empty($email)) {
            $user = $order->getOne('User');
            if ($user) {
                $profile = $user->getOne('Profile');
                if ($profile) {
                    $email = trim((string)$profile->get('email'));
                }
            }
        }

        return $email;
    }

    /* @inheritdoc} */
    public function receive(msOrder $order)
    {
        $this->validateResultConfiguration($_POST);

        foreach (['SignatureValue', 'OutSum', 'InvId'] as $key) {
            if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
                $this->paymentError('Invalid ResultURL request.', $_POST);
            }
        }

        $id = $order->get('id');
        $crc = strtoupper((string)$_POST['SignatureValue']);
        $hashData = [
            (string)$_POST['OutSum'],
            $id,
            $this->config['pass2'],
            'Shp_label=modx_official'
        ];
        if (isset($_POST['shp_interface']) && is_scalar($_POST['shp_interface'])) {
            $hashData[] = 'shp_interface=' . (string)$_POST['shp_interface'];
        }
        $crc1 = $this->getHash($hashData);

        if (hash_equals($crc1, $crc)) {
            $status_paid = $this->modx->getOption('ms2_status_paid', null, 2);
            $this->ms2->changeOrderStatus($id, $status_paid);
            exit('OK'. $id);
        } else {
            $this->paymentError('Wrong signature.', $_POST);
        }
    }

    public function validateResultConfiguration(array $request)
    {
        $configurationError = $this->getConfigurationError();
        if ($configurationError !== '') {
            $this->paymentError($configurationError, $request);
        }
    }

    /**
     * @param string $text
     * @param array $request
     */
    public function paymentError($text, $request = [])
    {
        $this->modx->log(
            modX::LOG_LEVEL_ERROR,
            self::LOG_NAME . ' ' . $this->getSafeRequestErrorLog($text, $request)
        );
        header("HTTP/1.0 400 Bad Request");

        die('ERR: ' . $text);
    }

    private function getConfigurationError()
    {
        $credentials = [
            'Merchant Login' => $this->config['login'],
            'Password #1' => $this->config['pass1'],
            'Password #2' => $this->config['pass2'],
        ];
        $missing = [];
        $placeholders = [];

        foreach ($credentials as $name => $value) {
            if (!is_scalar($value) || trim((string)$value) === '') {
                $missing[] = $name;
            } elseif (in_array(strtolower(trim((string)$value)), [
                'your robokassa login',
                'password1',
                'password2',
            ], true)) {
                $placeholders[] = $name;
            }
        }

        $errors = [];
        if ($missing) {
            $errors[] = 'missing: ' . implode(', ', $missing);
        }
        if ($placeholders) {
            $errors[] = 'legacy placeholder: ' . implode(', ', $placeholders);
        }

        if (!$errors) {
            return '';
        }

        return 'Robokassa configuration is invalid (' . implode('; ', $errors) . ').';
    }

    private function getSafeRequestErrorLog($text, array $request)
    {
        $entry = [
            'event' => 'request_error',
            'reason' => $this->limitLogValue($text, 200),
            'method' => $this->limitLogValue(
                isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'UNKNOWN',
                16
            ),
        ];

        foreach (['InvId' => 'inv_id', 'OutSum' => 'out_sum'] as $source => $target) {
            if (isset($request[$source]) && is_scalar($request[$source])) {
                $entry[$target] = $this->limitLogValue($request[$source], 64);
            }
        }

        return json_encode($entry, JSON_UNESCAPED_SLASHES);
    }

    private function limitLogValue($value, $maxLength)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string)$value);
        $value = preg_replace('/[^\x20-\x7E]/', '?', $value);

        return substr($value, 0, $maxLength);
    }

    /**
     * Генерация подписи
     * @param array $hashData
     * @param bool $upper
     * @return string
     */
    private function getHash(array $hashData, $upper = true)
    {
        $hash = md5(implode(':', $hashData));

        if (!$upper) {
            return $hash;
        }

        return strtoupper($hash);
    }

    private function formatSum($sum, $decimal = 2)
    {
        return number_format($sum, $decimal, '.', '');
    }

    /**
     * Отдает данные для хэширования запроса
     * @param msOrder $order
     * @return array
     */
    private function getRequestHashData(msOrder $order)
    {
        $data = [
            $this->config['login'],
            $order->get('cost'),
            $order->get('id'),
        ];

        if ($this->config['receipt']) {
            $receipt = $this->getReceipt($order);
            $receipt = $this->receiptEncode($receipt);
            $data[] = $receipt;
        }

        $data[] = $this->config['pass1'];

        $data[] = 'Shp_label=' . $this->config['shp_label'];

        return $data;
    }

    /**
     * Передача товаров для фискализации
     * @param msOrder $order
     * @return array
     */
    private function getReceipt(msOrder $order)
    {
        /** @var msProduct[] $products */
        $products = $order->getMany('Products');
        $out = [
            'items' => []
        ];

        if (!$products) {
            return $out;
        }
        switch ($this->config['country']) {
            case 'RUS':
            case 'RU':
                $out['items'] = $this->getItemsRus($order, $products);
                break;
            case 'KAZ':
            case 'KZ':
                $out['items'] = $this->getItemsKaz($order, $products);
                break;
        }

        return $out;
    }

    private function receiptEncode($receipt)
    {
        return urlencode(urlencode(json_encode($receipt)));
    }

    private function getInvoiceItems(msOrder $order)
    {
        $receipt = $this->getReceipt($order);
        if (empty($receipt['items'])) {
            return [];
        }

        $items = [];
        foreach ($receipt['items'] as $item) {
            $quantity = (float)$item['quantity'];
            $lineTotal = (float)$item['sum'];
            if ($quantity <= 0 || $lineTotal < 0) {
                return $this->paymentLinkError('Invoice item quantity and cost must be valid.');
            }

            $items[] = [
                'Name' => (string)$item['name'],
                'Quantity' => $quantity,
                // The legacy receipt contains a line total, while Invoice API expects a unit price.
                'Cost' => $lineTotal / $quantity,
                'Tax' => (string)$item['tax'],
                'PaymentMethod' => (string)$item['payment_method'],
                'PaymentObject' => (string)$item['payment_object'],
            ];
        }

        return $items;
    }

    private function getStoredInvoiceUrl(msOrder $order)
    {
        $properties = $this->getOrderProperties($order);
        if (empty($properties['robokassa_invoice']) || !is_array($properties['robokassa_invoice'])) {
            return false;
        }

        $invoice = $properties['robokassa_invoice'];
        if (!isset($invoice['inv_id'], $invoice['out_sum'], $invoice['test_mode'], $invoice['url'])
            || (int)$invoice['inv_id'] !== (int)$order->get('id')
            || (string)$invoice['out_sum'] !== $this->formatSum($order->get('cost'))
            || (int)$invoice['test_mode'] !== (int)(bool)$this->config['test_mode']
            || !$this->isRussianPaymentUrl($invoice['url'])
        ) {
            return false;
        }

        return $invoice['url'];
    }

    private function storeInvoice(msOrder $order, array $response)
    {
        $properties = $this->getOrderProperties($order);
        $properties['robokassa_invoice'] = [
            'id' => isset($response['id']) && is_scalar($response['id']) ? (string)$response['id'] : '',
            'inv_id' => (int)$order->get('id'),
            'out_sum' => $this->formatSum($order->get('cost')),
            'test_mode' => (int)(bool)$this->config['test_mode'],
            'url' => (string)$response['url'],
        ];

        $order->set('properties', $properties);
        if (!$order->save()) {
            $this->modx->log(
                modX::LOG_LEVEL_ERROR,
                self::LOG_NAME . ' Could not save the Invoice API payment URL for order ' . (int)$order->get('id') . '.'
            );
        }
    }

    private function getOrderProperties(msOrder $order)
    {
        $properties = $order->get('properties');
        if (is_string($properties) && $properties !== '') {
            $decoded = json_decode($properties, true);
            $properties = is_array($decoded) ? $decoded : [];
        }

        return is_array($properties) ? $properties : [];
    }

    private function isRussianPaymentUrl($url)
    {
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https'
            && strtolower((string)parse_url($url, PHP_URL_HOST)) === 'auth.robokassa.ru';
    }

    private function getItemsRus($order, $products)
    {
        $paymentPrice = $order->getOne('Payment')->get('price');
        $out = [];
        foreach ($products as $product) {
            $tmp = [
                'name' => $product->get('name'),
                'quantity' => $product->get('count'),
                'payment_method' => $this->config['payment_method'],
                'payment_object' => $this->config['payment_object'],
                'tax' => $this->config['tax']
            ];

            $productsCost = 0;
            if ((float)$paymentPrice < 0 && preg_match('/%$/', $paymentPrice)) {
                $productsCost += ($product->get('cost') - ($product->get('cost') / 100 * abs((float)$paymentPrice)));
            }
            if ((float)$paymentPrice > 0 && preg_match('/%$/', $paymentPrice)) {
                $productsCost += ($product->get('cost') + ($product->get('cost') / 100 * abs((float)$paymentPrice)));
            }

            if ((float)$paymentPrice === 0.0) {
                $productsCost += $product->get('cost');
            }

            $tmp['sum'] = $productsCost;

            $out[] = $tmp;
        }

        if ($order->get('delivery_cost') > 0) {
            $out[] = [
                'name' => 'Доставка',
                'quantity' => 1,
                'sum' => $order->get('delivery_cost'),
                'payment_method' => $this->config['payment_method'],
                'payment_object' => 'service',
                'tax' => $this->config['tax']
            ];
        }

        return $out;
    }

    private function getItemsKaz($order, $products)
    {
        $out = [];
        foreach ($products as $product) {
            $out[] = [
                'name' => $product->get('name'),
                'quantity' => $product->get('count'),
                'sum' => $product->get('cost'),
                'tax' => $this->config['tax']
            ];
        }

        if ($order->get('delivery_cost') > 0) {
            $out[] = [
                'name' => 'Доставка',
                'quantity' => 1,
                'sum' => $order->get('delivery_cost'),
                'tax' => $this->config['tax']
            ];
        }

        return $out;
    }

    protected function gateway($data)
    {
        return $this->invoiceApiRequest($this->config['postUrl'], $data);
    }

    private function invoiceApiRequest($url, array $data)
    {
        if ($this->isKazakhstan()) {
            $body = http_build_query($data);
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
        } else {
            $jwt = $this->createInvoiceToken($data);
            if ($jwt === false) {
                return false;
            }
            // Invoice API accepts the JWT itself as a JSON string, not the payload object.
            $body = json_encode($jwt);
            if ($body === false) {
                return $this->paymentLinkError('Could not encode the Invoice API request.');
            }
            $headers = ['Content-Type: application/json', 'Accept: application/json'];
        }

        $curl = curl_init();
        if ($curl === false) {
            return $this->paymentLinkError('Could not initialize the payment request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ]);
        $response = curl_exec($curl);
        $errorNumber = curl_errno($curl);
        $errorText = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false || $errorNumber !== 0) {
            $message = 'Payment request failed (cURL error ' . $errorNumber . ').';
            if ($this->config['debug'] && $errorText !== '') {
                $message .= ' ' . substr(preg_replace('/\s+/', ' ', $errorText), 0, 500);
            }
            return $this->paymentLinkError($message);
        }
        if ($status < 200 || $status >= 300) {
            return $this->paymentLinkError('Payment API returned HTTP ' . $status . '.');
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $this->paymentLinkError('Payment API returned invalid JSON.');
        }

        return $decoded;
    }

    private function createInvoiceToken(array $payload)
    {
        $headerJson = json_encode(['typ' => 'JWT', 'alg' => 'MD5']);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($headerJson === false || $payloadJson === false) {
            return $this->paymentLinkError('Could not encode the invoice as JSON.');
        }

        $signingInput = $this->base64UrlEncode($headerJson) . '.' . $this->base64UrlEncode($payloadJson);
        $secret = (string)$this->config['login'] . ':' . (string)$this->config['pass1'];
        $signature = hash_hmac('md5', $signingInput, $secret, true);

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function paymentLinkError($message)
    {
        $this->modx->log(modX::LOG_LEVEL_ERROR, self::LOG_NAME . ' ' . $message);
        return false;
    }
}
