<?php

$newBasePaymentHandler = dirname(__FILE__, 3) . '/handlers/mspaymenthandler.class.php';
$oldBasePaymentHandler = dirname(__FILE__, 3) . '/model/minishop2/mspaymenthandler.class.php';

if (!class_exists('msPaymentInterface')) {
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

        $checkoutUrl = 'https://auth.robokassa.ru/Merchant/Index/';
        $postUrl = 'https://auth.robokassa.ru/Merchant/Indexjson.aspx';

        $this->config = array_merge([
            'paymentUrl' => $paymentUrl,
            'checkoutUrl' => $checkoutUrl,
            'postUrl' => $postUrl,
            'login' => $this->modx->getOption('ms2_payment_rbks_login'),
            'pass1' => $this->modx->getOption('ms2_payment_rbks_pass1'),
            'pass2' => $this->modx->getOption('ms2_payment_rbks_pass2'),
            'country' => $this->modx->getOption('ms2_payment_rbks_country'),
            'currency' => $this->modx->getOption('ms2_payment_rbks_currency', null, 'rub'),
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
            'IncCurrLabel' => $this->config['currency'],
            'Culture' => $this->config['culture'],
        ];

        if ($this->config['receipt']) {
            $receipt = $this->getReceipt($order);
            $receipt = $this->receiptEncode($receipt);
            $request['Receipt'] = $receipt;
        }

        if ($this->config['test_mode']) {
            $request['isTest'] = 1;
        }

        $response = $this->gateway($request);

        if (isset($response['error']) && count($response['error']) > 0) {
            $this->modx->log(1, 'Ошибка получения ссылки на оплату');
            return false;
        }

        return $this->config['checkoutUrl'] . $response['invoiceID'];
    }


    /* @inheritdoc} */
    public function receive(msOrder $order)
    {
        $id = $order->get('id');
        $crc = $_POST['SignatureValue'];
        $crc1 = $this->getHash([
            $_POST['OutSum'],
            $id,
            $this->config['pass2'],
            'Shp_label=modx_official'
        ]);

        if ($crc === $crc1) {
            $this->ms2->changeOrderStatus($id, 2);
            exit('OK');
        } else {
            $this->paymentError('Wrong signature.', $_POST);
        }
    }


    /**
     * @param $text
     * @param array $request
     */
    public function paymentError($text, $request = [])
    {
        $this->modx->log(
            modX::LOG_LEVEL_ERROR,
            self::LOG_NAME . ' ' . $text . ', request: ' . print_r($request, true)
        );
        header("HTTP/1.0 400 Bad Request");

        die('ERR: ' . $text);
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
                $out['items'] = $this->getItemsRus($order, $products);
                break;
            case 'KAZ':
                $out['items'] = $this->getItemsKaz($order, $products);
                break;
        }

        return $out;
    }

    private function receiptEncode($receipt)
    {
        return urlencode(urlencode(json_encode($receipt)));
    }

    private function getItemsRus($order, $products)
    {
        $out = [];
        foreach ($products as $product) {
            $out[] = [
                'name' => $product->get('name'),
                'quantity' => $product->get('count'),
                'sum' => $product->get('cost'),
                'payment_method' => $this->config['payment_method'],
                'payment_object' => $this->config['payment_object'],
                'tax' => $this->config['tax']
            ];
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
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->config['postUrl'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_SSLVERSION => 6
        ));
        $response = curl_exec($curl);
        $response = json_decode($response, true);
        curl_close($curl);
        return $response;
    }

    private function log($text, $data)
    {
        $this->modx->log(
            modX::LOG_LEVEL_ERROR,
            self::LOG_NAME . ' ' . $text . print_r($data, true)
        );
    }
}
