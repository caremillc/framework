<?php 
namespace Careminate\Hashes;

class Hash
{
    /**
     * @param string $value
     * 
     * @return string
     */
    public static function encrypt(string $value): string
    {
        $cipher = config('session.encryption_mode');
        $key = config('session.encryption_key');
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($value, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac(config('app.sha_algorithm'), $ciphertext_raw, $key, true);
        $chipertext = base64_encode($iv . $hmac . $ciphertext_raw);
        return $chipertext;
    }

    /**
     * @param string $chipertext
     * 
     * @return string
     */
    public static function decrypt(string $chipertext): string
    {
        $cipher = config('session.encryption_mode');
        $key = config('session.encryption_key');
        $convert = base64_decode($chipertext);
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = substr($convert, 0, $ivlen);
        $hmac = substr($convert, $ivlen, 32);
        $ciphertext_raw = substr($convert, $ivlen + 32);
        $original_text = openssl_decrypt($ciphertext_raw, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        $calcmac = hash_hmac(config('app.sha_algorithm'), $ciphertext_raw, $key, true);
        if (hash_equals($hmac, $calcmac)) {
            return $original_text;
        }
        return '';
    }

    /**
     * make
     *
     * @param  mixed $value
     * @return string
     */
    public static function make(string $value):string
    {
        return password_hash($value, config('hash.bcrypt_algo'));
    }
    
    /**
     * hash
     *
     * @param  mixed $value
     * @return void
     */
    public static function hash($value)
    {
        return sha1($value);
    }

       
    /**
     * check
     *
     * @param  mixed $value
     * @param  mixed $hashedValue
     * @return bool
     */
    public static function check(string $value, string $hashedValue): bool
    {
        return password_verify($value, $hashedValue);
    }    
    /**
     * verify
     *
     * @param  mixed $value
     * @param  mixed $hashedValue
     * @return bool
     */
    public static function verify(string $value, string $hashedValue): bool
    {
        return self::check($value, $hashedValue);
    }
}
