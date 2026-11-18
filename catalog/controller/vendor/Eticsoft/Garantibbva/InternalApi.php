<?php

/**
 * Class InternalApi 
 * @package Eticsoft\Garantibbva
 * @description InternalApi class is used to handle the internal api requests 
 * from the module admin UI. 
 * @version 1.0
 * @since 1.0
 * @author EticSoft A.Ş.
 * @license MIT
 */

namespace Eticsoft\Garantibbva;

use Eticsoft\Garantibbva\EticConfig;
use Eticsoft\Garantibbva\EticTools;
use Eticsoft\Garantibbva\Payment;
use Eticsoft\Garantibbva\ApiClient;

use Eticsoft\Garantibbva\Common\Models\Cart;
use Eticsoft\Garantibbva\Common\Models\Payer;
use Eticsoft\Garantibbva\Common\Models\Order;
use Eticsoft\Garantibbva\Common\Models\Invoice;
use Eticsoft\Garantibbva\Common\Models\Address;
use Eticsoft\Garantibbva\Common\Models\Shipping;
use Eticsoft\Garantibbva\Common\Models\PaymentRequest;
use Eticsoft\Garantibbva\Common\Models\PaymentModel;
use Eticsoft\Garantibbva\Common\Models\CartItem;



class InternalApi
{
    public ?string $action = '';
    public ?string $payload = '';
    public ?array $params = [];
    public ?array $response = [
        'status' => 'error',
        'message' => 'Internal error',
        'data' => [],
        'xfvv' => '',

    ];
    public ?string $xfvv = '';
    public bool $isCallback = false;
    public function run(): self
    {
        $this->setAction()->setParams()->setXfvv()->call();
        return $this;
    }

    public static function getInstance(): self
    {
        return new self();
    }

    public function setXfvv(): self
    {
        $this->xfvv = EticTools::postVal('iapi_xfvv', false);
        return $this;
    }

    public function setAction(): self
    {
        $this->action = EticTools::postVal('iapi_action', false);
        return $this;
    }

    public function setController($controller): self
    {
        EticTools::setController($controller);
        return $this;
    }

    public function setParams(): self
    {
        $params = EticTools::postVal('iapi_params', '');
        if (empty($params)) {
            return $this;
        }
        $params = str_replace('&quot;', '"', $params);
        $this->params = json_decode($params, true);
        return $this;
    }

    public function setSettings($settings): self
    {
        EticConfig::setSettings($settings);
        return $this;
    }

    public function call(): self
    {
        if (!$this->action) {
            return $this->setResponse('error', 'Action not found. #' . $this->action);
        }
        //make action first letter uppercase
        $this->action = ucfirst($this->action);
        if (!method_exists($this, 'action' . $this->action)) {
            return $this->setResponse('error', 'Action func not found. #' . 'action' . $this->action);
        }
        if ($this->xfvv != EticConfig::get('xfvv')) {
            return $this->setResponse('error', 'XFVV not matched');
        }
        $f_name = 'action' . $this->action;
        return $this->$f_name();
    }

    public function setResponse(string $status = 'success', string $message = '', array $data = [], array $details = [], array $meta = []): self
    {
        $this->response = [
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'details' => $details,
            'meta' => $meta
        ];

        if ($status != 'success' && $status != 'warning') {
            unset($this->response['data']);
        }

        return $this;
    }

    private function actionSaveApiKeys(): self
    {
        try {
            $publicKey = $this->params['iapi_publicKey'];
            if ($publicKey) {
                EticConfig::set('public_key', $publicKey);
            }
            $secretKey = $this->params['iapi_secretKey'];
            if ($secretKey) {
                EticConfig::set('secret_key', $secretKey);
            }
            $this->setResponse('success', 'Api keys saved');
            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', $e->getMessage());
            return $this;
        }
    }

    private function actionCheckApiKeys(): self
    {
        try {
            if (!EticConfig::get('public_key') || !EticConfig::get('secret_key')) {
                $this->setResponse('error', 'Api keys not found');
                return $this;
            }
            $apiClient = ApiClient::getInstanse();
            $this->response = $apiClient->post('/check/accesstoken', [
                'accesstoken' => $this->params['iapi_accessToken']
            ]);

            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', $e->getMessage());
            return $this;
        }
    }

