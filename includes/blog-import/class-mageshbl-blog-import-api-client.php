<?php
/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to the Blog Import backend with the merchant's connection key.
 *
 * The key is "mfbi_" + base64url of {"v":1,"url":"…/sfapp/api/","token":"…"}: the endpoint comes
 * from the key, so the plugin never hardcodes where the backend lives.
 */
class MAGESHBL_Blog_Import_Api_Client {

	/**
	 * Connection key prefix.
	 */
	const KEY_PREFIX = 'mfbi_';

	/**
	 * Seconds to wait for the backend.
	 */
	const TIMEOUT = 60;

	/**
	 * Backend API base URL, ending with a slash.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Token sent in X-Blog-Import-Token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * @param string $url   Backend API base URL.
	 * @param string $token Connection token.
	 */
	public function __construct( $url, $token ) {
		$this->url   = trailingslashit( $url );
		$this->token = $token;
	}

	/**
	 * Decode a connection key pasted by the merchant.
	 *
	 * @param string $key Connection key.
	 * @return array|null ['url' => string, 'token' => string], null when the key is malformed.
	 */
	public static function parse_key( $key ) {
		$key = trim( (string) $key );
		if ( 0 !== strpos( $key, self::KEY_PREFIX ) ) {
			return null;
		}

		$json = base64_decode( strtr( substr( $key, strlen( self::KEY_PREFIX ) ), '-_', '+/' ), true );
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || 1 !== (int) ( $data['v'] ?? 0 ) || empty( $data['token'] ) ) {
			return null;
		}

		$url = (string) ( $data['url'] ?? '' );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}

		return array(
			'url'   => $url,
			'token' => (string) $data['token'],
		);
	}

	/**
	 * Call a backend action.
	 *
	 * @param string $action Action name, e.g. "importstart".
	 * @param array  $body   Request body.
	 * @return array Decoded response.
	 * @throws MAGESHBL_Blog_Import_Api_Exception When the request fails or the backend reports an error.
	 */
	public function request( $action, array $body = array() ) {
		$response = wp_remote_post(
			$this->url . $action,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'        => 'application/json',
					'Accept'              => 'application/json',
					'X-Blog-Import-Token' => $this->token,
				),
				'body'    => wp_json_encode( (object) $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new MAGESHBL_Blog_Import_Api_Exception(
				/* translators: %s: error message */
				esc_html( sprintf( __( 'Could not reach the Blog Import app: %s', 'magefan-blog-export' ), $response->get_error_message() ) )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			throw new MAGESHBL_Blog_Import_Api_Exception(
				/* translators: %d: HTTP status code */
				esc_html( sprintf( __( 'Unexpected response from the Blog Import app (HTTP %d). Please try again later.', 'magefan-blog-export' ), $status ) ),
				absint( $status ? $status : 502 )
			);
		}

		if ( 200 > $status || 300 <= $status ) {
			$message = isset( $data['error'] ) ? (string) $data['error'] : __( 'The Blog Import app returned an error.', 'magefan-blog-export' );
			$exception = new MAGESHBL_Blog_Import_Api_Exception( esc_html( $message ), absint( $status ) );
			$exception->set_data( $data );
			throw $exception;
		}

		return $data;
	}
}
