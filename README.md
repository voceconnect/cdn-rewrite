WP CDN Rewrite  
===========  
Contributors: voceplatforms, chrisscott, prettyboymp, kevinlangleyjr  
Tags: cdn, rewrite  
Requires at least: 3.3  
Tested up to: 3.8  
Stable tag: 0.1.1  
License: GPLv2 or later  
License URI: http://www.gnu.org/licenses/gpl-2.0.html

## Description  
This plugin allows you to rewrite the root url of assets, css, and js files. This allows you to load these resources from an external URL improving the page load time by taking advantage of parallel browser requests.

This plugin requires the use of the [Voce Settings API](https://github.com/voceconnect/voce-settings-api) library.

If using [Composer](http://getcomposer.org) for dependency management, you can alternatively after including the files, run composer install to retreive the Voce Settings API dependency.

## Installation  

### As standard plugin:  
> See [Installing Plugins](http://codex.wordpress.org/Managing_Plugins#Installing_Plugins).

## Changelog  

** 0.1.1 **  
*Fixing issue with URLs without a path part.*

** 0.1.0 **  
*Initial version.*