    private function actionSetInstallmentOptions(): self
    {
        try {
            $installmentOptions = $this->params['iapi_installmentOptions'];
            if (empty($installmentOptions)) {
                $this->setResponse('error', 'Invalid installment options');
                return $this;
            }
            EticConfig::set('installments', json_encode($installmentOptions));
            $this->setResponse('success', 'Installment options updated');
            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', $e->getMessage());
            return $this;
        }
    }

    private function actionCreatePaymentLink(): self
    {
        try {

            if (empty(EticTools::getSessionData('order_id'))) {
                EticTools::redirectToCart();
                return $this;
            }

            $order = EticTools::getOrder();
            $order_id = EticTools::getOrderId();

            if (empty($order)) {
                $this->setResponse('error', 'Order not found');
                return $this;
            }
            if (!EticTools::isGarantibbvaOrder($order)) {
                $this->setResponse('error', 'Payment method not found');
                return $this;
            }
            // contexts
            $currency = EticTools::getCurrency();
            $cart = EticTools::getCart();
            $cart_items = $cart->getProducts();
            $cart_totals = EticTools::getCartTotals();
            $cart_total = EticTools::getAmountCurrencyFormated($cart_totals['total']);
            $discount = abs($cart_totals['coupon']);
            $shipping_cost = EticTools::getShippingCost();

            // Create Cart instance
            $cartModel = new Cart();

            // Add products to cart
            // discount vs de kontrol edilip eklenilecek
            foreach ($cart_items as $product) {
                $cartItem = new CartItem(
                    $product['product_id'],
                    $product['name'],
                    'product',
                    EticTools::getAmountCurrencyFormated($product['price']),
                    $product['quantity']
                );
                $cartModel->addItem($cartItem);
            }

            // Add discounts to cart
            if ($discount > 0) {
                $discountItem = new CartItem(
                    'DSC-' . EticTools::getSessionData('coupon'),
                    EticTools::getSessionData('coupon') . ' İndirim Kuponu',
                    'discount',
                    EticTools::getAmountCurrencyFormated($discount),
                    1
                );
                $cartModel->addItem($discountItem);
            }

            if ($shipping_cost > 0) {
                $shippingItem = new CartItem(
                    'SHP-1',
                    'Kargo Ücreti',
                    'shipping',
                    $shipping_cost,
                    1
                );
                $cartModel->addItem($shippingItem);
            }


            foreach ($cart_totals['taxes'] as $key => $tax) {
                if ($tax['value'] == 0) {
                    continue;
                }
                $taxItem = new CartItem(
                    'TAX-' . $key,
                    $tax['title'],
                    'tax',
                    EticTools::getAmountCurrencyFormated($tax['value']),
                    1
                );
                $cartModel->addItem($taxItem);
            }

            $shippingAddress = EticTools::getSessionData('shipping_address');
            $paymentAddress =  EticTools::getSessionData('payment_address');

            if (empty($shippingAddress) && empty($paymentAddress)) {
                $shippingAddress['address_1'] = 'test';
                $shippingAddress['city'] = 'test';
                $shippingAddress['zone'] = 'test';
                $shippingAddress['postcode'] = 'test';
                $shippingAddress['country'] = 'test';
                $shippingAddress['firstname'] = 'test';
                $shippingAddress['lastname'] = 'test';
                $shippingAddress['telephone'] = '5000000000';
                $paymentAddress = $shippingAddress;
            } else if (empty($shippingAddress)) {
                $shippingAddress =  $paymentAddress;
            } else if (empty($paymentAddress)) {
                $paymentAddress = $shippingAddress;
            }

            $shippingAddress = (object) $shippingAddress;
            $paymentAddress = (object) $paymentAddress;

            $customer = (object) EticTools::getSessionData('customer');

            $payment = new PaymentModel();
            $payment->setAmount($cart_total);
            $payment->setCurrency($currency);
            $payment->setBuyerFee(0);
            $payment->setMethod('creditcard');
            $payment->setMerchantReference($order_id);
            $baseUrl = EticTools::getLink('extension/garantibbva/api/garantibbvaiapi');
/*             $payment->setReturnUrl($baseUrl . '&' . http_build_query([
                'action' => 'orderConfirmation',
                'nonce' => $this->xfvv
            ])); */
            $payment->setCallbackUrl($baseUrl . '&' . http_build_query([
                'action' => 'callback',
                'nonce' => $this->xfvv
            ]));

            $payerAddress = new Address();
            $payerAddress->setLine1($paymentAddress->address_1);
            $payerAddress->setCity($paymentAddress->city);
            $payerAddress->setState($paymentAddress->zone);
            $payerAddress->setPostalCode($paymentAddress->postcode);
            $payerAddress->setCountry($paymentAddress->country);


            $phone = !empty($customer->telephone) ? $customer->telephone : '5000000000';


            $payer = new Payer();
            $payer->setFirstName($customer->firstname);
            $payer->setLastName($customer->lastname);
            $payer->setEmail($customer->email);
            $payer->setPhone($phone);
            $payer->setAddress($payerAddress);
            $payer->setIp($_SERVER['REMOTE_ADDR']);


            $invoice = new Invoice();
            $invoice->setId($order_id);
            $invoice->setFirstName($customer->firstname);
            $invoice->setLastName($customer->lastname);
            $invoice->setPrice($cart_total);
            $invoice->setQuantity(1);

            $shipmentAddress = new Address();
            $shipmentAddress->setLine1($shippingAddress->address_1);
            $shipmentAddress->setCity($shippingAddress->city);
            $shipmentAddress->setState($shippingAddress->zone);
            $shipmentAddress->setPostalCode($shippingAddress->postcode);
            $shipmentAddress->setCountry($shippingAddress->country);

            $shipping = new Shipping();
            $shipping->setFirstName($shippingAddress->firstname);
            $shipping->setLastName($shippingAddress->lastname);
            $shipping->setPhone($phone);
            $shipping->setEmail($customer->email);
            $shipping->setAddress($shipmentAddress);

            $order = new Order();
            $order->setCart($cartModel->toArray()['items']);
            $order->setShipping($shipping);
            $order->setInvoice($invoice);


            $paymentRequest = new PaymentRequest();
            $paymentRequest->setPayment($payment);
            $paymentRequest->setPayer($payer);
            $paymentRequest->setOrder($order);

            EticTools::saveOrderSessionId($order_id);
            $result = Payment::createPayment($paymentRequest->toArray());

            if (isset($result['status']) && $result['status'] == 'success') {
                $payment_token = $result['data']['payment_token'] ?? '';
                if (!EticTools::isValidPaymentToken($payment_token)) {
                    $this->setResponse('error', 'Payment could not be started');
                    return $this;
                }
                EticTools::savePaymentToken($order_id, $payment_token);
            }

            $this->response = $result;
            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', $e->getMessage());
            return $this;
        }
    }

