<?php
/**
 * tiny_mce_gzip.php
 *
 * Copyright 2009, Moxiecode Systems AB
 * Released under LGPL License.
 *
 * License: http://tinymce.moxiecode.com/license
 * Contributing: http://tinymce.moxiecode.com/contributing
 *
 * This file compresses the TinyMCE JavaScript using GZip and
 * enables the browser to do two requests instead of one for each .js file.
 *
 * It's a good idea to use the diskcache option since it reduces the servers workload.
 */

	// Set the error reporting to minimal.
	@error_reporting(E_ERROR | E_WARNING | E_PARSE);

	// Get input
	$plugins = explode(',', getParam("plugins", ""));
	$languages = explode(',', getParam("languages", ""));
	$themes = explode(',', getParam("themes", ""));
	$diskCache = getParam("diskcache", "") == "true";
	$isJS = getParam("js", "") == "true";
	$compress = getParam("compress", "true") == "true";
	$core = getParam("core", "true") == "true";
	$suffix = getParam("suffix", "_src") == "_src" ? "_src" : "";
	$cachePath = realpath("."); // Cache path, this is where the .gz files will be stored
	$expiresOffset = 3600 * 24 * 10; // Cache for 10 days in browser cache
	$content = "";
	$encodings = array();
	$supportsGzip = false;
	$enc = "";
	$cacheKey = "";

	// Custom extra javascripts to pack
	$custom = array(/*
		"some custom .js file",
		"some custom .js file"
	*/);

	// Headers
	header("Content-type: text/javascript");
	header("Vary: Accept-Encoding");  // Handle proxies
	header("Expires: " . gmdate("D, d M Y H:i:s", time() + $expiresOffset) . " GMT");

	// Is called directly then auto init with default settings
	if (!$isJS) {
		echo getFileContents("tiny_mce_gzip.js");
		echo "tinyMCE_GZ.init({});";
		die();
	}

	// Setup cache info
	if ($diskCache) {
		if (!$cachePath || !is_readable($cachePath))
			die("alert('Compressor Real path failed. (tiny_mce_gzip.php) $cachePath');");

		$cacheKey = getParam("plugins", "") . getParam("languages", "") . getParam("themes", "") . $suffix;

		foreach ($custom as $file)
			$cacheKey .= $file;

		// Add modification date of core JS file, so that the disk cache expires on
		// TinyMCE upgrade/changes:
		$last_mod_time = @filemtime('tiny_mce'.$suffix.'.js');
		$cacheKey .= $last_mod_time;
		$cacheKey = md5($cacheKey);

		$cacheFile = $cachePath.'/tiny_mce_'.$cacheKey;
		if ($compress)
			$cacheFile .= '.gz';
		else
			$cacheFile .= '.js';

		// Remove old cache files on every ~1000th request:
		if( rand(0,10000) < 10 )
		{
			foreach( glob($cachePath.'/tiny_mce_*.{gz,js}', GLOB_NOSORT|GLOB_BRACE) as $old_cache_file )
			{
				if( filectime($old_cache_file) < $last_mod_time )
				{
					@unlink($old_cache_file);
				}
			}
		}
	}

	// Check if it supports gzip
	if (isset($_SERVER['HTTP_ACCEPT_ENCODING']))
		$encodings = explode(',', strtolower(preg_replace("/\s+/", "", $_SERVER['HTTP_ACCEPT_ENCODING'])));

	if ((in_array('gzip', $encodings) || in_array('x-gzip', $encodings) || isset($_SERVER['---------------'])) && function_exists('ob_gzhandler') && !ini_get('zlib.output_compression')) {
		$enc = in_array('x-gzip', $encodings) ? "x-gzip" : "gzip";
		$supportsGzip = true;
	}

	// Use cached file disk cache
	if ($diskCache && $supportsGzip && file_exists($cacheFile)) {
		if ($compress)
			header("Content-Encoding: " . $enc);

		echo getFileContents($cacheFile);
		die();
	}

	// Add core
	if ($core == "true") {
		$content .= getFileContents("tiny_mce" . $suffix . ".js");

		// Patch loading functions
		$content .= "tinyMCE_GZ.start();";
	}

	// Add core languages
	foreach ($languages as $lang)
		$content .= getFileContents("langs/" . $lang . ".js");

	// Add themes
	foreach ($themes as $theme) {
		$content .= getFileContents( "themes/" . $theme . "/editor_template" . $suffix . ".js");

		foreach ($languages as $lang)
			$content .= getFileContents("themes/" . $theme . "/langs/" . $lang . ".js");
	}

	// Add plugins
	foreach ($plugins as $plugin) {
		$content .= getFileContents("plugins/" . $plugin . "/editor_plugin" . $suffix . ".js");

		foreach ($languages as $lang)
			$content .= getFileContents("plugins/" . $plugin . "/langs/" . $lang . ".js");
	}

	// Add custom files
	foreach ($custom as $file)
		$content .= getFileContents($file);

	// Restore loading functions
	if ($core == "true")
		$content .= "tinyMCE_GZ.end();";

	// Generate GZIP'd content
	if ($supportsGzip) {
		if ($compress) {
			header("Content-Encoding: " . $enc);
			$cacheData = gzencode($content, 9, FORCE_GZIP);
		} else
			$cacheData = $content;

		// Write gz file
		if ($diskCache && $cacheKey != "")
			putFileContents($cacheFile, $cacheData);

		// Stream to client
		echo $cacheData;
	} else {
		// Stream uncompressed content
		echo $content;
	}

	/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

	function getParam($name, $def = false) {
		if (!isset($_GET[$name]))
			return $def;

		return preg_replace("/[^0-9a-z\-_,]+/i", "", $_GET[$name]); // Remove anything but 0-9,a-z,-_
	}

	function getFileContents($path) {
		$path = realpath($path);

		if (!$path || !@is_file($path))
			return "";

		if (function_exists("file_get_contents"))
			return @file_get_contents($path);

		$content = "";
		$fp = @fopen($path, "r");
		if (!$fp)
			return "";

		while (!feof($fp))
			$content .= fgets($fp);

		fclose($fp);

		return $content;
	}

	function putFileContents($path, $content) {
		if (function_exists("file_put_contents"))
			return @file_put_contents($path, $content);

		$fp = @fopen($path, "wb");
		if ($fp) {
			fwrite($fp, $content);
			fclose($fp);
		}
	}
?>