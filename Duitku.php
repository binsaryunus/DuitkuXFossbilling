<?php

// Autoload dependencies
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Pop.php';
require_once __DIR__ . '/Sanitizer.php';

use Duitku\Config;
use Duitku\Request;
use Duitku\Pop;
use Duitku\Sanitizer;

class Payment_Adapter_duitku
{
    protected $config = [];

    // Konstruktor untuk menerima konfigurasi dari FOSSBilling
    public function __construct($config)
    {
        $this->config = $config;
    }

    // Fungsi untuk mendapatkan konfigurasi gateway
    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description' => 'Accept payments via Duitku (API POP)',
            'logo' => [
                'logo' => 'https://cdn.duitku.com/images/logo.png',
                'height' => '60px',
                'width' => '60px',
            ],
            'form' => [
                'merchant_code' => [
                    'text',
                    [
                        'label' => 'Merchant Code',
                        'required' => true,
                    ],
                ],
                'api_key' => [
                    'text',
                    [
                        'label' => 'API Key',
                        'required' => true,
                    ],
                ],
                'callback_url' => [
                    'text',
                    [
                        'label' => 'Callback URL',
                        'required' => true,
                    ],
                ],
                'return_url' => [
                    'text',
                    [
                        'label' => 'Return URL',
                        'required' => true,
                    ],
                ],
                'test_mode' => [
                    'radio',
                    [
                        'label' => 'Enable Test Mode?',
                        'multiOptions' => [
                            '1' => 'Yes',
                            '0' => 'No',
                        ],
                        'required' => true,
                    ],
                ],
            ],
        ];
    }

    // Fungsi untuk memproses pembayaran
    public function singlePayment($invoice)
    {
        $merchantCode = $this->config['merchant_code'];
        $apiKey = $this->config['api_key'];
        $testMode = $this->config['test_mode'] ?? '1';

        $orderId = $invoice->getNumber();
        $amount = (int) $invoice->getTotal();
        $callbackUrl = $this->config['callback_url'];
        $returnUrl = $this->config['return_url'];

        $buyer = $invoice->getBuyer();
        $email = $buyer->getEmail();
        $customerName = $buyer->getFirstName() . ' ' . $buyer->getLastName();

        $params = [
            'merchantCode'    => $merchantCode,
            'paymentAmount'   => $amount,
            'merchantOrderId' => $orderId,
            'productDetails'  => 'Pembayaran ' . $invoice->getTitle(),
            'email'           => $email,
            'customerVaName'  => $customerName,
            'callbackUrl'     => $callbackUrl,
            'returnUrl'       => $returnUrl,
            'expiryPeriod'    => 60
        ];

        $signature = hash('sha256', $merchantCode . $orderId . $amount . $apiKey);

        $config = new Config($apiKey, $merchantCode, $testMode == '1');

        try {
            $response = Pop::createInvoice($params, $config);
            $result = json_decode($response, true);

            if (isset($result['paymentUrl'])) {
                header('Location: ' . $result['paymentUrl']);
                exit;
            } else {
                error_log('Duitku Error: ' . $response);
                return '<div style="color:red;text-align:center">Gagal membuat invoice di Duitku.</div>';
            }
        } catch (Exception $e) {
            error_log('DUITKU Error: ' . $e->getMessage());
            return '<div style="color:red;text-align:center">Gagal membuat invoice di Duitku.</div>';
        }
    }

    // Fungsi untuk memproses callback dari Duitku
    public function processCallback($data)
    {
        $merchantOrderId = $data['merchantOrderId'];
        $amount = $data['amount'];
        $reference = $data['reference'];
        $statusCode = $data['statusCode'];

        if ($statusCode === '00') {
            $api_admin = new \Api_Admin();
            $invoice = $api_admin->invoice_get(['number' => $merchantOrderId]);

            if ($invoice) {
                $api_admin->invoice_payment_complete([
                    'id'             => $invoice['id'],
                    'amount'         => $amount,
                    'transaction_id' => $reference,
                    'note'           => 'Pembayaran via Duitku POP'
                ]);
                return ['status' => 'ok'];
            }
        }

        error_log("Duitku Callback Error: Invoice tidak ditemukan dengan nomor $merchantOrderId");
        return ['status' => 'failed'];
    }
}