    private function actionConfirmOrder(): self
    {
        $order_id = (int) EticTools::getOrderId();
        if (!$order_id) {
            $redirect_url = EticTools::getLink('checkout/failure');
            $this->setResponse('warning', 'Order confirmation failed', [
                'redirect_url' => $redirect_url
            ]);
            return $this;
        }

        $db = EticTools::getDb();
        $lockName = 'garantibbva_confirm_' . $order_id;
        $lockResult = $db->query("SELECT GET_LOCK('" . $db->escape($lockName) . "', 10) AS locked");
        $locked = !empty($lockResult->row['locked']);

        if (!$locked) {
            $this->setResponse('error', 'Order confirmation in progress');
            return $this;
        }

        try {
            if (EticTools::isOrderPaid($order_id)) {
                $redirect_url = EticTools::getLink('checkout/success');
                $this->setResponse('success', 'Order already confirmed', [
                    'redirect_url' => $redirect_url
                ]);
                return $this;
            }

            $order = EticTools::getOrder();
            if (!$this->isOrderConfirmable($order_id, $order)) {
                return $this->setConfirmFailedResponse();
            }

            $process_token = $this->params['process_token'] ?? '';
            if (!is_string($process_token) || $process_token === '') {
                return $this->setConfirmFailedResponse();
            }
            $res = Payment::validatePayment($process_token);

            if (!is_array($res) || ($res['status'] ?? '') != 'success' || !is_array($res['data']['process'] ?? null) || !is_array($res['data']['transaction'] ?? null)) {
                $redirect_url = EticTools::getLink('checkout/failure');
                $this->setResponse('warning', 'Order confirmation failed', [
                    'redirect_url' => $redirect_url
                ]);
                return $this;
            }

            $processData = $res['data']['process'];
            $data = $res['data']['transaction'];
 
            if ($data['status'] == 'completed' && $processData['process_status'] == 'completed' && $this->isPaymentMatchingOrder($order_id, $order, $processData, $data)) {
                $transaction_amount = $processData['amount'];
                $order_currency = $order['currency_code'];

                $commission_fee = floatval($transaction_amount) - EticTools::getAmountCurrencyFormated($order['total'], $order_currency);
 
                EticTools::addOrderHistory($processData['payment_token'], $this->isCallback);
                if ($commission_fee > 0) {
                    EticTools::addCommissionFeeToTotal(EticTools::getOrderId(), $commission_fee, $order_currency);
                }

                if ($this->isCallback) {
                    EticTools::clearCartByOrderId($order_id);
                }

                $redirect_url = EticTools::getLink('checkout/success');
                $this->setResponse('success', 'Order confirmed', [
                    'redirect_url' => $redirect_url
                ]);
                return $this;
            } else {
                $redirect_url = EticTools::getLink('checkout/failure');
                $this->setResponse('warning', 'Order confirmation failed', [
                    'redirect_url' => $redirect_url
                ]);
                return $this;
            }
        } catch (\Exception $e) {
            $redirect_url = EticTools::getLink('checkout/failure');
            $this->setResponse('warning', 'Order confirmation failed', [
                'redirect_url' => $redirect_url
            ]);
            return $this;
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . $db->escape($lockName) . "')");
        }
    }

    private function isOrderConfirmable(int $order_id, $order): bool
    {
        if (empty($order)) {
            return false;
        }
        if (!EticTools::isGarantibbvaOrder($order)) {
            EticTools::logConfirmFailure($order_id, 'payment method');
            return false;
        }
        if ((int) $order['order_status_id'] !== 0) {
            EticTools::logConfirmFailure($order_id, 'order status');
            return false;
        }
        return true;
    }

    private function isPaymentMatchingOrder(int $order_id, array $order, array $processData, array $transactionData): bool
    {
        if (!EticTools::isPaymentTokenOfOrder($order_id, $processData['payment_token'] ?? '')) {
            EticTools::logConfirmFailure($order_id, 'payment token');
            return false;
        }
        if (strtoupper((string) ($transactionData['currency'] ?? '')) !== strtoupper((string) $order['currency_code'])) {
            EticTools::logConfirmFailure($order_id, 'currency');
            return false;
        }
        $expected = EticTools::getOrderTotalInOrderCurrency($order);
        $paid = (float) ($processData['amount'] ?? 0);
        // Compare in cents and allow a 0.01 rounding difference
        if ($expected === null || (int) round($paid * 100) < (int) round($expected * 100) - 1) {
            EticTools::logConfirmFailure($order_id, 'amount ' . round($paid, 2) . ' < ' . ($expected === null ? '?' : round($expected, 2)));
            return false;
        }
        return true;
    }

    private function setConfirmFailedResponse(): self
    {
        return $this->setResponse('warning', 'Order confirmation failed', [
            'redirect_url' => EticTools::getLink('checkout/failure')
        ]);
    }

    private function actionSetModuleSettings(): self
    {
        $settings = $this->params['iapi_moduleSettings'];
        try {
            if ($settings) {
                foreach ($settings as $key => $value) {
                    EticConfig::set($key, $value);
                }
                $this->setResponse('success', 'Module settings updated');
            } else {
                $this->setResponse('error', 'Invalid module settings');
            }
        } catch (\Exception $e) {
            $this->setResponse('error', 'Failed to update module settings: ' . $e->getMessage());
        }
        return $this;
    }

    private function actionGetMerchantInfo(): self
    {
        try {
            $country_info = EticTools::getCountryInfo(EticTools::getConfigData('config_country_id'));
            $currency_info = EticTools::getCurrencyInfo(EticTools::getConfigData('config_currency'));
            $data = [
                'store' => [
                    'name' => EticTools::getConfigData('config_name'),
                    'url' => empty(HTTP_CATALOG) ? EticTools::getConfigData('site_url') : HTTP_CATALOG,
                    'admin_email' => EticTools::getConfigData('config_email'),
                    'phone' => EticTools::getConfigData('config_telephone'),
                    'address' => [
                        'address' => EticTools::getConfigData('config_address'),
                        'country' => $country_info ? $country_info['name'] : 'TR'
                    ],
                    'language' => EticTools::getConfigData('config_language_admin')
                ],
                'payment' => [
                    'currency' => EticTools::getConfigData('config_currency'),
                    'currency_symbol' => $currency_info ? $currency_info['symbol_right'] : '₺'
                ]
            ];
    
            $this->setResponse('success', 'Merchant info retrieved successfully', $data);
            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', 'Failed to get merchant info: ' . $e->getMessage());
            return $this;
        }
    }
    
    public function getResponse()
    {
        return $this->response;
    }
}
