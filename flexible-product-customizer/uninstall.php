<?php
/**
 * Optional full data removal.
 *
 * @package FlexibleProductCustomizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'fpcw_cleanup_expired' );

if ( ! defined( 'FPCW_REMOVE_DATA_ON_UNINSTALL' ) || true !== FPCW_REMOVE_DATA_ON_UNINSTALL ) {
	return;
}

global $wpdb;

$template_ids = get_posts(
	array(
		'post_type'      => 'fpcw_template',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $template_ids as $template_id ) {
	wp_delete_post( $template_id, true );
}

$sample_attachment_id = absint( get_option( 'fpcw_sample_mug_attachment_id' ) );
if ( $sample_attachment_id ) {
	wp_delete_attachment( $sample_attachment_id, true );
}

delete_post_meta_by_key( '_fpcw_enabled' );
delete_post_meta_by_key( '_fpcw_required' );
delete_post_meta_by_key( '_fpcw_template_id' );
delete_post_meta_by_key( '_fpcw_allowed_colors' );
delete_post_meta_by_key( '_fpcw_surface_settings' );
delete_post_meta_by_key( '_fpcw_color_attribute' );
delete_option( 'fpcw_db_version' );
delete_option( 'fpcw_settings' );
delete_option( 'fpcw_sample_template_id' );
delete_option( 'fpcw_sample_mug_attachment_id' );
delete_option( 'fpcw_template_library_version' );

$table = $wpdb->prefix . 'fpcw_sessions';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$uploads = wp_upload_dir();
$root    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'flexible-product-customizer' );
$base    = wp_normalize_path( $uploads['basedir'] ) . '/';
if ( is_dir( $root ) && 0 === strpos( $root . '/', $base ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
	}
	rmdir( $root );
}
