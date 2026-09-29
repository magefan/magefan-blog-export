<?php
/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads WordPress content and turns it into import API items.
 *
 * Output is source-neutral (see the Blog Import API contract): categories become "blog" items,
 * posts become "post" items that reference their categories by id. How any of it maps onto
 * Shopify is decided by the backend.
 */
class MAGESHBL_Blog_Import_Exporter {

	/**
	 * Post statuses that are exported.
	 */
	const POST_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Slug of WordPress's placeholder category, which is not exported as a blog.
	 */
	const UNCATEGORIZED_SLUG = 'uncategorized';

	/**
	 * Numbers shown before an export starts and sent with importstart.
	 *
	 * @return array ['blogs' => int, 'posts' => int]
	 */
	public function get_totals() {
		$counts = wp_count_posts( 'post' );
		$posts  = 0;
		foreach ( self::POST_STATUSES as $status ) {
			$posts += (int) ( $counts->$status ?? 0 );
		}

		return array(
			'blogs' => count( $this->get_category_ids() ),
			'posts' => $posts,
		);
	}

	/**
	 * Blog items for a slice of the exported categories.
	 *
	 * @param int $offset Categories to skip.
	 * @param int $limit  Categories to return.
	 * @return array
	 */
	public function get_blog_items( $offset, $limit ) {
		$items = array();
		foreach ( array_slice( $this->get_category_ids(), $offset, $limit ) as $term_id ) {
			$term = get_term( $term_id, 'category' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$items[] = array(
				'type' => 'blog',
				'id'   => (string) $term->term_id,
				'data' => array(
					'title'            => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'handle'           => urldecode( $term->slug ),
					'description_html' => $term->description,
				),
			);
		}

		return $items;
	}

	/**
	 * Ids of a slice of the exported posts, in a stable order.
	 *
	 * @param int $offset Posts to skip.
	 * @param int $limit  Posts to return.
	 * @return int[]
	 */
	public function get_post_ids( $offset, $limit ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => 'post',
					'post_status'      => self::POST_STATUSES,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'offset'           => (int) $offset,
					'posts_per_page'   => (int) $limit,
					'fields'           => 'ids',
					'no_found_rows'    => true,
				)
			)
		);
	}

	/**
	 * Build the post item and the images it references.
	 *
	 * Images are returned separately, keyed by reference, so the caller can upload a whole batch
	 * at once; attach_images() then puts the results back into the item.
	 *
	 * @param int $post_id Post id.
	 * @return array|null ['item' => array, 'images' => array], null when the post no longer exists.
	 */
	public function build_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$images    = array();
		$body_html = $this->render_content( $post );
		$inline    = $this->extract_images( $body_html );
		foreach ( $inline as $index => $image ) {
			$images[ $post_id . ':images:' . $index ] = $image;
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post );
		if ( $thumbnail_id ) {
			$url = (string) wp_get_attachment_url( $thumbnail_id );
			if ( '' !== $url ) {
				$images[ $post_id . ':image' ] = array(
					'url'  => $url,
					'path' => (string) get_attached_file( $thumbnail_id ),
					'alt'  => (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
				);
			}
		}

		$author  = get_userdata( (int) $post->post_author );
		$is_live = in_array( $post->post_status, array( 'publish', 'future' ), true );

		return array(
			'item'   => array(
				'type' => 'post',
				'id'   => (string) $post->ID,
				'data' => array(
					'title'        => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'handle'       => urldecode( $post->post_name ),
					'body_html'    => $body_html,
					'summary_html' => '' === trim( $post->post_excerpt ) ? '' : wpautop( $post->post_excerpt ),
					'author'       => $author ? $author->display_name : '',
					'tags'         => wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) ),
					'blogs'        => $this->get_post_category_ids( $post ),
					'published'    => $is_live,
					'published_at' => $this->format_date( $post->post_date_gmt, $post->post_date ),
					'seo'          => $this->get_seo( $post ),
					'image'        => null,
					'images'       => array(),
				),
			),
			'images' => $images,
			'inline' => array_keys( $inline ),
		);
	}

	/**
	 * Put resolved images back into a post item.
	 *
	 * @param array $built    Result of build_post().
	 * @param array $resolved Result of MAGESHBL_Blog_Import_Image_Uploader::resolve() for the post's images.
	 * @return array Post item.
	 */
	public function attach_images( array $built, array $resolved ) {
		$item    = $built['item'];
		$post_id = $item['id'];

		if ( isset( $resolved[ $post_id . ':image' ] ) ) {
			$item['data']['image'] = $resolved[ $post_id . ':image' ];
		}

		foreach ( $built['inline'] as $index ) {
			$reference = $post_id . ':images:' . $index;
			if ( isset( $resolved[ $reference ] ) ) {
				$item['data']['images'][] = array( 'src' => $built['images'][ $reference ]['src'] ) + $resolved[ $reference ];
			}
		}

		return $item;
	}

	/**
	 * Post content rendered the way visitors see it.
	 *
	 * Runs the_content, so blocks, shortcodes, embeds and page builders produce their real HTML.
	 * The post is set up as the global post but not "in the loop": plugins that append share
	 * buttons or related posts check in_the_loop(), so their widgets stay out of the export.
	 * srcset and sizes are removed from images: they point at resized copies on the old site that
	 * the import does not carry over, and the browser would prefer them over the replaced src.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function render_content( WP_Post $post ) {
		$previous_post = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$html = str_replace( ']]>', ']]&gt;', $html );

		$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $previous_post instanceof WP_Post ) {
			setup_postdata( $previous_post );
		}

		return (string) preg_replace( '#\s(?:srcset|sizes)=("[^"]*"|\'[^\']*\')#i', '', $html );
	}

	/**
	 * Images used in rendered content.
	 *
	 * @param string $html Rendered content.
	 * @return array Keyed by index: src (exact attribute value), url (absolute), path (local file or ''), alt.
	 */
	private function extract_images( $html ) {
		if ( ! preg_match_all( '#<img\b[^>]*>#i', $html, $tags ) ) {
			return array();
		}

		$images = array();
		$seen   = array();
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '#\ssrc=("([^"]*)"|\'([^\']*)\')#i', $tag, $src_match ) ) {
				continue;
			}

			$src = '' !== ( $src_match[2] ?? '' ) ? $src_match[2] : ( $src_match[3] ?? '' );
			if ( '' === $src || 0 === strpos( $src, 'data:' ) || isset( $seen[ $src ] ) ) {
				continue;
			}
			$seen[ $src ] = true;

			$alt = '';
			if ( preg_match( '#\salt=("([^"]*)"|\'([^\']*)\')#i', $tag, $alt_match ) ) {
				$alt = html_entity_decode( '' !== ( $alt_match[2] ?? '' ) ? $alt_match[2] : ( $alt_match[3] ?? '' ), ENT_QUOTES, 'UTF-8' );
			}

			$url      = $this->to_absolute_url( html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) );
			$images[] = array(
				'src'  => $src,
				'url'  => $url,
				'path' => $this->to_local_path( $url ),
				'alt'  => $alt,
			);
		}

		return $images;
	}

	/**
	 * Absolute form of a URL found in content.
	 *
	 * @param string $url URL as written in the HTML.
	 * @return string
	 */
	private function to_absolute_url( $url ) {
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return home_url( $url );
		}

		return $url;
	}

	/**
	 * Local file behind an uploads URL, empty string for anything outside the uploads directory.
	 *
	 * Compared without the scheme, so http/https mismatches between content and settings still match.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	private function to_local_path( $url ) {
		$uploads = wp_get_upload_dir();
		$base    = preg_replace( '#^https?:#i', '', untrailingslashit( $uploads['baseurl'] ) );
		$bare    = preg_replace( '#^https?:#i', '', strtok( $url, '?#' ) );

		if ( 0 !== strpos( $bare, $base . '/' ) ) {
			return '';
		}

		$relative = rawurldecode( substr( $bare, strlen( $base ) + 1 ) );
		if ( false !== strpos( $relative, '..' ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . $relative;
	}

	/**
	 * Ids of the categories exported as blogs.
	 *
	 * @return int[]
	 */
	private function get_category_ids() {
		static $ids = null;
		if ( null === $ids ) {
			$ids   = array();
			$terms = get_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
					'orderby'    => 'term_id',
					'order'      => 'ASC',
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( self::UNCATEGORIZED_SLUG !== $term->slug ) {
					$ids[] = (int) $term->term_id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Category ids of a post, primary category first.
	 *
	 * The primary category is the one Yoast SEO or Rank Math marks; without either, WordPress's own
	 * order is kept. The backend puts the post into the blog of the first id.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	private function get_post_category_ids( WP_Post $post ) {
		$ids = array();
		foreach ( wp_get_post_terms( $post->ID, 'category' ) as $term ) {
			if ( self::UNCATEGORIZED_SLUG !== $term->slug ) {
				$ids[] = (string) $term->term_id;
			}
		}

		foreach ( array( '_yoast_wpseo_primary_category', 'rank_math_primary_category' ) as $meta_key ) {
			$primary = (string) get_post_meta( $post->ID, $meta_key, true );
			if ( '' !== $primary && in_array( $primary, $ids, true ) ) {
				$ids = array_values( array_unique( array_merge( array( $primary ), $ids ) ) );
				break;
			}
		}

		return $ids;
	}

	/**
	 * SEO title and description from the SEO plugin in use.
	 *
	 * Supports Yoast SEO, Rank Math, SEOPress and All in One SEO. Template variables are expanded
	 * with the plugin's own function when it is active; a value that still contains unexpanded
	 * variables is dropped, since Shopify would show it literally.
	 *
	 * @param WP_Post $post Post.
	 * @return array ['title' => string, 'description' => string]
	 */
	private function get_seo( WP_Post $post ) {
		$title       = '';
		$description = '';

		$sources = array(
			array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ),
			array( 'rank_math_title', 'rank_math_description' ),
			array( '_seopress_titles_title', '_seopress_titles_desc' ),
		);

		foreach ( $sources as $keys ) {
			$title       = '' !== $title ? $title : $this->expand_seo_variables( (string) get_post_meta( $post->ID, $keys[0], true ), $post );
			$description = '' !== $description ? $description : $this->expand_seo_variables( (string) get_post_meta( $post->ID, $keys[1], true ), $post );
		}

		if ( '' === $title || '' === $description ) {
			$aioseo = $this->get_aioseo( $post );
			$title       = '' !== $title ? $title : $aioseo['title'];
			$description = '' !== $description ? $description : $aioseo['description'];
		}

		return array(
			'title'       => $title,
			'description' => $description,
		);
	}

	/**
	 * Expand SEO plugin template variables, or drop the value when they cannot be expanded.
	 *
	 * @param string  $value Raw meta value.
	 * @param WP_Post $post  Post.
	 * @return string
	 */
	private function expand_seo_variables( $value, WP_Post $post ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( false !== strpos( $value, '%%' ) && function_exists( 'wpseo_replace_vars' ) ) {
			$value = wpseo_replace_vars( $value, $post );
		}

		if ( false !== strpos( $value, '%' ) && class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
			$value = \RankMath\Helper::replace_vars( $value, $post );
		}

		$value = trim( wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) ) );

		return preg_match( '#%%?[a-z_]+%?%#i', $value ) ? '' : $value;
	}

	/**
	 * SEO title and description stored by All in One SEO in its own table.
	 *
	 * @param WP_Post $post Post.
	 * @return array ['title' => string, 'description' => string]
	 */
	private function get_aioseo( WP_Post $post ) {
		global $wpdb;

		$empty = array(
			'title'       => '',
			'description' => '',
		);

		$table = $wpdb->prefix . 'aioseo_posts';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $empty;
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT title, description FROM {$table} WHERE post_id = %d", $post->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return $empty;
		}

		$result = array();
		foreach ( $empty as $key => $unused ) {
			$value          = trim( (string) $row[ $key ] );
			$result[ $key ] = false === strpos( $value, '#' ) ? $value : '';
		}

		return $result;
	}

	/**
	 * ISO 8601 UTC date, falling back to the local date for drafts that have no GMT date yet.
	 *
	 * @param string $gmt   post_date_gmt.
	 * @param string $local post_date.
	 * @return string Empty when the post has no date.
	 */
	private function format_date( $gmt, $local ) {
		if ( '0000-00-00 00:00:00' !== $gmt && '' !== $gmt ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $gmt . ' UTC' ) );
		}

		if ( '0000-00-00 00:00:00' !== $local && '' !== $local ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', (int) get_gmt_from_date( $local, 'U' ) );
		}

		return '';
	}
}
