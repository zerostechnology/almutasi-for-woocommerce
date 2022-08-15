<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Almutasi
{
    public static $tab_name = 'almutasi_settings';
    public static $option_prefix = 'almutasi_woocommerce';
    public static $version = '0.1.0';
    public static $baseurl = 'https://almutasi.com';

    public static function init()
    {
        $request = $_REQUEST;

        add_filter('woocommerce_settings_tabs_array', array(__CLASS__, 'add_almutasi_settings_tab'), 50);
        add_action('woocommerce_settings_tabs_almutasi_settings', array(__CLASS__, 'almutasi_settings_page'));
        add_action('woocommerce_update_options_almutasi_settings', array(__CLASS__, 'update_almutasi_settings'));
        add_action('woocommerce_cart_calculate_fees', array(__CLASS__, 'wp_add_checkout_fees'));
        add_action('woocommerce_review_order_before_payment', array(__CLASS__, 'wp_refresh_checkout_on_payment_methods_change'));
        add_action('woocommerce_view_order', array(__CLASS__, 'view_order_and_thankyou_page' ), 1, 1);
        add_action('woocommerce_thankyou', array(__CLASS__, 'view_order_and_thankyou_page' ), 1, 1);

        //load fee API before checkout
        add_action('woocommerce_review_order_before_payment', array(__CLASS__, 'fee'), 1, 1);
    }

    public static function wp_encrypt($text)
    {
        // ..
    }

    public static function wp_decrypt($text)
    {
        // ..
    }

    public static function gateways($id = null)
    {
        $lists = [
            'bri'	=> [
                'name'	=> 'Bank BRI',
                'code'	=> 'bri',
                'class'	=> 'WC_Gateway_Almutasi_BRI',
            ],
            'bni'	=> [
                'name'	=> 'Bank BNI',
                'code'	=> 'bni',
                'class'	=> 'WC_Gateway_Almutasi_BNI',
            ],
            'bca'	=> [
                'name'	=> 'Bank BCA',
                'code'	=> 'bca',
                'class'	=> 'WC_Gateway_Almutasi_BCA',
            ],
            'bsi'	=> [
                'name'	=> 'Bank BSI',
                'code'	=> 'bsi',
                'class'	=> 'WC_Gateway_Almutasi_BSI',
            ],
            'mandiri_retail3'	=> [
                'name'	=> 'Bank Mandiri (Livin Biru)',
                'code'	=> 'mandiri_retail3',
                'class'	=> 'WC_Gateway_Almutasi_MANDIRI_RETAIL3',
            ],
            'sinarmas'	=> [
                'name'	=> 'Bank Sinarmas',
                'code'	=> 'sinarmas',
                'class'	=> 'WC_Gateway_Almutasi_SINARMAS',
            ],
            'bni_direct'	=> [
                'name'	=> 'BNI Direct',
                'code'	=> 'bni_direct',
                'class'	=> 'WC_Gateway_Almutasi_BNI_DIRECT',
            ],
            'mandiri_mcm'	=> [
                'name'	=> 'Mandiri MCM',
                'code'	=> 'mandiri_mcm',
                'class'	=> 'WC_Gateway_Almutasi_MANDIRI_MCM',
            ],
        ];

        if (!empty($id)) {
            return isset($lists[$id]) ? $lists[$id] : null;
        }

        return $lists;
    }

    public static function view_order_and_thankyou_page($order_id)
    {
        if (!$order_id) {
            return;
        }

        $order = new WC_Order($order_id);
        $method = $order->get_payment_method_title();
        $accountNumber = get_post_meta($order->get_id(), '_almutasi_account_number', true);
        $accountName = get_post_meta($order->get_id(), '_almutasi_account_name', true);
        $expiredTime = get_post_meta($order->get_id(), '_almutasi_expired_time', true);

        $html = '
                <table class="woocommerce-table shop_table">
                    <tbody>
                    <tr>
                        <th scope="row" style="vertical-align:top">Metode Pembayaran :</th>
                        <td style="vertical-align:top">'.$method.'</td>
                    </tr>
                    <tr>
                        <th scope="row" style="vertical-align:top">No. Rekening :</th>
                        <td style="vertical-align:top">'.$accountNumber.'</td>
                    </tr>
                    <tr>
                        <th scope="row" style="vertical-align:top">Nama Rekening :</th>
                        <td style="vertical-align:top">'.$accountName.'</td>
                    </tr>';

        switch (wp_timezone_string()) {
            case 'Asia/Jakarta':   $tz = 'WIB';  break;
            case 'Asia/Makassar':
            case 'Asia/Pontianak': $tz = 'WITA'; break;
            case 'Asia/Jayapura':  $tz = 'WIT';  break;
            default:               $tz = '';     break;
        }

        $datetime = new \DateTime();
        $datetime->setTimestamp($expiredTime);
        $datetime->setTimezone(new \DateTimeZone(wp_timezone_string()));

        $html .= '<tr>
            <th scope="row" style="vertical-align:top">Batas Pembayaran :</th>
            <td style="vertical-align:top">'.$datetime->format('d F Y H:i').' '.$tz.'</td>
            </tr>';

        $html .= '</tbody></table>';

        echo $html;
    }

    public static function wp_add_checkout_fees($order_id)
    {
        global $wpdb;

        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }
        
        $chosen_gateway = WC()->session->get('chosen_payment_method');

        $gateways = self::gateways();

        foreach ($gateways as $id) {
            if ($chosen_gateway == self::$option_prefix.'_'.$id) {
                $uniqueLabel = get_option(self::$option_prefix.'_unique_label', 'Kode Unik');
                $uniqueCode = self::get_unique_code();
                if ($uniqueCode !== 0) {
                    WC()->cart->add_fee(
                        $uniqueLabel,
                        self::convertFromIdr($uniqueCode, get_woocommerce_currency(), $exchangeValue)
                    );
                } else {

                }
                break;
            }
        }
    }
    
    private static function get_unique_code()
    {
        global $wpdb;

        $uniqueCode = 0;
        $uniqueLabel = get_option(self::$option_prefix.'_unique_label', 'Kode Unik');
        $uniqueMin = (int) get_option(self::$option_prefix.'_unique_min', 1);
        $uniqueMax = (int) get_option(self::$option_prefix.'_unique_max', 9999);
        $uniqueType = get_option(self::$option_prefix.'_unique_type', 'increase');
        $validity = (int) get_option(self::$option_prefix.'_expired', 1440);

        $sql = "
            SELECT p.`ID`, oi.`order_item_id`, oim.`meta_value` `unique_code`
            FROM `{$wpdb->prefix}posts` p
            LEFT JOIN `{$wpdb->prefix}woocommerce_order_items` oi
            ON (
                oi.`order_id` = p.`ID`
                AND oi.`order_item_type` = 'fee'
                AND oi.`order_item_name` = '{$uniqueLabel}'
            )
            LEFT JOIN `{$wpdb->prefix}woocommerce_order_itemmeta` oim
            ON (
                oim.`order_item_id` = oi.`order_item_id`
                AND oim.`meta_key` = '_fee_amount'
            )
            WHERE `post_type`='shop_order'
            AND `post_status` IN (
                'wc-on-hold', 'wc-pending'
            )
            AND `post_date` >= DATE(NOW()) - INTERVAL {$validity} MINUTE
            LIMIT 0, {$uniqueMax}
        ";

        $results = $wpdb->get_results($sql, OBJECT);

        $uqCodes = [];

        foreach ($results as $meta) {
            if (empty($meta->unique_code) && $meta->unique_code != '0') {
                continue;
            }
            array_push($uqCodes, array($meta->unique_code));
        }


        $uniqueCode = null;
        $loopCount = 0;

        while (empty($uniqueCode) && ++$loopCount <= count($uqCodes)) {
            $uniqueCode = mt_rand( $uniqueMin, $uniqueMax );
            if( $uniqueType == 'decrease') {
                $uniqueCode = (int) -$uniqueCode;
            }
            $uniqueCode = !empty($uqCodes) && in_array($uniqueCode, $uqCodes) ? null : $uniqueCode;
        }

        if($uniqueCode == 0 || $uniqueCode === null) {
            $uniqueCode = $uniqueMin;
            if( $uniqueType == 'decrease') {
                $uniqueCode = (int) -$uniqueCode;
            }
        }

        return $uniqueCode;
    }

    public static function wp_refresh_checkout_on_payment_methods_change()
    {
        ?>
		<script type="text/javascript">
			(function($){
				$('form.checkout').on('change', 'input[name^="payment_method"]', function() {
					$('body').trigger('update_checkout');
				});
			})(jQuery);
		</script>
		<?php
    }

    public static function add_almutasi_settings_tab($woocommerce_tab)
    {
        $woocommerce_tab[self::$tab_name] = 'alMutasi';
        return $woocommerce_tab;
    }

    public static function almutasi_settings_fields()
    {
        $settings = apply_filters('woocommerce_' . self::$tab_name, array(
            array(
                'title' => 'alMutasi Global Setting',
                'id' => self::$option_prefix . '_global_settings',
                'desc' => '',
                'type' => 'title',
                'default' => '',
            ),
            array(
                'title' => __('Mode Integrasi', 'wc-almutasi'),
                'type' => 'select',
                'desc' => __('Mode integrasi sistem.<br/><b>Development</b> digunakan untuk testing<br/><b>Production</b> digunakan untuk transaksi riil', 'wc-almutasi'),
                'id' => self::$option_prefix.'_mode',
                'default' => 'development',
                'options' => array(
                    'development' => 'Development',
                    'production' => 'Production'
                ),
                'css' => 'width:25em;',
            ),
            array(
                'title' => __('API Key', 'wc-almutasi'),
                'desc' => 'Lihat <a href="https://app.almutasi.com/integration?tab=keys" traget="_blank">di sini</a>',
                'id' => self::$option_prefix . '_api_key',
                'type' => 'text',
                'css' => 'width:25em;',
                'default' => '',
            ),
            array(
                'title' => __("Private Key", "wc-tripay"),
                "desc" => 'Lihat <a href="https://app.almutasi.com/integration?tab=keys" traget="_blank">di sini</a>',
                "id" => self::$option_prefix."_private_key",
                "type" => "text",
                "css" => "width:25em",
                "default" => ""
            ),
            array(
                'title' => __('Tipe Kode Unik', 'wc-almutasi'),
                'label' => '',
                'type' => 'select',
                'description' => __('', 'wc-almutasi'),
                'default'   =>  'increase',
                'options' => array(
                    'increase'      => 'Tambahkan',
                    'decrease'		=> 'Kurangkan',
                ),
                'id'   => self::$option_prefix.'_unique_type',
                'css' => 'width:25em;',
            ),
            array(
                'title' => __('Batas Awal Kode Unik', 'wc-almutasi'),
                'desc' => '',
                'id' => self::$option_prefix . '_unique_min',
                'type' => 'number',
                'css' => 'width:25em;',
                'default' => '1',
            ),
            array(
                'title' => __('Batas Akhir Kode Unik', 'wc-almutasi'),
                'desc' => '',
                'id' => self::$option_prefix . '_unique_max',
                'type' => 'number',
                'css' => 'width:25em;',
                'default' => '1999',
            ),
            array(
                'title' => __('Aktifkan Debugging', 'wc-almutasi'),
                'desc' => __('Aktifkan/Nonaktifkan log transaksi.<br/>Log dapat dilihat di menu WooCommerce > Status > Logs'),
                'id' => self::$option_prefix . '_debug',
                'type' => 'checkbox',
                'default' => 'no',
                'css' => 'width:25em;',
            ),
            array(
                'title' => __('Status Pesanan Awal', 'wc-almutasi'),
                'type' => 'select',
                'desc' => __('Status pesanan awal sebelum pembayaran dilakukan', 'wc-almutasi'),
                'id' => self::$option_prefix.'_initial_status',
                'default' => 'pending',
                'options' => array(
                    'pending' => 'Pending',
                    'on-hold' => 'On Hold'
                ),
                'css' => 'width:25em;',
            ),
            array(
                'title' => __('Status Sukses', 'wc-almutasi'),
                'type' => 'select',
                'desc' => __('Status pesanan setelah pembayaran berhasil', 'wc-almutasi'),
                'id' => self::$option_prefix.'_success_status',
                'default' => 'processing',
                'options' => array(
                    'completed' => 'Completed',
                    'on-hold' => 'On Hold',
                    'processing' => 'Processing',
                ),
                'css' => 'width:25em;',
            ),
            array(
                'title' => __('Aktifkan Email Invoice ke Pelanggan', 'wc-almutasi'),
                'desc' => 'Aktifkan/nonaktifkan email invoice yang dikirim ke pelanggan',
                'id' => self::$option_prefix . '_customer_invoice_email',
                'type' => 'checkbox',
                'default' => 'yes',
                'css' => 'width:25em;',
            ),
            array(
                'title' => __('Setelah checkout redirect ke?', 'wc-almutasi'),
                'label' => '',
                'type' => 'select',
                'description' => __('Setelah konsumen checkout, pilih ke halaman mana pelanggan akan dialihkan', 'wc-almutasi'),
                'default'   =>  'thankyou',
                'options' => array(
                    'thankyou'      => 'Thank You Page',
                    'orderpay'		=> 'Order Pay',
                ),
                'id'   => self::$option_prefix.'_redirect_page',
                'css' => 'width:25em;',
            ),
        ));
        return apply_filters('woocommerce_' . self::$tab_name, $settings);
    }

    public static function almutasi_settings_page()
    {
        $form = self::almutasi_settings_fields();

        woocommerce_admin_fields($form);

        $currencyExhanges = get_option('almutasi_woocommerce_exchange_rate', '{"usd_idr": 0}');
        $currencyExhanges = json_decode($currencyExhanges, true);

        $i = 0;
        foreach ($currencyExhanges as $key => $value) {
            $fromCur = strtoupper(explode("_", $key)[0]);
            $echo = '<tr valign="top" class="currency_conversion_field">
				<th scope="row" class="titledesc">';

            if ($i == 0) {
                $echo .= '<label>Kurs Konversi ke IDR</label>';
            }

            $echo .= '</th>
				<td class="forminp forminp-text">
					<div style="width:7em;display:inline-block;margin-right:5px">
						<input name="almutasi_woocommerce_exchange_rate_from[]" type="text" value="'.$fromCur.'" class="" placeholder="Mata uang" style="width:100%;text-transform:uppercase">
					</div>
					<div style="width:10em;display:inline-block;margin-right:5px">
						<input name="almutasi_woocommerce_exchange_rate_value[]" type="number" value="'.$value.'" class="" placeholder="Nilai Tukar" style="width:100%">
					</div>
					<div style="width:4em;display:inline-block">';

            if ($i == 0) {
                $echo .= '<button type="button" style="vertical-align: top;font-size: 18px;" onclick="addCurrencyConversionField()">+</button>';
            } else {
                $echo .= '<button type="button" style="vertical-align: top;font-size: 18px;" onclick="removeCurrencyConversionField(this)">x</button>';
            }
                        
            $echo .= '
					</div>
				</td>
			</tr>';

            echo $echo;

            $i++;
        }

        echo '<tr valign="top"><th scope="row" class="titledesc"><label for="almutasi_woocommerce_webhook_url">Webhook URL </label></th><td class="forminp forminp-text"><input id="almutasi_woocommerce_webhook_url" type="text" value="'.self::webhook_url().'" class="" placeholder="" readonly="true" style="width:25em;"></td></tr>';

        echo '<script type="text/javascript">
			function addCurrencyConversionField() {
				var field = "<tr valign=\"top\" class=\"currency_conversion_field\"><th scope=\"row\" class=\"titledesc\"></th><td class=\"forminp forminp-text\"><div style=\"width:7em;display:inline-block;margin-right:9px\"><input name=\"almutasi_woocommerce_exchange_rate_from[]\" type=\"text\" value=\"\" class=\"\" placeholder=\"Mata uang\" style=\"width:100%;text-transform:uppercase\"></div><div style=\"width:10em;display:inline-block;margin-right:9px\"><input name=\"almutasi_woocommerce_exchange_rate_value[]\" type=\"number\" value=\"\" class=\"\" placeholder=\"Nilai Tukar\" style=\"width:100%\"></div><div style=\"width:4em;display:inline-block\"><button type=\"button\" style=\"vertical-align: top;font-size: 18px;padding: 0px 8px;\" onclick=\"removeCurrencyConversionField(this)\">x</button></div></td></tr>";

				jQuery(field).insertAfter(jQuery(".currency_conversion_field")[jQuery(".currency_conversion_field").length-1]);
			}

			function removeCurrencyConversionField(obj) {
				jQuery(obj).parent().parent().parent().remove();
			}

		</script>';
    }

    public static function update_almutasi_settings()
    {
        $exchangeFrom = $_POST['almutasi_woocommerce_exchange_rate_from'];
        $exchangeValue = $_POST['almutasi_woocommerce_exchange_rate_value'];

        unset($_POST['almutasi_woocommerce_exchange_rate_from']);
        unset($_POST['almutasi_woocommerce_exchange_rate_value']);

        $i = 0;
        $values = [];
        foreach ($exchangeFrom as $exFrom) {
            if (!empty($exFrom)) {
                $values[strtolower($exFrom).'_idr'] = $exchangeValue[$i];
            }
            $i++;
        }
        update_option(self::$option_prefix.'_exchange_rate', json_encode($values));

        woocommerce_update_options(self::almutasi_settings_fields());
    }

    public static function webhook_url()
    {
        $checkoutUrl = wc_get_checkout_url();

        $hasQuery = !empty(parse_url($checkoutUrl, PHP_URL_QUERY));

        if ($hasQuery) {
            $checkoutUrl = rtrim($checkoutUrl, '&').'&wc-api=wc_gateway_almutasi';
        } else {
            $checkoutUrl = $checkoutUrl.'?wc-api=wc_gateway_almutasi';
        }

        return $checkoutUrl;
    }

    public static function convertToIdr($value, $optionValue = null)
    {
        $currency = get_woocommerce_currency();
        $currentCurrency = strtolower($currency);

        if ($currentCurrency == 'idr') {
            return ceil($value);
        }

        $optionValue = $optionValue ? $optionValue : get_option(self::$option_prefix.'_exchange_rate', null);

        if (empty($optionValue)) {
            (new \WC_Logger())->add('almutasi', "alMutasi exchange rate has not been set");
            return 0;
        }

        $optionValue = json_decode($optionValue, true);
        $key = $currentCurrency.'_idr';

        if (!isset($optionValue[$key]) || empty($optionValue[$key])) {
            (new \WC_Logger())->add('almutasi', $currency." to IDR conversion has not been set");
            return 0;
        }

        return ceil($value * $optionValue[$key]);
    }

    public static function convertFromIdr($value, $currency, $optionValue = null)
    {
        $currentCurrency = strtolower($currency);

        if ($currentCurrency == 'idr') {
            return ceil($value);
        }

        $optionValue = $optionValue ? $optionValue : get_option(self::$option_prefix.'_exchange_rate', null);

        if (empty($optionValue)) {
            (new \WC_Logger())->add('almutasi', "alMutasi exchange rate has not been set");
            return 0;
        }

        $optionValue = json_decode($optionValue, true);
        $key = $currentCurrency.'_idr';

        if (!isset($optionValue[$key]) || empty($optionValue[$key])) {
            (new \WC_Logger())->add('almutasi', $currency." to IDR conversion has not been set");
            return 0;
        }

        return $value / $optionValue[$key];
    }
}

Almutasi::init();
