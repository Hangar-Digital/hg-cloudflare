<?php
/*
Plugin Name: HG CloudFlare
Description: Acesso rápido e fácil para limpar o cache do CloudFlare.
Version: 2.5
Requires PHP: 7.2
Author: Hangar Digital
Author URI: https://hangar.digital/
*/

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/libraries/cripto.php';

class HG_Cloudflare {

    const OPTION       = 'hgcloudflare_settings';
    const DOMAINS      = 2;
    const SAVE_ACTION  = 'hgcloudflare_save_settings';
    const PURGE_ACTION = 'hg_clean_cache';
    const PURGE_CAP    = 'edit_posts';

    private $_version = '2.5';

    /** Indica que existem configurações salvas que não puderam ser descriptografadas. */
    private $decrypt_failed = false;

    function __construct() {

        // Registrar hooks REST para todos os post types (funciona fora do is_admin)
        add_action( 'init', [$this, 'register_rest_hooks'], 999 );

        // Limpar cache quando um post for movido para lixeira ou restaurado
        // (cobre admin e REST, pois ambos chamam wp_trash_post()/wp_untrash_post())
        add_action( 'trashed_post', function() {
            $this->clean_cache();
        });
        add_action( 'untrashed_post', function() {
            $this->clean_cache();
        });

        // Limpar cache quando um anexo (imagem/mídia) for excluído ou editado
        // (attachment não passa por trash; admin e REST chamam wp_delete_attachment()
        // e wp_update_attachment_metadata() diretamente, então estes hooks cobrem ambos)
        add_action( 'delete_attachment', function() {
            $this->clean_cache();
        });
        add_action( 'edit_attachment', function() {
            $this->clean_cache();
        });

        // Adicionar script para exibir mensagens no editor de blocos
        add_action( 'enqueue_block_editor_assets', [$this, 'enqueue_block_editor_script'] );

        // Adicionar endpoint REST para verificar status da limpeza de cache
        add_action( 'rest_api_init', [$this, 'register_rest_route'] );

        if ( is_admin() ) {
            // Limpeza de cache manual (botão do admin bar), protegida por nonce e capability
            add_action( 'admin_init', [$this, 'handle_manual_purge'] );

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

        $this->clean_cache( true );
    }

    function enqueue_block_editor_script() {
        wp_enqueue_script(
            'hg-cloudflare-editor',
            plugin_dir_url( __FILE__ ) . 'editor-notices.js',
            array( 'wp-data', 'wp-plugins', 'wp-edit-post', 'wp-element' ),
            $this->_version,
            true
        );

        wp_localize_script(
            'hg-cloudflare-editor',
            'hgCloudflare',
            array(
                'restUrl' => rest_url( 'hg-cloudflare/v1/check-status' ),
                'nonce' => wp_create_nonce( 'wp_rest' )
            )
        );
    }

    function register_rest_route() {
        register_rest_route( 'hg-cloudflare/v1', '/check-status', array(
            'methods' => 'GET',
            'callback' => [$this, 'get_cache_status'],
            'permission_callback' => function() {
                return current_user_can( self::PURGE_CAP );
            }
        ));
    }

    function get_cache_status() {
        $user_id = get_current_user_id();
        $status = get_transient( 'hg_cloudflare_status_' . $user_id );

        if ( $status ) {
            delete_transient( 'hg_cloudflare_status_' . $user_id );
            return rest_ensure_response( $status );
        }

        return rest_ensure_response( array( 'status' => 'none' ) );
    }

    function add_adminbar( $wp_admin_bar ) {
        if ( ! current_user_can( self::PURGE_CAP ) ) {
            return;
        }

        $wp_admin_bar->add_node( array(
            'id'    => 'hg-clean-cache',
            'title' => '☁ Limpar Cache',
            'href'  => wp_nonce_url( add_query_arg( 'hg_clean_cache', '1' ), self::PURGE_ACTION ),
            'meta'  => array(
                'class' => 'hg-clean-cache',
                'title' => 'Limpar Cache do CloudFlare'
            )
        ) );
    }

    function handle_manual_purge() {
        if ( ! isset( $_GET['hg_clean_cache'] ) || $_GET['hg_clean_cache'] !== '1' || wp_doing_ajax() ) {
            return;
        }

        if ( ! current_user_can( self::PURGE_CAP ) ) {
            wp_die( 'Você não tem permissão para limpar o cache.', 403 );
        }

        check_admin_referer( self::PURGE_ACTION );

        $this->clean_cache();

        // Remove os parâmetros da URL para que um F5 não dispare nova limpeza
        wp_safe_redirect( remove_query_arg( array( 'hg_clean_cache', '_wpnonce' ) ) );
        exit;
    }

    function clean_cache( $is_rest = false ) {
        $cloudflare_msg = new stdClass();
        $errors = [];
        $configured = false;

        foreach ( $this->get_domains() as $domain ) {
            if ( $domain['zone_id'] === '' || $domain['api_token'] === '' ) {
                continue;
            }

            $configured = true;
            $error = $this->purge_zone( $domain['zone_id'], $domain['api_token'] );

            if ( $error !== null ) {
                $errors[] = $error;
                break;
            }
        }

        if ( ! $configured ) {
            $cloudflare_msg->error = 'Os dados de API não foram configurados!';

        } elseif ( empty( $errors ) ) {
            $cloudflare_msg->success = 'O cache foi limpo com sucesso!';
            error_log( '[ CLOUDFLARE ] ' . $cloudflare_msg->success );

        } else {
            $cloudflare_msg->error = 'Houve um erro ao limpar o cache! Mensagem da API: ' . implode( ' ', $errors );
            error_log( '[ CLOUDFLARE ] ' . $cloudflare_msg->error );
        }

        $this->store_message( $cloudflare_msg, $is_rest );
    }

    function store_message( $cloudflare_msg, $is_rest = false ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return; // cron / WP-CLI: ninguém para exibir a mensagem
        }

        $status = array(
            'status'  => isset( $cloudflare_msg->success ) ? 'success' : 'error',
            'message' => isset( $cloudflare_msg->success ) ? $cloudflare_msg->success : $cloudflare_msg->error
        );

        // REST: consumido pelo editor de blocos. Admin clássico: exibido no admin_footer.
        $key = $is_rest ? 'hg_cloudflare_status_' : 'hg_cloudflare_notice_';
        set_transient( $key . $user_id, $status, $is_rest ? 30 : 60 );
    }

