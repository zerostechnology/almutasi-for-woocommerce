<?php

/*
Plugin Name: alMutasi for WooCommerce
Plugin URI: https://almutasi.com
Description: Verifikasi pembayaran otomatis berbasis nominal unik
Version: 0.1.0
Author: alMutasi.com
Author URI: https://almutasi.com
WC requires at least: 3.1.0
WC tested up to: 6.8.0
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Text Domain: almutasi-woocommerce
Domain Path: /languages
------------------------------------------------------------------------
*/

if (!defined('ABSPATH')) {
    exit;
}

add_filter('plugin_row_meta', 'almutasi_woocommerce_plugin_row_meta', 10, 2);
function almutasi_woocommerce_plugin_row_meta($links, $file)
{
    if (plugin_basename(__FILE__) == $file) {
        $row_meta = array(
          'docs'    => '<a href="' . esc_url('https://almutasi.com') . '" target="_blank" aria-label="' . esc_attr__('Plugin Additional Links', 'domain') . '">' . esc_html__('Instal & Konfigurasi', 'domain') . '</a>',
          'docs_api' => '<a href="' . esc_url('https://almutasi.com/docs/api') . '" target="_blank" aria-label="' . esc_attr__('Plugin Additional Links', 'domain') . '">' . esc_html__('Dokumentasi API', 'domain') . '</a>',
        );
        return array_merge($links, $row_meta);
    }
    return (array) $links;
}

