<?php
/**
 * A class for extracting and caching Web resources.
 *
 * @author Salim Ibrogimov <ibrogimov.salim@gmail.com>
 * @version 1.0
 *
 * Examples on how to use this class. ******************************************
 *
 * $web = new WebResourceExtractor(true, '/cache/', 3600);
 * $web->setUserAgent('YOUR USER AGENT');
 * $web->followLocation(5); // allow cURL to follow redirects.
 *
 * Example #1. Extract a single Web resource.
 * $arResults = $web->extractWebResource('https://en.wikipedia.org/wiki/Main_Page', '', '', true, false);
 *
 * Example #2. Extract multiple Web resources by relative URLs.
 * $arUrls = [
 * 	['wiki/Wikipedia:Contents', '', '', true],
 * 	['wiki/Portal:Current_events', '', '', false],
 * 	'wiki/Wikipedia:About',
 * 	'wiki/404',
 * ];
 * $arResults = $web->extractMultipleWebResources($arUrls, 'https://en.wikipedia.org', 15);
 *
 * Example #3. Extract multiple Web resources by absolute URLs.
 * $arUrls = [
 * 	'https://en.wikipedia.org',
 * 	'https://tg.wikipedia.org',
 * ];
 * $arResults = $web->extractMultipleWebResources($arUrls);
 */
class WebResourceExtractor
{
	/**
	 * Indicates whether to cache the extracted Web resource or not. Set to "true"
	 * to cache the extracted Web resource.
	 *
	 * @var boolean
	 */
	private bool $bCache;

	/**
	 * The path to the cache directory.
	 *
	 * @var string
	 */
	private string $sCacheDirectory;

	/**
	 * The amount of time, in seconds, it takes for a cached Web resource to
	 * expire.
	 *
	 * @var integer
	 */
	private int $iCacheAge;

	/**
	 * The full path of the cookie file.
	 *
	 * @var string
	 */
	private string $sCookieFile;

	/**
	 * The user agent to be used in a HTTP request.
	 *
	 * @var string
	 */
	private string $sUserAgent = 'Mozilla/5.0 (compatible; WebResourceExtractorBot/1.0; +https://webresourceextractor.com)';

	/**
	 * Set to "true" to follow redirects.
	 *
	 * @var boolean
	 */
	private bool $bFollowLocation = false;


	/**
	 * The maximum amount of HTTP redirection to follow.
	 *
	 * @var integer
	 */
	private int $iMaxRedirs = 0;

	/**
	 * The HTTP response headers.
	 *
	 * @var array
	 */
	private array $arResponseHeaders = [];

	/**
	 * Initialize a new WebResourceExtractor.
	 *
	 * @param boolean $bCache
	 * @param string [$sCacheDirectory] - required if $bCache is set to "true".
	 * @param integer [$iCacheAge] - required if $bCache is set to "true".
	 * @throws Exception
	 */
	public function __construct(bool $bCache, string $sCacheDirectory = '', int $iCacheAge = 0) {
		$this->bCache = $bCache;
		$sCacheDirectory = trim($sCacheDirectory);

		// If caching is disabled then just create a cookie file.
		if (!$bCache) {
			$this->sCookieFile = tempnam('/tmp', 'cookies');
			return;

		// If caching is enabled then the cache directory must be provided too.
		} elseif ('' === $sCacheDirectory) {
			throw new Exception('Caching enabled but the cache directory is not provided.');

		// If caching is enabled then the cache age must be greater than zero.
		} elseif ($iCacheAge <= 0) {
			throw new Exception('Caching enabled but the cache age is less than or equal to zero. The cache age must be greater than zero.');
		}

		// Create the cache directory if it's not exists.
		if (!is_dir($sCacheDirectory)) mkdir($sCacheDirectory, 0744, true);

		// Possibly add the trailing slash to the cache directory name.
		if (substr($sCacheDirectory, -1) !== '/') $sCacheDirectory .= '/';

		$this->sCacheDirectory = $sCacheDirectory;
		$this->iCacheAge = $iCacheAge;
		$this->sCookieFile = $sCacheDirectory.'cookies.txt';
	}

	/** Delete the cookie file. */
	public function __destruct() {
		if (!$this->bCache) unlink($this->sCookieFile);
	}

	/**
	 * Set the user agent to use in a HTTP request.
	 *
	 * @param string $sUserAgent
	 * @return void
	 */
	public function setUserAgent(string $sUserAgent): void {
		$this->sUserAgent = trim($sUserAgent);
	}

