<?php
/*
Plugin Name: CDN Rewrite
Plugin URI: http://voceconnect.com/
Description: Rewrites asset URLs to CDN
Version: 0.1
Author: Chris Scott, Michael Pretty
Author URI: http://voceconnect.com/
*/

require_once('voce-settings.php');

class CDN_Rewrite {

	const OPTION_GENERAL = 'cdn_general';

	private $submenu_general;
	private $file_extensions;
	private $blog_details;
	private $cdn_root_url;

	public function __construct() {
		$this->file_extensions = $this->get_setting('file_extensions');
		$this->cdn_root_url = untrailingslashit($this->get_setting('root_url'));
	}

	public function initialize() {
		if (!class_exists('Voce_Settings')) {
			return;
		}

		add_action('admin_menu', array($this, 'add_options_page'));
		if ('' == $this->file_extensions || '' == $this->cdn_root_url) {
			add_action('admin_notices', array($this, 'settings_warning'));
			return;
		}

		if('/' != $this->cdn_root_url) {
			add_action('template_redirect', array($this, 'start_buffer'), 1);
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
				'file_extensions' => 'bmp|bz2|css|gif|ico|gz|jpg|jpeg|js|mp3|pdf|png|rar|rtf|swf|tar|tgz|txt|wav|zip'
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
		$this->submenu_general = add_options_page('CDN Rewrite', 'CDN Rewrite', 'manage_options', self::OPTION_GENERAL, array($this, 'submenu_general'));
		$settings = new Voce_Settings(self::OPTION_GENERAL, self::OPTION_GENERAL);

		$section = $settings->add_section('api', 'CDN Rewrite Settings', $this->submenu_general);
		$section->add_field('root_url', 'CDN Root URL (required)', 'field_input', array('description' => 'The base URL of the CDN.'));
		$section->add_field('file_extensions', 'File Extensions (required)', 'field_input');
	}

	/**
	 * callback to display submenu_external
	 *
	 * @return void
	 */
	function submenu_general() {
		?>
		<div class="wrap">
			<h2>CDN Rewrite Settings</h2>
			<form method="post" action="options.php">
				<?php settings_fields(self::OPTION_GENERAL); ?>
				<?php do_settings_sections($this->submenu_general); ?>
				<p class="submit">
					<input name="Submit" type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>" />
				</p>
			</form>
		</div>
		<?php
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
		$regex = '#(?<=[(\"\'])'.quotemeta($root_url).'(?:(/[^\"\')]+\.('.$this->file_extensions.')))#';

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
			$root_url = $root_blog->siteurl;
		} else {
			$root_url = site_url();
		}
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
		global $blog_id;
		$path = $match[1];
		//if is subfolder install and isn't root blog and path starts with site_url and isnt uploads dir
		if(is_multisite() && !is_subdomain_install() && $blog_id !== 1) {
			$bloginfo = $this->get_this_blog_details();
			if((0 === strpos($path, $bloginfo->path)) && (0 !== strpos($path, $bloginfo->path.'files/'))) {
				$path = '/'.substr($path, strlen($bloginfo->path));
			}
		}
		return $this->cdn_root_url . $path;
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
		if(0 === strpos($url, $this->root_url)) {
			$parts = parse_url($url);
			$file_path = str_replace(site_url('/'), ABSPATH, $parts['scheme'].'://'.$parts['host'].$parts['path']);
			if(	!($version = @filemtime($file_path)) ) {
				$version = $this->default_version;
			}
			return $version;
		}
		return false;
	}

	public function replace_version($src) {
		if( $new_version = $this->get_version($src) ) {
			return add_query_arg('ver', $new_version, $src);
		}
		return $src;
	}
}
add_action('init', array(new CDN_VersionAssets(), 'initialize'));