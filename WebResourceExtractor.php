<?php

/**
 * A class for extracting and caching Web resources.
 *
 * @author Salim Ibrohimi <ibrogimov.salim@gmail.com>
 * @version 1.0
 *
 * Examples on how to use this class. ******************************************
 *
 * $extractor = new WebResourceExtractor(true, '/cache/', 3600);
 * $extractor->setUserAgent('YOUR USER AGENT');
 * $extractor->followLocation(5); // allow cURL to follow redirects.
 *
 * Example #1. Extract a single Web resource.
 * $results = $extractor->extractWebResource('https://en.wikipedia.org/wiki/Main_Page', '', '', true, false);
 *
 * Example #2. Extract multiple Web resources by relative URLs.
 * $urls = [
 *     ['wiki/Wikipedia:Contents', '', '', true],
 *     ['wiki/Portal:Current_events', '', '', false],
 *     'wiki/Wikipedia:About',
 *     'wiki/404',
 * ];
 * $results = $extractor->extractMultipleWebResources($urls, 'https://en.wikipedia.org', 15);
 *
 * Example #3. Extract multiple Web resources by absolute URLs.
 * $urls = [
 *     'https://en.wikipedia.org',
 *     'https://tg.wikipedia.org',
 * ];
 * $results = $extractor->extractMultipleWebResources($urls);
 */

declare(strict_types=1);

namespace WebResourceExtractor;

class WebResourceExtractor
{
    /**
     * Indicates whether to cache the extracted Web resource or not. Assign
     * "true" to cache the extracted Web resource.
     */
    private bool $cache;

    /**
     * A path to the cache directory.
     */
    private string $cacheDirectory;

    /**
     * An amount of time (in seconds) it takes for a cached Web resource to
     * expire.
     */
    private int $cacheAge;

    /**
     * A full path of the cookie file.
     */
    private string $cookieFile;

    /**
     * A user agent to be used in an HTTP request.
     */
    private string $userAgent = 'Mozilla/5.0 (compatible; WebResourceExtractorBot/1.0; +https://webresourceextractor.com)';

    /**
     * Assign "true" to follow redirects.
     */
    private bool $followLocation = false;


    /**
     * A maximum number of an HTTP redirection to follow.
     */
    private int $maxRedirects = 0;

    /**
     * An HTTP response headers.
     *
     * @var string[][]
     */
    private array $responseHeaders = [];

