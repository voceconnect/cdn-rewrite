<?php
/*
Plugin Name: Cloudfiles CDN
Plugin URI: http://voceconnect.com/
Description: Adds/Deletes uploaded images on CDN and rewrites asset URLs to CDN
Version: 0.1
Author: Chris Scott, Michael Pretty
Author URI: http://voceconnect.com/
*/

require_once('voce-settings.php');

class CloudfilesCdn {

	var $submenu_general;

	//private static $option_group = 'cloudfiles_cdn';
	const OPTION_GENERAL = 'cloudfiles_cdn_general';

	/**
	 * get general setting
	 *
	 * @param string $setting setting name
	 * @return mixed setting value or false if not set
	 */
	public static function get_setting($setting) {
		$settings = get_option(self::OPTION_GENERAL);
		if(!$settings || !is_array($settings)) {
			$settings = array(
				'file_extensions' => 'bmp|bz2|css|gif|ico|gz|jpg|jpeg|js|mp3|pdf|png|rar|rtf|swf|tar|tgz|txt|wav|zip'
			);
		}
		return (isset($settings[$setting])) ? $settings[$setting] : false;
	}

	public function __construct() {}

	public function initialize() {
		// relies on Voce_Settings
		if (!class_exists('Voce_Settings')) {
			return;
		}

		add_action('admin_menu', array($this, 'add_options_page'));

		if (!self::get_setting('username') || !self::get_setting('api_key') || !self::get_setting('container') || !self::get_setting('root_url') || !self::get_setting('file_extensions')) {
			add_action('admin_notices', array($this, 'settings_warning'));
			return;
		}

		add_filter('wp_handle_upload', array($this, 'catch_wp_handle_upload'));
		add_filter('wp_delete_file', array($this, 'catch_wp_delete_file'));
		add_filter('wp_generate_attachment_metadata', array($this, 'catch_wp_generate_attachment_metadata'));
		add_filter('bp_core_avatar_cropstore', array($this, 'catch_bp_core_avatar_cropstore'));
		add_action('bp_core_avatar_save', array($this, 'catch_bp_core_avatar_save'), 10, 2);

	}

	public function settings_warning() {
		echo "<div class='update-nag'>The Cloudfiles CDN plugin is missing some required settings.</div>";
	}

	/**
	 * adds the options page
	 *
	 * @return void
	 */
	public function add_options_page() {
		$this->submenu_general = add_options_page('Cloudfiles CDN', 'Cloudfiles CDN', 'manage_options', self::OPTION_GENERAL, array($this, 'submenu_general'));
		$settings = new Voce_Settings(self::OPTION_GENERAL, self::OPTION_GENERAL);

		$section = $settings->add_section('api', 'Cloudfiles API Settings', $this->submenu_general);
		$section->add_field('username', 'Username (required)', 'field_input');
		$section->add_field('api_key', 'API Key (required)', 'field_input');
		$section->add_field('container', 'Container Name (required)', 'field_input', array('description' => 'The container to store files in.'));
		$section->add_field('root_url', 'Root URL (required)', 'field_input', array('description' => 'The root URL to the container without a trailing slash.'));
		$section->add_field('file_extensions', 'File Extensions (required)', 'field_input');
		$section->add_field('enable_debug', 'Enable Debugging?', 'field_checkbox', array('description' => 'Enable error_log() to log upload/delete actions.'));

	}

