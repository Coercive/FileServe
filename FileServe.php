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
	static private $_aMime = null;

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
	static private function _loadMime() {

		# SINGLE LOAD
		if(null !== self::$_aMime) { return self::$_aMime; }

		# File
		$sMimePath = realpath(__DIR__ . '/mime.yml');
		if(!$sMimePath || !file_exists($sMimePath) || !is_file($sMimePath)) { return self::$_aMime = []; }

		# Parse
		$oYamlParser = new YamlParser;
		$aCurrentYaml = $oYamlParser->parse(file_get_contents($sMimePath)) ?: [];
		if(!$aCurrentYaml || !is_array($aCurrentYaml)) { return self::$_aMime = []; }

		return self::$_aMime = $aCurrentYaml;

	}

	/**
	 * CLEAN PATH
	 *
	 * prevent slashes and dots at start/end
	 *
	 * @param string $sPath
	 * @return string
	 */
	private function _cleanPath($sPath) {
		$sPath = trim($sPath, " \t\n\r\0\x0B/.");
		$sPath = str_replace('//', '/', $sPath);
		$sPath = str_replace('..', '', $sPath);
		return realpath("/$sPath") ?: '';
	}

	/**
	 * IS REGULAR FILE
	 *
	 * @return bool
	 */
	private function _isRegularFile() {
		return ($this->_sFilePath && file_exists($this->_sFilePath) && is_file($this->_sFilePath));
	}

	/**
	 * EXTRACT RANGE DATAS
	 *
	 * @param int $iFileStartByte
	 * @param int $iFileEndByte
	 * @return array
	 */
	private function _extractRangeDatas($iFileStartByte, $iFileEndByte) {

		# Base
		$aRange = ['start' => $iFileStartByte, 'end' => $iFileEndByte];

		# No datas
		if(empty($_SERVER['HTTP_RANGE'])) { return $aRange; }

		# Get
		$aHttpRange = explode('=', $_SERVER['HTTP_RANGE'], 2);
		if (empty($aHttpRange[0]) || $aHttpRange[0] !== 'bytes') {
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
		if(!isset($aHttpRange[1])) { return $aRange; }
		$sRange = $aHttpRange[1];

		# Multi Range is not handled yet
		if (strpos($sRange, ',') !== false) { return []; }

		// If the range starts with an '-' we start from the beginning.
		// If not, we forward the file pointer and make sure to get the end byte
		// if spesified.
		if ($sRange{0} === '-') {
			// The n-number of the last bytes is requested.
			$iLastBytes = (int) substr($sRange, 1);
			$aRange['start'] = $this->getFileSize() - $iLastBytes;
		}
		else {
			$aRangeData  = explode('-', $sRange);
			$aRange['start'] = (int) $aRangeData[0];
			$aRange['end'] = (isset($aRangeData[1]) && is_numeric($aRangeData[1])) ? intval($aRangeData[1]) : $this->getFileSize();
		}

		// Check the range and make sure it's treated according to the specs.
		// http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html

		# End bytes can not be larger than $iFileEndByte.
		if($aRange['end'] > $iFileEndByte) { $aRange['end'] = $iFileEndByte; }

		// Validate the requested range and return an error if it's not correct.
		if (   $aRange['start'] < 0
			|| $aRange['start'] > $aRange['end']
			|| $aRange['start'] > $this->getFileSize() - 1
			|| $aRange['end'] >= $this->getFileSize()
		) {
			return [];
		}

		return $aRange;

	}

	/**
	 * CONTENT TYPE HEADER
	 *
	 * @return void
	 */
	private function _provideContentTypeHeader() {
		$sMime = $this->mimeType();
		header("Content-Type: $sMime");
	}

	/**
	 * ETAG HEADER
	 *
	 * @return void
	 */
	private function _provideEtagHeader() {
		# Enable resuamble download in IE9.
		// http://blogs.msdn.com/b/ieinternals/archive/2011/06/03/send-an-etag-to-enable-http-206-file-download-resume-without-restarting.aspx
		$sEtag = $this->etag(true);
		if($sEtag) { header("Etag:  $sEtag"); }
	}

	/**
	 * PARTIAL CONTENT HEADER
	 *
	 * @return void
	 */
	private function _providePartialContentHeader() {
		header('HTTP/1.1 206 Partial Content');
	}

	/**
	 * CONTENT LENGTH HEADER
	 *
	 * @param int $iContentLength [optional]
	 * @return void
	 */
	private function _provideContentLengthHeader($iContentLength = null) {
		header("Content-Length: ". (null === $iContentLength ? $this->getFileSize() : $iContentLength));
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
	 * @param int $iStartByte
	 * @param int $iEndByte
	 * @return void
	 */
	private function _provideAcceptRangesHeader($iStartByte, $iEndByte) {
		header('Accept-Ranges: bytes');
		// multipart/byteranges
		// http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.2
		// header("Accept-Ranges: {$iStartByte}-{$iEndByte}");
	}

	/**
	 * CONTENT RANGE HEADER
	 *
	 * @param int $iStartByte
	 * @param int $iEndByte
	 * @return void
	 */
	private function _provideContentRangeHeader($iStartByte, $iEndByte) {
		header("Content-Range: bytes $iStartByte-$iEndByte/{$this->getFileSize()}");
	}

	/**
	 * LAST MODIFIED HEADER
	 *
	 * @return bool
	 */
	private function _provideLastModifiedHeader() {
		# Handle caching
		$iFileModTime = gmdate('D, d M Y H:i:s', filemtime($this->_sFilePath)).' GMT';
		$aHeaders = getallheaders();
		if(isset($aHeaders['If-Modified-Since']) && $aHeaders['If-Modified-Since'] === $iFileModTime) {

			# HEADER NOT MODIFIED
			header('HTTP/1.1 304 Not Modified');
			return false;
		}

		# HEADER LAST MOD
		header("Last-Modified: $iFileModTime");

		return true;
	}

	/**
	 * RECURSE BUFFER LOOP
	 *
	 * @param int $iEnd
	 * @param int $iBuffer [optional] 1 octet (1024 * 8)
	 * @return void
	 * @throws Exception
	 */
	private function _recurseBufferLoop($iEnd, $iBuffer = 8192) {

		# End of file
		if(@feof($this->_getFile())) {
			return;
		}

		# Check if we have outputted all the data requested
		$iPosition = ftell($this->_getFile());
		if($iPosition > $iEnd) {
			return;
		}

		# In case we're only outputtin a chunk, make sure we don't read past the length
		if ($iPosition + $iBuffer > $iEnd) {
			$iBuffer = $iEnd - $iPosition + 1;
		}

		# Reset time limit for big files
		set_time_limit(0);

		# Read the file part
		$mChunk = fread($this->_getFile(), $iBuffer);
		if(false === $mChunk) { throw new Exception('Read file chunk error : stop process'); }
		echo $mChunk;

		# Free up memory. Otherwise large files will trigger PHP's memory limit.
		flush();

		# Recursive
		$this->_recurseBufferLoop($iEnd, $iBuffer);
		return;

	}

	/**
	 * FILE OPEN
	 *
	 * @return $this
	 * @throws Exception
	 */
	private function _fileOpen() {

		# Try open
		$this->_rFile = @fopen($this->_sFilePath, 'rb');

		# Verify
		if(false === $this->_rFile) { throw new Exception("Can't open file : $this->_sFilePath."); }

		# Maintain chainability
		return $this;

	}

	/**
	 * FILE OPEN
	 *
	 * @return $this
	 * @throws Exception
	 */
	private function _fileClose() {

		# Verify
		if(!$this->_rFile) { return $this; }

		# Close
		if(!fclose($this->_rFile)) { throw new Exception("Can't close file : $this->_sFilePath."); }

		# Maintain chainability
		return $this;

	}

	/**
	 * GET R FILE
	 *
	 * @return resource
	 */
	private function _getFile() {
		return $this->_rFile;
	}

	/**
	 * FileServe constructor.
	 *
	 * @param string $sFilePath
	 * @throws Exception
	 */
	public function __construct($sFilePath) {

		# Set File
		$this->_sFilePath = $this->_cleanPath($sFilePath);

		# Verify File
		if(!$this->_isRegularFile()) {
			throw new Exception('File does not exist or not regular file : ' . $sFilePath);
		}

	}

	/**
	 * GET FILE SIZE
	 *
	 * @return int
	 */
	public function getFileSize() {
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
	 * @param bool $bQuote [optional]
	 * @return string
	 */
	public function etag($bQuote = true) {

		# Get Infos
		$aInfo = stat($this->_sFilePath);
		if (!$aInfo || !isset($aInfo['ino']) || !isset($aInfo['size']) || !isset($aInfo['mtime'])) { return ''; }

		# Additionnal Quotes
		$q = ($bQuote) ? '"' : '';

		# Etag
		return sprintf("$q%x-%x-%x$q", $aInfo['ino'], $aInfo['size'], $aInfo['mtime']);

	}

	/**
	 * SEND FILE FOR CLIENT DOWNLOAD
	 */
	public function download() {

		# Headers
		$this->_provideContentTypeHeader();
		$this->_provideContentLengthHeader();
		$this->_provideEtagHeader();

		# Skip if file as not modified since last client cache
		if(!$this->_provideLastModifiedHeader()) {
			exit;
		}

		# Read the file
		readfile($sFilePath);
		exit;

	}

	/**
	 * SEND FILE FOR CLIENT DOWNLOAD
	 */
	public function range() {

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
		$iContentLength = $this->getFileSize();
		$iStartByte = 0;
		$iEndByte = $this->getFileSize() - 1;

		# Header Range
		$this->_provideAcceptRangesHeader(0, $iContentLength);

		# Classic process if no request range
		if (empty($_SERVER['HTTP_RANGE'])) {

			// It's not a range request, output the file anyway
			$this->_provideContentLengthHeader();

			# Try read the file
			$bReadStatus = @readfile($this->_sFilePath);
			if(false === $bReadStatus) { throw new Exception('ReadFile error, with no range : stop process'); }

			// and flush the buffer
			flush();
			exit;
		}

		# Extract the range string
		$aRange = $this->_extractRangeDatas($iStartByte, $iEndByte);
		if (!$aRange) {
			header('HTTP/1.1 416 Requested Range Not Satisfiable');
			$this->_provideContentRangeHeader($iStartByte, $iEndByte);
			throw new Exception('Requested Range Not Satisfiable');
		}

		# Init new processed range
		$iStartByte  = $aRange['start'];
		$iEndByte    = $aRange['end'];

		# Calculate new content length
		$iContentLength = $iEndByte - $iStartByte + 1;

		# Notify the client the byte range we'll be outputting
		$this->_providePartialContentHeader();
		$this->_provideContentRangeHeader($iStartByte, $iEndByte);
		$this->_provideContentLengthHeader($iContentLength);

		# Open file for read / stream
		$this->_fileOpen();

		# Init pointer start
		fseek($this->_getFile(), $iStartByte);

		/*
		 * Ensure output buffering is off. It appeared to yield 1 on an default WAMP
		 * installation. When clicking the download the hour glass would spin for a
		 * while and the file dialog took a while to appear. When it finally did the
		 * 214MB file downloaded only 128MB before a fatal PHP error was thrown,
		 * complaining about memory being exhausted.
		 */
		@ob_end_clean();

		// Start buffered download
		$this->_recurseBufferLoop($iEndByte);

		# Close file
		$this->_fileClose();

	}

	/**
	 * MIME TYPE
	 *
	 * @return string
	 */
	public function mimeType() {

		/** @var string $sFileExt */
		$sFileExt = strtolower(pathinfo($this->_sFilePath, PATHINFO_EXTENSION));
		if(!$sFileExt) { return "unknown/unknown"; }

		# Mime List
		$aMimes = self::_loadMime();

		# Get Mime
		if(isset($aMimes[$sFileExt])) { return $aMimes[$sFileExt]; }

		# Default Ouput
		if(function_exists('mime_content_type')) {
			$sMime = mime_content_type($this->_sFilePath);
			if($sMime) { return $sMime; }
		}
		return "unknown/$sFileExt";

	}
}