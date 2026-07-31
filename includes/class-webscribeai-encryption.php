<?php
/**
 * Symmetric encryption helper for storing secrets (e.g. API keys) at rest.
 *
 * @package WebscribeAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WebscribeAI_Encryption
 */
class WebscribeAI_Encryption {

	/**
	 * Prefix marking a value as encrypted by this class, so legacy
	 * plaintext values saved before encryption was introduced can still
	 * be read.
	 */
	private const PREFIX = 'wsai_enc::';

	/**
	 * Cipher used for encryption.
	 */
	private const CIPHER = 'aes-256-cbc';

	/**
	 * Encrypt a plaintext string for storage.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Encrypted (or, if openssl is unavailable, original) value.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! self::is_available() ) {
			return $plaintext;
		}

		$iv         = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a value previously produced by encrypt().
	 *
	 * Values that don't carry our prefix are treated as legacy plaintext
	 * (saved before encryption was introduced) and returned as-is.
	 *
	 * @param string $stored Stored value.
	 * @return string Decrypted plaintext, or '' if it can't be decrypted.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return $stored;
		}

		if ( ! self::is_available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw ) {
			return '';
		}

		$iv_len     = openssl_cipher_iv_length( self::CIPHER );
		$iv         = substr( $raw, 0, $iv_len );
		$ciphertext = substr( $raw, $iv_len );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Whether the openssl functions we need are available.
	 */
	private static function is_available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Derive a 256-bit encryption key from the site's WordPress salts, so
	 * the key never has to be stored separately.
	 */
	private static function get_key(): string {
		$secret = ( defined( 'AUTH_KEY' ) && AUTH_KEY ) ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', $secret, true );
	}
}
