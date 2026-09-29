<?php
/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Shopify default blog" destination: export to the native Shopify blog through the Blog Import app.
 *
 * The browser drives the export one short AJAX request at a time (connect, start, send blogs,
 * send posts, finish, then status polling), so no single PHP request runs long enough to hit a
 * host's time limit. Progress lives in the mageshbl_blog_import_job option, not in the browser: a
 * reloaded page continues where it stopped, and a batch retried after a network error is sent
 * again rather than skipped (the backend stores items by id, so a repeat is harmless).
 */
class MAGESHBL_Blog_Import {

	/**
	 * Slug of the export form page the destination is shown on.
	 */
	const PAGE_SLUG = 'magefan-blog-export-form';

	/**
	 * Destination value in the export form.
	 */
	const DESTINATION = 'shopify_blog';

	/**
	 * Option holding the connection: url, token, shop.
	 */
	const OPTION_CONNECTION = 'mageshbl_blog_import_connection';

	/**
	 * Option holding the current export: job_id, totals, sent counters, finished flag.
	 */
	const OPTION_JOB = 'mageshbl_blog_import_job';

	/**
	 * AJAX nonce action.
	 */
	const NONCE = 'mageshbl_blog_import';

	/**
	 * Categories sent per request.
	 */
	const BLOGS_PER_REQUEST = 50;

	/**
	 * Most posts sent per request.
	 */
	const POSTS_PER_REQUEST = 10;

	/**
	 * Most images collected into one request before it is sent.
	 */
	const IMAGES_PER_REQUEST = 200;

	/**
	 * Seconds of work after which a posts request stops taking more posts.
	 */
	const TIME_BUDGET = 20;

	/**
	 * @var MAGESHBL_Blog_Import|null
	 */
	private static $instance = null;

