<?php

use Pimple\Container;
use Duitku\Config;
use Duitku\Pop;

class Payment_Adapter_duitku implements \FOSSBilling\InjectionAwareInterface
{
    protected ?Container $di = null;
    protected array $config = [];

    public function setDi(Container $di): void { $this->di = $di; }
    public function getDi(): ?Container { return $this->di; }

    public function __construct(array $config)
    {
        foreach (['merchant_code','api_key','test_mode'] as $k) {
            if (!isset($config[$k])) {
                throw new Payment_Exception("Duitku gateway missing configuration: $k");
            }
        }
        $this->config = $config;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description'               => 'Accept payments via Duitku POP (QRIS, VA, etc)',
            'logo'                      => [
                'logo'   => 'https://cdn.duitku.com/images/logo.png',
                'height' => '60px',
                'width'  => '60px',
            ],
            'form' => [
                'merchant_code' => ['text',  ['label' => 'Merchant Code', 'required' => true]],
                'api_key'       => ['text',  ['label' => 'API Key',      'required' => true]],
                'test_mode'     => ['radio', ['label' => 'Enable Sandbox','multiOptions' => ['1' => 'Yes', '0' => 'No'], 'required' => true]],
            ],
        ];
    }

    public function getType(): string
    {
        return Payment_AdapterAbstract::TYPE_HTML;
    }

    public function getServiceURL(): string
    {
        return '';
    }

    public function getHtml($api_admin, $invoice_id, $subscription = null): string
    {
        $invSvc  = $this->di['mod_service']('Invoice');
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoice_id, 'Invoice not found');
        $amount  = $invSvc->getTotalWithTax($invoice);

        // Use invoice primary key for mapping
        $merchantOrderId = (string)$invoice_id;
        $returnUrl       = $this->di['tools']->url('invoice/'.$invoice->hash);

        $customerDetail = [
            'firstName'   => $invoice->buyer_first_name,
            'lastName'    => $invoice->buyer_last_name,
            'email'       => $invoice->buyer_email,
            'phoneNumber' => $invoice->buyer_phone ?? '',
        ];

        $params = [
            'paymentAmount'   => $amount,
            'merchantOrderId' => $merchantOrderId,
            'productDetails'  => 'Invoice '.$invoice->serie.$invoice->nr,
            'customerDetail'  => $customerDetail,
            'callbackUrl'     => $this->di['mod_service']('Invoice','PayGateway')
                                        ->getCallbackUrl(
                                            $this->di['db']->findOne('PayGateway','gateway = "Duitku"'),
                                            $invoice
                                        ),
            'returnUrl'       => $returnUrl,
            'expiryPeriod'    => 60,
        ];
        error_log('[Duitku POP] createInvoice Params: '.json_encode($params));

        $sandbox = filter_var($this->config['test_mode'], FILTER_VALIDATE_BOOLEAN);
        $cfg     = new Config((string)$this->config['api_key'], (string)$this->config['merchant_code']);
        $cfg->setSandboxMode($sandbox);

        $resp = Pop::createInvoice($params, $cfg);
        error_log('[Duitku POP] createInvoice Response: '.$resp);
        $data = json_decode($resp, true);
        if (empty($data['paymentUrl'])) {
            throw new Payment_Exception('Duitku error: '.($data['Message'] ?? $resp));
        }

        $jsUrl  = $sandbox
                  ? 'https://sandbox.duitku.com/lib/js/duitku.js'
                  : 'https://cdn.duitku.com/lib/js/duitku.js';
        $iframe = htmlspecialchars($data['paymentUrl'], ENT_QUOTES, 'UTF-8');
        $ref    = htmlspecialchars($data['reference'] ?? '',    ENT_QUOTES, 'UTF-8');

        return <<<HTML
<iframe allowtransparency="true" frameborder="0" scrolling="no" src="{$iframe}" style="width:100%" height="600"></iframe>
<script src="{$jsUrl}"></script>
<script>
  checkout.process('{$ref}', {
    onSuccess: function(result) {
      window.location.href = '{$returnUrl}';
    },
    onFailure: function(result) {
      console.error('Payment failed', result);
    }
  });
</script>
HTML;
    }

    /**
     * Dipanggil oleh FOSSBilling IPN router
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        // Handle callback
        $sandbox = filter_var($this->config['test_mode'], FILTER_VALIDATE_BOOLEAN);
        $cfg     = new Config((string)$this->config['api_key'], (string)$this->config['merchant_code']);
        $cfg->setSandboxMode($sandbox);

        $raw   = Pop::callback($cfg);
        $notif = json_decode($raw, true);
        if (!$notif) {
            throw new \Exception("Invalid JSON callback: $raw");
        }
        error_log('[Duitku POP] callback payload: '.print_r($notif, true));

        // Process only on success
        if (($notif['resultCode'] ?? '') === '00') {
            $orderId      = (int)$notif['merchantOrderId'];
            $paymentValue = (float)($notif['amount'] ?? 0);
            $reference    = $notif['reference'] ?? '';

            // Fetch invoice and client
            $invoiceModel   = $this->di['db']->getExistingModelById('Invoice', $orderId, 'Invoice not found');
            $clientService  = $this->di['mod_service']('Client');
            $client         = $clientService->get(['id' => $invoiceModel->client_id]);

            // Record funds for client
            if ($paymentValue > 0) {
                $clientService->addFunds($client, $paymentValue, 'Duitku payment ref '.$reference, []);
            }

                        // Mark invoice paid
            $invoiceService = $this->di['mod_service']('Invoice');
            $invoiceService->markAsPaid($invoiceModel);
            error_log('[Duitku POP] Invoice #'.$orderId.' marked as paid via service');

            // Record or update transaction entry
            $tx = $this->di['db']->findOne(
                'Transaction',
                'invoice_id = ? and gateway_id = ?',
                [$invoiceModel->id, $gateway_id]
            );
            if (!$tx) {
                $tx = $this->di['db']->dispense('Transaction');
                $tx->invoice_id = $invoiceModel->id;
                $tx->gateway_id = $gateway_id;
            }
            // Transaction type on gateway
            $tx->type     = 'Payment';
            // Transaction status on Payment Gateway
            $tx->status   = (($notif['resultCode'] ?? '') === '00')
                            ? 'Complete'
                            : 'Pending validation';
            // Gateway's transaction reference ID
            $tx->txn_id   = $reference;
            $tx->amount   = $paymentValue;
            $tx->currency = $invoiceModel->currency;
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
            error_log('[Duitku POP] Transaction record stored/updated: ID '.$tx->id);;

            return ['status' => 'ok'];
        }

        return ['status' => 'failed'];
    }
}
