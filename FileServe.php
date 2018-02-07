<?php
namespace Coercive\Utility\FileServe;

use Exception;
use Symfony\Component\Yaml\Parser as YamlParser;

/**
 * ServeFile
 * PHP Version 5
 *
 * @version		2
 * @package 	Coercive\Utility\FileServe
 * @link		https://github.com/Coercive/FileServe
 *
 * @author  	Anthony Moral <contact@coercive.fr>
 * @copyright   (c) 2016 - 2017 Anthony Moral
 * @license 	http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
class FileServe {

	/** @var array MIME TYPE */
	static private $mime = null;

	/** @var string FILE PATH */
	private $_sFilePath = '';

	/** @var int FILE SIZE */
	private $_iFileSize = null;

	/** @var resource FILE */
	private $_rFile = null;

	/**
	 * LOAD MIME TYPE LIST
	 *
	 * @return array
	 */
	static private function _loadMime(): array
	{
		# SINGLE LOAD
		if(null !== self::$mime) { return self::$mime; }

		# File
		$path = realpath(__DIR__ . '/mime.yml');
		if(!$path || !is_file($path)) { return self::$mime = []; }

		# Parse
		$yaml = (new YamlParser)->parse(file_get_contents($path)) ?: [];
		if(!$yaml || !is_array($yaml)) { return self::$mime = []; }

		return self::$mime = $yaml;
	}

	/**
	 * CLEAN PATH
	 *
	 * prevent slashes and dots at start/end
	 *
	 * @param string $path
	 * @return string
	 */
	private function _cleanPath(string $path): string
	{
		$path = trim($path, " \t\n\r\0\x0B/.");
		$path = str_replace('//', '/', $path);
		$path = str_replace('..', '', $path);
		return realpath("/$path") ?: '';
	}

	/**
	 * EXTRACT RANGE DATAS
	 *
	 * @param int $startByte
	 * @param int $endByte
	 * @return array
	 */
	private function _extractRangeDatas(int $startByte, $endByte): array
	{
		# Base
		$range = ['start' => $startByte, 'end' => $endByte];

		# No datas
		if(empty($_SERVER['HTTP_RANGE'])) { return $range; }

		# Get
		$httpRange = explode('=', $_SERVER['HTTP_RANGE'], 2);
		if (empty($httpRange[0]) || $httpRange[0] !== 'bytes') {
			/*
			  http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.4.17

			  A server SHOULD return a response with this status code if a request
			  included a Range request-header field (section 14.35), and none of
			  the range-specifier values in this field overlap the current extent
			  of the selected resource, and the request did not include an
			  If-Range request-header field. (For byte-ranges, this means that the
			  first- byte-pos of all of the byte-range-spec values were greater
			  than the current length of the selected resource.)

			  When this status code is returned for a byte-range request, the
			  response SHOULD include a Content-Range entity-header field
			  specifying the current length of the selected resource
			  (see section 14.16). This response MUST NOT use the
			  multipart/byteranges content- type.
			*/
			return [];
		}
		if(!isset($httpRange[1])) { return $range; }
		$ranges = $httpRange[1];

		# Multi Range is not handled yet
		if (strpos($ranges, ',') !== false) { return []; }

		// If the range starts with an '-' we start from the beginning.
		// If not, we forward the file pointer and make sure to get the end byte
		// if spesified.
		if ($ranges{0} === '-') {
			// The n-number of the last bytes is requested.
			$lastBytes = (int) substr($ranges, 1);
			$range['start'] = $this->getFileSize() - $lastBytes;
		}
		else {
			$delimiters = explode('-', $ranges);
			$range['start'] = (int) $delimiters[0];
			$range['end'] = (isset($delimiters[1]) && is_numeric($delimiters[1])) ? intval($delimiters[1]) : $this->getFileSize();
		}

		// Check the range and make sure it's treated according to the specs.
		// http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html

		# End bytes can not be larger than $endByte.
		if($range['end'] > $endByte) { $range['end'] = $endByte; }

		// Validate the requested range and return an error if it's not correct.
		if (   $range['start'] < 0
			|| $range['start'] > $range['end']
			|| $range['start'] > $this->getFileSize() - 1
			|| $range['end'] >= $this->getFileSize()
		) {
			return [];
		}

		return $range;
	}

	/**
	 * CONTENT TYPE HEADER
	 *
	 * @return void
	 */
	private function _provideContentTypeHeader(): void
	{
		header("Content-Type: {$this->mimeType()}");
	}

	/**
	 * ETAG HEADER
	 *
	 * Enable resuamble download in IE9.
	 * http://blogs.msdn.com/b/ieinternals/archive/2011/06/03/send-an-etag-to-enable-http-206-file-download-resume-without-restarting.aspx
	 *
	 * @return void
	 */
	private function _provideEtagHeader(): void
	{
		$etag = $this->etag(true);
		if($etag) { header("Etag:  $etag"); }
	}

	/**
	 * PARTIAL CONTENT HEADER
	 *
	 * @return void
	 */
	private function _providePartialContentHeader(): void
	{
		header('HTTP/1.1 206 Partial Content');
	}

	/**
	 * CONTENT LENGTH HEADER
	 *
	 * @param int $length [optional]
	 * @return void
	 */
	private function _provideContentLengthHeader(int $length = null): void
	{
		header('Content-Length: ' . (null === $length ? $this->getFileSize() : $length));
	}

	/**
	 * ACCEPT RANGES HEADER
	 *
	 * At the moment we only support single ranges.
	 * Multiple ranges requires some more work to ensure it works correctly
	 * and comply with the spesifications: http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.2
	 *
	 * Multirange support annouces itself with:
	 * header('Accept-Ranges: bytes');
	 *
	 * Multirange content must be sent with multipart/byteranges mediatype,
	 * (mediatype = mimetype)
	 * as well as a boundry header to indicate the various chunks of data.
	 *
	 * @param int $startByte
	 * @param int $endByte
	 * @return void
	 */
	private function _provideAcceptRangesHeader(int $startByte, int $endByte): void
	{
		header('Accept-Ranges: bytes');
		// multipart/byteranges
		// http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.2
		// header("Accept-Ranges: {$startByte}-{$endByte}");
	}

	/**
	 * CONTENT RANGE HEADER
	 *
	 * @param int $startByte
	 * @param int $endByte
	 * @return void
	 */
	private function _provideContentRangeHeader(int $startByte, int $endByte): void
	{
		header("Content-Range: bytes $startByte-$endByte/{$this->getFileSize()}");
	}

	/**
	 * LAST MODIFIED HEADER
	 *
	 * @return bool
	 */
	private function _provideLastModifiedHeader(): bool
	{
		# Handle caching
		$modTime = gmdate('D, d M Y H:i:s', filemtime($this->_sFilePath)).' GMT';
		$headers = getallheaders();
		if(isset($headers['If-Modified-Since']) && $headers['If-Modified-Since'] === $modTime) {

			# HEADER NOT MODIFIED
			header('HTTP/1.1 304 Not Modified');
			return false;
		}

		# HEADER LAST MOD
		header("Last-Modified: $modTime");
		return true;
	}

	/**
	 * RECURSE BUFFER LOOP
	 *
	 * @param int $end
	 * @param int $buffer [optional] 1 octet (1024 * 8)
	 * @return void
	 * @throws Exception
	 */
	private function _recurseBufferLoop(int $end, int $buffer = 8192): void
	{
		# End of file
		if(@feof($this->_getFile())) { return; }

		# Check if we have outputted all the data requested
		$position = ftell($this->_getFile());
		if($position > $end) { return; }

		# In case we're only outputtin a chunk, make sure we don't read past the length
		if ($position + $buffer > $end) { $buffer = $end - $position + 1; }

		# Reset time limit for big files
		set_time_limit(0);

		# Read the file part
		$chunk = fread($this->_getFile(), $buffer);
		if(false === $chunk) { throw new Exception('Read file chunk error : stop process'); }
		echo $chunk;

		# Free up memory. Otherwise large files will trigger PHP's memory limit.
		flush();

		# Recursive
		$this->_recurseBufferLoop($end, $buffer);
		return;
	}

	/**
	 * FILE OPEN
	 *
	 * @return void
	 * @throws Exception
	 */
	private function _fileOpen(): void
	{
		# Try open
		$this->_rFile = @fopen($this->_sFilePath, 'rb');

		# Verify
		if(false === $this->_rFile) { throw new Exception("Can't open file : $this->_sFilePath."); }
	}

	/**
	 * FILE OPEN
	 *
	 * @return void
	 * @throws Exception
	 */
	private function _fileClose(): void
	{
		# Verify
		if(!$this->_rFile) { return; }

		# Close
		if(!fclose($this->_rFile)) { throw new Exception("Can't close file : $this->_sFilePath."); }
	}

	/**
	 * GET R FILE
	 *
	 * @return resource
	 */
	private function _getFile(): resource
	{
		return $this->_rFile;
	}

	/**
	 * FileServe constructor.
	 *
	 * @param string $path
	 * @throws Exception
	 */
	public function __construct(string $path)
	{
		# Set File
		$this->_sFilePath = $this->_cleanPath($path);

		# Verify File
		if(!$this->_sFilePath || !is_file($this->_sFilePath)) {
			throw new Exception('File does not exist or not regular file : ' . $path);
		}
	}

	/**
	 * GET FILE SIZE
	 *
	 * @return int
	 */
	public function getFileSize(): int
	{
		if(null !== $this->_iFileSize) { return $this->_iFileSize; }
		return $this->_iFileSize = filesize($this->_sFilePath) ?: 0;
	}

	/**
	 * Generates an ETAG for a given file.
	 *
	 * @link http://php.net/manual/en/function.http-match-etag.php
	 * @link http://www.xpertdeveloper.com/2011/03/http-etag-explained/
	 * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.19
	 * @link http://en.wikipedia.org/wiki/HTTP_ETag
	 * @link http://blogs.msdn.com/b/ieinternals/archive/2011/06/03/send-an-etag-to-enable-http-206-file-download-resume-without-restarting.aspx
	 *
	 * @param bool $quote [optional]
	 * @return string
	 */
	public function etag(bool $quote = true): string
	{
		# Get Infos
		$info = stat($this->_sFilePath);
		if (!$info || !isset($info['ino']) || !isset($info['size']) || !isset($info['mtime'])) { return ''; }

		# Additionnal Quotes
		$q = ($quote) ? '"' : '';

		# Etag
		return sprintf("$q%x-%x-%x$q", $info['ino'], $info['size'], $info['mtime']);
	}

	/**
	 * SEND FILE FOR CLIENT DOWNLOAD
	 *
	 * @return void
	 */
	public function download(): void
	{
		# Headers
		$this->_provideContentTypeHeader();
		$this->_provideContentLengthHeader();
		$this->_provideEtagHeader();

		# Skip if file as not modified since last client cache
		if(!$this->_provideLastModifiedHeader()) { exit; }

		# Read the file
		readfile($this->_sFilePath);
		exit;
	}

	/**
	 * SEND FILE FOR CLIENT DOWNLOAD
	 * 
	 * @return void
	 * @throws Exception
	 */
	public function range(): void
	{
		# Clears the cache and prevent unwanted output
		# Do not send cache limiter header
		@ob_clean();
		@ini_set('error_reporting', E_ALL & ~ E_NOTICE);
		@apache_setenv('no-gzip', 1);
		@ini_set('zlib.output_compression', 'Off');
		@ini_set('session.cache_limiter','none');

		# Headers
		$this->_provideContentTypeHeader();
		$this->_provideEtagHeader();

		# Init
		$length = $this->getFileSize();
		$startByte = 0;
		$endByte = $this->getFileSize() - 1;

		# Header Range
		$this->_provideAcceptRangesHeader(0, $length);

		# Classic process if no request range
		if (empty($_SERVER['HTTP_RANGE'])) {

			// It's not a range request, output the file anyway
			$this->_provideContentLengthHeader();

			# Try read the file
			$status = @readfile($this->_sFilePath);
			if(false === $status) { throw new Exception('ReadFile error, with no range : stop process'); }

			// and flush the buffer
			flush();
			exit;
		}

		# Extract the range string
		$range = $this->_extractRangeDatas($startByte, $endByte);
		if (!$range) {
			header('HTTP/1.1 416 Requested Range Not Satisfiable');
			$this->_provideContentRangeHeader($startByte, $endByte);
			throw new Exception('Requested Range Not Satisfiable');
		}

		# Init new processed range
		$startByte  = $range['start'];
		$endByte    = $range['end'];

		# Calculate new content length
		$length = $endByte - $startByte + 1;

		# Notify the client the byte range we'll be outputting
		$this->_providePartialContentHeader();
		$this->_provideContentRangeHeader($startByte, $endByte);
		$this->_provideContentLengthHeader($length);

		# Open file for read / stream
		$this->_fileOpen();

		# Init pointer start
		fseek($this->_getFile(), $startByte);

		/*
		 * Ensure output buffering is off. It appeared to yield 1 on an default WAMP
		 * installation. When clicking the download the hour glass would spin for a
		 * while and the file dialog took a while to appear. When it finally did the
		 * 214MB file downloaded only 128MB before a fatal PHP error was thrown,
		 * complaining about memory being exhausted.
		 */
		@ob_end_clean();

		// Start buffered download
		$this->_recurseBufferLoop($endByte);

		# Close file
		$this->_fileClose();
	}

	/**
	 * MIME TYPE
	 *
	 * @return string
	 */
	public function mimeType(): string
	{
		# Detect extension
		$ext = strtolower(pathinfo($this->_sFilePath, PATHINFO_EXTENSION));
		if(!$ext) { return "unknown/unknown"; }

		# Mime List
		self::_loadMime();

		# Get Mime
		if(isset(self::$mime[$ext])) { return self::$mime[$ext]; }

		# Default Ouput
		if(function_exists('mime_content_type')) {
			$mime = mime_content_type($this->_sFilePath);
			if($mime) { return $mime; }
		}
		return "unknown/$ext";
	}
}