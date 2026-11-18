<?php

namespace Eticsoft\Garantibbva;

use Opencart\System\Library\Request;

class EticTools
{

    private static $controller;

    public static function setController($controller)
    {
        self::$controller = $controller;
    }

    /**
     * OpenCart 4 için POST değerini alma
     */
    public static function postVal($key, $default = null)
    {
        $request = new Request();
        if (isset($request->post[$key])) {
            return $request->post[$key];
        }
        return $default;
    }

    /**
     * OpenCart 4 için GET değerini alma
     */
    public static function getVal($key, $default = null)
    {
        $request = new Request();
        if (isset($request->get[$key])) {
            return $request->get[$key];
        }
        return $default;
    }

    public static function getSessionData($key)
    {
        return !empty(self::$controller->session->data[$key]) ? self::$controller->session->data[$key] : [];
    }

    public static function getSession()
    {
        return self::$controller->session->data;
    }


    public static function getOrderId()
    {
        return self::getSessionData('order_id');
    }

    public static function setOrderId($order_id)
    {
        self::$controller->session->data['order_id'] = (int) $order_id;
    }

    public static function getOrder()
    {
        return self::getOrderInstance()->getOrder(self::getOrderId());
    }

    public static function getOrderById($order_id)
    {
        return self::getOrderInstance()->getOrder((int) $order_id);
    }

    public static function getDb()
    {
        return self::$controller->db;
    }

    public static function getPaidOrderStatusId()
    {
        return !empty(self::$controller->config->get('payment_garantibbva_order_status')) ? (int) self::$controller->config->get('payment_garantibbva_order_status') : 2;
    }

    public static function isOrderPaid($order_id)
    {
        $order = self::getOrderById($order_id);
        return !empty($order) && (int) $order['order_status_id'] === self::getPaidOrderStatusId();
    }

    public static function getOrderPaymentCode($order)
    {
        // OpenCart 4.0.2.0+ stores the payment method as an array, older 4.0 versions use payment_code
        if (isset($order['payment_method']) && is_array($order['payment_method'])) {
            return (string) ($order['payment_method']['code'] ?? '');
        }
        return (string) ($order['payment_code'] ?? '');
    }

    public static function isGarantibbvaOrder($order)
    {
        return !empty($order) && in_array(self::getOrderPaymentCode($order), ['garantibbva', 'garantibbva.garantibbva'], true);
    }

    public static function getOrderInstance()
    {
        self::$controller->load->model('checkout/order');
        return self::$controller->model_checkout_order;
    }

    public static function getCart()
    {
        self::$controller->load->model('checkout/cart');
        return self::$controller->model_checkout_cart;
    }

    public static function clearCart()
    {
        self::$controller->cart->clear();
        unset(self::$controller->session->data['shipping_method']);
        unset(self::$controller->session->data['shipping_methods']);
        unset(self::$controller->session->data['payment_method']);
        unset(self::$controller->session->data['payment_methods']);
        unset(self::$controller->session->data['coupon']);
        unset(self::$controller->session->data['reward']);
        unset(self::$controller->session->data['voucher']);
        unset(self::$controller->session->data['vouchers']);
        unset(self::$controller->session->data['order_id']);
    }