    /**
     * @return string|null mensagem de erro, ou null em caso de sucesso.
     */
    private function purge_zone( $zone_id, $api_token ) {
        if ( ! self::is_valid_zone_id( $zone_id ) || ! self::is_valid_token( $api_token ) ) {
            return 'ID da Zona ou Token da API em formato inválido.';
        }

        $response = wp_remote_post(
            'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ) . '/purge_cache',
            array(
                'timeout'     => 15,
                'headers'     => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_token,
                ),
                'body'        => wp_json_encode( array( 'purge_everything' => true ) ),
                'data_format' => 'body',
            )
        );

        if ( is_wp_error( $response ) ) {
            return 'Falha de conexão: ' . $response->get_error_message();
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( is_object( $body ) && ! empty( $body->success ) ) {
            return null;
        }

        $messages = [];
        if ( is_object( $body ) && ! empty( $body->errors ) && is_array( $body->errors ) ) {
            foreach ( $body->errors as $reg ) {
                if ( isset( $reg->message ) ) {
                    $messages[] = (string) $reg->message;
                }
            }
        }

        return $messages
            ? implode( ' ', $messages )
            : 'Resposta inesperada da API (HTTP ' . wp_remote_retrieve_response_code( $response ) . ').';
    }

    function show_message() {
        $user_id = get_current_user_id();
        $status = $user_id ? get_transient( 'hg_cloudflare_notice_' . $user_id ) : false;

        if ( ! is_array( $status ) ) {
            return;
        }

        delete_transient( 'hg_cloudflare_notice_' . $user_id );

        $is_success = $status['status'] === 'success';

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
        <div class="hgcloudflare_msg <?php echo $is_success ? 'hgcloudflare_success' : 'hgcloudflare_error' ?>">
            <p><?php echo $is_success ? '✅' : '❌' ?> <strong>[ CLOUDFLARE ]</strong> <?php echo esc_html( $status['message'] ) ?></p>
        </div>
        <?php
    }

    function admin_menu() {
        add_options_page(
            'CloudFlare',
            'CloudFlare',
            'manage_options',
            'hgcloudflare-settings',
            [$this, 'display_settings']
        );
    }

    function display_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $domains = $this->get_domains();
        foreach ( $domains as $i => $domain ) {
            $domains[$i]['token_hint'] = self::mask_token( $domain['api_token'] );
            unset( $domains[$i]['api_token'] ); // token nunca vai para o HTML
        }

        $decrypt_failed    = $this->decrypt_failed;
        $has_dedicated_key = HG_Cloudflare_Crypto::has_dedicated_key();