	/**
	 * callback to display submenu_external
	 *
	 * @return void
	 */
	function submenu_general() {
		?>
		<div class="wrap">
			<h2>Cloudfiles CDN Settings</h2>
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
	 * delete old BP avatars on save
	 *
	 * @param string $user_id not used
	 * @param string $old the path to the file being deleted
	 * @return void
	 */
	public function catch_bp_core_avatar_save($user_id, $old) {
		$files = array();
		// this will be -avatar2
		$files[] = str_replace(ABSPATH, '', $old);
		// get -avatar1 also
		$files[] = str_replace('-avatar2', '-avatar1', $files[0]);

		foreach ($files as $file) {
			if (self::get_setting('enable_debug'))
				error_log("DELETING OLD BP AVATAR: $file");

			$this->delete_file($file);
		}
	}

	/**
	 * when BP avatars are cropped, catch the cropped sizes and upload
	 *
	 * @param string $files
	 * @return array the original file array
	 */
	public function catch_bp_core_avatar_cropstore($files) {
		foreach ((array) $files as $file) {
			$relative_file_path = str_replace(ABSPATH, '', $file);
			$file_type = wp_check_filetype($file);

			if (self::get_setting('enable_debug'))
				error_log("UPLOADING BP AVATAR: $relative_file_path");
			$this->upload_file($file, $file_type['type'], $relative_file_path);
		}

		return $files;
	}

	/**
	 * go grab the already generated intermediate sizes and upload
	 *
	 * @param string $metadata
	 * @return array updated metadata
	 */
	public function catch_wp_generate_attachment_metadata($metadata) {
		//error_log("WP_GENERATE_ATTACHMENT_METADATA: " . var_export($metadata, true));
		$upload_dir = wp_upload_dir();
		$upload_path = trailingslashit($upload_dir['path']);
		$sizes = $metadata['sizes'];

		foreach ($sizes as $size => $size_data ) {
			$file = $size_data['file'];
			$relative_file_path = self::get_blog_path() . 'files' . trailingslashit($upload_dir['subdir']) . $file;
			$file_type = wp_check_filetype($file);
			if (self::get_setting('enable_debug'))
				error_log("UPLOADING INTERMEDIATE SIZE: $relative_file_path");
			$this->upload_file($upload_path . $file, $file_type['type'], $relative_file_path);
		}

		return $metadata;
	}

	/**
	 * get a local filename from a CDN URL
	 *
	 * @param string $url
	 * @return string filename
	 */
	private function get_local_filename($url) {
		return ABSPATH . str_replace(trailingslashit(self::get_setting('root_url')), '', $url);
	}

	/**
	 * Filter to handle wp_handle_upload for uploaded files. Logs any errors.
	 *
	 * @param string $upload
	 * @return void
	 */
	public function catch_wp_handle_upload($upload) {
		// check for buddypress avatar upload and don't upload since it resizes and deletes this one
		if (function_exists('bp_core_setup_globals') && strpos($upload['file'], '/avatars/') !== false) {
			return $upload;
		}

		$blog_path = $this->get_blog_path();
		$relative_url = $blog_path . $this->remove_site_url($upload['url']);
		if (self::get_setting('enable_debug'))
			error_log("UPLOADING: $relative_url");

		// upload file
		if (!$this->upload_file($upload['file'], $upload['type'], $relative_url)) {
			error_log("[CloudfilesCdn] Error uploading file: $relative_url");
			return $upload;
		}

		return $upload;
	}

	/**
	 * Filter to handle wp_delete_file for deleted files. Deletes the file
	 * from the CDN.
	 *
	 * @param string $file
	 * @return string the original file path
	 */
	public function catch_wp_delete_file($file) {
		//error_log("WP_DELETE_FILE: " . var_export($file, true));
		// handle 'wp-content/blogs.dir/1/files/2010/07/653106995_338e53fb1412.jpg' files
		if (strpos($file, 'blogs.dir')) {
			$parts = explode('files/', $file);
			$file = $parts[1];
		}
		$upload_dir = wp_upload_dir();
		$relative_path = $this->get_blog_path() . 'files/' . $file;

		// delete the file from the CDN if it is on there
		if (self::get_setting('enable_debug'))
			error_log("DELETING FILE: $relative_path");
		$this->delete_file(str_replace(ABSPATH, '', $relative_path));

		return $file;
	}

	/**
	 * get the blog's path without the leading slash
	 *
	 * @return string
	 */
	private function get_blog_path() {
		global $blog_id;
		$blog_path = '';
		if ((int) $blog_id !== 1) {
			$blog_details = get_blog_details($blog_id);
			$blog_path = $blog_details->path;
		}

		return ltrim($blog_path, '/');
	}

	/**
	 * Get the relative filename. The filename from the wp_delete_file filter
	 * will either have the file path to the WP_UPLOAD_DIR prepended to the cdn'd file
	 * url or it will be the path relative to the WP_UPLOAD_DIR
	 *
	 * @param string $file
	 * @return void
	 */
	private function get_relative_file($file) {
		$cont_url = self::get_setting('root_url');
		if (strpos($file, $cont_url) !== false) {
			if ($file_parts = explode(trailingslashit(self::get_setting('root_url')), $file)) {
				// prepended w/bad upload path (e.g. http://c0002127.cdn1.cloudfiles.rackspacecloud.com/wp-content/uploads/2010/07/http://c0002127.cdn1.cloudfiles.rackspacecloud.com/wp-content/uploads/2010/07/653106995_338e53fb1416-150x150.jpg)
				if (isset($file_parts[2])) {
					return $file_parts[2];
				} elseif (isset($file_parts[1])) {
					// just regular CDN url
					return $file_parts[1];
				}
			}
		}

		return false;
	}

	/**
	 * delete a file from the CDN
	 *
	 * @param string $file relative filename
	 * @return bool true on success, false on failure
	 */
	private function delete_file($file) {
		require_once(trailingslashit(dirname(__FILE__)) . 'cloudfiles/cloudfiles.php');
		$auth = new CF_Authentication(self::get_setting('username'), self::get_setting('api_key'));

		try {
			$auth->authenticate();
		} catch (Exception $e) {
			error_log(sprintf("[CloudfilesCdn] Error authenticating to Cloudfiles: %s", $e->getMessage()));
			return false;
		}

		$conn = new CF_Connection($auth);
		$container = $conn->get_container(self::get_setting('container'));

		try {
			$obj = $container->get_object($file);
			$container->delete_object($obj);
		} catch (Exception $e) {
			error_log(sprintf("[CloudfilesCdn] Error deleting file '%s' from Cloudfiles: %s", $file, $e->getMessage()));
		}

		return true;
	}



	/**
	 * Update the attachement URL if the attached file is on the CDN
	 *
	 * @param string $url
	 * @param string $post_id
	 * @return string original or updated URL
	 */
	public function cdn_attachment_url($url, $post_id) {
		if ($file = get_post_meta($post_id, '_wp_attached_file', true)) {
			if (strpos($file, self::get_setting('root_url')) !== false) {
				return $file;
			}
		}

		return $url;
	}

	/**
	 * Upload a file to cloudfiles
	 *
	 * @param string $file the file's path
	 * @param string $file_type the file's mime type
	 * @param string $file_url the file's site-relative URL
	 * @return bool true on succes, false on fail
	 */
	private function upload_file($file, $file_type, $file_url) {
		require_once(trailingslashit(dirname(__FILE__)) . 'cloudfiles/cloudfiles.php');
		$auth = new CF_Authentication(self::get_setting('username'), self::get_setting('api_key'));

		try {
			$auth->authenticate();
		} catch (Exception $e) {
			error_log(sprintf("[CloudfilesCdn] Error authenticating to Cloudfiles: %s", $e->getMessage()));
			return false;
		}

		$conn = new CF_Connection($auth);
		$container = $conn->get_container(self::get_setting('container'));

		$obj = $container->create_object($file_url);
		$obj->content_type = $file_type;

		try {
			$obj->load_from_filename($file);
		} catch (Exception $e) {
			error_log(sprintf("[CloudfilesCdn] Error uploading '%s' to Cloudfiles: %s", $file, $e->getMessage()));
			return false;
		}

		if (self::get_setting('enable_debug'))
			error_log("UPLOADED: $file, $file_type, $file_url");

		return true;
	}

	/**
	 * Get a site-relative url without a leading / from an absolute URL containing the siteurl
	 *
	 * @param string $absolute_url
	 * @return string relative url
	 */
	private function remove_site_url($absolute_url) {
		return str_replace(trailingslashit(get_option('siteurl')), '', $absolute_url);
	}

	private function remove_cdn_url($url) {
		return str_replace(trailingslashit(self::get_setting('root_url')), '', $url);
	}

}
add_action('init', array(new CloudfilesCdn(), 'initialize'));

class CDN_Rewrite {

	private $file_extensions;
	private $blog_details;
	private $cdn_root_url;

	public function __construct() {
		$this->file_extensions = CloudfilesCdn::get_setting('file_extensions');
		$this->cdn_root_url = untrailingslashit(CloudfilesCdn::get_setting('root_url'));
	}

	/**
	 * Initializes class and registers hooks
	 *
	 */
	public function initialize() {
		if('/' != $this->cdn_root_url) {
			add_action('template_redirect', array($this, 'start_buffer'), 1);
		}
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
		add_filter('style_loader_src', array($this, 'replace_version'), 10);
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