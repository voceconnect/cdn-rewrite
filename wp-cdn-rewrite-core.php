<?php

if(file_exists( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' ) )
    include_once( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' );

class CDN_Rewrite {

	const OPTION_GENERAL = 'cdn_general';

	private $cdn_root_url;
	private $file_extensions;

	private $css_file_extensions;
	private $css_cdn_root_url;

	private $js_file_extensions;
	private $js_cdn_root_url;

	private $blog_details;

	public function __construct() {
		$this->cdn_root_url = $this->css_cdn_root_url = $this->js_cdn_root_url = untrailingslashit($this->get_setting('root_url'));

		if ($css_url = trim($this->get_setting('css_root_url'))) {
			$this->css_cdn_root_url = untrailingslashit($css_url);
		}

		if ($js_url = trim($this->get_setting('js_root_url'))) {
			$this->js_cdn_root_url = untrailingslashit($js_url);
		}

		$this->file_extensions = $this->get_setting('file_extensions');
		$this->css_file_extensions = $this->get_setting('css_file_extensions');
		$this->js_file_extensions = $this->get_setting('js_file_extensions');
	}

	public function initialize() {
		if( !class_exists( 'Voce_Settings_API' ) )
			return _doing_it_wrong( __CLASS__, 'The Voce Settings API plugin must be active for the CDN Rewrite plugin to work', NULL );

		$this->add_options_page();
		if ('' == $this->file_extensions || '' == $this->cdn_root_url) {
			add_action('admin_notices', array($this, 'settings_warning'));
			return;
		}

		if('/' != $this->cdn_root_url) {
			$action = (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ? 'xmlrpc_call' : 'template_redirect';
			add_action($action, array($this, 'start_buffer'), 1);
		}
	}

	/**
	 * get general setting
	 *
	 * @param string $setting setting name
	 * @return mixed setting value or false if not set
	 */
	private function get_setting($setting) {
		$settings = get_option(self::OPTION_GENERAL);
		if(!$settings || !is_array($settings)) {
			$settings = array(
				'file_extensions' => 'bmp|bz2|gif|ico|gz|jpg|jpeg|mp3|pdf|png|rar|rtf|swf|tar|tgz|txt|wav|zip',
				'css_file_extensions' => 'css',
				'js_file_extensions' => 'js'
			);
			update_option(self::OPTION_GENERAL, $settings);
		}
		return (isset($settings[$setting])) ? $settings[$setting] : false;
	}

	public function settings_warning() {
		echo "<div class='update-nag'>The CDN Rewrite plugin is missing some required settings.</div>";
	}

	/**
	 * adds the options page
	 *
	 * @return void
	 */
	public function add_options_page() {
		Voce_Settings_API::GetInstance()->add_page('CDN Rewrite', 'CDN Rewrite', self::OPTION_GENERAL, 'manage_options', '', 'options-general.php' )
			->add_group( 'CDN Rewrite Settings', 'cdn_general' )
				->add_setting( 'CDN Root URL (required)', 'root_url', array( 'description' => 'The base URL of the CDN.' ) )->group
				->add_setting( 'File Extensions (required)', 'file_extensions' )->group
				->add_setting( 'CDN Root URL for CSS Files (optional)', 'css_root_url', array( 'description' => 'The base URL of the CDN for CSS Files.' ) )->group
				->add_setting( 'File Extensions for CSS Files (optional)', 'css_file_extensions' )->group
				->add_setting( 'CDN Root URL for JS Files (optional, defaults to Root URL)', 'js_root_url', array( 'description' => 'The base URL of the CDN for JS Files.' ) )->group
				->add_setting( 'File Extensions for JS Files (optional, defaults to Root URL)', 'js_file_extensions' );
	}

	/**
	 * start output buffering.
	 *
	 */
	public function start_buffer() {
		ob_start(array($this, 'filter_urls'));
	}

	/**
	 * Callback for output buffering.  Search content for urls to replace
	 *
	 * @param string $content
	 * @return string
	 */
	public function filter_urls($content) {

		$root_url = $this->get_site_root_url();
		$xml_begin = $xml_end = '';
		if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
			$xml_begin = '>';
			$xml_end = '<';
		}
		$extensions = join('|', array_filter(array($this->file_extensions, $this->css_file_extensions, $this->js_file_extensions)));

		// replace srcset values
		$srcset_regex = '#<img[^\>]*[^\>\S]+srcset=[\'"]('.quotemeta($root_url).'(?:([^"\'\s,]+)('.$this->file_extensions.')\s*(?:\s+\d+[wx])(?:,\s*)?)+)["\'][^>]*?>#';
		$content = preg_replace_callback( $srcset_regex, array($this, 'srcset_rewrite'), $content);

		// replace the remaining urls
		$regex = '#(?<=[(\"\''.$xml_begin.'])'.quotemeta($root_url).'(?:(/[^\"\''.$xml_end.')]+\.('.$extensions.')))#';
		$content = preg_replace_callback($regex, array($this, 'url_rewrite'), $content);

		return $content;
	}

	/**
	 * Returns the root url of the current site
	 *
	 * @return string
	 */
	public function get_site_root_url() {
		if(is_multisite() && !is_subdomain_install()) {
			$root_blog = get_blog_details(1);
			$root_site_url = $root_blog->siteurl;
		} else {
			$root_site_url = site_url();
		}
		$parsed_root_site_url = parse_url($root_site_url);
		if (array_key_exists('scheme', $parsed_root_site_url)) {
			$root_url_scheme = $parsed_root_site_url['scheme'] . '://';
		} else {
			$root_url_scheme = '//';
		}
		$root_url = $root_url_scheme . $parsed_root_site_url['host'];
		return $root_url;
	}

	/**
	 * Returns the details for the current blog
	 *
	 * @return object
	 */
	public function get_this_blog_details() {
		if(!isset($this->blog_details)) {
			global $blog_id;
			$this->blog_details = get_blog_details($blog_id);
		}
		return $this->blog_details;
	}

	/**
	 * Callback for url preg_replace_callback.  Returns corrected URL
	 *
	 * @param array $match
	 * @return string
	 */
	public function url_rewrite($match) {
		$path = $this->get_rewrite_path( $match[1] );
		if('/' !== $this->css_cdn_root_url && preg_match("/^.*\.(".$this->css_file_extensions.")$/i", $path) ) {
			return $this->css_cdn_root_url . $path;
		}
		if('/' !== $this->js_cdn_root_url && preg_match("/^.*\.(".$this->js_file_extensions.")$/i", $path) ) {
			return $this->js_cdn_root_url . $path;
		}
		return $this->cdn_root_url . $path;
	}

	/**
	 * Callback for srcset preg_replace_callback, Returns image tag with updated srcset value
	 * @param type $match
	 * @return type
	 */
	public function srcset_rewrite( $match ) {
		$root_url = $this->get_site_root_url();

		$image_tag           = empty( $match[0] ) ? false : $match[0];
		$srcset_field        = empty( $match[1] ) ? false : $match[1];
		if ( empty( $srcset_field ) ) {
			return $image_tag;
		}

		$srcset_images       = array();
		$srcset_images_count = preg_match_all( '#'.quotemeta( $root_url ).'(?:([^"\'\s,]+)\s*(?:\s+\d+[wx])(?:,\s*)?)#', $image_tag, $srcset_images );
		$srcset_images_sizes = empty( $srcset_images[0] ) ? false : $srcset_images[0];
		$srcset_images_paths = empty( $srcset_images[1] ) ? false : $srcset_images[1];

		if ( empty( $srcset_images_paths ) ) {
			return $image_tag;
		}

		foreach( $srcset_images_paths as $key => $original_path ) {
			$path     = $this->get_rewrite_path( $original_path );
			$cdn_path = $this->cdn_root_url . $path;
			$srcset_images[0][ $key ] = str_replace( $root_url . $original_path, $cdn_path, $srcset_images[0][ $key ] );
		}

		$image_tag = str_replace( $srcset_field, implode( ' ', $srcset_images[0] ), $image_tag );

		return $image_tag;
	}

	/**
	 * Helper function to get the path depending on the site structure
	 * @global type $blog_id
	 * @param string $path
	 * @return string
	 */
	private function get_rewrite_path( $path ) {
		global $blog_id;
		//if is subfolder install and isn't root blog and path starts with site_url and isnt uploads dir
		if(is_multisite() && !is_subdomain_install() && $blog_id !== 1) {
			$bloginfo = $this->get_this_blog_details();
			if((0 === strpos($path, $bloginfo->path)) && (0 !== strpos($path, $bloginfo->path.'files/'))) {
				$path = '/'.substr($path, strlen($bloginfo->path));
			}
		}
		return $path;
	}

}
add_action('init', array(new CDN_Rewrite(), 'initialize'));

class CDN_VersionAssets {

	private $default_version = '';
	private $root_url;

	public function __construct() {
		$this->root_url = site_url();
	}

	public function initialize() {
		add_filter('style_loader_src', array($this, 'replace_version'), 10);
		add_filter('script_loader_src', array($this, 'replace_version'), 10);
		add_filter('stylesheet_uri', array($this, 'replace_version'), 10);
	}

	public function on_template_redirect() {
		$this->default_version = @filemtime(get_stylesheet_directory().'/style.css');
	}

	private function get_version($url) {
		$version = false;

		if(0 === strpos($url, $this->root_url)) {
			$parts = parse_url($url);
			foreach( array( 'scheme', 'host', 'path' ) as $part ){
				if( !isset( $parts[$part] ) )
					return false;
			}

			$file_path = str_replace( site_url('/'), ABSPATH, $parts['scheme'] . '://' . $parts['host'] . $parts['path'] );

			if( file_exists( $file_path ) && !($version = @filemtime($file_path)) ) {
				$version = $this->default_version;
			}
		}
		return $version;
	}

	public function replace_version($src) {
		if( $new_version = $this->get_version($src) ) {
			return add_query_arg('ver', $new_version, $src);
		}
		return $src;
	}
}
add_action('init', array(new CDN_VersionAssets(), 'initialize'));