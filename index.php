<?php
/*
Plugin Name: HG CloudFlare
Description: Acesso rápido e fácil para limpar o cache do CloudFlare.
Version: 2.0
Author: Hangar Digital
Author URI: https://hangar.digital/
*/

require 'libraries/cripto.php';

class HG_Cloudflare {

    function __construct() {

        if ( is_admin() ) {
            $clean_cache = isset($_GET['hg_clean_cache']) ? $_GET['hg_clean_cache'] : 0;
            if ($clean_cache == 1) {
                $this->clean_cache();
            }

            add_action( 'admin_bar_menu', [$this, 'add_adminbar'], 999 );

            // Limpar cache do CloudFlare depois de salvar qualquer CPT
            add_action( 'save_post', [$this, 'clean_cache'] );

            // Abrir e salvar opcoes
            add_action( 'admin_menu', [$this, 'admin_menu']);
            add_action( 'admin_init', [$this, 'save_settings']);
        }
    }

    function add_adminbar( $wp_admin_bar ) {
        $http = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $link = $http.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
        $simbolo = strpos($link, '?') > 0 ? '&' : '?';
        $link .= $simbolo.'hg_clean_cache=1';

        $args = array(
            'id'    => 'hg-clean-cache',
            'title' => '☁ Limpar Cache',
            'href'  => $link,
            'meta'  => array(
                'class' => 'hg-clean-cache',
                'title' => 'Limpar Cache do CloudFlare'
            )
        );
        $wp_admin_bar->add_node( $args );
    }

    function clean_cache() {
        global $cloudflare_msg;

        $configs = $this->get_options();

        if (!$configs) {
            $cloudflare_msg = (object) [
                'success' => false,
                'errors' => [
                    (object) [
                        'message' => 'Os dados de API do CloudFlare não foram configurados!'
                    ]
                ]
            ];
            add_action('admin_notices', [$this, 'admin_notice']);
            return;
        }

        $res = [];
        for ($i = 1; $i <= 2; $i++) {
            $zone_id = isset($configs->{"zone_id_$i"}) ? $configs->{"zone_id_$i"} : '';
            $api_token = isset($configs->{"api_token_$i"}) ? $configs->{"api_token_$i"} : '';

            if ($zone_id == '' || $api_token == '') {
                continue;
            }
            
            $res = $this->consult_rest($zone_id, $api_token);

            if (!$res->success) {
                break;
            }
        }

        $cloudflare_msg = $res;

        add_action('admin_notices', [$this, 'admin_notice']);
    }

    function consult_rest($zone_id, $api_token) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/'.$zone_id.'/purge_cache');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer '.$api_token
            ]);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode([
                'purge_everything' => true
            ]) );
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $res = json_decode( curl_exec($ch) );
            curl_close($ch);

        } catch (Exception $e) {
            return $e->getMessage();
        }

        return $res;
    }

    function admin_notice() {
        global $cloudflare_msg;
        
        if ($cloudflare_msg->success) {
            echo '<div class="notice notice-success is-dismissible">
                <p>O cache do CloudFlare foi limpo com sucesso!</p>
            </div>';
        
        } else {
            $msg = '';
            foreach ($cloudflare_msg->errors as $reg) {
                $msg .= ' '.$reg->message;
            }

            echo '<div class="notice notice-error is-dismissible">
                <p>Houve um erro ao limpar o cache do CloudFlare! Mensagem da API: '.trim($msg).'</p>
            </div>';
        }
    }

    function admin_menu() {
		global $submenu;
		$cd_site_id = wp_get_current_user();
		
		if ( !in_array('administrator', $cd_site_id->roles) ) {
			return;
		}

		add_options_page(
			'CloudFlare',
			'CloudFlare',
			'manage_options',
			'hgcloudflare-settings',
			[$this, 'display_settings']
		);
	}

	function display_settings() {
        $configs = $this->get_options();

        for ($i = 1; $i <= 2; $i++) {
            $zone_id[$i] = isset($configs->{"zone_id_$i"}) ? $configs->{"zone_id_$i"} : '';
            $api_token[$i] = isset($configs->{"api_token_$i"}) ? $configs->{"api_token_$i"} : '';
        }

		include 'settings.php';
	}

    function get_options() {
        $configs = get_option('hgcloudflare_settings');
        if ($configs != '') {
            $configs = json_decode( Cripto::decrypt( $configs, NONCE_SALT) );
        }
        return $configs;
    }

	function save_settings() {
		if ( !isset($_POST['hgcloudflare_settings']) ) {
			return;
		}

        $data = [];
        for ($i = 1; $i <= 2; $i++) {
            $data['zone_id_'.$i] = isset($_POST['zone_id_'.$i]) ? $_POST['zone_id_'.$i] : '';
            $data['api_token_'.$i] = isset($_POST['api_token_'.$i]) ? $_POST['api_token_'.$i] : '';
        } 
		update_option('hgcloudflare_settings', Cripto::encrypt( json_encode($data), NONCE_SALT) );
		
		add_settings_error(
			'hgcloudflare_settings',
			'hgcloudflare_settings',
			'As configurações foram salvas com sucesso!',
			'updated'
		);
    }
}

new HG_Cloudflare();