	/**
	 * Allow/disallow following redirection. The redirection amount ($iMaxRedirs)
	 * is unlimited by default. Set to "0" to disallow following the redirection.
	 *
	 * @param integer [$iMaxRedirs] - the redirection limit amount.
	 * @throws UnexpectedValueException
	 * @return void
	 */
	public function followLocation(int $iMaxRedirs = -1): void {
		if ($iMaxRedirs < -1) {
			throw new UnexpectedValueException('Argument must be grater than or equal to "-1", "'.$iMaxRedirs.'" given.');
		}

		$this->bFollowLocation = ($iMaxRedirs !== 0);
		$this->iMaxRedirs = $iMaxRedirs;
	}

	/**
	 * Validate given URL and return it otherwise throw an exception.
	 *
	 * @param string $sUrl
	 * @return string
	 * @throws UnexpectedValueException
	 */
	public function validateUrl(string $sUrl): string {
		if (!filter_var($sUrl, FILTER_VALIDATE_URL)) {
			throw new UnexpectedValueException('Argument must be a valid URL, "'.$sUrl.'" given.');
		}

		return $sUrl;
	}

	/**
	 * Generate a file name for the given URL and the post fields.
	 *
	 * @param string $sUrl
	 * @param string $sPostFields
	 * @return string
	 */
	public function generateFileName(string $sUrl, string $sPostFields): string {
		return $this->sCacheDirectory.sha1($sUrl.$sPostFields); // file extension is omitted on purpose.
	}

	/**
	 * Check is cached Web resource outdated or not.
	 *
	 * @param string $sCacheFileName
	 * @return boolean
	 */
	public function cacheAvailable(string $sCacheFileName): bool {
		return ($this->bCache && file_exists($sCacheFileName) && (time() - filemtime($sCacheFileName) < $this->iCacheAge));
	}

	/**
	 * Save the HTTP response headers and return the number of bytes written. It
	 * is used as a callback.
	 *
	 * @param CurlHandle $ch
	 * @param string $sHeader
	 * @return integer
	 */
	private function cb_saveHeaders(CurlHandle $ch, string $sHeader): int {
		$iLength = strlen($sHeader);
		$sHeader = explode(':', $sHeader, 2);
		if (count($sHeader) < 2) return $iLength;
		$sId = curl_getinfo($ch, CURLINFO_PRIVATE);

		$this->arResponseHeaders[$sId][strtolower(trim($sHeader[0]))] = trim($sHeader[1]);

		return $iLength;
	}

	/**
	 * Get encoded file, decode it, and return it as an array.
	 *
	 * @param string $sFileName
	 * @return array - with only "sData" key.
	 */
	private function getDecodedFile(string $sFileName): array {
		return ['sData' => gzdecode(file_get_contents($sFileName))];
	}

	/**
	 * Set up a cURL session and return it's handle.
	 *
	 * @param array $arOptions - [$sUrl, $sReferer, $sPostFields, $bSaveHeaders].
	 * @return CurlHandle
	 */
	private function setUpCurlSession(array $arOptions): CurlHandle {

		// List all given options.
		[$sUrl, $sReferer, $sPostFields, $bSaveHeaders] = $arOptions;

		// Validate URLs.
		$this->validateUrl($sUrl);
		if (empty($sReferer)) {
			$sReferer = $sUrl;
		} else {
			$this->validateUrl($sReferer);
		}

		// Initialize and set up a cURL session.
		$ch = curl_init();
		curl_setopt_array($ch, [

			// Hardcoded cURL options.
			CURLOPT_HTTPHEADER => [
				'accept: text/html, application/xhtml+xml, application/xml;q=0.9, */*;q=0.8',
				'accept-charset: utf-8, windows-1251;q=0.7, *;q=0.7',
				'accept-language: en-us, en;q=0.5',
				'accept-encoding: gzip',
				'cache-control: no-cache',
			],
			CURLOPT_FRESH_CONNECT => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,

			// Changeable cURL options.
			CURLOPT_PRIVATE => sha1($sUrl),
			CURLOPT_URL => $sUrl,
			CURLOPT_REFERER => $sReferer,
			CURLOPT_USERAGENT => $this->sUserAgent,
			CURLOPT_COOKIEFILE => $this->sCookieFile,
			CURLOPT_COOKIEJAR => $this->sCookieFile,
			CURLOPT_FOLLOWLOCATION => $this->bFollowLocation,
			CURLOPT_MAXREDIRS => $this->iMaxRedirs,
		]);

		// Possibly set post data.
		if ($sPostFields) {
			curl_setopt_array($ch, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $sPostFields,
			]);
		}

