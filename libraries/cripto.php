<?php

if (!class_exists('Cripto')) {
    
    class Cripto {

        const key2 = 'MziTEeqetKdwDMX5Ce4lGhiOGwC8otCX//UgiEtxgsNko2UXCV0lp3EKbQYscXMekOO0skH99XDgyUPjHgz0nw==';
        const method = 'aes-256-cbc';

        public static function encrypt($string, $key1) {
            if ($string == '')
                return '';

            $first_key = base64_decode($key1);
            $second_key = base64_decode(self::key2);

            $iv_length = openssl_cipher_iv_length(self::method);
            $iv = openssl_random_pseudo_bytes($iv_length);

            $first_encrypted = openssl_encrypt($string, self::method, $first_key, OPENSSL_RAW_DATA, $iv);
            $second_encrypted = hash_hmac('sha3-512', $first_encrypted, $second_key, TRUE);

            $output = base64_encode($iv.$second_encrypted.$first_encrypted);
            return $output;
        }

        public static function decrypt($string, $key1) {
            $first_key = base64_decode($key1);
            $second_key = base64_decode(self::key2);
            $mix = base64_decode($string);

            $iv_length = openssl_cipher_iv_length(self::method);

            $iv = substr($mix, 0, $iv_length);
            $second_encrypted = substr($mix, $iv_length, 64);
            $first_encrypted = substr($mix, $iv_length+64);

            $data = openssl_decrypt($first_encrypted, self::method, $first_key, OPENSSL_RAW_DATA, $iv);
            $second_encrypted_new = hash_hmac('sha3-512', $first_encrypted, $second_key, TRUE);

            return $data;
        }
    }

    //echo base64_encode(openssl_random_pseudo_bytes(127)); exit;
}