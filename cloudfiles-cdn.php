<?php
/*
Plugin Name: Cloudfiles CDN
Plugin URI: http://voceconnect.com/
Description: Adds/Deletes uploaded images on CDN and rewrites asset URLs to CDN
Version: 0.1
Author: Chris Scott, Michael Pretty
Author URI: http://voceconnect.com/
*/

class CloudfilesCdn {

	function __construct() {
		if (!defined('CF_USERNAME') || !defined('CF_API_KEY') || !defined('CF_CONTAINER') || !defined('CF_CONTAINER_URL')) {
			// these should be defined in wp-config. if not, don't do anything here.
			return;
		}

		add_filter('wp_handle_upload', array('CloudfilesCdn', 'catch_wp_handle_upload'));
		add_filter('wp_delete_file', array('CloudfilesCdn', 'catch_wp_delete_file'));
		add_filter('wp_generate_attachment_metadata', array('CloudfilesCdn', 'catch_wp_generate_attachment_metadata'));
		add_filter('bp_core_avatar_cropstore', array('CloudfilesCdn', 'catch_bp_core_avatar_cropstore'));
		add_action('bp_core_avatar_save', array('CloudfilesCdn', 'catch_bp_core_avatar_save'), 10, 2);
	}

	/**
	 * delete old BP avatars on save
	 *
	 * @param string $user_id not used
	 * @param string $old the path to the file being deleted
	 * @return void
	 */
	public static function catch_bp_core_avatar_save($user_id, $old) {
		$files = array();
		// this will be -avatar2
		$files[] = str_replace(ABSPATH, '', $old);
		// get -avatar1 also
		$files[] = str_replace('-avatar2', '-avatar1', $files[0]);

		foreach ($files as $file) {
			if (defined('CF_DEBUG_ENABLED') && CF_DEBUG_ENABLED)
				error_log("DELETING OLD BP AVATAR: $file");

			self::delete_file($file);
		}
	}

	/**
	 * when BP avatars are cropped, catch the cropped sizes and upload
	 *
	 * @param string $files
	 * @return array the original file array
	 */
	public static function catch_bp_core_avatar_cropstore($files) {
		foreach ((array) $files as $file) {
			$relative_file_path = str_replace(ABSPATH, '', $file);
			$file_type = wp_check_filetype($file);

			if (defined('CF_DEBUG_ENABLED') && CF_DEBUG_ENABLED)
				error_log("UPLOADING BP AVATAR: $relative_file_path");
			self::upload_file($file, $file_type['type'], $relative_file_path);
		}

		return $files;
	}

	/**
	 * go grab the already generated intermediate sizes and upload
	 *
	 * @param string $metadata
	 * @return array updated metadata
	 */
	public static function catch_wp_generate_attachment_metadata($metadata) {
		//error_log("WP_GENERATE_ATTACHMENT_METADATA: " . var_export($metadata, true));
		$upload_dir = wp_upload_dir();
		$upload_path = trailingslashit($upload_dir['path']);
		$sizes = $metadata['sizes'];

		foreach ($sizes as $size => $size_data ) {
			$file = $size_data['file'];
			$relative_file_path = self::get_blog_path() . 'files' . trailingslashit($upload_dir['subdir']) . $file;
			$file_type = wp_check_filetype($file);
			if (defined('CF_DEBUG_ENABLED') && CF_DEBUG_ENABLED)
				error_log("UPLOADING INTERMEDIATE SIZE: $relative_file_path");
			self::upload_file($upload_path . $file, $file_type['type'], $relative_file_path);
		}

		return $metadata;
	}

	/**
	 * get a local filename from a CDN URL
	 *
	 * @param string $url
	 * @return string filename
	 */
	private static function get_local_filename($url) {
		return ABSPATH . str_replace(trailingslashit(CF_CONTAINER_URL), '', $url);
	}