    /**
     * Initialize a new WebResourceExtractor.
     *
     * @param bool $cache - assign "true" to cache the extracted Web resource.
     * @param string $cacheDirectory - required if $cache is "true".
     * @param int $cacheAge - required if $cache is "true".
     * @throws \Exception
     */
    public function __construct(bool $cache, string $cacheDirectory = '', int $cacheAge = 0)
    {
        $this->cache = $cache;
        $cacheDirectory = trim($cacheDirectory);

        // If caching is disabled then just create a cookie file.
        if (!$cache) {
            if (!($cookieFile = tempnam('/tmp', 'cookies'))) {
                throw new \Exception('Could not create a temporary cookie file.');
            }

            $this->cookieFile = $cookieFile;

            return;

        // If caching is enabled then the cache directory must be provided too.
        } elseif ('' === $cacheDirectory) {
            throw new \Exception('Caching enabled but the cache directory is not provided.');

        // If caching is enabled then the cache age must be greater than zero.
        } elseif ($cacheAge <= 0) {
            throw new \Exception('Caching enabled but the cache age is less than or equal to zero. The cache age must be greater than zero.');
        }

        // Create the cache directory if it's not exists.
        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0744, true);
        }

        // Possibly add the trailing slash to the cache directory name.
        if (substr($cacheDirectory, -1) !== '/') {
            $cacheDirectory .= '/';
        }

        $this->cacheDirectory = $cacheDirectory;
        $this->cacheAge = $cacheAge;
        $this->cookieFile = $cacheDirectory . 'cookies.txt';
    }

    /** Delete the cookie file. */
    public function __destruct()
    {
        if (!$this->cache) {
            unlink($this->cookieFile);
        }
    }

    /**
     * Set a user agent to use in a HTTP request.
     */
    public function setUserAgent(string $userAgent): void
    {
        $this->userAgent = trim($userAgent);
    }

    /**
     * Allow/disallow following redirection. A redirection number
     * ($maxRedirects) is unlimited by default. Assign "0" to disallow following
     * redirection.
     *
     * @param int $maxRedirects - a redirection limit amount.
     * @throws \UnexpectedValueException
     */
    public function followLocation(int $maxRedirects = -1): void
    {
        if ($maxRedirects < -1) {
            throw new \UnexpectedValueException('Argument must be grater than or equal to "-1", "' . $maxRedirects . '" given.');
        }

        $this->followLocation = ($maxRedirects !== 0);
        $this->maxRedirects = $maxRedirects;
    }

    /**
     * Validate given URL and return it otherwise throw an exception.
     *
     * @throws \UnexpectedValueException
     */
    public function validateUrl(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Argument must be a valid URL, "' . $url . '" given.');
        }

        return $url;
    }

    /**
     * Generate a file name for the given URL and the post fields.
     */
    public function generateFileName(string $url, string $postFields): string
    {
        return $this->cacheDirectory . sha1($url . $postFields); // file extension is omitted on purpose.
    }

    /**
     * Check is cached Web resource outdated or not.
     */
    public function cacheAvailable(string $cacheFileName): bool
    {
        return ($this->cache && file_exists($cacheFileName)
            && (time() - filemtime($cacheFileName) < $this->cacheAge));
    }

    /**
     * Save the HTTP response headers and return the number of bytes written. It
     * is used as a callback.
     */
    private function saveHeaders(\CurlHandle $ch, string $header): int
    {
        $length = strlen($header);
        $header = explode(':', $header, 2);

        if (count($header) < 2) {
            return $length;
        }

        $id = curl_getinfo($ch, CURLINFO_PRIVATE);

        $this->responseHeaders[$id][strtolower(trim($header[0]))] = trim($header[1]);

        return $length;
    }

    /**
     * Get encoded file, decode it, and return it as an array.
     *
     * @return mixed[] - with only "data" key.
     * @throws \Exception
     */
    private function getDecodedFile(string $fileName): array
    {
        if (!($fileContent = file_get_contents($fileName))) {
            throw new \Exception('Could not read file content.');
        }

        return [
            'data' => gzdecode($fileContent)
        ];
    }

    /**
     * Set up a cURL session and return it's handle.
     *
     * @param mixed[] $options - [$url, $referer, $postFields, $saveHeaders].
     */
    private function setUpCurlSession(array $options): \CurlHandle
    {
        // List all given options.
        [$url, $referer, $postFields, $saveHeaders] = $options;

        // Validate URLs.
        $this->validateUrl($url);

        if (empty($referer)) {
            $referer = $url;
        } else {
            $this->validateUrl($referer);
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
            CURLOPT_PRIVATE => sha1($url),
            CURLOPT_URL => $url,
            CURLOPT_REFERER => $referer,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_FOLLOWLOCATION => $this->followLocation,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
        ]);

        // Possibly set post data.
        if ($postFields) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
            ]);
        }

        // Possibly save HTTP response headers for further use.
        if ($saveHeaders) {
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this, 'saveHeaders']);
        }

        return $ch;
    }

    /**
     * If caching is enabled then return the cached Web resource, if the cached
     * Web resource is expired or does not exist then extract a Web resource by
     * its URL and return status code, response headers, data and total
     * execution time, and cache the Web resource.
     *
     * @param string $url
     * @param string $referer - if empty $url is used instead.
     * @param string $postFields
     * @param bool $saveHeaders - assign "true" to save HTTP response headers.
     * @param bool $bypassCaching - assign "true" to bypass caching.
     * @throws \Exception
     * @return mixed[] - [httpCode, headers, data, executionTime]|[data].
     */
    public function extractWebResource(
        string $url,
        string $referer,
        string $postFields,
        bool $saveHeaders,
        bool $bypassCaching = false
    ): array {
        if (!$bypassCaching) {
            $cacheFileName = $this->generateFileName($url, $postFields);

            // Possibly return the cached Web resource.
            if ($this->cacheAvailable($cacheFileName)) {
                return $this->getDecodedFile($cacheFileName);
            }
        }

        // Get current function's arguments and pass them into the following
        // function and extract a Web resource by its URL.
        $ch = $this->setUpCurlSession(func_get_args());
        $data = curl_exec($ch);

        if (false === $data) {
            throw new \Exception('Failed to extract a Web resource[' . $url . ']. cURL error: ' . curl_error($ch));
        }

        // Get necessary cURL transfer information and close the cURL session.
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $executionTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $id = curl_getinfo($ch, CURLINFO_PRIVATE);
        // curl_close($ch); // in PHP8, it is no longer has any effect.
        unset($ch);

        // Possibly cache the extracted Web resource.
        if (!$bypassCaching && $this->cache && $data && 200 === $httpCode) {
            file_put_contents($cacheFileName, $data);
        }

        return [
            'httpCode' => $httpCode,
            'headers' => ($this->responseHeaders[$id] ?? []),
            'data' => gzdecode((string) $data),
            'executionTime' => $executionTime,
        ];
    }

    /**
     * Does the same thing as the extractWebResource() function but for multiple
     * URLs at once. If $urlDetails is the list of URLs, without details, then
     * this function will always return the response headers.
     *
     * @param string[] $urlDetails - accepts list of URLs, list of URL details
     *     like [$url, $referer, $postFields, $saveHeaders] or mixed list.
     * @param string $baseUrl - base part of the URL like
     *     https://en.wikipedia.org, it will be prefixed to
     *     all URLs from the list. If it's empty string then you must provide
     *     full URLs in the $urlDetails parameter.
     * @param int $maxThreads - the number of parallel, asynchronous requests
     *     to be processed.
     * @throws \Exception
     * @return mixed[] - URL => [httpCode, headers, data, executionTime,
     *     curlError]|[data].
     */
    public function extractMultipleWebResources(array $urlDetails, string $baseUrl = '', int $maxThreads = 10): array
    {
        // Do nothing if $urlDetails contains less than 2 elements.
        $urlCount = count($urlDetails);

        if ($urlCount < 2) {
            throw new \Exception('The $urlDetails should contain more than 1 elements.');
        }

        // Validate the base URL and possibly add the trailing slash to it.
        if ($baseUrl) {
            $this->validateUrl($baseUrl);

            if (substr($baseUrl, -1) !== '/') {
                $baseUrl .= '/';
            }
        }

        $createUrlDetailsList = function ($details) {
            if (!is_array($details)) {
                $details = [$details, '', '', true];
            }

            return $details;
        };

        // It's needed to store and return the responses.
        $out = [];

        // Get cached HTML pages.
        if ($this->cache) {
            foreach ($urlDetails as $index => $details) {
                [$url, , $postFields] = $createUrlDetailsList($details);

                // Generate a cache file name.
                $cacheFileName = $this->generateFileName($baseUrl . $url, $postFields);

                // Possibly get the cached Web resources.
                if ($this->cacheAvailable($cacheFileName)) {
                    $out[$url] = $this->getDecodedFile($cacheFileName);

                    // Remove this URL from the list so it doesn't get processed.
                    unset($urlDetails[$index]);
                }
            }

            if (empty($urlDetails)) {
                return $out;
            }
        }

        // Initialize the necessary variables.
        $curlHandles = [];
        $curlHandlesMap = [];
        $mh = curl_multi_init();

        // Add a new cURL session.
        $addUrlToCurlMulti = function () use (&$urlDetails, &$curlHandles, &$curlHandlesMap, $baseUrl, $createUrlDetailsList, $mh) {
            $details = array_shift($urlDetails);
            [$url, $referer, $postFields] = $details = $createUrlDetailsList($details);

            // Merge base URL and other URLs.
            $details[0/* URL */] = $baseUrl . $url;
            if (!empty($referer)) {
                $details[1/* Referer */] = $baseUrl . $referer;
            }

            // Get a new cURL handle and add it into the multi-handle cURL object.
            $ch = $this->setUpCurlSession($details);
            $curlId = uniqid('', true);
            $curlHandles[$curlId] = [$url, $postFields];
            $curlHandlesMap[$curlId] = $ch;
            curl_multi_add_handle($mh, $ch);
        };

        // Set up a cURL session for URLs.
        for ($i = 0; $i < ($urlCount < $maxThreads ? $urlCount : $maxThreads); $i++) {
            $addUrlToCurlMulti();
        }

        // Execute the prepared cURL sessions.
        do {
            // Get status update.
            while (CURLM_CALL_MULTI_PERFORM === ($curlCode = curl_multi_exec($mh, $stillRunning)));

            // If no request has finished yet, keep looping.
            if (CURLM_OK !== $curlCode) {
                break;
            }

            // If a request was just completed, find out which one.
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $curlId = key(array_filter($curlHandlesMap, function ($value) use ($ch) {
                    return $value === $ch;
                }));

                // Get the necessary cURL transfer information.
                $curlError = curl_error($ch);
                $data = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $executionTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
                $id = curl_getinfo($ch, CURLINFO_PRIVATE);

                // If there are more URLs to process, add a new cURL session.
                if (!empty($urlDetails)) {
                    $addUrlToCurlMulti();
                }

                // Remove the handle that we finished processing. This needs to be done
                // AFTER we've already added a new URL for processing.
                curl_multi_remove_handle($mh, $ch);
                // curl_close($ch); // in PHP8, it is no longer has any effect.
                unset($ch);

                // Get the necessary request details and unset its association.
                [$url, $postFields] = $curlHandles[$curlId];
                unset($curlHandles[$curlId], $curlHandlesMap[$curlId]);

                // Possibly cache the Web resource.
                if ($this->cache && $data && 200 === $httpCode) {
                    $cacheFileName = $this->generateFileName($baseUrl . $url, $postFields);
                    file_put_contents($cacheFileName, $data);
                }

                // Add all necessary data into the output array.
                $out[$url] = [
                    'httpCode' => $httpCode,
                    'headers' => ($this->responseHeaders[$id] ?? []),
                    'data' => gzdecode($data),
                    'executionTime' => $executionTime,
                    'curlError' => $curlError,
                ];
            }

            // Waits until curl_multi_exec() returns CURLM_CALL_MULTI_PERFORM or until
            // the timeout, whatever happens first call usleep() if a select returns
            // -1 - workaround for PHP bug: https://bugs.php.net/bug.php?id=61141.
            if ($stillRunning && curl_multi_select($mh) === -1) {
                usleep(100);
            }

        // As long as there are threads running or requests waiting in the queue.
        } while ($stillRunning || !empty($curlHandles));

        // curl_multi_close($mh); // in PHP8, it is no longer has any effect.
        unset($mh);

        return $out;
    }
}
