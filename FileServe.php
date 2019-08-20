<?php
namespace Coercive\Utility\FileServe;

use Exception;

/**
 * ServeFile
 * 
 * @package 	Coercive\Utility\FileServe
 * @link		https://github.com/Coercive/FileServe
 *
 * MimeType detection by ralouphie/mimey
 * @author ralouphie
 * @link https://github.com/ralouphie
 *
 * @author  	Anthony Moral <contact@coercive.fr>
 * @copyright   (c) 2019
 * @license 	MIT
 */
class FileServe
{
	/** @var string FILE PATH */
	private $path = '';

	/** @var int FILE SIZE */
	private $size = null;

	/** @var resource FILE */
	private $resource = null;

	/** @var bool Active the no cache header */
	private $cache = false;

	/** @var bool Active the no-named file */
	private $filename = true;

	/**
	 * CLEAN PATH
	 *
	 * prevent slashes and dots at start/end
	 *
	 * @param string $path
	 * @return string
	 */
	private function cleanPath(string $path): string
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
	private function extractRangeDatas(int $startByte, int $endByte): array
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
			$range['start'] = $this->getSize() - $lastBytes;
		}
		else {
			$delimiters = explode('-', $ranges);
			$range['start'] = (int) $delimiters[0];
			$range['end'] = (isset($delimiters[1]) && is_numeric($delimiters[1])) ? intval($delimiters[1]) : $this->getSize();
		}

		// Check the range and make sure it's treated according to the specs.
		// http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html

		# End bytes can not be larger than $endByte.
		if($range['end'] > $endByte) { $range['end'] = $endByte; }

		// Validate the requested range and return an error if it's not correct.
		if (   $range['start'] < 0
			|| $range['start'] > $range['end']
			|| $range['start'] > $this->getSize() - 1
			|| $range['end'] >= $this->getSize()
		) {
			return [];
		}

		return $range;
	}

	/**
	 * Ensure output buffering is off. It appeared to yield 1 on an default WAMP
	 * installation. When clicking the download the hour glass would spin for a
	 * while and the file dialog took a while to appear. When it finally did the
	 * 214MB file downloaded only 128MB before a fatal PHP error was thrown,
	 * complaining about memory being exhausted.
	 *
	 * @return void
	 */
	private function cleanBuffer()
	{
		@ob_end_clean();
	}

	/**
	 * HEADER : no cache
	 *
	 * @return void
	 */
	private function headerNoCache()
	{
		header('Pragma: public');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Cache-Control: private', false);
	}

	/**
	 * HEADER : transfer encoding
	 * same as 8-bit, but with no length limit
	 *
	 * @return void
	 */
	private function headerContentTransferEncoding()
	{
		header('Content-Transfer-Encoding: binary');
	}

	/**
	 * HEADER : content disposition
	 *
	 * @param bool $forceDownload [optional]
	 * @param string $filename [optional]
	 */
	private function headerContentDisposition(bool $forceDownload = true, string $filename = '')
	{
		$header = 'Content-Disposition:';
		$options = [];
		if ($forceDownload) {
			$options[] = 'attachment';
		}
		if($this->filename) {
			if ($filename) {
				$options[] = 'filename="' . $filename . '"';
			}
			else {
				$options[] = 'filename="' . basename($this->path) . '"';
			}
		}
		if($options) {
			header('Content-Disposition: ' . implode('; ', $options));
		}
	}

	/**
	 * HEADER : content description
	 *
	 * @return void
	 */
	private function headerContentDescription()
	{
		header('Content-Description: File Transfer');
	}

	/**
	 * HEADER : content type
	 *
	 * @param string $filename [optional]
	 * @return void
	 */
	private function headerContentType(string $filename = '')
	{
		header("Content-Type: {$this->mimeType($filename)}");
	}

	/**
	 * HEADER etag
	 *
	 * Enable resuamble download in IE9.
	 * http://blogs.msdn.com/b/ieinternals/archive/2011/06/03/send-an-etag-to-enable-http-206-file-download-resume-without-restarting.aspx
	 *
	 * @return void
	 */
	private function headerEtag()
	{
		$etag = $this->etag(true);
		if($etag) { header("Etag:  $etag"); }
	}

	/**
	 * HEADER : partial content
	 *
	 * @return void
	 */
	private function headerPartialContent()
	{
		header('HTTP/1.1 206 Partial Content');
	}

	/**
	 * HEADER : content length
	 *
	 * @param int $length [optional]
	 * @return void
	 */
	private function headerContentLength(int $length = null)
	{
		header('Content-Length: ' . (null === $length ? $this->getSize() : $length));
	}

	/**
	 * HEADER : accept ranges
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
	private function headerAcceptRanges(int $startByte, int $endByte)
	{
		header('Accept-Ranges: bytes');
		// multipart/byteranges
		// http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.2
		// header("Accept-Ranges: {$startByte}-{$endByte}");
	}

	/**
	 * HEADER : content range
	 *
	 * @param int $startByte
	 * @param int $endByte
	 * @return void
	 */
	private function headerContentRange(int $startByte, int $endByte)
	{
		header("Content-Range: bytes $startByte-$endByte/{$this->getSize()}");
	}

	/**
	 * HEADER : last modified
	 *
	 * @return bool
	 */
	private function headerLastModified(): bool
	{
		# Handle caching
		$modTime = gmdate('D, d M Y H:i:s', filemtime($this->path)).' GMT';
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
	private function recurseBufferLoop(int $end, int $buffer = 8192)
	{
		# End of file
		if(@feof($this->get())) { return; }

		# Check if we have outputted all the data requested
		$position = ftell($this->get());
		if($position > $end) { return; }

		# In case we're only outputtin a chunk, make sure we don't read past the length
		if ($position + $buffer > $end) { $buffer = $end - $position + 1; }

		# Reset time limit for big files
		set_time_limit(0);

		# Read the file part
		$chunk = fread($this->get(), $buffer);
		if(false === $chunk) { throw new Exception('Read file chunk error : stop process'); }
		echo $chunk;

		# Free up memory. Otherwise large files will trigger PHP's memory limit.
		flush();

		# Recursive
		$this->recurseBufferLoop($end, $buffer);
		return;
	}

	/**
	 * FILE OPEN
	 *
	 * @return void
	 * @throws Exception
	 */
	private function open()
	{
		$this->resource = @fopen($this->path, 'rb');
		if(false === $this->resource) { throw new Exception("Can't open file : $this->path."); }
	}

	/**
	 * FILE OPEN
	 *
	 * @return void
	 * @throws Exception
	 */
	private function close()
	{
		# Verify
		if(!$this->resource) { return; }

		# Close
		if(!fclose($this->resource)) { throw new Exception("Can't close file : $this->path."); }
	}

	/**
	 * GET R FILE
	 *
	 * @return resource
	 */
	private function get(): resource
	{
		return $this->resource;
	}

	/**
	 * FileServe constructor.
	 *
	 * @param string $path
	 * @return void
	 * @throws Exception
	 */
	public function __construct(string $path)
	{
		$this->path = $this->cleanPath($path);
		if(!$this->path || !is_file($this->path)) {
			throw new Exception('File does not exist or not regular file : ' . $path);
		}
	}

	/**
	 * Try to close file if opened
	 *
	 * @return void
	 * @throws Exception
	 */
	public function __destruct()
	{
		$this->close();
	}

	/**
	 * Do not send the no cache header
	 *
	 * @return FileServe
	 */
	public function enableCache(): FileServe
	{
		$this->cache = true;
		return $this;
	}

	/**
	 * Send the no cache header
	 *
	 * @return FileServe
	 */
	public function disableCache(): FileServe
	{
		$this->cache = false;
		return $this;
	}

	/**
	 * (Content-Disposition)
	 * Send the file name
	 *
	 * @return FileServe
	 */
	public function enableFilename(): FileServe
	{
		$this->filename = true;
		return $this;
	}

	/**
	 * (Content-Disposition)
	 * Do not send the file name
	 *
	 * @return FileServe
	 */
	public function disableFilename(): FileServe
	{
		$this->filename = false;
		return $this;
	}

	/**
	 * GET FILE SIZE
	 *
	 * @return int
	 */
	public function getSize(): int
	{
		if(null !== $this->size) { return $this->size; }
		return $this->size = filesize($this->path) ?: 0;
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
		$info = stat($this->path);
		if (!$info || !isset($info['ino']) || !isset($info['size']) || !isset($info['mtime'])) { return ''; }

		# Additionnal Quotes
		$q = ($quote) ? '"' : '';

		# Etag
		return sprintf("$q%x-%x-%x$q", $info['ino'], $info['size'], $info['mtime']);
	}

	/**
	 * Send file for client
	 *
	 * @param string $filename [optional]
	 * @return void
	 */
	public function serve(string $filename = '')
	{
		# Headers
		if(!$this->cache) { $this->headerNoCache(); }
		$this->headerContentDescription();
		$this->headerContentTransferEncoding();
		$this->headerContentType($filename);
		$this->headerContentLength();
		$this->headerEtag();

		# Skip if file as not modified since last client cache
		if(!$this->headerLastModified()) { return; }

		# Read the file
		readfile($this->path);
		$this->cleanBuffer();
	}

	/**
	 * Send file for client with apache x-send-file mod
	 *
	 * @link https://tn123.org/mod_xsendfile/
	 *
	 * @return void
	 */
	public function XSendFile(bool $forceDownload = true, string $filename = '')
	{
		header('X-Sendfile: ' . $this->path);
		header('Content-Type: application/octet-stream');
		$this->headerContentDisposition($forceDownload, $filename);
		$this->cleanBuffer();
	}

	/**
	 * Send file for download
	 *
	 * @param bool $forceDownload [optional]
	 * @param string $filename [optional]
	 * @return void
	 */
	public function download(bool $forceDownload = true, string $filename = '')
	{
		if(!$this->cache) { $this->headerNoCache(); }
		$this->headerContentDescription();
		$this->headerContentTransferEncoding();
		$this->headerContentType($filename);
		$this->headerContentLength();
		$this->headerEtag();
		$this->headerContentDisposition($forceDownload, $filename);

		# Read the file
		readfile($this->path);
		$this->cleanBuffer();
	}

	/**
	 * Send range bytes of file for client
	 * 
	 * @return void
	 * @throws Exception
	 */
	public function range()
	{
		# Clears the cache and prevent unwanted output
		# Do not send cache limiter header
		@ob_clean();
		@ini_set('error_reporting', E_ALL & ~ E_NOTICE);
		@apache_setenv('no-gzip', 1);
		@ini_set('zlib.output_compression', 'Off');
		@ini_set('session.cache_limiter','none');

		# Headers
		$this->headerContentType();
		$this->headerEtag();

		# Init
		$length = $this->getSize();
		$startByte = 0;
		$endByte = $this->getSize() - 1;

		# Header Range
		$this->headerAcceptRanges(0, $length);

		# Classic process if no request range
		if (empty($_SERVER['HTTP_RANGE'])) {

			// It's not a range request, output the file anyway
			$this->headerContentLength();

			# Try read the file
			$status = @readfile($this->path);
			if(false === $status) { throw new Exception('ReadFile error, with no range : stop process'); }

			// and flush the buffer
			flush();
			return;
		}

		# Extract the range string
		$range = $this->extractRangeDatas($startByte, $endByte);
		if (!$range) {
			header('HTTP/1.1 416 Requested Range Not Satisfiable');
			$this->headerContentRange($startByte, $endByte);
			throw new Exception('Requested Range Not Satisfiable');
		}

		# Init new processed range
		$startByte = $range['start'];
		$endByte = $range['end'];

		# Calculate new content length
		$length = $endByte - $startByte + 1;

		# Notify the client the byte range we'll be outputting
		$this->headerPartialContent();
		$this->headerContentRange($startByte, $endByte);
		$this->headerContentLength($length);

		# Open file for read / stream
		$this->open();

		# Init pointer start
		fseek($this->get(), $startByte);

		/*
		 * Ensure output buffering is off. It appeared to yield 1 on an default WAMP
		 * installation. When clicking the download the hour glass would spin for a
		 * while and the file dialog took a while to appear. When it finally did the
		 * 214MB file downloaded only 128MB before a fatal PHP error was thrown,
		 * complaining about memory being exhausted.
		 */
		@ob_end_clean();

		// Start buffered download
		$this->recurseBufferLoop($endByte);

		# Close file
		$this->close();
	}

	/**
	 * Get mime type of the target file or for the given filename
	 *
	 * @param string $filename [optional]
	 * @return string
	 */
	public function mimeType(string $filename = ''): string
	{
		# Detect extension
		$ext = strtolower(pathinfo($filename ?: $this->path, PATHINFO_EXTENSION));
		if(!$ext) { return "unknown/unknown"; }

		# Detect Mime with ralouphie/mimey
		$mimey = new \Mimey\MimeTypes;
		$mime = (string) $mimey->getMimeType($ext);

		return $mime ?: "unknown/$ext";
	}
}
