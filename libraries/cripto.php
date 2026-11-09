<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'HG_Cloudflare_Crypto' ) ) {

    /**
     * Criptografia autenticada (libsodium secretbox: XSalsa20-Poly1305) das configurações.
     *
     * Origem da chave, em ordem de prioridade:
     *  1. Constante HG_CLOUDFLARE_ENCRYPTION_KEY no wp-config.php (ou variável de ambiente
     *     de mesmo nome), com no mínimo 32 caracteres aleatórios. Gerar com:
     *     php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
     *  2. Fallback: derivada dos salts do WordPress (wp_salt('auth')). Atenção: trocar os
     *     salts invalida os tokens salvos, que precisarão ser cadastrados novamente.
     *
     * Formato gravado: "hgcf2:" + base64(nonce[24] . ciphertext+MAC).
     * O formato legado (AES-256-CBC + NONCE_SALT, v2.4 e anteriores) só é lido para migração.
     */
    final class HG_Cloudflare_Crypto {

        const PREFIX       = 'hgcf2:';
        const KEY_CONSTANT = 'HG_CLOUDFLARE_ENCRYPTION_KEY';
        const HKDF_INFO    = 'hg-cloudflare/settings/v2';
        const KEY_BYTES    = 32;
        const NONCE_BYTES  = 24;
        const MAC_BYTES    = 16;

        /**
         * @throws RuntimeException quando a chave dedicada está configurada de forma inválida.
         */
        public static function encrypt( $plaintext ) {
            $nonce  = random_bytes( self::NONCE_BYTES );
            $cipher = sodium_crypto_secretbox( (string) $plaintext, $nonce, self::key() );

            return self::PREFIX . base64_encode( $nonce . $cipher );
        }

        /**
         * @return string|null null quando a chave não confere ou o conteúdo foi adulterado.
         * @throws RuntimeException quando a chave dedicada está configurada de forma inválida.
         */
        public static function decrypt( $payload ) {
            if ( ! is_string( $payload ) || $payload === '' ) {
                return null;
            }

            if ( ! self::is_current( $payload ) ) {
                return self::decrypt_legacy( $payload );
            }

            $raw = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true );
            if ( $raw === false || strlen( $raw ) < self::NONCE_BYTES + self::MAC_BYTES ) {
                return null;
            }

            $plain = sodium_crypto_secretbox_open(
                substr( $raw, self::NONCE_BYTES ),
                substr( $raw, 0, self::NONCE_BYTES ),
                self::key()
            );

            return $plain === false ? null : $plain;
        }

        public static function is_current( $payload ) {
            return is_string( $payload ) && strncmp( $payload, self::PREFIX, strlen( self::PREFIX ) ) === 0;
        }

        public static function has_dedicated_key() {
            return self::configured_secret() !== null;
        }

        private static function key() {
            $secret = self::configured_secret();

            if ( $secret !== null && strlen( $secret ) < 32 ) {
                throw new RuntimeException( self::KEY_CONSTANT . ' deve ter no mínimo 32 caracteres.' );
            }

            if ( $secret === null ) {
                $secret = wp_salt( 'auth' );
            }

            return hash_hkdf( 'sha256', $secret, self::KEY_BYTES, self::HKDF_INFO );
        }

        private static function configured_secret() {
            $value = defined( self::KEY_CONSTANT ) ? constant( self::KEY_CONSTANT ) : getenv( self::KEY_CONSTANT );

            return ( is_string( $value ) && $value !== '' ) ? $value : null;
        }

        /**
         * Lê o formato da v2.4: base64(iv[16] . hmac[64] . aes-256-cbc), chave = base64_decode(NONCE_SALT).
         * O HMAC legado não é verificado (a v2.4 também não verificava); o dado é regravado no formato atual.
         */
        private static function decrypt_legacy( $payload ) {
            if ( ! defined( 'NONCE_SALT' ) ) {
                return null;
            }

            $mix       = base64_decode( $payload, true );
            $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );

            if ( $mix === false || strlen( $mix ) <= $iv_length + 64 ) {
                return null;
            }

            $plain = openssl_decrypt(
                substr( $mix, $iv_length + 64 ),
                'aes-256-cbc',
                base64_decode( NONCE_SALT ),
                OPENSSL_RAW_DATA,
                substr( $mix, 0, $iv_length )
            );

            return $plain === false ? null : $plain;
        }
    }
}
