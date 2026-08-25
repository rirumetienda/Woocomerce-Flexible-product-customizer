<?php
/**
 * Secure upload, preview, and production file storage.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class File_Storage {
	const MAX_UPLOAD_BYTES = 10485760;
	const MAX_DIMENSION    = 10000;

	/** @var array */
	private $uploads;

	/** @var Repository|null */
	private $repository;

	public function __construct() {
		$this->uploads = wp_upload_dir();
	}

	/** @param Repository $repository Repository. @return void */
	public function register_hooks( Repository $repository ) {
		$this->repository = $repository;
		add_action( 'admin_post_fpcw_file', array( $this, 'stream_private_file' ) );
		add_action( 'admin_post_nopriv_fpcw_file', array( $this, 'stream_private_file' ) );
		add_action( 'admin_post_fpcw_base_image', array( $this, 'stream_base_image' ) );
		add_action( 'admin_post_nopriv_fpcw_base_image', array( $this, 'stream_base_image' ) );
	}

	/**
	 * Create storage roots and deny direct access to originals where supported.
	 *
	 * @return void
	 */
	public function prepare_directories() {
		$private = $this->base_dir() . '/private';
		$public  = $this->base_dir() . '/public';
		wp_mkdir_p( $private );
		wp_mkdir_p( $public );

		$this->write_if_missing( $private . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		$this->write_if_missing( $private . '/web.config', '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		$this->write_if_missing( $private . '/index.php', "<?php\n// Silence is golden.\n" );
		$this->write_if_missing( $public . '/index.php', "<?php\n// Silence is golden.\n" );
	}

	/**
	 * Validate and store a customer's source image.
	 *
	 * @param array $session Session record.
	 * @param array $file    PHP upload record.
	 * @return array|\WP_Error
	 */
	public function save_source_upload( array $session, array $file ) {
		$validation = $this->validate_image_upload( $file, self::MAX_UPLOAD_BYTES, self::MAX_DIMENSION, array( 'image/png', 'image/jpeg', 'image/webp' ) );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		try {
			$id = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $exception ) {
			$id = substr( hash( 'sha256', wp_generate_uuid4() . wp_rand() . microtime( true ) ), 0, 32 );
		}
		$relative = sprintf( 'private/temp/%s/%s.%s', $session['token'], $id, $validation['extension'] );
		$moved    = $this->move_uploaded_file( $file['tmp_name'], $relative );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$original_name = sanitize_file_name( wp_basename( $file['name'] ) );
		$result = array(
			'id'            => $id,
			'original_name' => $original_name ? $original_name : 'image.' . $validation['extension'],
			'relative_path' => $relative,
			'mime'          => $validation['mime'],
			'size'          => (int) $file['size'],
			'width'         => $validation['width'],
			'height'        => $validation['height'],
		);
		$result = array_merge( $result, $this->create_editor_copy( $relative, $id, $validation ) );
		$result['url'] = $this->private_url( $session, $result );
		return $result;
	}

	/**
	 * Store a generated PNG preview or transparent production render.
	 *
	 * @param array  $session    Session record.
	 * @param string $surface_id Surface key.
	 * @param string $kind       preview|production.
	 * @param array  $file       PHP upload record.
	 * @param string $view_id    Optional preview view ID.
	 * @param string $view_label Optional preview view label.
	 * @param int    $rotation   Optional preview rotation.
	 * @return array|\WP_Error
	 */
	public function save_render( array $session, $surface_id, $kind, array $file, $view_id = '', $view_label = '', $rotation = 0 ) {
		$surface_id = sanitize_key( $surface_id );
		$kind       = 'production' === $kind ? 'production' : 'preview';
		$view_id    = sanitize_key( $view_id );
		$view_id    = $view_id ? $view_id : 'default';
		$view_label = sanitize_text_field( $view_label );
		$rotation   = max( -180, min( 180, (int) round( (float) $rotation ) ) );
		$validation = $this->validate_image_upload( $file, 10 * MB_IN_BYTES, 4000, array( 'image/png' ) );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$is_public = 'preview' === $kind;
		$suffix    = $is_public ? $surface_id . '-' . $view_id : $surface_id;
		$id        = $kind . '-' . $suffix;
		$relative  = sprintf(
			'%s/temp/%s/%s-%s.png',
			$is_public ? 'public' : 'private',
			$session['token'],
			$kind,
			$suffix
		);
		$moved = $this->move_uploaded_file( $file['tmp_name'], $relative );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$result = array(
			'id'            => $id,
			'surface_id'    => $surface_id,
			'original_name' => $kind . '-' . $suffix . '.png',
			'relative_path' => $relative,
			'mime'          => 'image/png',
			'size'          => (int) $file['size'],
			'width'         => $validation['width'],
			'height'        => $validation['height'],
		);
		if ( $is_public ) {
			$result['view_id']    = $view_id;
			$result['view_label'] = $view_label ? $view_label : $view_id;
			$result['rotation']   = $rotation;
		}
		$result['url'] = $is_public ? $this->public_url( $relative ) : $this->private_url( $session, $result );
		return $result;
	}

	/**
	 * Add runtime URLs to persisted path-only metadata.
	 *
	 * @param array $payload Payload.
	 * @param array $session Session record.
	 * @return array
	 */
	public function hydrate_payload_urls( array $payload, array $session ) {
		foreach ( array( 'uploads', 'production_files' ) as $collection ) {
			if ( empty( $payload[ $collection ] ) || ! is_array( $payload[ $collection ] ) ) {
				continue;
			}
			foreach ( $payload[ $collection ] as &$file ) {
				$file['url'] = $this->private_url( $session, $file );
			}
			unset( $file );
		}
		if ( ! empty( $payload['previews'] ) && is_array( $payload['previews'] ) ) {
			foreach ( $payload['previews'] as &$preview ) {
				$preview['url'] = $this->public_url( $preview['relative_path'] );
			}
			unset( $preview );
		}
		return $payload;
	}

	/**
	 * Delete source images no longer referenced after a save.
	 *
	 * @param array $uploads      Existing uploads.
	 * @param array $used_file_ids IDs retained by design.
	 * @return array
	 */
	public function prune_uploads( array $uploads, array $used_file_ids ) {
		$kept = array();
		foreach ( $uploads as $upload ) {
			if ( ! empty( $upload['id'] ) && in_array( $upload['id'], $used_file_ids, true ) ) {
				unset( $upload['url'] );
				$kept[] = $upload;
			} elseif ( ! empty( $upload['relative_path'] ) ) {
				$this->delete_relative_file( $upload['relative_path'] );
				if ( ! empty( $upload['editor_relative_path'] ) ) {
					$this->delete_relative_file( $upload['editor_relative_path'] );
				}
			}
		}
		return $kept;
	}

	/** @param array $files Files. @return void */
	public function delete_file_records( array $files ) {
		foreach ( $files as $file ) {
			if ( ! empty( $file['relative_path'] ) ) {
				$this->delete_relative_file( $file['relative_path'] );
			}
			if ( ! empty( $file['editor_relative_path'] ) ) {
				$this->delete_relative_file( $file['editor_relative_path'] );
			}
		}
	}

	/**
	 * Move all files into order-owned locations and rewrite payload paths.
	 *
	 * @param array $session  Session record.
	 * @param int   $order_id Order ID.
	 * @return array{payload:array,warnings:array}
	 */
	public function finalize_for_order( array $session, $order_id ) {
		$order_id = absint( $order_id );
		$token    = $session['token'];
		$map      = array(
			'private/temp/' . $token => 'private/orders/' . $order_id . '/' . $token,
			'public/temp/' . $token  => 'public/orders/' . $order_id . '/' . $token,
		);

		$moved    = array();
		$warnings = array();
		foreach ( $map as $from => $to ) {
			$result = $this->move_directory( $from, $to );
			if ( is_wp_error( $result ) ) {
				$warnings[] = $result->get_error_message();
			} else {
				$moved[ $from ] = $to;
			}
		}

		$payload = $session['payload'];
		foreach ( array( 'uploads', 'previews', 'production_files' ) as $collection ) {
			if ( empty( $payload[ $collection ] ) || ! is_array( $payload[ $collection ] ) ) {
				continue;
			}
			foreach ( $payload[ $collection ] as &$file ) {
				foreach ( $moved as $from => $to ) {
					foreach ( array( 'relative_path', 'editor_relative_path' ) as $path_key ) {
						if ( isset( $file[ $path_key ] ) && 0 === strpos( $file[ $path_key ], $from ) ) {
							$file[ $path_key ] = $to . substr( $file[ $path_key ], strlen( $from ) );
						}
					}
				}
				unset( $file['url'] );
			}
			unset( $file );
		}
		return array( 'payload' => $payload, 'warnings' => $warnings );
	}

	/**
	 * Remove every temporary file belonging to a session token.
	 *
	 * @param string $token Session token.
	 * @return void
	 */
	public function delete_temporary_session( $token ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $token ) ) {
			return;
		}
		$this->delete_directory( 'private/temp/' . $token );
		$this->delete_directory( 'public/temp/' . $token );
	}

	/**
	 * Signed controller for non-public originals and production PNGs.
	 *
	 * @return void
	 */
	public function stream_private_file() {
		if ( ! $this->repository ) {
			wp_die( esc_html__( 'File service is unavailable.', 'flexible-product-customizer' ), '', array( 'response' => 503 ) );
		}
		$token     = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$file_id   = isset( $_GET['file_id'] ) ? sanitize_key( wp_unslash( $_GET['file_id'] ) ) : '';
		$signature = isset( $_GET['signature'] ) ? sanitize_text_field( wp_unslash( $_GET['signature'] ) ) : '';
		$session   = $this->repository->find( $token );

		if ( ! $session || ! hash_equals( $this->signature( $token, $file_id ), $signature ) || ! $this->can_access( $session ) ) {
			wp_die( esc_html__( 'You are not allowed to access this file.', 'flexible-product-customizer' ), '', array( 'response' => 403 ) );
		}

		$record = null;
		foreach ( array( 'uploads', 'production_files' ) as $collection ) {
			foreach ( isset( $session['payload'][ $collection ] ) && is_array( $session['payload'][ $collection ] ) ? $session['payload'][ $collection ] : array() as $candidate ) {
				if ( isset( $candidate['id'] ) && $file_id === $candidate['id'] ) {
					$record = $candidate;
					break 2;
				}
			}
		}

		$use_editor = ! empty( $_GET['variant'] ) && 'editor' === sanitize_key( wp_unslash( $_GET['variant'] ) ) && ! empty( $record['editor_relative_path'] );
		$relative   = $use_editor ? $record['editor_relative_path'] : ( $record && ! empty( $record['relative_path'] ) ? $record['relative_path'] : '' );
		$path       = $relative ? $this->absolute_path( $relative ) : '';
		if ( ! $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'The requested file does not exist.', 'flexible-product-customizer' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . sanitize_mime_type( $record['mime'] ) );
		header( 'Content-Length: ' . filesize( $path ) );
		$disposition = ! empty( $_GET['download'] ) ? 'attachment' : 'inline';
		header( 'Content-Disposition: ' . $disposition . '; filename="' . rawurlencode( sanitize_file_name( $record['original_name'] ) ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}

	/**
	 * Same-origin proxy for public template attachments, preventing tainted canvas exports when a CDN is active.
	 *
	 * @return void
	 */
	public function stream_base_image() {
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		$signature     = isset( $_GET['signature'] ) ? sanitize_text_field( wp_unslash( $_GET['signature'] ) ) : '';
		$expected      = hash_hmac( 'sha256', 'attachment:' . $attachment_id, wp_salt( 'secure_auth' ) );
		$path          = $attachment_id ? get_attached_file( $attachment_id ) : '';
		$mime          = $attachment_id ? get_post_mime_type( $attachment_id ) : '';

		if ( ! $signature || ! hash_equals( $expected, $signature ) || ! $path || ! is_file( $path ) || ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
			wp_die( esc_html__( 'The template image is unavailable.', 'flexible-product-customizer' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( wp_basename( $path ) ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}

	/** @param array $session Session. @param array $file File. @param \WC_Order|null $order Order. @return string */
	public function private_url( array $session, array $file, $order = null, $download = false ) {
		$args = array(
			'action'    => 'fpcw_file',
			'token'     => $session['token'],
			'file_id'   => $file['id'],
			'signature' => $this->signature( $session['token'], $file['id'] ),
		);
		if ( $order instanceof \WC_Order ) {
			$args['order_key'] = $order->get_order_key();
		}
		if ( $download ) {
			$args['download'] = '1';
		} elseif ( ! empty( $file['editor_relative_path'] ) ) {
			$args['variant'] = 'editor';
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/** @param int $attachment_id Attachment ID. @return string */
	public function attachment_url( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		return add_query_arg(
			array(
				'action'        => 'fpcw_base_image',
				'attachment_id' => $attachment_id,
				'signature'     => hash_hmac( 'sha256', 'attachment:' . $attachment_id, wp_salt( 'secure_auth' ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/** @param string $relative Relative path. @return string */
	public function public_url( $relative ) {
		return trailingslashit( $this->uploads['baseurl'] ) . 'flexible-product-customizer/' . ltrim( str_replace( '\\', '/', $relative ), '/' );
	}

	/** @param array $session Session. @return bool */
	private function can_access( array $session ) {
		if ( 'ordered' !== $session['status'] ) {
			return Session_Identity::owns( $session );
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$order = $session['order_id'] ? wc_get_order( $session['order_id'] ) : null;
		if ( ! $order ) {
			return false;
		}
		if ( get_current_user_id() && (int) $order->get_user_id() === get_current_user_id() ) {
			return true;
		}
		$key = isset( $_GET['order_key'] ) ? wc_clean( wp_unslash( $_GET['order_key'] ) ) : '';
		return $key && hash_equals( $order->get_order_key(), $key );
	}

	/** @param string $token Token. @param string $file_id File ID. @return string */
	private function signature( $token, $file_id ) {
		return hash_hmac( 'sha256', $token . ':' . $file_id, wp_salt( 'secure_auth' ) );
	}

	/** @param array $file File. @param int $max_bytes Size. @param int $max_dimension Dimension. @param array $mimes Mimes. @return array|\WP_Error */
	private function validate_image_upload( array $file, $max_bytes, $max_dimension, array $mimes ) {
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'fpcw_upload_error', __( 'The image upload did not complete.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}
		if ( empty( $file['tmp_name'] ) || empty( $file['size'] ) || (int) $file['size'] > $max_bytes ) {
			return new \WP_Error( 'fpcw_file_size', sprintf( __( 'Images must be no larger than %s MB.', 'flexible-product-customizer' ), round( $max_bytes / MB_IN_BYTES ) ), array( 'status' => 400 ) );
		}
		$image = getimagesize( $file['tmp_name'] );
		if ( ! $image || ! in_array( $image['mime'], $mimes, true ) ) {
			return new \WP_Error( 'fpcw_file_type', __( 'Use a PNG, JPEG, or WebP image. PNG is recommended for transparency and print quality.', 'flexible-product-customizer' ), array( 'status' => 400 ) );
		}
		if ( $image[0] > $max_dimension || $image[1] > $max_dimension ) {
			return new \WP_Error( 'fpcw_image_dimensions', sprintf( __( 'Image dimensions cannot exceed %1$s x %1$s pixels.', 'flexible-product-customizer' ), number_format_i18n( $max_dimension ) ), array( 'status' => 400 ) );
		}

		$extensions = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp' );
		return array( 'mime' => $image['mime'], 'extension' => $extensions[ $image['mime'] ], 'width' => (int) $image[0], 'height' => (int) $image[1] );
	}

	/**
	 * Create a bounded working copy for browsers while preserving the original upload.
	 *
	 * @param string $relative   Original relative path.
	 * @param string $id         File ID.
	 * @param array  $validation Validated image data.
	 * @return array
	 */
	private function create_editor_copy( $relative, $id, array $validation ) {
		if ( max( $validation['width'], $validation['height'] ) <= 2400 || ! function_exists( 'wp_get_image_editor' ) ) {
			return array();
		}
		$source = $this->absolute_path( $relative );
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return array();
		}
		$result = $editor->resize( 2400, 2400, false );
		if ( is_wp_error( $result ) ) {
			return array();
		}
		$extension   = pathinfo( $relative, PATHINFO_EXTENSION );
		$editor_path = dirname( $relative ) . '/' . $id . '-editor.' . $extension;
		$saved       = $editor->save( $this->absolute_path( $editor_path ), $validation['mime'] );
		if ( is_wp_error( $saved ) || empty( $saved['width'] ) || empty( $saved['height'] ) ) {
			return array();
		}
		return array(
			'editor_relative_path' => $editor_path,
			'editor_width'         => (int) $saved['width'],
			'editor_height'        => (int) $saved['height'],
		);
	}

	/** @param string $tmp Temporary file. @param string $relative Destination. @return true|\WP_Error */
	private function move_uploaded_file( $tmp, $relative ) {
		$this->prepare_directories();
		$destination = $this->absolute_path( $relative );
		wp_mkdir_p( dirname( $destination ) );
		if ( ! move_uploaded_file( $tmp, $destination ) ) {
			return new \WP_Error( 'fpcw_storage_error', __( 'The server could not store the image.', 'flexible-product-customizer' ), array( 'status' => 500 ) );
		}
		@chmod( $destination, 0644 );
		return true;
	}

	/** @param string $from Relative source. @param string $to Relative destination. @return true|\WP_Error */
	private function move_directory( $from, $to ) {
		$source = $this->absolute_path( $from );
		$target = $this->absolute_path( $to );
		if ( ! is_dir( $source ) ) {
			return true;
		}
		wp_mkdir_p( dirname( $target ) );
		if ( @rename( $source, $target ) ) {
			return true;
		}
		if ( ! $this->copy_directory( $source, $target ) ) {
			return new \WP_Error( 'fpcw_move_error', __( 'Customization files could not be attached to the order.', 'flexible-product-customizer' ) );
		}
		$this->delete_absolute_directory( $source );
		return true;
	}

	/** @param string $source Source. @param string $target Target. @return bool */
	private function copy_directory( $source, $target ) {
		if ( ! wp_mkdir_p( $target ) ) {
			return false;
		}
		$iterator = new \FilesystemIterator( $source, \FilesystemIterator::SKIP_DOTS );
		foreach ( $iterator as $item ) {
			$destination = $target . '/' . $item->getFilename();
			if ( $item->isDir() ) {
				if ( ! $this->copy_directory( $item->getPathname(), $destination ) ) {
					return false;
				}
			} elseif ( ! copy( $item->getPathname(), $destination ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param string $relative Relative directory. @return void */
	private function delete_directory( $relative ) {
		$path = $this->absolute_path( $relative );
		if ( is_dir( $path ) ) {
			$this->delete_absolute_directory( $path );
		}
	}

	/** @param string $path Absolute directory. @return void */
	private function delete_absolute_directory( $path ) {
		$root = wp_normalize_path( $this->base_dir() ) . '/';
		$path = wp_normalize_path( $path );
		if ( 0 !== strpos( $path . '/', $root ) || ! is_dir( $path ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
		}
		@rmdir( $path );
	}

	/** @param string $relative Relative file. @return void */
	private function delete_relative_file( $relative ) {
		$path = $this->absolute_path( $relative );
		if ( $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/** @param string $relative Relative path. @return string */
	private function absolute_path( $relative ) {
		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		if ( false !== strpos( $relative, '../' ) || false !== strpos( $relative, '..\\' ) ) {
			return '';
		}
		return wp_normalize_path( $this->base_dir() . '/' . $relative );
	}

	/** @return string */
	private function base_dir() {
		return wp_normalize_path( trailingslashit( $this->uploads['basedir'] ) . 'flexible-product-customizer' );
	}

	/** @param string $path Path. @param string $contents Contents. @return void */
	private function write_if_missing( $path, $contents ) {
		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
