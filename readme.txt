=== WP CDN Rewrite ===
Contributors: voceplatforms, chrisscott, prettyboymp, kevinlangleyjr
Tags: cdn, rewrite
Requires at least: 3.3
Tested up to: 4.5
Stable tag: 0.4.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Rewrite the root url of assets, css, and js files.

== Description ==

This plugin allows you to rewrite the root url of assets, css, and js files. This allows you to load these resources from an external URL improving the page load time by taking advantage of parallel browser requests.

This plugin requires the use of the [Voce Settings API](https://github.com/voceconnect/voce-settings-api) library.

If using [Composer](http://getcomposer.org) for dependency management, you can alternatively, after including the plugin files, run composer install to retreive the Voce Settings API dependency.

== Installation ==

1. Upload `cdn-rewrite` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Once the plugin has been activated, navigate to the CDN Rewrite settings page and set the appropriate settings.

== Changelog ==

= 0.4.0 =
* Fixing bug with relative protocol and explicitly checking scheme

= 0.3.0 =
* Abstracting logic from wp-cdn-rewrite.php in order for the `class_exists` check to work as intended when APC cache is enabled

= 0.2.1 =
* Updating version numbers and change logs

= 0.2.0 =
* Testing WordPress 4.4
* Adding srcset support

= 0.1.5 =
* Testing with WordPress 4.1

= 0.1.4 =
* Fixing error when file does not exist and fixing _doing_it_wrong call

= 0.1.3 =
* Adding Grunt build files

= 0.1.2 =
* Adding Capistrano deploy files

= 0.1.1 =
* Fixing issue with URLs without a path part.

= 0.1.0 =
* Initial version.