<?php
/**
 * Bounded automatic cleanup of expired temporary customizations.
 *
 * @package FlexibleProductCustomizer
 */

namespace FPCW;

defined( 'ABSPATH' ) || exit;

final class Cleanup {
	/** @var Repository */ private $repository;
	/** @var File_Storage */ private $storage;

	public function __construct( Repository $repository, File_Storage $storage ) {
		$this->repository = $repository;
		$this->storage    = $storage;
	}

	/** @return void */
	public function register_hooks() {
		add_action( 'fpcw_cleanup_expired', array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ), 20 );
	}

	/** @return void */
	public function ensure_schedule() {
		if ( ! wp_next_scheduled( 'fpcw_cleanup_expired' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'fpcw_cleanup_expired' );
		}
	}

	/** @return void */
	public function run() {
		for ( $batch = 0; $batch < 10; $batch++ ) {
			$sessions = $this->repository->find_expired( 100 );
			if ( ! $sessions ) {
				break;
			}
			foreach ( $sessions as $session ) {
				$this->storage->delete_temporary_session( $session['token'] );
				$this->repository->delete( $session['token'] );
			}
			if ( count( $sessions ) < 100 ) {
				break;
			}
		}
	}
}

