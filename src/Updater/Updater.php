<?php

namespace MaxmindGeolocator\Updater;

use Concrete\Core\Application\Application;
use Concrete\Core\Cache\Cache;
use Concrete\Core\File\Service\VolatileDirectory;
use Concrete\Core\Http\Client\Client as HttpClient;
use MaxmindGeolocator\Exception\InvalidConfigurationArgument;
use MaxmindGeolocator\Exception\InvalidProductIdException;
use Zend\Http\Client\Exception\RuntimeException as ZendRuntimeException;
use Zend\Http\Header\ContentType;

/**
 * Updater.
 */
abstract class Updater
{
    /**
     * Duration of the cached items (in seconds).
     *
     * @var int
     */
    const CACHE_LIFETIME = 3600;

    /**
     * MD5 code to be used when the local database does not exist.
     *
     * @var string
     */
    const MD5_INEXISTING_FILE = '00000000000000000000000000000000';

    /**
     * The Application instance.
     *
     * @var \Concrete\Core\Application\Application
     */
    protected $application;

    /**
     * The updater configuration.
     *
     * @var \MaxmindGeolocator\Updater\Configuration
     */
    protected $configuration;

    /**
     * The cache to be used.
     *
     * @var \Concrete\Core\Cache\Cache|null
     */
    protected $cache;

    /**
     * Initialize the instance.
     *
     * @param \MaxmindGeolocator\Updater\Configuration $configuration the updater configuration
     * @param \Concrete\Core\Application\Application $application the Application instance
     */
    public function __construct(Configuration $configuration, Application $application)
    {
        $this->configuration = $configuration;
        $this->application = $application;
    }

    /**
     * Get the updater configuration.
     *
     * @return \MaxmindGeolocator\Updater\Configuration
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * Set the updater configuration.
     *
     * @param \MaxmindGeolocator\Updater\Configuration $configuration
     *
     * @return $this
     */
    public function setConfiguration(Configuration $configuration)
    {
        $this->configuration = $configuration;

        return $this;
    }

    /**
     * Get the cache to be used.
     *
     * @return \Concrete\Core\Cache\Cache|null
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * Set the cache to be used.
     *
     * @return $this
     */
    public function setCache(Cache $cache = null)
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Check if a GeoIP2 database needs to be updated: if so, update it.
     *
     * @throws \Concrete\Core\Error\UserMessageException in case of configuration problems
     * @throws \Zend\Http\Client\Exception\RuntimeException in case of HTTP communication problems
     *
     * @return bool Returns true if the database has been updated, false if the local copy is already up-to-date
     */
    abstract public function update();

    /**
     * Get the MaxMind filename for a specific product.
     *
     * @param string $productId The MaxMind product ID (for instance: 'GeoLite2-City')
     *
     * @throws \Zend\Http\Client\Exception\RuntimeException in case of HTTP communication problems
     *
     * @return string
     */
    public function getMaxmindFilename()
    {
        $productId = $this->configuration->getProductId();
        if ($productId === '') {
            throw new InvalidProductIdException($productId);
        }
        $cacheItem = null;
        if ($this->cache !== null) {
            $cacheItem = $this->cache->getItem('maxmind_geolocator.updater.maxmind_filename@' . $productId);
        }
        if ($cacheItem === null || $cacheItem->isMiss()) {
            $result = $this->performTextRequest('app/update_getfilename', 'product_id=' . rawurlencode($productId));
            if ($cacheItem !== null) {
                $cacheItem->set($result)->setTTL(static::CACHE_LIFETIME)->save();
            }
        } else {
            $result = $cacheItem->get();
        }

        return $result;
    }

    /**
     * Get the MD5 of the local database file.
     *
     * @throws \MaxmindGeolocator\Exception\InvalidConfigurationArgument
     *
     * @return string
     */
    protected function getCurrentLocalDatabaseMD5()
    {
        $filename = $this->configuration->getDatabasePath();
        if ($filename === '') {
            throw new InvalidConfigurationArgument('databasePath', $filename);
        }
        set_error_handler(static function () {}, -1);
        $isFile = @is_file($filename);
        restore_error_handler();
        if (!$isFile) {
            return static::MD5_INEXISTING_FILE;
        }
        set_error_handler(static function () {}, -1);
        $fileMD5 = @md5_file($filename);
        restore_error_handler();
        if ($fileMD5 === false) {
            throw new InvalidConfigurationArgument('databasePath', $filename);
        }

        return $fileMD5;
    }

