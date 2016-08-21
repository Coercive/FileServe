<?php
namespace Coercive\Utility\FileServe;

/**
 * ServeFile
 * PHP Version 5
 *
 * @version		1
 * @package 	Coercive\Utility\FileServe
 * @link		https://github.com/Coercive/FileServe
 *
 * @author  	Anthony Moral <contact@coercive.fr>
 * @copyright   (c) 2016 - 2017 Anthony Moral
 * @license 	http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
class FileServe {

	/**
	 * OUTPUT
	 *
	 * @param string $sFilePath
	 */
	static function output($sFilePath) {

		# REALPATH
		$sFilePath = realpath($sFilePath);

		# Check if the file exists
		if(!$sFilePath || !file_exists($sFilePath) || !is_file($sFilePath)) { return; }

		# Set the content-type header
		$sMime = self::mimeType($sFilePath);
		header("Content-Type: $sMime");

		# Handle caching
		$iFileModTime = gmdate('D, d M Y H:i:s', filemtime($sFilePath)).' GMT';
		$aHeaders = getallheaders();
		if(isset($aHeaders['If-Modified-Since']) && $aHeaders['If-Modified-Since'] === $iFileModTime) {

			# HEADER NOT MODIFIED
			header('HTTP/1.1 304 Not Modified');
			exit;
		}

		# HEADER LAST MOD
		header("Last-Modified: $iFileModTime");

		// Read the file
		readfile($sFilePath);
		exit;
	}

	/**
	 * MIME TYPE
	 *
	 * @param string $sFilePath
	 * @return string
	 */
	static function mimeType($sFilePath) {

		/** @var string $sFileExt */
		$sFileExt = strtolower(pathinfo($sFilePath, PATHINFO_EXTENSION));
		if(!$sFileExt) { return "unknown/$sFileExt"; }

		# GET MIME
		switch($sFileExt) {
			case 'js':
				return 'application/x-javascript';
			case 'json':
				return 'application/json';
			case 'jpg':
			case 'jpeg':
			case 'jpe':
				return 'image/jpg';
			case 'png':
			case 'gif':
			case 'bmp':
			case 'tiff':
				return "image/$sFileExt";
			case 'css':
				return 'text/css';
			case 'xml':
				return 'application/xml';
			case 'doc':
			case 'docx':
				return 'application/msword';
			case 'xls':
			case 'xlt':
			case 'xlm':
			case 'xld':
			case 'xla':
			case 'xlc':
			case 'xlw':
			case 'xll':
				return 'application/vnd.ms-excel';
			case 'ppt':
			case 'pps':
				return 'application/vnd.ms-powerpoint';
			case 'rtf':
				return 'application/rtf';
			case 'pdf':
				return 'application/pdf';
			case 'html':
			case 'htm':
			case 'php':
				return 'text/html';
			case 'txt':
				return 'text/plain';
			case 'mpeg':
			case 'mpg':
			case 'mpe':
				return 'video/mpeg';
			case 'mp3':
				return 'audio/mpeg3';
			case 'wav':
				return 'audio/wav';
			case 'aiff':
			case 'aif':
				return 'audio/aiff';
			case 'avi':
				return 'video/msvideo';
			case 'wmv':
				return 'video/x-ms-wmv';
			case 'mov':
				return 'video/quicktime';
			case 'zip':
				return 'application/zip';
			case 'tar':
				return 'application/x-tar';
			case 'swf':
				return 'application/x-shockwave-flash';
			case 'svg':
				return 'image/svg+xml';
			case 'ttf':
				return 'application/x-font-truetype';
			case 'otf':
				return 'application/x-font-opentype';
			case 'woff':
				return 'application/font-woff';
			case 'woff2':
				return 'application/font-woff2';
			case 'eot':
				return 'application/vnd.ms-fontobject';
			case 'sfnt':
				return 'application/font-sfnt';
			default:
				if(function_exists('mime_content_type')) {
					$sMime = mime_content_type($sFilePath);
					if($sMime) { return $sMime; }
				}
				return "unknown/$sFileExt";
		}
	}
}