	/**
	 * Filter to handle wp_handle_upload for uploaded files. Logs any errors.
	 *
	 * @param string $upload
	 * @return void
	 */
	public static function catch_wp_handle_upload($upload) {
		// check for buddypress avatar upload and don't upload since it resizes and deletes this one
		if (function_exists('bp_core_setup_globals') && strpos($upload['file'], '/avatars/') !== false) {
			return $upload;
		}

		$blog_path = self::get_blog_path();
		$relative_url = $blog_path . self::remove_site_url($upload['url']);
		if (defined('CF_DEBUG_ENABLED') && CF_DEBUG_ENABLED)
			error_log("UPLOADING: $relative_url");

		// upload file
		if (!self::upload_file($upload['file'], $upload['type'], $relative_url)) {
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
	public static function catch_wp_delete_file($file) {
		//error_log("WP_DELETE_FILE: " . var_export($file, true));
		// handle 'wp-content/blogs.dir/1/files/2010/07/653106995_338e53fb1412.jpg' files
		if (strpos($file, 'blogs.dir')) {
			$parts = explode('files/', $file);
			$file = $parts[1];
		}
		$upload_dir = wp_upload_dir();
		$relative_path = self::get_blog_path() . 'files/' . $file;

		// delete the file from the CDN if it is on there
		if (defined('CF_DEBUG_ENABLED') && CF_DEBUG_ENABLED)
			error_log("DELETING FILE: $relative_path");
		self::delete_file(str_replace(ABSPATH, '', $relative_path));

		return $file;
	}

	/**
	 * get the blog's path without the leading slash
	 *
	 * @return string
	 */
	private static function get_blog_path() {
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
	private static function get_relative_file($file) {
		$cont_url = CF_CONTAINER_URL;
		if (strpos($file, $cont_url) !== false) {
			if ($file_parts = explode(trailingslashit(CF_CONTAINER_URL), $file)) {
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
	private static function delete_file($file) {
		require_once(trailingslashit(dirname(__FILE__)) . 'cloudfiles/cloudfiles.php');
		$auth = new CF_Authentication(CF_USERNAME, CF_API_KEY);

		try {
			$auth->authenticate();
		} catch (Exception $e) {
			error_log(sprintf("[CloudfilesCdn] Error authenticating to Cloudfiles: %s", $e->getMessage()));
			return false;
		}

		$conn = new CF_Connection($auth);
		$container = $conn->get_container(CF_CONTAINER);

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
	public static function cdn_attachment_url($url, $post_id) {
		if ($file = get_post_meta($post_id, '_wp_attached_file', true)) {
			if (strpos($file, CF_CONTAINER_URL) !== false) {
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
	private static function upload_file($file, $file_type, $file_url) {
		require_once(trailingslashit(dirname(__FILE__)) . 'cloudfiles/cloudfiles.php');
		$auth = new CF_Authentication(CF_USERNAME, CF_API_KEY);

		try {
			$auth->authenticate();
		} catch (Exception $e) {
			error_log(sprintf("[CloudfilesCdn] Error authenticating to Cloudfiles: %s", $e->getMessage()));
			return false;
		}

		$conn = new CF_Connection($auth);
		$container = $conn->get_container(CF_CONTAINER);

		$obj = $container->create_object($file_url);
		$obj->content_type = $file_type;

		try {
			$obj->load_from_filename($file);
		} catch (Exception $e) {
			error_log(sprintf("[CloudfilesCdn] Error uploading '%s' to Cloudfiles: %s", $file, $e->getMessage()));
			return false;
		}

		if (defined('CF_DEBUG_ENABLED') && CF_DEBUG_ENABLED)
			error_log("UPLOADED: $file, $file_type, $file_url");

		return true;
	}

	/**
	 * Get a site-relative url without a leading / from an absolute URL containing the siteurl
	 *
	 * @param string $absolute_url
	 * @return string relative url
	 */
	private static function remove_site_url($absolute_url) {
		return str_replace(trailingslashit(get_option('siteurl')), '', $absolute_url);
	}

	private static function remove_cdn_url($url) {
		return str_replace(trailingslashit(CF_CONTAINER_URL), '', $url);
	}

}

$cdn = new CloudfilesCdn();