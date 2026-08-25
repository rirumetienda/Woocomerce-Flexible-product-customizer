<?php
/**
 * Customization session persistence.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Repository {
	/** @var \wpdb */
	private $wpdb;

	/** @var string */
	private $table;

	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'fpcw_sessions';
	}

	/**
	 * Create a seven-day customization session.
	 *
	 * @param array $data Session values.
	 * @return array|\WP_Error
	 */
	public function create( array $data ) {
		$now     = time();
		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $exception ) {
			$token = hash( 'sha256', wp_generate_uuid4() . wp_rand() . microtime( true ) );
		}
		$payload = isset( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : array();
		$insert  = array(
			'token'        => $token,
			'owner_key'    => Session_Identity::owner_hash(),
			'user_id'      => get_current_user_id(),
			'product_id'   => absint( $data['product_id'] ),
			'variation_id' => isset( $data['variation_id'] ) ? absint( $data['variation_id'] ) : 0,
			'template_id'  => absint( $data['template_id'] ),
			'status'       => 'draft',
			'payload'      => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + ( 7 * DAY_IN_SECONDS ) ),
			'order_id'     => 0,
			'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
			'updated_at'   => gmdate( 'Y-m-d H:i:s', $now ),
		);

		$result = $this->wpdb->insert(
			$this->table,
			$insert,
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'fpcw_database_error', __( 'The customization session could not be created.', 'flexible-product-customizer' ) );
		}

		return $this->find( $token );
	}

	/**
	 * Find a session by public token.
	 *
	 * @param string $token Session token.
	 * @return array|null
	 */
	public function find( $token ) {
		$token = $this->sanitize_token( $token );
		if ( ! $token ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE token = %s LIMIT 1", $token ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['id']           = (int) $row['id'];
		$row['user_id']      = (int) $row['user_id'];
		$row['product_id']   = (int) $row['product_id'];
		$row['variation_id'] = (int) $row['variation_id'];
		$row['template_id']  = (int) $row['template_id'];
		$row['order_id']     = (int) $row['order_id'];
		$row['payload']      = json_decode( $row['payload'], true );
		$row['payload']      = is_array( $row['payload'] ) ? $row['payload'] : array();

		return $row;
	}

	/**
	 * Update an existing session using a strict field allowlist.
	 *
	 * @param string $token  Session token.
	 * @param array  $values Values to update.
	 * @return bool
	 */
	public function update( $token, array $values ) {
		$allowed = array(
			'variation_id' => '%d',
			'status'       => '%s',
			'payload'      => '%s',
			'expires_at'   => '%s',
			'order_id'     => '%d',
		);
		$data    = array();
		$formats = array();

		foreach ( $values as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			if ( 'payload' === $key ) {
				$value = wp_json_encode( is_array( $value ) ? $value : array(), JSON_UNESCAPED_SLASHES );
			}
			$data[ $key ] = $value;
			$formats[]    = $allowed[ $key ];
		}

		if ( ! $data ) {
			return true;
		}

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]          = '%s';

		return false !== $this->wpdb->update(
			$this->table,
			$data,
			array( 'token' => $this->sanitize_token( $token ) ),
			$formats,
			array( '%s' )
		);
	}

	/**
	 * Return expired non-order sessions in bounded batches.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function find_expired( $limit = 100 ) {
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status <> 'ordered' AND expires_at IS NOT NULL AND expires_at <= %s ORDER BY id ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s' ),
				$limit
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row['payload'] = json_decode( $row['payload'], true );
			$row['payload'] = is_array( $row['payload'] ) ? $row['payload'] : array();
		}

		return $rows;
	}

	/**
	 * Count open sessions for the current anonymous or authenticated owner.
	 *
	 * @return int
	 */
	public function count_open_for_current_owner() {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE owner_key = %s AND status <> 'ordered' AND expires_at > %s",
				Session_Identity::owner_hash(),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Permanently remove a session row.
	 *
	 * @param string $token Session token.
	 * @return bool
	 */
	public function delete( $token ) {
		return false !== $this->wpdb->delete( $this->table, array( 'token' => $this->sanitize_token( $token ) ), array( '%s' ) );
	}

	/**
	 * Check whether the fixed seven-day expiration has passed.
	 *
	 * @param array $session Session record.
	 * @return bool
	 */
	public function is_expired( array $session ) {
		if ( 'ordered' === $session['status'] || empty( $session['expires_at'] ) ) {
			return false;
		}
		return $this->expiration_timestamp( $session ) <= time();
	}

	/** @param array $session Session record. @return int */
	public function expiration_timestamp( array $session ) {
		return empty( $session['expires_at'] ) ? 0 : (int) strtotime( $session['expires_at'] . ' UTC' );
	}

	/** @param array $session Session record. @return string */
	public function expiration_iso( array $session ) {
		$timestamp = $this->expiration_timestamp( $session );
		return $timestamp ? gmdate( 'c', $timestamp ) : '';
	}

	/** @param array $session Session record. @return string */
	public function expiration_display( array $session ) {
		$timestamp = $this->expiration_timestamp( $session );
		return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $timestamp ) : '';
	}

	/** @param string $token Session token. @return string */
	public function cart_proof( $token ) {
		return hash_hmac( 'sha256', 'cart:' . $this->sanitize_token( $token ), wp_salt( 'nonce' ) );
	}

	/** @param string $token Session token. @param string $proof Signed proof. @return bool */
	public function verify_cart_proof( $token, $proof ) {
		return is_string( $proof ) && 64 === strlen( $proof ) && hash_equals( $this->cart_proof( $token ), $proof );
	}

	/**
	 * Sanitize a 256-bit public token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function sanitize_token( $token ) {
		$token = strtolower( (string) $token );
		return preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '';
	}
}