    /**
     * Perform a request for a resource.
     *
     * @param string $path
     * @param string $querystring
     * @param string $saveToFilename
     * @param string[] $userAndPassword
     *
     * @throws \Zend\Http\Client\Exception\RuntimeException
     *
     * @return \Zend\Http\Response
     */
    protected function performRequest($path, $querystring = '', $saveToFilename = '', array $userAndPassword = [], callable $validHttpStatusCodeChecker = null)
    {
        $uri = 'https://' . $this->configuration->getHost() . '/' . ltrim($path, '/');
        if ($querystring !== '' && $querystring !== '?') {
            $uri .= '?' . ltrim($querystring, '?');
        }
        $httpClient = $this->application->make(HttpClient::class);
        $httpClient->reset();
        if ($userAndPassword !== []) {
            $httpClient->setAuth($userAndPassword[0], $userAndPassword[1]);
        }
        if ($saveToFilename) {
            $httpClient->setOptions([
                'storeresponse' => false,
                'outputstream' => $saveToFilename,
            ]);
        }
        $httpClient->setUri($uri);
        $response = $httpClient->send();
        if ($validHttpStatusCodeChecker === null) {
            $ok = $response->isSuccess();
        } else {
            $ok = $validHttpStatusCodeChecker($response->getStatusCode());
        }
        if (!$ok) {
            $failureReason = $response->getReasonPhrase();
            $contentType = $response->getHeaders()->get('Content-Type');
            if ($contentType instanceof ContentType && $contentType->getMediaType() === 'text/plain') {
                $s = trim($response->getBody());
                if ($s !== '') {
                    $failureReason = $s;
                }
            }
            throw new ZendRuntimeException($failureReason);
        }

        return $response;
    }

    /**
     * Perform a request for a text resource.
     *
     * @param string $path
     * @param string $querystring
     *
     * @throws \Zend\Http\Client\Exception\RuntimeException
     *
     * @return string
     */
    protected function performTextRequest($path, $querystring = '')
    {
        $response = $this->performRequest($path, $querystring);
        $contentType = $response->getHeaders()->get('Content-Type');
        if ($contentType instanceof ContentType && $contentType->getMediaType() !== 'text/plain') {
            throw new ZendRuntimeException(t('Invalid data received: %s', $contentType->getMediaType()));
        }

        return trim($response->getBody());
    }

    /**
     * Decode GZip-encode data to file.
     *
     * @param string $compressedFilename
     * @param string $uncompressedFilename
     */
    protected function decodeGzipFile($compressedFilename, $uncompressedFilename, VolatileDirectory $tmp)
    {
        if (!function_exists('gzopen')) {
            throw new \Exception('Missing ZLIB extension');
        }
        $compressedHandle = @fopen($compressedFilename, 'rb');
        if ($compressedHandle === false) {
            throw new \Exception('Failed to open gzip file');
        }
        $header = @fread($compressedHandle, 2);
        $gzipSize = 0;
        if (@fseek($compressedHandle, -4, SEEK_END) === 0) {
            $d = (string) @fread($compressedHandle, 4);
            if (strlen($d) === 4) {
                $d = @unpack('V', $d);
                if (is_array($d)) {
                    $gzipSize = array_shift($d);
                }
            }
        }
        @fclose($compressedHandle);
        if ($header !== "\x1F\x8B" || 0) {
            throw new \Exception('The downloaded data is not in gzip format');
        }
        $compressedHandle = @gzopen($compressedFilename, 'rb');
        if ($compressedHandle === false) {
            throw new \Exception('Failed to open gzip file');
        }
        $unzippedFilename = $tmp->getPath() . '/decompressed';
        $unzippedHandle = @fopen($unzippedFilename, 'wb');
        if ($unzippedHandle === false) {
            @gzclose($compressedHandle);
            throw new \Exception('Failed to create a temporary file');
        }
        while (($chunk = @gzread($compressedHandle, 4096)) !== '') {
            if (@fwrite($unzippedHandle, $chunk) === false) {
                @fclose($unzippedHandle);
                @gzclose($compressedHandle);
                throw new \Exception('Failed to write to a temporary file');
            }
        }
        @fclose($unzippedHandle);
        @gzclose($compressedHandle);
        if (@filesize($unzippedFilename) !== $gzipSize) {
            throw new \Exception('Decompressed data size mismatch');
        }
        if (@rename($unzippedFilename, $uncompressedFilename) !== true) {
            throw new \Exception('Failed to move decompressed file');
        }
    }
}
