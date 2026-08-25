<?php
/**
 * Anonymous and authenticated session ownership.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Session_Identity {
	const COOKIE_NAME = 'fpcw_owner';

	/**
	 * Ensure every storefront visitor has a private owner token.
	 *
	 * @return void
	 */
	public static function ensure_cookie() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) && self::is_valid_cookie( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) ) {
			return;
		}

		$value = wp_generate_password( 48, false, false );
		$_COOKIE[ self::COOKIE_NAME ] = $value;

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_NAME,
				$value,
				array(
					'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * Return the irreversible owner key stored in the database.
	 *
	 * @return string
	 */
	public static function owner_hash() {
		self::ensure_cookie();
		$value = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) : '';
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	/**
	 * Confirm the current visitor owns a session.
	 *
	 * @param array $session Session record.
	 * @return bool
	 */
	public static function owns( array $session ) {
		if ( get_current_user_id() && (int) $session['user_id'] === get_current_user_id() ) {
			return true;
		}

		return ! empty( $session['owner_key'] ) && hash_equals( $session['owner_key'], self::owner_hash() );
	}

	/**
	 * Validate the raw owner cookie.
	 *
	 * @param string $value Cookie value.
	 * @return bool
	 */
	private static function is_valid_cookie( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^[A-Za-z0-9]{32,64}$/', $value );
	}
}

