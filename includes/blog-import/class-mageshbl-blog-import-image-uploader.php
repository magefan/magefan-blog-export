<?php
/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets post images to Shopify.
 *
 * Files that exist on this server are uploaded straight to Shopify's staged upload storage, so an
 * import works even when the site is not publicly reachable (localhost, staging, a domain already
 * pointed at Shopify). Image bytes never pass through the Blog Import backend. When a file is not
 * on disk, is too large, or its upload fails, the public URL is sent instead and Shopify tries to
 * download it.
 */
class MAGESHBL_Blog_Import_Image_Uploader {

	/**
	 * Largest image Shopify accepts, in bytes.
	 */
	const MAX_FILE_SIZE = 20971520;

	/**
	 * Most upload targets one backend request may ask for.
	 */
	const TARGETS_PER_REQUEST = 50;

	/**
	 * Seconds allowed for one file upload.
	 */
	const UPLOAD_TIMEOUT = 60;

	/**
	 * @var MAGESHBL_Blog_Import_Api_Client
	 */
	private $client;

	/**
	 * @var int
	 */
	private $job_id;

	/**
	 * Resolved image per local path, so a file used by several posts in one batch is uploaded once.
	 *
	 * @var array
	 */
	private $uploaded = array();

	/**
	 * @param MAGESHBL_Blog_Import_Api_Client $client Backend client.
	 * @param int             $job_id Import job id.
	 */
	public function __construct( MAGESHBL_Blog_Import_Api_Client $client, $job_id ) {
		$this->client = $client;
		$this->job_id = (int) $job_id;
	}

	/**
	 * Turn image references into what the import API expects.
	 *
	 * @param array $images Keyed by caller reference: ['url' => absolute URL, 'path' => local file or '', 'alt' => string].
	 * @return array Same keys: ['resource_url' => …] or ['url' => …], plus 'alt'. References that cannot be imported at all are omitted.
	 * @throws MAGESHBL_Blog_Import_Api_Exception When the backend cannot provide upload targets.
	 */
	public function resolve( array $images ) {
		$result  = array();
		$pending = array();

		foreach ( $images as $reference => $image ) {
			$path = $this->get_uploadable_path( $image['path'] );
			if ( '' === $path ) {
				if ( preg_match( '#^https?://#i', $image['url'] ) ) {
					$result[ $reference ] = array(
						'url' => $image['url'],
						'alt' => $image['alt'],
					);
				}
				continue;
			}

			if ( isset( $this->uploaded[ $path ] ) ) {
				$result[ $reference ] = $this->uploaded[ $path ] + array( 'alt' => $image['alt'] );
				continue;
			}

			$pending[ $path ][] = $reference;
		}

		foreach ( array_chunk( array_keys( $pending ), self::TARGETS_PER_REQUEST ) as $paths ) {
			$this->upload_files( $paths );
		}

		foreach ( $pending as $path => $references ) {
			foreach ( $references as $reference ) {
				if ( isset( $this->uploaded[ $path ] ) ) {
					$result[ $reference ] = $this->uploaded[ $path ] + array( 'alt' => $images[ $reference ]['alt'] );
				} elseif ( preg_match( '#^https?://#i', $images[ $reference ]['url'] ) ) {
					$result[ $reference ] = array(
						'url' => $images[ $reference ]['url'],
						'alt' => $images[ $reference ]['alt'],
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Ask the backend for upload targets and upload each file to its target.
	 *
	 * @param string[] $paths Local file paths.
	 * @return void
	 * @throws MAGESHBL_Blog_Import_Api_Exception When the backend cannot provide upload targets.
	 */
	private function upload_files( array $paths ) {
		$files = array();
		foreach ( $paths as $path ) {
			$files[] = array(
				'filename'  => wp_basename( $path ),
				'mime_type' => wp_check_filetype( $path )['type'],
				'file_size' => filesize( $path ),
			);
		}

		$response = $this->client->request(
			'importuploads',
			array(
				'job_id' => $this->job_id,
				'files'  => $files,
			)
		);

		foreach ( $paths as $index => $path ) {
			$target = $response['targets'][ $index ] ?? null;
			if ( is_array( $target ) && $this->upload_file( $path, $files[ $index ]['mime_type'], $target ) ) {
				$this->uploaded[ $path ] = array( 'resource_url' => $target['resource_url'] );
			}
		}
	}

	/**
	 * Upload one file to a staged upload target as multipart/form-data: the target's parameters first, the file last.
	 *
	 * @param string $path      Local file path.
	 * @param string $mime_type File MIME type.
	 * @param array  $target    url, resource_url, parameters.
	 * @return bool Whether the upload succeeded.
	 */
	private function upload_file( $path, $mime_type, array $target ) {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return false;
		}

		$boundary = wp_generate_password( 24, false );
		$body     = '';
		foreach ( (array) $target['parameters'] as $parameter ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $parameter['name'] . "\"\r\n\r\n";
			$body .= $parameter['value'] . "\r\n";
		}
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . str_replace( '"', '', wp_basename( $path ) ) . "\"\r\n";
		$body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
		$body .= $contents . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		$response = wp_remote_post(
			$target['url'],
			array(
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return 200 <= $status && 300 > $status;
	}

	/**
	 * Local path of an image file that can be uploaded, empty string when it cannot.
	 *
	 * @param string $path Candidate path.
	 * @return string
	 */
	private function get_uploadable_path( $path ) {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$size = filesize( $path );
		$type = wp_check_filetype( $path )['type'];

		if ( ! $size || self::MAX_FILE_SIZE < $size || ! $type || 0 !== strpos( $type, 'image/' ) ) {
			return '';
		}

		return $path;
	}
}
