=== CDN Rewrite ===
Contributors: voceplatforms, chrisscott, prettyboymp
Tags: cdn, rewrite
Requires at least: 3.3
Tested up to: 3.8
Stable tag: 0.1.0
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