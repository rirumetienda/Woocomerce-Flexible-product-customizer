<?php
/**
 * Customization session persistence.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Repository {
	const DRAFT_TTL = HOUR_IN_SECONDS;
	const CART_TTL  = 7 * DAY_IN_SECONDS;

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
		$payload    = isset( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : array();
		$expires_in = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : self::DRAFT_TTL;
		$insert     = array(
			'token'        => $token,
			'owner_key'    => Session_Identity::owner_hash(),
			'user_id'      => get_current_user_id(),
			'product_id'   => absint( $data['product_id'] ),
			'variation_id' => isset( $data['variation_id'] ) ? absint( $data['variation_id'] ) : 0,
			'template_id'  => absint( $data['template_id'] ),
			'status'       => 'draft',
			'payload'      => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
			'expires_at'   => $this->expiration_after( $expires_in ),
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
		$now           = gmdate( 'Y-m-d H:i:s' );
		$active_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::DRAFT_TTL );
		$rows          = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status <> 'ordered' AND ( ( expires_at IS NOT NULL AND expires_at <= %s ) OR ( status <> 'cart' AND updated_at <= %s ) ) ORDER BY id ASC LIMIT %d",
				$now,
				$active_cutoff,
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
	 * Find a recent empty draft for the same owner and product context.
	 *
	 * Empty drafts are safe to reuse because they do not contain uploads, previews, production files, or saved objects.
	 *
	 * @param int $product_id Product ID.
	 * @param int $variation_id Variation ID.
	 * @param int $template_id Template ID.
	 * @param int $max_age Maximum age in seconds.
	 * @return array|null
	 */
	public function find_reusable_empty_draft_for_current_owner( $product_id, $variation_id, $template_id, $max_age = HOUR_IN_SECONDS ) {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT token,payload FROM {$this->table} WHERE owner_key = %s AND product_id = %d AND variation_id = %d AND template_id = %d AND status = 'draft' AND expires_at > %s AND updated_at >= %s ORDER BY updated_at DESC, id DESC LIMIT 5",
				Session_Identity::owner_hash(),
				absint( $product_id ),
				absint( $variation_id ),
				absint( $template_id ),
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', time() - max( 60, absint( $max_age ) ) )
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$payload = json_decode( isset( $row['payload'] ) ? $row['payload'] : '', true );
			if ( is_array( $payload ) && ! $this->payload_has_customer_data( $payload ) ) {
				return $this->find( $row['token'] );
			}
		}
		return null;
	}

	/**
	 * Delete empty draft sessions for the current owner before enforcing the open-session quota.
	 *
	 * @param string $keep_token Optional token to preserve.
	 * @param int    $limit Maximum rows to inspect.
	 * @return int Deleted row count.
	 */
	public function delete_empty_drafts_for_current_owner( $keep_token = '', $limit = 200 ) {
		$keep_token = $this->sanitize_token( $keep_token );
		$rows       = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT token,payload FROM {$this->table} WHERE owner_key = %s AND status = 'draft' AND token <> %s ORDER BY id ASC LIMIT %d",
				Session_Identity::owner_hash(),
				$keep_token,
				max( 1, min( 500, absint( $limit ) ) )
			),
			ARRAY_A
		);
		$deleted = 0;
		foreach ( $rows as $row ) {
			$payload = json_decode( isset( $row['payload'] ) ? $row['payload'] : '', true );
			if ( ! is_array( $payload ) || ! $this->payload_has_customer_data( $payload ) ) {
				if ( $this->delete( $row['token'] ) ) {
					++$deleted;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Count open sessions for the current anonymous or authenticated owner.
	 *
	 * @return int
	 */
	public function count_open_for_current_owner() {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE owner_key = %s AND status IN ( 'draft', 'active' ) AND expires_at > %s AND updated_at > %s",
				Session_Identity::owner_hash(),
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', time() - self::DRAFT_TTL )
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
	 * Check whether the session retention window has passed.
	 *
	 * @param array $session Session record.
	 * @return bool
	 */
	public function is_expired( array $session ) {
		if ( 'ordered' === $session['status'] || empty( $session['expires_at'] ) ) {
			return false;
		}
		if ( 'cart' !== $session['status'] && ! empty( $session['updated_at'] ) && (int) strtotime( $session['updated_at'] . ' UTC' ) <= time() - self::DRAFT_TTL ) {
			return true;
		}
		return $this->expiration_timestamp( $session ) <= time();
	}

	/** @param int $seconds Seconds from now. @return string */
	public function expiration_after( $seconds ) {
		$seconds = max( 60, min( self::CART_TTL, absint( $seconds ) ) );
		return gmdate( 'Y-m-d H:i:s', time() + $seconds );
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
	 * Check whether a payload contains customer-created data or files.
	 *
	 * @param array $payload Session payload.
	 * @return bool
	 */
	private function payload_has_customer_data( array $payload ) {
		foreach ( array( 'uploads', 'previews', 'production_files' ) as $collection ) {
			if ( ! empty( $payload[ $collection ] ) && is_array( $payload[ $collection ] ) ) {
				return true;
			}
		}

		$design = isset( $payload['design'] ) && is_array( $payload['design'] ) ? $payload['design'] : array();
		foreach ( isset( $design['surfaces'] ) && is_array( $design['surfaces'] ) ? $design['surfaces'] : array() as $surface ) {
			if ( ! empty( $surface['objects'] ) && is_array( $surface['objects'] ) ) {
				return true;
			}
		}
		return false;
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
