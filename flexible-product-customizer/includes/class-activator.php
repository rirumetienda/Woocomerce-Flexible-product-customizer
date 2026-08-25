<?php
/**
 * Installation and lifecycle tasks.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Activator {
	/**
	 * Install the session table and cleanup schedule.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_schema();

		$storage = new File_Storage();
		$storage->prepare_directories();

		if ( ! wp_next_scheduled( 'fpcw_cleanup_expired' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'fpcw_cleanup_expired' );
		}

		self::install_sample_template();
	}

	/**
	 * Apply future schema revisions without requiring plugin reactivation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( 'fpcw_db_version' );
		if ( FPCW_VERSION !== $installed ) {
			self::install_schema();
			if ( ! $installed || version_compare( $installed, '1.5.0', '<' ) ) {
				self::migrate_templates();
				self::install_sample_template();
			}
		}
	}

	/** @return void */
	private static function migrate_templates() {
		$templates = new Template_Manager();
		$templates->register_post_type();
		$ids = get_posts(
			array(
				'post_type'      => Template_Manager::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $template_id ) {
			$stored = get_post_meta( $template_id, Template_Manager::META_KEY, true );
			if ( is_array( $stored ) && (int) ( isset( $stored['schema_version'] ) ? $stored['schema_version'] : 1 ) < 6 ) {
				update_post_meta( $template_id, Template_Manager::META_KEY, $templates->sanitize_config( $stored ) );
			}
		}
	}

	/** @return void */
	private static function install_sample_template() {
		$templates = new Template_Manager();
		$templates->register_post_type();
		$template_id = absint( get_option( 'fpcw_sample_template_id' ) );
		if ( $template_id && Template_Manager::POST_TYPE === get_post_type( $template_id ) && 'cylindrical-mug-v2' === get_post_meta( $template_id, '_fpcw_bundled_sample', true ) ) {
			return;
		}

		$existing = get_posts(
			array(
				'post_type'      => Template_Manager::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_fpcw_bundled_sample',
				'meta_value'     => 'cylindrical-mug-v2',
				'no_found_rows'  => true,
			)
		);
		if ( $existing ) {
			update_option( 'fpcw_sample_template_id', (int) $existing[0], false );
			return;
		}

		$image_id = self::install_sample_image();
		if ( ! $image_id ) {
			return;
		}
		$template_id = wp_insert_post(
			array(
				'post_type'   => Template_Manager::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => __( 'Sample: Cylindrical mug', 'flexible-product-customizer' ),
			),
			true
		);
		if ( is_wp_error( $template_id ) ) {
			return;
		}
		update_post_meta( $template_id, Template_Manager::META_KEY, $templates->sample_config( $image_id ) );
		update_post_meta( $template_id, '_fpcw_bundled_sample', 'cylindrical-mug-v2' );
		update_option( 'fpcw_sample_template_id', (int) $template_id, false );
	}

	/** @return int */
	private static function install_sample_image() {
		$attachment_id = absint( get_option( 'fpcw_sample_mug_attachment_id' ) );
		if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
			return $attachment_id;
		}
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_fpcw_bundled_sample_asset',
				'meta_value'     => 'white-mug-v1',
				'no_found_rows'  => true,
			)
		);
		if ( $existing ) {
			update_option( 'fpcw_sample_mug_attachment_id', (int) $existing[0], false );
			return (int) $existing[0];
		}

		$source = FPCW_PATH . 'assets/demo/sample-white-mug.png';
		if ( ! is_readable( $source ) ) {
			return 0;
		}
		$contents = file_get_contents( $source );
		if ( false === $contents ) {
			return 0;
		}
		$upload = wp_upload_bits( 'fpcw-sample-white-mug.png', null, $contents );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$filetype = wp_check_filetype( $upload['file'], null );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => __( 'Sample white mug', 'flexible-product-customizer' ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
		update_post_meta( $attachment_id, '_fpcw_bundled_sample_asset', 'white-mug-v1' );
		update_option( 'fpcw_sample_mug_attachment_id', (int) $attachment_id, false );
		return (int) $attachment_id;
	}

	/** @return void */
	private static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'fpcw_sessions';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(64) NOT NULL,
			owner_key char(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			template_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			payload longtext NOT NULL,
			expires_at datetime NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY expiry_status (expires_at,status),
			KEY order_id (order_id),
			KEY user_id (user_id)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'fpcw_db_version', FPCW_VERSION, false );
	}

	/**
	 * Remove only scheduled jobs. Store and order data are preserved.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'fpcw_cleanup_expired' );
	}
}
