<?php
/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error returned by the Blog Import backend, or a failure to reach it.
 */
class MAGESHBL_Blog_Import_Api_Exception extends Exception {

	/**
	 * HTTP status of the backend response, 0 when no response was received.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Decoded response body.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * @param string $message Merchant-readable message, already escaped for HTML.
	 * @param int    $status  HTTP status, 0 when the request did not complete.
	 */
	public function __construct( $message, $status = 0 ) {
		parent::__construct( $message );
		$this->status = (int) $status;
		$this->data   = array();
	}

	/**
	 * Attach the decoded response body.
	 *
	 * @param array $data Decoded response body.
	 * @return void
	 */
	public function set_data( array $data ) {
		$this->data = $data;
	}

	/**
	 * HTTP status of the backend response.
	 *
	 * @return int
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Decoded response body.
	 *
	 * @return array
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Whether sending the same request again may succeed: network failures, throttling and server errors.
	 *
	 * @return bool
	 */
	public function is_retryable() {
		return 0 === $this->status || 429 === $this->status || 500 <= $this->status;
	}
}
