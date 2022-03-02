<?php
/**
 * Main plugin class.
 *
 * @package Open_Graph_Parser
 */

/**
 * Main plugin class.
 */
class Open_Graph_Parser {
	/**
	 * This plugin's single instance.
	 *
	 * @var Open_Graph_Parser $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * Returns the single instance of this class.
	 *
	 * @return Open_Graph_Parser Single class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * (Private) constructor.
	 */
	private function __construct() { }

	/**
	 * Registers callbacks and such.
	 */
	public function register() {
		// Run whenever Notes or Likes are published, i.e., whenever they are
		// saved and have a "publish" status.
		add_action( 'publish_iwcpt_like', array( $this, 'schedule' ), 20, 2 );
		add_action( 'publish_iwcpt_note', array( $this, 'schedule' ), 20, 2 );
		// To do: Use `transition_post_status` and check for a filterable array
		// of post types.

		add_action( 'ogp_parse_post', array( $this, 'parse_post' ) );
	}

	/**
	 * Schedules fetching Open Graph data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 */
	public function schedule( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			// Prevent double posting.
			return;
		}

		if ( ! in_array( $post->post_type, array( 'iwcpt_like', 'iwcpt_note' ), true ) ) {
			return;
		}

		// We could try and parse Open Graph metadata immediately, but let's
		// schedule it into the very near future instead, to speed up publishing
		// a bit.
		wp_schedule_single_event( time() + wp_rand( 0, 300 ), 'ogp_parse_post', array( $post->ID ) );
	}

	/**
	 * Attempts to find the first link in the post content, and parse its Open
	 * Graph metadata, and save its URL, title, and (preview) image.
	 *
	 * @param int $post_id Post ID.
	 */
	public function parse_post( $post_id ) {
		$post = get_post( $post_id );

		if ( empty( $post ) ) {
			// Not a post?
			return;
		}

		// Convert plain URLs to hyperlinks, and apply `the_content` filters.
		$content = make_clickable( $post->post_content );
		$content = apply_filters( 'the_content', $content );

		$url = self::get_first_href( $content );

		if ( empty( $url ) ) {
			return;
		}

		if ( get_post_meta( $post_id, '_og_url', true ) === $url ) {
			return;
		}

		$tags = self::parse_url( $url );
		$tags = array_map(
			function( $tag ) {
				// Convert HTML entities, if any, to Unicode.
				if ( is_string( $tag ) ) {
					$tag = html_entity_decode( $tag, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}

				return $tag;
			},
			$tags
		);

		if ( empty( $tags['title'] ) ) {
			// It all starts with a title.
			return;
		}

		update_post_meta( $post->ID, '_og_url', esc_url_raw( $url ) );
		update_post_meta( $post->ID, '_og_title', $tags['title'] );

		if ( empty( $tags['image'] ) || ! wp_http_validate_url( $tags['image'] ) ) {
			// We're done here.
			return;
		}

		// Download the page's "social share" image.
		$thumbnail = self::create_thumbnail( $tags['image'], $post );

		if ( ! empty( $thumbnail ) ) {
			update_post_meta( $post->ID, '_og_image', $thumbnail );
		}

		// Use to save more tags as post meta.
		do_action( 'ogp_after_parse', $post_id, $url, $tags );
	}

	/**
	 * Fetches the post's first hyperlink.
	 *
	 * @param  string $post_content The post's content.
	 * @return string               First link's URL, or an empty string.
	 */
	public static function get_first_href( $post_content ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true ); // Work around DOMDocument's HTML5 limitations.
		$dom->loadHTML( mb_convert_encoding( $post_content, 'HTML-ENTITIES', get_bloginfo( 'charset' ) ) );

		$nodes = $dom->getElementsByTagName( 'a' );

		if ( empty( $nodes ) || $nodes->length < 1 ) {
			return '';
		}

		// Grab the first link.
		$node = $nodes[0];

		if ( ! $node->hasAttribute( 'href' ) ) {
			return '';
		}

		$url = $node->getAttribute( 'href' );

		if ( wp_http_validate_url( $url ) ) {
			return $url;
		}
	}

	/**
	 * Parses a web page's metadata.
	 *
	 * @param  string $url Web page URL.
	 * @return array       Array containing Open Graph or Twitter Card elements.
	 */
	public static function parse_url( $url ) {
		// Download page.
		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'user-agent' => apply_filters( 'ogp_user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:67.0) Gecko/20100101 Firefox/67.0', $url ),
				'timeout'    => 11,
				'cookies'    => apply_filters( 'ogp_cookies', array(), $url ),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$html = wp_remote_retrieve_body( $response );
		}

		if ( empty( $html ) ) {
			return array();
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true ); // Work around DOMDocument's HTML5 limitations.
		$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'auto' ) );

		$nodes = $dom->getElementsByTagName( 'meta' );

		if ( empty( $nodes ) || $nodes->length < 1 ) {
			return array();
		}

		$tags = array();

		// Loop over the page's `meta` tags.
		foreach ( $nodes as $node ) {
			if ( $node->hasAttribute( 'property' ) ) {
				$property = $node->getAttribute( 'property' );
			} elseif ( $node->hasAttribute( 'name' ) ) {
				$property = $node->getAttribute( 'name' );
			}

			if ( empty( $property ) || ! $node->hasAttribute( 'content' ) ) {
				continue;
			}

			if ( strpos( $property, 'og:' ) === 0 ) {
				// Open Graph.
				$property = substr( $property, 3 );
			} elseif ( strpos( $property, 'twitter:' ) === 0 ) {
				// Twitter card.
				$property = substr( $property, 8 );

				if ( 'image:src' === $property ) {
					$property = 'image';
				}
			}

			$tags[ $property ] = esc_attr( sanitize_text_field( $node->getAttribute( 'content' ) ) );
		}

		if ( empty( $tags['title'] ) ) {
			// Use the `title` tag instead.
			$nodes = $dom->getElementsByTagName( 'title' );

			if ( ! empty( $nodes ) && $nodes->length > 0 ) {
				$tags['title'] = esc_attr( sanitize_text_field( $nodes->item( 0 )->textContent ) );
			}
		}

		return $tags;
	}

	/**
	 * Downloads and stores a preview of a page's Open Graph image.
	 *
	 * @param  string  $image_url Image URL.
	 * @param  WP_Post $post      Post object.
	 * @return string             Local image URL, or an empty string.
	 */
	public static function create_thumbnail( $image_url, $post ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return '';
		}

		$response = wp_remote_get(
			esc_url_raw( $image_url ),
			array(
				'user-agent' => apply_filters( 'ogp_user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:67.0) Gecko/20100101 Firefox/67.0', $image_url ),
				'timeout'    => 11,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$image = wp_remote_retrieve_body( $response );

		if ( ! $image ) {
			return '';
		}

		// Set up Imagick.
		$im = new Imagick();
		$im->setBackgroundColor( new ImagickPixel( 'transparent' ) );

		try {
			$im->readImageBlob( $image );
		} catch ( \Exception $e ) {
			// Not an image?
			error_log( $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}

		$format = strtolower( $im->getImageFormat() );

		switch ( $format ) {
			case 'gif':
				$extension = 'gif';
				break;

			case 'jpeg':
				$extension = 'jpg';
				break;

			case 'jpg':
				$extension = 'jpg';
				break;

			case 'png':
				$extension = 'png';
				break;

			default:
				return;
		}

		$wp_upload_dir = wp_get_upload_dir();
		$filename      = trailingslashit( $wp_upload_dir['path'] ) . $post->post_name . '-min.' . $extension;

		if ( is_file( $filename ) ) {
			// File already exists.
			$im->clear();
			$im->destroy();

			// To do: Check this way sooner, rather than after the image is already downloaded.

			// Return thumbnail URL.
			return str_replace( $wp_upload_dir['path'], $wp_upload_dir['url'], $filename );
		}

		$im->cropThumbnailImage( 200, 200 );
		$im->setImagePage( 0, 0, 0, 0 );
		$im->setImageCompressionQuality( 90 );

		$image_buffer = $im->getImageBlob();

		// Initialize WordPress's Filesystem API.
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( defined( 'TINY_API_KEY' ) && class_exists( 'Tinify' ) && in_array( $extension, array( 'png', 'jpg' ), true ) ) {
			try {
				// Use Tinify API to compress image.
				\Tinify\setKey( TINY_API_KEY );
				$image_buffer = \Tinify\fromBuffer( $image_buffer )->toBuffer();
			} catch ( \Exception $e ) {
				error_log( $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// Either way, write image data.
		if ( ! $wp_filesystem->put_contents( $filename, $image_buffer, 0644 ) ) {
			return '';
		}

		$im->clear();
		$im->destroy();

		if ( is_file( $filename ) ) {
			// File got saved OK.
			return str_replace( $wp_upload_dir['path'], $wp_upload_dir['url'], $filename );
		}

		return '';
	}
}
