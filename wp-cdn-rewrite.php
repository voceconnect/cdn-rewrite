<?php
/*
Plugin Name: WP CDN Rewrite
Plugin URI: http://voceconnect.com/
Description: Rewrites asset URLs to CDN
Version: 0.4.0
Author: Chris Scott, Michael Pretty, Kevin Langley, Sean McCafferty
Author URI: http://voceconnect.com/
*/

if ( ! class_exists( 'CDN_Rewrite' ) ) {
	require_once( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'wp-cdn-rewrite-core.php' );
}