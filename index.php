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

        // Registrar hooks REST para todos os post types (funciona fora do is_admin)
        add_action( 'init', [$this, 'register_rest_hooks'], 999 );

        if ( is_admin() ) {
            // Limpeza cache manualmente quando solicitado
            add_action( 'admin_init', function() {
                $clean_cache = isset($_GET['hg_clean_cache']) ? $_GET['hg_clean_cache'] : 0;
                if ($clean_cache == 1) {
                    $this->clean_cache();
                }
            });

            // Adicionar botão no admin bar
            add_action( 'admin_bar_menu', [$this, 'add_adminbar'], 999 );

            // Limpar cache do CloudFlare depois de salvar qualquer CPT
            add_action( 'save_post', function($post_id) {
                // Verificar se é um auto-salvamento
                if (wp_is_post_autosave($post_id)) {
                    return;
                }

                // Verificar se o post é uma revisão
                if (wp_is_post_revision($post_id)) {
                    return;
                }

                // Verificar se o post é novo
                if (get_post_status($post_id) == 'auto-draft') {
                    return;
                }

                // Não limpar cache ao salvar menu
                if (get_post_type($post_id) === 'nav_menu_item') {
                    return;
				}
				
                $this->clean_cache();
            });

            // Abrir e salvar opcoes
            add_action( 'admin_menu', [$this, 'admin_menu']);
            add_action( 'admin_init', [$this, 'save_settings']);

            // Adicionar conteúdo ao rodapé do painel de administração
            add_action( 'admin_footer', [$this, 'show_message'] );

			add_action('wp_update_nav_menu', function () {
                $this->clean_cache();
            });
        }
    }

    function register_rest_hooks() {
        // Obter todos os post types públicos
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        
        foreach ( $post_types as $post_type ) {
            // Ignorar anexos e nav_menu_item
            if ( in_array( $post_type, array( 'attachment', 'nav_menu_item' ) ) ) {
                continue;
            }
            
            // Registrar hook para cada post type
            add_action( "rest_after_insert_{$post_type}", [$this, 'clean_cache_after_rest'], 10, 3 );
        }
    }

    function clean_cache_after_rest( $post, $request, $creating ) {
        // Não limpar cache ao criar um novo post (rascunho)
        if ( $creating ) {
            return;
        }

        // Não limpar cache para auto-saves
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        $this->clean_cache();
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
        $cloudflare_msg = new stdClass();

        $configs = $this->get_options();

        if (empty($configs) || $configs->zone_id_1 == '' || $configs->api_token_1 == '') {
            $cloudflare_msg->error = 'Os dados de API não foram configurados!';
            $_SESSION['cloudflare_msg'] = $cloudflare_msg;
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

        if ($res->success) {
            $cloudflare_msg->success = 'O cache foi limpo com sucesso!';
            error_log('[ CLOUDFLARE ] '.$cloudflare_msg->success);
            $_SESSION['cloudflare_msg'] = $cloudflare_msg;
        
        } else {
            $msg = '';
            foreach ($res->errors as $reg) {
                $msg .= ' '.$reg->message;
            }

            $cloudflare_msg->error = 'Houve um erro ao limpar o cache! Mensagem da API: '.trim($msg);
            error_log('[ CLOUDFLARE ] '.$cloudflare_msg->error);
            $_SESSION['cloudflare_msg'] = $cloudflare_msg;
        }
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

    function show_message() {
        $cloudflare_msg = isset($_SESSION['cloudflare_msg']) ? $_SESSION['cloudflare_msg'] : null;
        unset($_SESSION['cloudflare_msg']);

        if (!isset($cloudflare_msg)) {
            return;
        }

        ?>
        <style>
            .hgcloudflare_msg {
                padding: 0 15px;
                margin: 10px !important;
                border-radius: 5px;
                position: fixed;
                z-index: 10000;
                bottom: 0;
                right: 0;
            }
            .hgcloudflare_success {
                background-color: #d4edda;
                color: #155724;
            }
            .hgcloudflare_error {
                background-color: #f8d7da;
                color: #721c24;
            }
        </style>
        <script>
            setTimeout(function() {
                document.querySelector('.hgcloudflare_msg').style.display = 'none';
            }, 5000);
        </script>
        <?php

        if (isset($cloudflare_msg->success)) {
            echo '<div class="hgcloudflare_msg hgcloudflare_success">
                <p>✅ <strong>[ CLOUDFLARE ]</strong> '.$cloudflare_msg->success.'</p>
            </div>';

        } else {
            echo '<div class="hgcloudflare_msg hgcloudflare_error">
                <p>❌ <strong>[ CLOUDFLARE ]</strong> '.$cloudflare_msg->error.'</p>
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