    public static function saveOrderSessionId($order_id)
    {
        $db = self::getDb();

        $db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "garantibbva_session` (
            `order_id` INT(11) NOT NULL,
            `session_id` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $session_id = self::$controller->session->getId();
        $db->query("REPLACE INTO `" . DB_PREFIX . "garantibbva_session` SET 
            order_id = '" . (int)$order_id . "', 
            session_id = '" . $db->escape($session_id) . "'");
    }

    public static function clearCartByOrderId($order_id)
    {
        $order = self::getOrderById($order_id);
        if (empty($order)) {
            return;
        }

        $db = self::getDb();
        $customer_id = (int) $order['customer_id'];

        if ($customer_id > 0) {
            $db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE customer_id = '" . $customer_id . "'");
        } else {
            $result = $db->query("SELECT session_id FROM `" . DB_PREFIX . "garantibbva_session` WHERE order_id = '" . (int)$order_id . "'");
            if ($result->num_rows && !empty($result->row['session_id'])) {
                $db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE session_id = '" . $db->escape($result->row['session_id']) . "' AND customer_id = '0'");
            }
        }

        $db->query("DELETE FROM `" . DB_PREFIX . "garantibbva_session` WHERE order_id = '" . (int)$order_id . "'");
    }

    private static function createPaymentTokenTable()
    {
        self::getDb()->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "garantibbva_payment_token` (
            `payment_token` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `order_id` INT(11) NOT NULL,
            `date_added` DATETIME NOT NULL,
            PRIMARY KEY (`payment_token`),
            KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }

    public static function isValidPaymentToken($payment_token)
    {
        return is_string($payment_token) && preg_match('/^[A-Za-z0-9]{1,64}$/', $payment_token) === 1;
    }

    public static function savePaymentToken($order_id, $payment_token)
    {
        self::createPaymentTokenTable();
        $db = self::getDb();
        $db->query("INSERT IGNORE INTO `" . DB_PREFIX . "garantibbva_payment_token` SET
            payment_token = '" . $db->escape($payment_token) . "',
            order_id = '" . (int)$order_id . "',
            date_added = NOW()");
    }

    public static function isPaymentTokenOfOrder($order_id, $payment_token)
    {
        if (!self::isValidPaymentToken($payment_token)) {
            return false;
        }
        self::createPaymentTokenTable();
        $db = self::getDb();
        $result = $db->query("SELECT order_id FROM `" . DB_PREFIX . "garantibbva_payment_token` WHERE payment_token = '" . $db->escape($payment_token) . "' AND order_id = '" . (int)$order_id . "'");
        return $result->num_rows > 0;
    }

    public static function getCartItems()
    {
        return self::$controller->model_checkout_cart->getProducts();
    }

    public static function getCartTotals()
    {
        $cart = self::getCart();
        $totals = [];
        $taxes = self::$controller->cart->getTaxes();
        $total = 0;
        ($cart->getTotals)($totals, $taxes, $total);
        $coupon = 0;
        $cleared_taxes = [];
        foreach ($totals as $item) {
            if ($item['code'] === 'coupon') {
                $coupon = $item['value'];
            }
            if ($item['code'] === 'tax') {
                $cleared_taxes[] = $item;
            }
        }
        $res = [
            'total' => $total,
            'totals' => $totals,
            'taxes' => $cleared_taxes,
            'coupon' => $coupon
        ];
        return $res;
    }

    public static function getCurrency()
    {
        return self::$controller->session->data['currency'] ?? self::$controller->config->get('config_currency');
    }

    public static function getAmountCurrencyFormated($amount, $currency = null)
    {
        return self::$controller->currency->format($amount, $currency ?? self::getCurrency(), false, false);
    }

    public static function getOrderTotalInOrderCurrency($order)
    {
        // Uses the currency rate saved with the order; returns null for an unknown currency
        $total = self::$controller->currency->format((float) $order['total'], (string) $order['currency_code'], (float) $order['currency_value'], false);
        return $total === '' ? null : (float) $total;
    }

    public static function getShippingCost()
    {
        $tax = isset(self::$controller->session->data['shipping_method']) ? self::$controller->session->data['shipping_method']['cost'] : 0;
        return self::getAmountCurrencyFormated($tax);
    }

    public static function calculateWithTaxAmount($amount, $tax_class_id)
    {
        return self::$controller->tax->calculate($amount, $tax_class_id, self::$controller->config->get('config_tax'));
    }

    public static function redirectToCart()
    {
        self::$controller->response->redirect(self::$controller->url->link('checkout/cart'));
    }

    public static function addCommissionFeeToTotal($order_id, $amount, $order_currency = null)
    {
        self::$controller->load->language('extension/garantibbva/payment/garantibbva');
        $title = self::$controller->language->get('commission_fee');

        $default_currency = self::$controller->config->get('config_currency');
        $currency = $order_currency ?? self::getCurrency();
        $amount = self::$controller->currency->convert($amount, $currency, $default_currency);

        self::$controller->db->query("INSERT INTO `" . DB_PREFIX . "order_total` SET order_id = '" . (int)$order_id . "', code = 'fee', title = '" . $title . "', value = '" . (float)$amount . "', sort_order = '4'");
        self::$controller->db->query("UPDATE `" . DB_PREFIX . "order_total` SET value = value + '" . (float)$amount . "' WHERE order_id = '" . (int)$order_id . "' AND code = 'total'");
        self::$controller->db->query("UPDATE `" . DB_PREFIX . "order` SET total = total + '" . (float)$amount . "' WHERE order_id = '" . (int)$order_id . "'");
    }

    public static function addOrderHistory($payment_token, bool $isCallback = false)
    {
        self::$controller->load->model('checkout/order');
        self::$controller->load->language('extension/garantibbva/payment/garantibbva');
        $flowType = $isCallback ? '[Callback]' : '[Normal]';
        self::$controller->model_checkout_order->addHistory(
            self::getOrderId(),
            !empty(self::$controller->config->get('payment_garantibbva_order_status')) ? self::$controller->config->get('payment_garantibbva_order_status') : 2,
            self::$controller->language->get('payment_success') . ' Garanti BBVA ' . $payment_token . ' ' . $flowType
        );
    }

    public static function logConfirmFailure($order_id, $reason)
    {
        self::$controller->log->write('Garanti BBVA: order ' . (int)$order_id . ' not confirmed (' . $reason . ')');
    }

    public static function getLink($route)
    {
        return self::$controller->url->link($route);
    }

    public static function getConfigData($key)
    {
        return self::$controller->config->get($key);
    }

    public static function getCountryInfo($country_id) {
        self::$controller->load->model('localisation/country');
        return self::$controller->model_localisation_country->getCountry($country_id);
    }

    public static function getCurrencyInfo($currency) {  
        self::$controller->load->model('localisation/currency');
        return self::$controller->model_localisation_currency->getCurrencyByCode($currency);
    }
}
