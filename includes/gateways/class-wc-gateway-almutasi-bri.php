<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_Gateway_Almutasi_BRI extends Almutasi_Gateway
{
    public $sub_id = 'almutasi_bri';
    
    public function __construct()
    {
        parent::__construct();

        $this->method_title = "alMutasi - Bank BRI";
        $this->method_description = "Pembayaran divalidasi otomatis berdasarkan nominal unik";
        $this->payment_method = "bri";
        
        $this->init_form_fields();
        $this->init_settings();

        if ($this->settings['enable_icon'] == 'yes') {
            $this->icon = !empty($this->settings['custom_icon'])
                ? $this->settings['custom_icon']
                : plugins_url('/assets/bri.png', dirname(__FILE__));
        }
    }
    
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Aktifkan ' . $this->method_title, 'wc-almutasi'),
                'label' => '',
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Judul', 'wc-almutasi'),
                'type' => 'text',
                'description' => __('Nama metode pembayaran yang ditampilkan', 'wc-almutasi'),
                'default' => $this->method_title,
            ),
            'enable_icon' => array(
                'title' => __('Aktifkan Ikon', 'wc-almutasi'),
                'label' => '',
                'type' => 'checkbox',
                'description' => '<img src="'.plugins_url('/assets/bri.png', dirname(__FILE__)).'" style="height:100%;max-height:40px !important" />',
                'default' => 'no',
            ),
            'custom_icon' => array(
                'title' => __('URL Ikon Pembayaran Kustom', 'wc-almutasi'),
                'label' => __('URL Ikon Pembayaran Kustom', 'wc-almutasi'),
                'type' => 'text',
                'description' => 'URL kustom untuk menggunakan ikon pembayaran pribadi. Jika kosong akan menggunakan ikon default diatas',
                'default' => '',
            ),
            'description' => array(
                'title' => __('Deskripsi', 'wc-almutasi'),
                'type' => 'textarea',
                'description' => '',
                'default' => 'Pembayaran melalui ' . $this->method_title,
            ),
            'expired' => array(
                'title' => __("Masa Berlaku Nominal Unik", "wc-almutasi"),
                "type" => 'number',
                'description' => __('Masa berlaku nominal unik dalam satuan menit', 'wc-almutasi'),
                'default' => '1440',
            )
        );
    }
}