        include __DIR__ . '/settings.php';
    }

    /**
     * Domínios configurados. Constantes no wp-config.php têm prioridade sobre o banco:
     * HG_CLOUDFLARE_ZONE_ID_{n} e HG_CLOUDFLARE_API_TOKEN_{n}.
     *
     * @return array<int, array{zone_id: string, api_token: string, locked: bool}>
     */
    function get_domains() {
        $stored = $this->get_stored_settings();
        $domains = [];

        for ( $i = 1; $i <= self::DOMAINS; $i++ ) {
            if ( defined( "HG_CLOUDFLARE_ZONE_ID_$i" ) && defined( "HG_CLOUDFLARE_API_TOKEN_$i" ) ) {
                $domains[$i] = array(
                    'zone_id'   => (string) constant( "HG_CLOUDFLARE_ZONE_ID_$i" ),
                    'api_token' => (string) constant( "HG_CLOUDFLARE_API_TOKEN_$i" ),
                    'locked'    => true,
                );
                continue;
            }

            $domains[$i] = array(
                'zone_id'   => isset( $stored["zone_id_$i"] ) ? (string) $stored["zone_id_$i"] : '',
                'api_token' => isset( $stored["api_token_$i"] ) ? (string) $stored["api_token_$i"] : '',
                'locked'    => false,
            );
        }

        return $domains;
    }

    private function get_stored_settings() {
        $stored = get_option( self::OPTION, '' );
        if ( ! is_string( $stored ) || $stored === '' ) {
            return [];
        }

        try {
            $json = HG_Cloudflare_Crypto::decrypt( $stored );
        } catch ( Throwable $e ) {
            error_log( '[ CLOUDFLARE ] ' . $e->getMessage() );
            $json = null;
        }

        $data = $json !== null ? json_decode( $json, true ) : null;

        if ( ! is_array( $data ) ) {
            $this->decrypt_failed = true;
            error_log( '[ CLOUDFLARE ] Não foi possível descriptografar as configurações (chave ou salts alterados?).' );
            return [];
        }

        // Migração automática do formato legado (v2.4) para o formato atual
        if ( ! HG_Cloudflare_Crypto::is_current( $stored ) ) {
            $this->store_settings( $data );
        }

        return $data;
    }

    private function store_settings( array $data ) {
        try {
            update_option( self::OPTION, HG_Cloudflare_Crypto::encrypt( wp_json_encode( $data ) ), false );
            return true;
        } catch ( Throwable $e ) {
            error_log( '[ CLOUDFLARE ] ' . $e->getMessage() );
            return false;
        }
    }

    function save_settings() {
        if ( ! isset( $_POST['hgcloudflare_settings'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Você não tem permissão para alterar estas configurações.', 403 );
        }

        check_admin_referer( self::SAVE_ACTION );

        $current = $this->get_stored_settings();
        $data = [];
        $errors = [];

        for ( $i = 1; $i <= self::DOMAINS; $i++ ) {
            // Domínio definido via constante: mantém o que estiver no banco
            if ( defined( "HG_CLOUDFLARE_ZONE_ID_$i" ) && defined( "HG_CLOUDFLARE_API_TOKEN_$i" ) ) {
                $data["zone_id_$i"] = isset( $current["zone_id_$i"] ) ? $current["zone_id_$i"] : '';
                $data["api_token_$i"] = isset( $current["api_token_$i"] ) ? $current["api_token_$i"] : '';
                continue;
            }

            $zone_id = isset( $_POST["zone_id_$i"] ) ? strtolower( trim( wp_unslash( $_POST["zone_id_$i"] ) ) ) : '';
            $api_token = isset( $_POST["api_token_$i"] ) ? trim( wp_unslash( $_POST["api_token_$i"] ) ) : '';

            // ID da Zona vazio = domínio removido (token é descartado junto)
            if ( $zone_id === '' ) {
                $data["zone_id_$i"] = '';
                $data["api_token_$i"] = '';
                continue;
            }

            // Token em branco = manter o token já salvo
            if ( $api_token === '' && isset( $current["api_token_$i"] ) ) {
                $api_token = $current["api_token_$i"];
            }

            if ( ! self::is_valid_zone_id( $zone_id ) ) {
                $errors[] = "Domínio $i: ID da Zona inválido (esperado 32 caracteres hexadecimais).";
            }
            if ( ! self::is_valid_token( $api_token ) ) {
                $errors[] = "Domínio $i: Token da API inválido ou não informado.";
            }

            $data["zone_id_$i"] = $zone_id;
            $data["api_token_$i"] = $api_token;
        }

        if ( $errors ) {
            add_settings_error( self::OPTION, self::OPTION, implode( '<br>', array_map( 'esc_html', $errors ) ), 'error' );
            return;
        }

        if ( ! $this->store_settings( $data ) ) {
            add_settings_error( self::OPTION, self::OPTION, 'Não foi possível criptografar as configurações. Verifique a constante HG_CLOUDFLARE_ENCRYPTION_KEY.', 'error' );
            return;
        }

        $this->decrypt_failed = false;

        add_settings_error(
            self::OPTION,
            self::OPTION,
            'As configurações foram salvas com sucesso!',
            'updated'
        );
    }

    private static function is_valid_zone_id( $zone_id ) {
        return is_string( $zone_id ) && preg_match( '/^[a-f0-9]{32}$/i', $zone_id ) === 1;
    }

    /** Bloqueia espaços, quebras de linha e caracteres de controle (evita header injection). */
    private static function is_valid_token( $token ) {
        return is_string( $token ) && preg_match( '/^[A-Za-z0-9_.\-]{20,200}$/', $token ) === 1;
    }

    private static function mask_token( $token ) {
        if ( $token === '' ) {
            return '';
        }

        return strlen( $token ) > 8 ? '•••••••• ' . substr( $token, -4 ) : '••••••••';
    }
}

new HG_Cloudflare();