		// Possibly save HTTP response headers for further use.
		if ($bSaveHeaders) {
			curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this, 'cb_saveHeaders']);
		}

		return $ch;
	}

	/**
	 * If caching is enabled then return the cached Web resource, if the cached
	 * Web resource is expired or does not exists then extract a Web resource by
	 * it's URL and return status code, response headers, data and total execution
	 * time, and cache the Web resource.
	 *
	 * @param string $sUrl
	 * @param string $sReferer - if empty $sUrl is used instead.
	 * @param string $sPostFields
	 * @param boolean $bSaveHeaders - set to "true" to save HTTP response headers.
	 * @param boolean [$bBypassCaching] - set to "true" to bypass caching.
	 * @throws Exception
	 * @return array - [iHttpCode, arHeaders, sData, iExecutionTime]|[sData].
	 */
	public function extractWebResource(string $sUrl, string $sReferer, string $sPostFields, bool $bSaveHeaders, $bBypassCaching = false): array {
		if (!$bBypassCaching) {
			$sCacheFileName = $this->generateFileName($sUrl, $sPostFields);

			// Possibly return the cached Web resource.
			if ($this->cacheAvailable($sCacheFileName)) {
				return $this->getDecodedFile($sCacheFileName);
			}
		}

		// Get current function's arguments and pass them into the following
		// function and extract a Web resource by it's URL.
		$ch = $this->setUpCurlSession(func_get_args());
		$sData = curl_exec($ch);
		if (false === $sData) {
			throw new Exception('Failed to extract a Web resource['.$sUrl.']. cURL error: '.curl_error($ch));
		}

		// Get necessary cURL transfer information and close the cURL session.
		$iHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$iExecutionTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
		$sId = curl_getinfo($ch, CURLINFO_PRIVATE);
		// curl_close($ch); // in PHP8, it is no longer has any effect.
		unset($ch);

		// Possibly cache the extracted Web resource.
		if (!$bBypassCaching && $this->bCache && $sData && 200 === $iHttpCode) {
			file_put_contents($sCacheFileName, $sData);
		}

		return [
			'iHttpCode' => $iHttpCode,
			'arHeaders' => ($this->arResponseHeaders[$sId] ?? []),
			'sData' => gzdecode($sData),
			'iExecutionTime' => $iExecutionTime,
		];
	}

	/**
	 * Does the same thing as the extractWebResource() function but for multiple
	 * URLs at once. If $arUrlDetails is the list of URLs, without details, then
	 * this function will always return the response headers.
	 *
	 * @param array $arUrlDetails - accepts list of URLs, list of URL details like
	 *                            [$sUrl, $sReferer, $sPostFields, $bSaveHeaders]
	 *                            or mixed list.
	 * @param string [$sBaseUrl] - base part of the URL like
	 *                           https://en.wikipedia.org, it will be prefixed to
	 *                           all URLs from the list. If it's empty string then
	 *                           you must provide full URLs in the $arUrlDetails
	 *                           parameter.
	 * @param integer [$iMaxThreads] - the number of parallel, asynchronous
	 *                                 requests to be processed.
	 * @throws Exception
	 * @return array - URL => [iHttpCode, arHeaders, sData, iExecutionTime,
	 *                 sCurlError]|[sData].
	 */
	public function extractMultipleWebResources(array $arUrlDetails, string $sBaseUrl = '', int $iMaxThreads = 10): array {

		// Do nothing if $arUrlDetails contains less than 2 elements.
		$iUrls = count($arUrlDetails);
		if ($iUrls < 2) {
			throw new Exception('The $arUrlDetails should contain more than 1 elements.');
		}

		// Validate the base URL and possibly add the trailing slash to it.
		if ($sBaseUrl) {
			$this->validateUrl($sBaseUrl);
			if (substr($sBaseUrl, -1) !== '/') $sBaseUrl .= '/';
		}

		$createUrlDetailsList = function($arDetails) {
			if (!is_array($arDetails)) $arDetails = [$arDetails, '', '', true];
			return $arDetails;
		};

		// It's needed to keep and return the responses.
		$arOut = [];

		// Get cached HTML pages.
		if ($this->bCache) {
			foreach ($arUrlDetails as $iIndex => $arDetails) {
				[$sUrl, , $sPostFields] = $createUrlDetailsList($arDetails);

				// Generate a cache file name.
				$sCacheFileName = $this->generateFileName($sBaseUrl.$sUrl, $sPostFields);

				// Possibly get the cached Web resources.
				if ($this->cacheAvailable($sCacheFileName)) {
					$arOut[$sUrl] = $this->getDecodedFile($sCacheFileName);

					// Remove this URL from the list so it doesn't get processed.
					unset($arUrlDetails[$iIndex]);
				}
			}

			if (empty($arUrlDetails)) return $arOut;
		}

		// Initialize the necessary variables.
		$arCurlHandles = [];
		$arCurlHandlesMap = [];
		$mh = curl_multi_init();

		// Add a new cURL session.
		$addUrlToCurlMulti = function() use (&$arUrlDetails, &$arCurlHandles, &$arCurlHandlesMap, $sBaseUrl, $createUrlDetailsList, $mh) {
			$arDetails = array_shift($arUrlDetails);
			[$sUrl, $sReferer, $sPostFields] = $arDetails = $createUrlDetailsList($arDetails);

			// Merge base URL and other URLs.
			$arDetails[0/* URL */] = $sBaseUrl.$sUrl;
			if (!empty($sReferer)) $arDetails[1/* Referer */] = $sBaseUrl.$sReferer;

			// Get a new cURL handle and add it into the multi-handle cURL object.
			$ch = $this->setUpCurlSession($arDetails);
			$sCurlId = uniqid('', true);
			$arCurlHandles[$sCurlId] = [$sUrl, $sPostFields];
			$arCurlHandlesMap[$sCurlId] = $ch;
			curl_multi_add_handle($mh, $ch);
		};

		// Set up a cURL session for URLs.
		for ($i = 0; $i < ($iUrls < $iMaxThreads ? $iUrls : $iMaxThreads); $i++) {
			$addUrlToCurlMulti();
		}

		// Execute the prepared cURL sessions.
		do {

			// Get status update.
			while (CURLM_CALL_MULTI_PERFORM === ($iCurlCode = curl_multi_exec($mh, $iStillRunning)));

			// If no request has finished yet, keep looping.
			if (CURLM_OK !== $iCurlCode) break;

			// If a request was just completed, find out which one.
			while ($arInfo = curl_multi_info_read($mh)) {
				$ch = $arInfo['handle'];
				$sCurlId = key(array_filter($arCurlHandlesMap, function($value) use ($ch) { return $value === $ch; }));

				// Get the necessary cURL transfer information.
				$sCurlError = curl_error($ch);
				$sData = curl_multi_getcontent($ch);
				$iHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$iExecutionTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
				$sId = curl_getinfo($ch, CURLINFO_PRIVATE);

				// If there are more URLs to process, add a new cURL session.
				if (!empty($arUrlDetails)) $addUrlToCurlMulti();

				// Remove the handle that we finished processing. This needs to be done
				// AFTER we've already added a new URL for processing.
				curl_multi_remove_handle($mh, $ch);
				// curl_close($ch); // in PHP8, it is no longer has any effect.
				unset($ch);

				// Get the necessary request details and unset its association.
				[$sUrl, $sPostFields] = $arCurlHandles[$sCurlId];
				unset($arCurlHandles[$sCurlId], $arCurlHandlesMap[$sCurlId]);

				// Possibly cache the Web resource.
				if ($this->bCache && $sData && 200 === $iHttpCode) {
					$sCacheFileName = $this->generateFileName($sBaseUrl.$sUrl, $sPostFields);
					file_put_contents($sCacheFileName, $sData);
				}

				// Add all necessary data into the output array.
				$arOut[$sUrl] = [
					'iHttpCode' => $iHttpCode,
					'arHeaders' => ($this->arResponseHeaders[$sId] ?? []),
					'sData' => gzdecode($sData),
					'iExecutionTime' => $iExecutionTime,
					'sCurlError' => $sCurlError,
				];
			}

			// Waits until curl_multi_exec() returns CURLM_CALL_MULTI_PERFORM or until
			// the timeout, whatever happens first call usleep() if a select returns
			// -1 - workaround for PHP bug: https://bugs.php.net/bug.php?id=61141.
			if ($iStillRunning && curl_multi_select($mh) === -1) usleep(100);

		// As long as there are threads running or requests waiting in the queue.
		} while ($iStillRunning || !empty($arCurlHandles));

		// curl_multi_close($mh); // in PHP8, it is no longer has any effect.
		unset($mh);

		return $arOut;
	}
}