add_action('plugins_loaded', 'almutasi_woocommerce_init', 0);
function almutasi_woocommerce_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    include_once dirname(__FILE__) . '/includes/admin/class-wc-almutasi.php';

    if (!class_exists('Almutasi_Gateway')) {
        abstract class Almutasi_Gateway extends WC_Payment_Gateway
        {
            public static $log_enabled = false;
            public static $log = false;
            public $enable_icon = false;

            public function __construct()
            {
                $this->id = $this->sub_id;
                $this->payment_method = '';
                $this->init_settings();
                $this->title = empty($this->settings['title']) ? "Bank Transfer (Otomatis)" : $this->settings['title'];
                $this->account_number = $this->settings['account_number'];
                $this->account_name = $this->settings['account_name'];
                $this->uniqueValidity = get_option('almutasi_woocommerce_unique_validity', 1440);
                $this->enabled = isset($this->settings['enabled']) == 'yes' ? true : false;
                $this->description = isset($this->settings['description']) ? $this->settings['description']: '';
                $this->mode = get_option('almutasi_woocommerce_mode');
                $this->apikey = get_option('almutasi_woocommerce_api_key');
                $this->privateKey = get_option('almutasi_woocommerce_private_key');
                $this->successStatus = get_option('almutasi_woocommerce_success_status', 'processing');
                $this->initialStatus = get_option('almutasi_woocommerce_initial_status', 'on-hold');
                $this->redirectPage = get_option('almutasi_woocommerce_redirect_page');
                $this->customerInvoiceEmail = get_option('almutasi_woocommerce_customer_invoice_email', 'no') === 'yes' ? true : false;

                self::$log_enabled = get_option('almutasi_woocommerce_debug', 'no') === 'yes' ? true : false;

                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
                add_action('woocommerce_api_wc_gateway_almutasi', array($this, 'handle_webhook'));
                add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
                add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
            }

            public function email_instructions($order, $sent_to_admin, $plain_text = false)
            {
                if (!$sent_to_admin && $this->id === $order->get_payment_method() && ($order->has_status('pending') || $order->has_status('on-hold'))) {
                    $expiredTime = strtotime($order->get_date_created()) + ($this->uniqueValidity * 60);
                    $serviceName = get_post_meta($order->get_id(), '_almutasi_service_name', true);
                    $serviceCode = get_post_meta($order->get_id(), '_almutasi_service_code', true);
                    $accountNumber = get_post_meta($order->get_id(), '_almutasi_account_number', true);
                    $accountName = get_post_meta($order->get_id(), '_almutasi_account_name', true);

                    switch (wp_timezone_string()) {
                        case 'Asia/Jakarta':   $tz = 'WIB';
                            break;
                        case 'Asia/Makassar':
                        case 'Asia/Pontianak': $tz = 'WITA';
                            break;
                        case 'Asia/Jayapura':  $tz = 'WIT';
                            break;
                        default:               $tz = '';
                            break;
                    }

                    $datetime = new \DateTime();
                    $datetime->setTimestamp($expiredTime);
                    $datetime->setTimezone(new \DateTimeZone(wp_timezone_string()));

                    $html = '<p>Untuk menyelesaikan pesanan, silahkan lakukan pembayaran berikut:</p>';
                    $html .= '<p>';
                    $html .= '<b>Metode Pembayaran:</b> '.$serviceName;
                    $html .= '<br/><b>Nomor Rekening :</b> '.$accountNumber;
                    $html .= '<br/><b>Nama Rekening :</b> '.$accountName;
                    $html .= '<br/><b>Batas Pembayaran :</b> '.$datetime->format('d F Y H:i').' '.$tz;
                    $html .= '</p>';
                    echo $html;
                }
            }

            public function receipt_page($order)
            {
                echo Almutasi::view_order_and_thankyou_page($order);
            }

            public function admin_options()
            {
                echo '<table class="form-table">';
                $this->generate_settings_html();
                echo '</table>';
            }

            public function process_payment($order_id)
            {
                global $woocommerce;

                if (! $this->enabled) {
                    wc_add_notice("Metode pembayaran tidak tersedia", "error");
                    return;
                }

                if (get_woocommerce_currency() !== 'IDR') {
                    wc_add_notice("Metode pembayaran hanya mendukung mata uang Rupiah (IDR)", "error");
                    return;
                }

                if (empty($this->account_number) || empty($this->account_name)) {
                    wc_add_notice("Metode pembayaran tidak dapat digunakan", "error");
                    return;
                }

                $order = new WC_Order($order_id);
                $exchangeValue = get_option(Almutasi::$option_prefix.'_exchange_rate', null);

                if ($order->has_status('pending') && $this->initialStatus == 'on-hold') {
                    $order->update_status('on-hold', __('Menunggu Pembayaran dengan ' . $this->title, 'woocommerce'));
                } elseif ($order->has_status('on-hold') && $this->initialStatus == 'pending') {
                    $order->update_status('pending', __('Menunggu Pembayaran dengan ' . $this->title, 'woocommerce'));
                }

                $expired_time = (time()+(60*$this->uniqueValidity));

                if (get_option('woocommerce_manage_stock', 'yes') === 'yes' && get_option('woocommerce_hold_stock_minutes') > 0) {
                    $held_duration = get_option('woocommerce_hold_stock_minutes');
                    $expired_time = (time()+(60*$held_duration));
                }
                
                WC()->cart->empty_cart();

                $order->update_meta_data('_almutasi_service_code', $this->payment_method);
                $order->update_meta_data('_almutasi_service_name', $this->title);
                $order->update_meta_data('_almutasi_account_number', $this->account_number);
                $order->update_meta_data('_almutasi_account_name', $this->account_name);
                $order->update_meta_data('_almutasi_expired_time', $expired_time);

                $datetime = new \DateTime();
                $datetime->setTimestamp($expired_time);
                $datetime->setTimezone(new \DateTimeZone(wp_timezone_string()));
                $order->update_meta_data('_almutasi_expired_date', $datetime->format('d F Y H:i'));
                $order->save();

                if ($this->customerInvoiceEmail) {
                    WC()->mailer()->get_emails()['WC_Email_Customer_Invoice']->trigger($order_id);
                }
                    
                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(),
                );
            }

            public function handle_webhook()
            {
                if (! isset($_SERVER['HTTP_SIGNATURE'])) {
                    die("Invalid Signature");
                }

                $json = file_get_contents("php://input");

                $incomingSignature = strval($_SERVER['HTTP_SIGNATURE']);
                $localSignature = hash_hmac("sha256", $json, $this->privateKey);

                if (
                    empty($incomingSignature)
                    || empty($localSignature)
                    || (! hash_equals($localSignature, $incomingSignature))
                ) {
                    $this->log("Invalid Signature: local(".$localSignature.") vs incoming(".$incomingSignature.")");
                    die("Invalid Signature");
                }

                $webhook = json_decode($json);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    die("Invalid JSON");
                }

                if ($webhook->event == 'mutation:new') {
                    $results = [];

                    foreach ($webhook->data->mutations as $mutation) {
                        do_action('almutasi_woocommerce_before_auto_confirm', $mutation);
                        $args = array(
                            'post_type'     => 'shop_order',
                            'meta_query' => array(
                                'relation' => 'AND',
                                array(
                                    'key'     => '_order_total',
                                    'value'   => intval($mutation->amount),
                                    'type'    => 'numeric',
                                    'compare' => '=',
                                ),
                                array(
                                    'key'     => '_almutasi_service_code',
                                    'value'   => $webhook->data->service->code,
                                    'type'    => 'string',
                                    'compare' => '=',
                                ),
                                array(
                                    'key'     => '_almutasi_expired_time',
                                    'value'   => time(),
                                    'type'    => 'numeric',
                                    'compare' => '>',
                                ),
                            ),
                            'post_status'   => array('wc-on-hold', 'wc-pending'),
                        );
                        $query = new WP_Query($args);

                        if ($query->have_posts()) {
                            if ($query->found_posts > 1) {
                                /** Send notification to admin */
                                $admin_email = get_bloginfo('admin_email');
                                $message = "Hai Admin\r\n\r\n";
                                $message .= sprintf("Ada order yang sama dengan nominal Rp %s", number_format($mutation->amount, 0, '', '.')). "\r\n\r\n";
                                $message .= "Mohon untuk dicek secara manual";
                                wp_mail($admin_email, sprintf('[%s] Duplikat Order - alMutasi', get_option('blogname')), $message);
                            } else {
                                while ($query->have_posts()) {
                                    $query->the_post();
                                    $order = new WC_Order(get_the_ID());
                                    if ($order->has_status($this->successStatus)) {
                                        continue;
                                    }
                                    $order->add_order_note('Pembayaran diverifikasi otomatis melalui : ' . $webhook->data->service->name . ' / ' . $webhook->data->account->account_number . ' - alMutasi');
                                    $order->update_status($this->successStatus);
                                    array_push($results, array(
                                        'order_id'  => $order->get_order_number(),
                                        'status'    => $order->get_status(),
                                    ));
                                }
                                wp_reset_postdata();
                            }
                        }
                    }

                    echo json_encode($results, JSON_PRETTY_PRINT);
                    exit;
                }

                die("No action was taken");
            }

            public function log($message)
            {
                if (self::$log_enabled) {
                    if (empty(self::$log)) {
                        self::$log = new WC_Logger();
                    }
                    self::$log->add('almutasi', $message);
                }
            }
        }
    }

    function add_almutasi_gateway($methods)
    {
        foreach (Almutasi::gateways() as $id => $property) {
            $methods[] = $property['class'];
        }

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_almutasi_gateway');

    foreach (glob(dirname(__FILE__) . '/includes/gateways/*.php') as $filename) {
        include_once $filename;
    }
}

function almutasi_woocommerce_warning()
{
    ?>
    <div class="update-nag notice" style="display: block;">
        <p><?php _e('<b>alMutasi for WooCommerce</b>: Plugin dalam mode <b>Development</b>', 'almutasi_woocommerce'); ?></p>
    </div>
    <?php
}

function almutasi_woocommerce_wc_warning()
{
    ?>
    <div class="update-nag notice" style="display: block;">
        <p><?php _e('<b>alMutasi for WooCommerce</b>: WooCommerce belum terinstall!', 'almutasi_woocommerce'); ?></p>
    </div>
    <?php
}

add_action('wp_loaded', 'almutasi_woocommerce_loaded');
function almutasi_woocommerce_loaded()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'almutasi_woocommerce_wc_warning');
        return;
    }

    if (get_option('almutasi_woocommerce_mode', 'development') === 'development') {
        add_action('admin_notices', 'almutasi_woocommerce_warning');
    }
}
