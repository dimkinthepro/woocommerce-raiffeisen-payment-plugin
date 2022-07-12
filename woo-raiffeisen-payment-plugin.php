<?php
/*
Plugin Name:       WooCommerce Raiffeisen payment plugin
Plugin URI:        https://github.com/dimkinthepro/woocommerce-raiffeisen-payment-plugin
Description:       A WooCommerce Extension that adds Raiffeisen payment plugin
Version:           1.0.0
Author:            DimkinThePro
Author URI:        https://github.com/dimkinthepro
License:           MIT
*/;

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'initRaiffeisenPaymentPlugin');

/**
 * @see https://github.com/Raiffeisen-DGTL/ecom-sdk-javascript
 */
function initRaiffeisenPaymentPlugin()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    function addRaiffeisenPaymentPlugin($methods)
    {
        $methods[] = 'RaiffeisenPaymentPlugin';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'addRaiffeisenPaymentPlugin');

    class RaiffeisenPaymentPlugin extends WC_Payment_Gateway
    {
        const SUCCESS_STATUSES = [
            'PAID',
            'SUCCESS',
        ];

        public $title;
        public $description;
        private $secretKey;
        private $publicKey;
        private $callbackProcessingUrl;
        private $successProcessingUrl;
        private $successUrl;
        private $cartUrl;

        public function __construct()
        {
            $this->id = 'wc_rpg';
            $this->method_title = __('Raiffeisen payment plugin', "wc_{$this->id}");
            $this->icon = plugins_url('logo.png', __FILE__);
            $this->has_fields = false;

            $this->initAdminFormFields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->secretKey = $this->get_option('secretKey');
            $this->publicKey = $this->get_option('publicKey');
            $this->callbackProcessingUrl = home_url("/wc-api/raiffeisen_callback");
            $this->successProcessingUrl = home_url("/wc-api/raiffeisen_success");
            $this->successUrl = $this->get_return_url();
            $this->cartUrl = get_permalink(wc_get_page_id('cart'));

            add_action("woocommerce_update_options_payment_gateways_{$this->id}", [$this, 'process_admin_options']);
            add_action("woocommerce_receipt_{$this->id}", [$this, 'receiptPage']);
            add_action("woocommerce_api_raiffeisen_callback", [$this, 'callbackProcessing']);
            add_action("woocommerce_api_raiffeisen_success", [$this, 'successProcessing']);
        }

        public function initAdminFormFields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable/Disable', "wc_{$this->id}"),
                    'type' => 'checkbox',
                    'label' => __('Enable Raiffeisen Payment Gateway', "wc_{$this->id}"),
                    'default' => 'yes'
                ],
                'title' => [
                    'title' => __('Title', "wc_{$this->id}"),
                    'type' => 'text',
                    'description' => __('Payment method title which the customer will see during checkout', "wc_{$this->id}"),
                    'default' => __('Raiffeisen Bank.', "wc_{$this->id}"),
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => __('Description', "wc_{$this->id}"),
                    'type' => 'textarea',
                    'description' => __('Payment method description which the customer will see during checkout', "wc_{$this->id}"),
                    'default' => __('', "wc_{$this->id}"),
                    'desc_tip' => true,
                ],
                'secretKey' => [
                    'title' => __('Merchant ID (Secret key)', "wc_{$this->id}"),
                    'type' => 'text',
                    'description' => __('Merchant ID provided by bank', "wc_{$this->id}"),
                    'default' => __('', "wc_{$this->id}"),
                    'desc_tip' => true,
                ],
                'publicKey' => [
                    'title' => __('Terminal  ID (Public key)', "wc_{$this->id}"),
                    'type' => 'text',
                    'description' => __('Terminal ID provided by bank', "wc_{$this->id}"),
                    'default' => __('', "wc_{$this->id}"),
                    'desc_tip' => true,
                ],
            ];
        }

        /**
         * {@inheritDoc}
         */
        public function admin_options()
        {
            ?>
            <h3><?php __('Raiffeisen payment plugin', "wc_{$this->id}"); ?></h3>
            <p><?php __('Raiffeisen is a popular payment plugin for online shopping', "wc_{$this->id}"); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
        }

        /**
         * {@inheritDoc}
         */
        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            ];
        }

        public function receiptPage($order)
        {
            echo $this->generateJsForm($order);
        }

        public function callbackProcessing()
        {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
            $orderId = (int) ($data['transaction']['orderId'] ?? null);

            if (!$orderId) {
                echo 'FAIL';
                die();
            }

            $status = $this->getOrderStatus($orderId);
            $this->orderStatusProcessing($orderId, $status);

            echo 'OK';
            die();
        }

        public function successProcessing()
        {
            $orderId = (int) ($_GET['orderId'] ?? null);
            if (!$orderId) {
                wp_redirect($this->cartUrl);

                return;
            }

            $order = new WC_Order($orderId);
            $order_data = $order->get_data();
            if ($order_data['status'] === 'completed') {
                wp_redirect($this->successUrl);

                return;
            }

            $status = $this->getOrderStatus($orderId);
            $this->orderStatusProcessing($orderId, $status);
            if (true === in_array($status, self::SUCCESS_STATUSES, true)) {
                wp_redirect($this->successUrl);

                return;
            }

            wp_redirect($this->cartUrl);
        }

        private function orderStatusProcessing(int $orderId, string $status)
        {
            if (true === in_array($status, self::SUCCESS_STATUSES, true)) {
                $order = new WC_Order($orderId);
                $order->update_status('completed');
            }
        }

        private function getOrderStatus(int $orderId)
        {
            $ch = curl_init("https://e-commerce.raiffeisen.ru/api/payment/v1/orders/{$orderId}");
            $authorization = "Authorization: Bearer " . $this->secretKey;
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $result = curl_exec($ch);
            curl_close($ch);
            $result = @json_decode($result, true) ?? [];

            return (string) ($result['status']['value'] ?? null);
        }

        private function generateJsForm($orderId)
        {
            $order = new WC_Order($orderId);
            $total = $order->get_total();

            $items = [];
            foreach ($order->get_items() as $item) {
                $items[] = [
                    'name' => $item['name'],
                    'price' => $item['subtotal'],
                    'quantity' => $item['qty'],
                    'amount' => $item['total'],
                    'vatType' => 'VAT20',
                ];
            }

            $js = file_get_contents('https://pay.raif.ru/pay/sdk/v2/payment.styled.js');
            $successProcessingUrl = $this->successProcessingUrl . "?orderId={$orderId}";

            wc_enqueue_js($js . '
                let pay = function() {
                    const paymentPage = new PaymentPageSdk("' . $this->publicKey . '", {
                    });
                
                    paymentPage.openPopup({
                        publicId: "' . $this->publicKey . '",
                        amount: ' . $total . ',
                        orderId: "' . $orderId . '",
                        successUrl: "' . $successProcessingUrl . '",
                        failUrl: "' . $successProcessingUrl . '",
                        comment: "Оплата заказа ' . $orderId . '",
                        "receipt": {
                            "receiptNumber": "' . $orderId . '",
                            "customer": {
                                "email": "' . $order->get_billing_email() . '",
                                "phone": "' . $order->get_billing_phone() . '",
                                "name": "' . $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() . '"
                            },
                            "items": ' . json_encode($items) . '
                        }
                    })
                        .then(function(data) {
                            console.log("success", data);
                            document.location.href = "' . $successProcessingUrl . '";
                        })
                        .catch(function(error) {
                            console.log("fail", error);
                            document.location.href = "' . $successProcessingUrl . '";
                        });
                }
                
                pay();
                
                jQuery("#submit_raiffeisen_payment_form").click(function() {
                  pay();
                });
            ');

            return '
            <form action="" method="post" id="raiffeisen_payment_form" target="_top">
                <div class="payment_buttons">
                    <input type="submit" class="button alt" id="submit_raiffeisen_payment_form" value="Оплатить" /> 
                    <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">Отменить заказ</a>
                </div>
            </form>';
        }

        private function getOrderUrl(int $orderId): string
        {
            return get_permalink(woocommerce_get_page_id('myaccount')) . "view-order/$orderId/";
        }
    }
}
