<?php
/**
 * Short-lived signed context for trusted mobile/WebView integrations.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Bridge {
	/**
	 * Create a compact signed context token.
	 *
	 * @param int    $product_id        Product ID.
	 * @param string $external_reference App-side reference.
	 * @param int    $ttl               Lifetime in seconds.
	 * @return array
	 */
	public static function create( $product_id, $external_reference = '', $ttl = 900, $variation_id = 0 ) {
		$payload = array(
			'product_id' => absint( $product_id ),
			'variation_id' => absint( $variation_id ),
			'external_reference' => sanitize_text_field( $external_reference ),
			'exp' => time() + max( 60, min( HOUR_IN_SECONDS, absint( $ttl ) ) ),
			'nonce' => wp_generate_password( 16, false, false ),
		);
		$encoded   = self::base64url_encode( wp_json_encode( $payload ) );
		$signature = hash_hmac( 'sha256', $encoded, wp_salt( 'nonce' ) );
		return array( 'token' => $encoded . '.' . $signature, 'payload' => $payload );
	}

	/**
	 * Verify a bridge token without creating server-side state.
	 *
	 * @param string $token Signed token.
	 * @return array|false
	 */
	public static function verify( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), $parts[1] ) ) {
			return false;
		}
		$payload = json_decode( self::base64url_decode( $parts[0] ), true );
		if ( ! is_array( $payload ) || empty( $payload['product_id'] ) || empty( $payload['exp'] ) || (int) $payload['exp'] < time() ) {
			return false;
		}
		return $payload;
	}

	/** @param string $value Value. @return string */
	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** @param string $value Value. @return string */
	private static function base64url_decode( $value ) {
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		return (string) base64_decode( strtr( $value, '-_', '+/' ), true );
	}
}