	/**
	 * Shared instance.
	 *
	 * @return MAGESHBL_Blog_Import
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$actions = array( 'connect', 'start', 'send', 'finish', 'status', 'cancel' );
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_mageshbl_blog_import_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/**
	 * Load the destination script and styles on the export form page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'mageshbl-blog-import', plugins_url( 'admin/css/blog-import.css', MAGESHBL_PLUGIN_FILE ), array(), MAGESHBL_PLUGIN_NAME_VERSION );
		wp_enqueue_script( 'mageshbl-blog-import', plugins_url( 'admin/js/blog-import.js', MAGESHBL_PLUGIN_FILE ), array(), MAGESHBL_PLUGIN_NAME_VERSION, true );

		$connection = $this->get_connection();
		wp_localize_script(
			'mageshbl-blog-import',
			'mageshblBlogImportConfig',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'destination' => self::DESTINATION,
				'shop'        => $connection ? $connection['shop'] : '',
				'totals'      => ( new MAGESHBL_Blog_Import_Exporter() )->get_totals(),
				'job'         => $this->get_job(),
				'i18n'        => array(
					/* translators: 1: number of posts, 2: number of categories */
					'summary'        => __( '%1$d posts and %2$d categories will be exported.', 'magefan-blog-export' ),
					'nothing'        => __( 'There are no posts to export.', 'magefan-blog-export' ),
					'keyHint'        => __( 'Paste the connection key from the Blog Import app in your Shopify admin. Drafts, scheduled and private posts are exported as hidden posts. Categories become separate blogs in Shopify.', 'magefan-blog-export' ),
					'connecting'     => __( 'Connecting…', 'magefan-blog-export' ),
					'starting'       => __( 'Starting…', 'magefan-blog-export' ),
					/* translators: %s: Shopify store domain */
					'exportingTo'    => __( 'Exporting to %s', 'magefan-blog-export' ),
					'sendingBlogs'   => __( 'Sending categories', 'magefan-blog-export' ),
					'sendingPosts'   => __( 'Sending posts', 'magefan-blog-export' ),
					'waiting'        => __( 'Queued — the import usually starts within a minute or two…', 'magefan-blog-export' ),
					'importing'      => __( 'Importing into Shopify', 'magefan-blog-export' ),
					'done'           => __( 'Import finished', 'magefan-blog-export' ),
					/* translators: 1: imported posts, 2: failed posts */
					'doneSummary'    => __( '%1$d posts imported, %2$d failed.', 'magefan-blog-export' ),
					'cancelled'      => __( 'Import cancelled.', 'magefan-blog-export' ),
					'failedJob'      => __( 'The import could not be completed.', 'magefan-blog-export' ),
					'canClose'       => __( 'All posts are sent. You can close this page — the import continues in Shopify. Come back here or open the Blog Import app in Shopify to check progress.', 'magefan-blog-export' ),
					'retrying'       => __( 'Connection problem, retrying…', 'magefan-blog-export' ),
					'interrupted'    => __( 'The export was interrupted before all posts were sent.', 'magefan-blog-export' ),
					'conflict'       => __( 'Another import to this store is still running.', 'magefan-blog-export' ),
					'confirmCancel'  => __( 'Cancel this import? Posts already imported stay in Shopify.', 'magefan-blog-export' ),
					'moreFailed'     => __( 'See the full list in the Blog Import app in Shopify.', 'magefan-blog-export' ),
					'genericError'   => __( 'Something went wrong. Please try again.', 'magefan-blog-export' ),
				),
			)
		);
	}

	/**
	 * Save a connection key after the backend confirms it.
	 *
	 * @return void
	 */
	public function ajax_connect() {
		$this->verify_request();

		$key    = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$parsed = MAGESHBL_Blog_Import_Api_Client::parse_key( $key );
		if ( null === $parsed ) {
			wp_send_json_error( array( 'message' => __( 'This is not a valid connection key. Copy it from the Blog Import app in your Shopify admin.', 'magefan-blog-export' ) ), 400 );
		}

		try {
			$response = ( new MAGESHBL_Blog_Import_Api_Client( $parsed['url'], $parsed['token'] ) )->request( 'importconnect' );
		} catch ( MAGESHBL_Blog_Import_Api_Exception $e ) {
			$this->send_api_error( $e );
		}

		$connection = $parsed + array( 'shop' => (string) $response['shop'] );
		update_option( self::OPTION_CONNECTION, $connection, false );

		wp_send_json_success( array( 'shop' => $connection['shop'] ) );
	}

	/**
	 * Open an import job on the backend.
	 *
	 * @return void
	 */
	public function ajax_start() {
		$this->verify_request();
		$client = $this->get_client();
		$totals = ( new MAGESHBL_Blog_Import_Exporter() )->get_totals();

		try {
			$response = $client->request(
				'importstart',
				array(
					'source'         => 'wordpress',
					'schema'         => 1,
					'site_url'       => home_url(),
					'client_version' => MAGESHBL_PLUGIN_NAME_VERSION,
					'totals'         => $totals,
				)
			);
		} catch ( MAGESHBL_Blog_Import_Api_Exception $e ) {
			$this->send_api_error( $e );
		}

		$job = array(
			'job_id'      => (int) $response['job_id'],
			'blogs_total' => $totals['blogs'],
			'posts_total' => $totals['posts'],
			'blogs_sent'  => 0,
			'posts_sent'  => 0,
			'finished'    => false,
		);
		update_option( self::OPTION_JOB, $job, false );

		wp_send_json_success( $job );
	}

	/**
	 * Send the next batch: categories until all are sent, then posts.
	 *
	 * @return void
	 */
	public function ajax_send() {
		$this->verify_request();
		$client = $this->get_client();
		$job    = $this->get_job();
		if ( null === $job || $job['finished'] ) {
			wp_send_json_error( array( 'message' => __( 'There is no export in progress.', 'magefan-blog-export' ) ), 409 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$exporter = new MAGESHBL_Blog_Import_Exporter();

		try {
			if ( $job['blogs_sent'] < $job['blogs_total'] ) {
				$job = $this->send_blogs( $client, $exporter, $job );
			} else {
				$job = $this->send_posts( $client, $exporter, $job );
			}
		} catch ( MAGESHBL_Blog_Import_Api_Exception $e ) {
			$this->send_api_error( $e );
		}

		update_option( self::OPTION_JOB, $job, false );

		wp_send_json_success( $job );
	}

	/**
	 * Tell the backend every item is sent.
	 *
	 * @return void
	 */
	public function ajax_finish() {
		$this->verify_request();
		$client = $this->get_client();
		$job    = $this->get_job();
		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'There is no export in progress.', 'magefan-blog-export' ) ), 409 );
		}

		try {
			$response = $client->request( 'importfinish', array( 'job_id' => $job['job_id'] ) );
		} catch ( MAGESHBL_Blog_Import_Api_Exception $e ) {
			$this->send_api_error( $e );
		}

		$job['finished'] = true;
		update_option( self::OPTION_JOB, $job, false );

		wp_send_json_success( array( 'status' => $response['status'] ) );
	}

	/**
	 * Progress of the current import as reported by the backend.
	 *
	 * @return void
	 */
	public function ajax_status() {
		$this->verify_request();
		$client = $this->get_client();
		$job    = $this->get_job();
		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'There is no export in progress.', 'magefan-blog-export' ) ), 409 );
		}

		try {
			wp_send_json_success( $client->request( 'importstatus', array( 'job_id' => $job['job_id'] ) ) );
		} catch ( MAGESHBL_Blog_Import_Api_Exception $e ) {
			$this->send_api_error( $e );
		}
	}

	/**
	 * Cancel an import: the current one, or the conflicting one named in the request.
	 *
	 * The saved export is forgotten even when the backend cannot be reached (for example after the
	 * key was reset in the app), so the export form is never locked; the import can then still be
	 * cancelled in the Blog Import app.
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		$this->verify_request();
		$job    = $this->get_job();
		$job_id = absint( $_POST['job_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $job_id && $job ) {
			$job_id = $job['job_id'];
		}

		$is_own_job = $job && $job['job_id'] === $job_id;
		$error      = null;

		if ( $job_id ) {
			$connection = $this->get_connection();
			try {
				if ( null === $connection ) {
					throw new MAGESHBL_Blog_Import_Api_Exception( esc_html__( 'Enter the connection key from the Blog Import app first.', 'magefan-blog-export' ), 409 );
				}
				( new MAGESHBL_Blog_Import_Api_Client( $connection['url'], $connection['token'] ) )->request( 'importcancel', array( 'job_id' => $job_id ) );
			} catch ( MAGESHBL_Blog_Import_Api_Exception $e ) {
				if ( 404 !== $e->get_status() ) {
					$error = $e;
				}
			}
		}

		if ( $error && ! $is_own_job ) {
			$this->send_api_error( $error );
		}

		if ( $is_own_job ) {
			delete_option( self::OPTION_JOB );
		}

		wp_send_json_success(
			$error
				? array( 'warning' => __( 'The export was removed here, but the Blog Import app could not be reached to stop it. If the import is still running, cancel it in the Blog Import app in your Shopify admin.', 'magefan-blog-export' ) )
				: null
		);
	}

	/**
	 * Send one batch of categories.
	 *
	 * @param MAGESHBL_Blog_Import_Api_Client $client   Backend client.
	 * @param MAGESHBL_Blog_Import_Exporter   $exporter Exporter.
	 * @param array                           $job      Export state.
	 * @return array Updated state.
	 * @throws MAGESHBL_Blog_Import_Api_Exception When the backend rejects the batch.
	 */
	private function send_blogs( MAGESHBL_Blog_Import_Api_Client $client, MAGESHBL_Blog_Import_Exporter $exporter, array $job ) {
		$items = $exporter->get_blog_items( $job['blogs_sent'], self::BLOGS_PER_REQUEST );
		if ( $items ) {
			$client->request(
				'importitems',
				array(
					'job_id' => $job['job_id'],
					'items'  => $items,
				)
			);
		}

		$job['blogs_sent'] = $items ? $job['blogs_sent'] + self::BLOGS_PER_REQUEST : $job['blogs_total'];
		$job['blogs_sent'] = min( $job['blogs_sent'], $job['blogs_total'] );

		return $job;
	}

	/**
	 * Send one batch of posts with their images.
	 *
	 * Takes posts until the batch holds POSTS_PER_REQUEST posts, IMAGES_PER_REQUEST images, or
	 * TIME_BUDGET seconds of work — always at least one post, so a slow post still moves forward.
	 *
	 * @param MAGESHBL_Blog_Import_Api_Client $client   Backend client.
	 * @param MAGESHBL_Blog_Import_Exporter   $exporter Exporter.
	 * @param array                           $job      Export state.
	 * @return array Updated state.
	 * @throws MAGESHBL_Blog_Import_Api_Exception When the backend rejects the batch.
	 */
	private function send_posts( MAGESHBL_Blog_Import_Api_Client $client, MAGESHBL_Blog_Import_Exporter $exporter, array $job ) {
		$started  = microtime( true );
		$post_ids = $exporter->get_post_ids( $job['posts_sent'], self::POSTS_PER_REQUEST );
		if ( ! $post_ids ) {
			$job['posts_total'] = $job['posts_sent'];
			return $job;
		}

		$uploader    = new MAGESHBL_Blog_Import_Image_Uploader( $client, $job['job_id'] );
		$items       = array();
		$taken       = 0;
		$image_count = 0;

		foreach ( $post_ids as $post_id ) {
			if ( $taken && ( self::TIME_BUDGET < microtime( true ) - $started || self::IMAGES_PER_REQUEST <= $image_count ) ) {
				break;
			}

			++$taken;
			$built = $exporter->build_post( $post_id );
			if ( null === $built ) {
				continue;
			}

			$items[]      = $exporter->attach_images( $built, $uploader->resolve( $built['images'] ) );
			$image_count += count( $built['images'] );
		}

		if ( $items ) {
			$client->request(
				'importitems',
				array(
					'job_id' => $job['job_id'],
					'items'  => $items,
				)
			);
		}

		$job['posts_sent'] += $taken;
		if ( count( $post_ids ) < self::POSTS_PER_REQUEST && $taken === count( $post_ids ) ) {
			$job['posts_total'] = $job['posts_sent'];
		}
		$job['posts_total'] = max( $job['posts_total'], $job['posts_sent'] );

		return $job;
	}

	/**
	 * Saved connection, null when the plugin is not connected.
	 *
	 * @return array|null
	 */
	private function get_connection() {
		$connection = get_option( self::OPTION_CONNECTION );

		return is_array( $connection ) && ! empty( $connection['token'] ) ? $connection : null;
	}

	/**
	 * Saved export state, null when there is none.
	 *
	 * @return array|null
	 */
	private function get_job() {
		$job = get_option( self::OPTION_JOB );

		return is_array( $job ) && ! empty( $job['job_id'] ) ? $job : null;
	}

	/**
	 * Backend client for the saved connection; ends the request when the plugin is not connected.
	 *
	 * @return MAGESHBL_Blog_Import_Api_Client
	 */
	private function get_client() {
		$connection = $this->get_connection();
		if ( null === $connection ) {
			wp_send_json_error( array( 'message' => __( 'Enter the connection key from the Blog Import app first.', 'magefan-blog-export' ) ), 409 );
		}

		return new MAGESHBL_Blog_Import_Api_Client( $connection['url'], $connection['token'] );
	}

	/**
	 * Check the nonce and capability of an AJAX request.
	 *
	 * @return void
	 */
	private function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'magefan-blog-export' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Report a backend error to the page.
	 *
	 * @param MAGESHBL_Blog_Import_Api_Exception $e Error.
	 * @return void
	 */
	private function send_api_error( MAGESHBL_Blog_Import_Api_Exception $e ) {
		$data = $e->get_data();

		wp_send_json_error(
			array(
				'message'   => wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ),
				'status'    => $e->get_status(),
				'retryable' => $e->is_retryable(),
				'job_id'    => isset( $data['job_id'] ) ? (int) $data['job_id'] : 0,
			),
			$e->is_retryable() ? 502 : 400
		);
	}
}
