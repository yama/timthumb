<?php

/**
 * TimThumb by Ben Gillbanks and Mark Maunder
 * Based on work done by Tim McDaniels and Darren Hoyt
 * https://code.google.com/archive/p/timthumb/
 *
 * GNU General Public License, version 2
 * https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * Examples and documentation available on the project homepage
 * https://www.binarymoon.co.uk/projects/timthumb/
 *
 */

// Do not execute the instance when it is being loaded from an external source
if (realpath(__FILE__) !== realpath(timthumb::server('SCRIPT_FILENAME'))) {
    return;
}

$timthumb = new timthumb();
$timthumb->start();

class timthumb
{
    public $version = '2.11.0'; // Version of this script (part of the cache key, so a new version regenerates the cache)

    public static $config = [
        'debug' => [
            'level'  => 0, // Debug level (written to error_log). 1 is less noisy, while 3 is the most verbose. 0 disables it
            'displayErrorMessages' => true, // Show error details in the error image. Set to false to show only a generic error image (good for production websites)
        ],
        'memoryLimit'   => '256M', // Minimum PHP memory limit while resizing (only raised, never lowered). GD needs roughly width x height x 5 bytes, so a 24MP photo needs ~120MB
        'maxConcurrentProcessing' => 4, // How many images may be resized at the same time on this server. Other requests wait instead of using memory and CPU. 0 = unlimited
        'processingWaitTimeout'   => 20, // Seconds a request may wait for other requests (the same image being generated, or a free processing slot) before giving up with an error image (503)
        'maxFileSize'   => 15728640, // 15 Megs is 15728640. This is the max internal or external file size that we'll process.
        'curlTimeout'   => 20, // Timeout in seconds for fetching external images. External images require the PHP curl extension.
        // User-Agent sent when fetching external images. A browser-like value is used because some image hosts reject unknown clients.
        'curlUserAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36',
        // Allowed external websites. Hosts must match exactly; prefix with a dot to also allow subdomains.
        // Example: ['img.youtube.com', '.example.com'] allows img.youtube.com, example.com and *.example.com
        // Only ports 80/443 are allowed unless the entry names a port, e.g. 'localhost:9000' (development).
        // Avoid allowing domains where anyone can create content (e.g. '.github.io', '.s3.amazonaws.com').
        'allowedSites'  => [],
        'allowPrivateIPs' => false, // Allow fetching from hosts that resolve to private/loopback/reserved IP addresses (e.g. an intranet image server)

        'browserCache' => [
            'maxAge' => 60*60*24*10, // Time to cache in the browser
            'enable' => true, // Use for testing if you want to disable all browser caching
        ],
        'fileCache' => [
            'enabled'           => true, // Should we store resized/modified images on disk to speed things up?
            'timeBetweenCleans' => 60*60*24, // How often the cache is cleaned
            'maxFileAge'        => 60*60*24*7, // How old does a file have to be to be deleted from the cache
            'suffix'            => '.cache', // What to put at the end of all files in the cache directory so we can identify them
            'prefix'            => 'timthumb', // What to put at the beg of all files in the cache directory so we can identify them
            'directory'         => __DIR__ . '/cache', // Directory where images are cached. Left blank it will use the system temporary directory (which is better for security)
        ],
        'maxWidth'      => 1920, // Maximum image width
        'maxHeight'     => 1920, // Maximum image height
        'default' => [
            'q'      => 85, // Default image quality.
            'zc'     => 1, // Default zoom/crop setting.
            'f'      => '', // Default image filters.
            's'      => 0, // Default sharpen value.
            'cc'     => 'ffffff', // Default canvas colour.
            'w'      => 200, // Default thumbnail width.
            'h'      => 200, // Default thumbnail height.
        ],
        'png' => [
            // Kept for compatibility with the original TimThumb (PNG_IS_TRANSPARENT), whose meaning is inverted:
            // false = the canvas behind PNGs is transparent (unless `ct=0` is given)
            // true  = the canvas behind PNGs is filled with the `cc` colour
            'isTransparent'   => false,
            'optipngEnabled'  => false,
            'optipngPath'     => '/usr/bin/optipng', //This will run first because it gives better compression than pngcrush.
            'pngcrushEnabled' => false,
            'pngcrushPath'    => '/usr/bin/pngcrush', //This will only run if `png.optipngPath` is not set or is not valid
        ],

        'waitBetweenFetchErrors' => 3600, // Time to wait between errors fetching remote file
        'blockExternalLeechers'  => false, // If the image is being loaded on an external site, display a red "No Hotlinking" gif.
        'notFoundImage'          => '', // Image to serve if any 404 occurs
        'errorImage'             => '', // Image to serve if an error occurs instead of the generated error image
    ];

    protected static $mergedConfig = null;

    protected $src             = '';
    protected $is404           = false;
    protected $lastURLError    = false;
    protected $docRoot         = null; // cached result of calcDocRoot()
    protected $localImagePath  = '';
    protected $localImageMTime = 0;
    protected $myHost          = '';
    protected $isExternalURL   = false;
    protected $cachefilePath   = '';
    protected $errors          = [];
    protected $toDeletes       = [];
    protected $cacheDirectory  = '';
    protected $lastBenchTime   = 0;
    protected $debugLevel      = 0;
    protected $isBusy          = false;
    protected $waitedSeconds   = 0.0;
    protected $sourceResolved  = false; // init() and resolveSource() succeeded
    // The cache key contains $version and this number. Increase it to force all cache files to be recreated.
    protected $fileCacheVersion = 1;
    //Designed to have three letter mime type, space, question mark and greater than symbol appended. 6 bytes total.
    protected $filePrependSecurityBlock = "<?php die('Execution denied!'); //";

    public static function mergedConfig() {
        $path = sprintf('%s/%s-config.php', __DIR__, basename(__FILE__, '.php'));
        if (is_file($path) && $customConfig = include $path) {
            return static::mergeConfig(static::$config, $customConfig);
        }
        return static::$config;
    }

    private static function mergeConfig($default, $custom) {
        foreach ($custom as $key => $value) {
            if (is_array($value) && isset($default[$key]) && is_array($default[$key])) {
                $default[$key] = static::mergeConfig($default[$key], $value);
            } else {
                $default[$key] = $value;
            }
        }
        return $default;
    }

    /**
     * Does nothing: the request is read by start() (or run()), not when the object is created.
     * Kept so that subclasses written for the old constructor can still call parent::__construct().
     */
    public function __construct()
    {
    }

    /**
     * Check the environment and read the requested `src`.
     * @return bool false when an error was recorded
     */
    protected function init()
    {
        if (!extension_loaded('gd')) {
            return $this->error('GD extension is not loaded');
        }

        $this->debugLevel = (int) self::config('debug.level');
        if ($this->debugLevel >= 1) {
            $this->debug(1, sprintf('Starting new request from %s to %s', $this->getClientIP(), self::server('REQUEST_URI')));
        }
        $this->cacheDirectory = $this->getCacheDirectory();
        if (!$this->cacheDirectory) {
            // Without a cache directory there is nowhere to write the resized image (or temporary files).
            return false;
        }

        // Clean the cache after the response has been sent, so that no visitor has to wait for it.
        register_shutdown_function([$this, 'cleanCache']);

        $this->myHost = preg_replace('@^www\.@i', '', self::server('HTTP_HOST'));
        $this->src    = self::param('src');
        if ($this->myHost !== '') {
            // Strip our own host so that full URLs to this site are treated as local files
            $this->src = preg_replace(
                '@^https?://(?:www\.)?' . preg_quote($this->myHost, '@') . '(?=/|$)@i',
                '',
                $this->src
            );
        }

        if (strlen($this->src) <= 3) {
            return $this->error('No image specified');
        }
        return true;
    }

    /**
     * Decide whether `src` is an external URL or a local file, check that it may be served
     * and work out the cache file path.
     * @return bool false when an error was recorded
     */
    protected function resolveSource()
    {
        if (preg_match('@^https?://[^/]+@i', $this->src)) {
            $this->debug(2, 'Is a request for an external URL: ' . $this->src);
            $this->isExternalURL = true;
            if (!$this->isExternalImageAllowed()) {
                return false;
            }
        } else {
            $this->debug(2, 'Is a request for an internal file: ' . $this->src);
        }
        // The salt does not use the mtime of this file, so that redeploying the same version keeps the cache.
        $salt = $this->version . '-';

        if ($this->isExternalURL) {
            $this->cachefilePath = $this->generateCacheFilePath(
                $salt . $this->cacheKeyParams() . $this->fileCacheVersion
            );
            return $this->sourceResolved = true;
        }

        $this->localImagePath = $this->getLocalImagePath($this->src);
        if (!$this->localImagePath) {
            $this->debug(1, 'Could not find the local image: ' . $this->src);
            $this->set404();
            return $this->error('Could not find the internal image you specified.');
        }

        $this->debug(1, 'Local image path is ' . $this->localImagePath);
        $this->localImageMTime = @filemtime($this->localImagePath);
        //We include the mtime of the local file in case in changes on disk.
        $this->cachefilePath = $this->generateCacheFilePath(
            $salt . $this->localImageMTime . $this->localImagePath . $this->cacheKeyParams() . $this->fileCacheVersion
        );
        return $this->sourceResolved = true;
    }

    /**
     * Build the part of the cache key that depends on the request and on the settings that change the output.
     * Only parameters that affect the output are used, so that arbitrary
     * extra query parameters can not be used to fill the cache directory.
     */
    protected function cacheKeyParams()
    {
        $params = [];
        foreach (['src', 'w', 'h', 'zc', 'q', 'a', 'f', 's', 'cc', 'ct'] as $key) {
            $params[$key] = self::param($key, null);
        }
        $params['config'] = [
            self::config('default'),
            self::config('png'),
            self::config('maxWidth'),
            self::config('maxHeight'),
        ];
        return serialize($params);
    }

    public function __destruct()
    {
        foreach ($this->toDeletes as $del) {
            $this->debug(2, 'Deleting temp file ' . $del);
            @unlink($del);
        }
    }

    /**
     * Handle the request and send a response: the image, a 304, or an error image.
     */
    public function start()
    {
        if (!$this->handleRequest()) {
            $this->handleErrors();
        }
    }

    /**
     * @return bool true when a response has been sent, false when errors were recorded instead
     */
    protected function handleRequest()
    {
        if (!$this->init()) {
            return false;
        }
        if ($this->isHotlinkBlocked()) {
            $this->serveHotlinkImage();
            return true;
        }
        if (!$this->resolveSource()) {
            return false;
        }
        if ($this->tryBrowserCache()) {
            return true;
        }
        if (self::config('fileCache.enabled') && $this->tryServerCache()) {
            return true;
        }
        if ($this->errors) {
            // tryServerCache() found a recent failure to fetch this external image
            return false;
        }
        return $this->run();
    }

    /**
     * Create the resized image (or wait for another request that is creating it) and send it.
     * Public for compatibility with code that calls `(new timthumb())->run()` directly (the request used to be
     * read in the constructor, which also sent the hotlink image); in that case the request is read here first.
     * Errors are recorded, not sent.
     * @return bool true when a response (the image or the hotlink image) has been sent
     */
    public function run()
    {
        if (!$this->sourceResolved) {
            if (!$this->init()) {
                return false;
            }
            if ($this->isHotlinkBlocked()) {
                $this->serveHotlinkImage();
                return true;
            }
            if (!$this->resolveSource()) {
                return false;
            }
        }

        // Only one request generates a given cache file; concurrent requests for the same image wait here
        // and then serve the result, instead of all decoding (or downloading) the same image at once.
        $lockFile = $this->cachefilePath . '.lock';
        $lock     = $this->acquireLock($lockFile);
        if (!$lock) {
            return $this->error($this->isBusy
                ? 'The server is busy processing other images. Please try again.'
                : 'Could not get a lock for writing an image.');
        }
        clearstatcache();
        if (self::config('fileCache.enabled') && is_file($this->cachefilePath)) {
            $this->debug(3, 'The cache file was created while waiting for the lock.');
            if ($this->tryServerCache()) {
                $this->releaseLock($lock, $lockFile);
                return true;
            }
            if ($this->errors) {
                $this->releaseLock($lock, $lockFile);
                return false;
            }
        }

        if ($this->isExternalURL) {
            $this->debug(3, 'Got request for external image. Starting generateExternalImage.');
            $image = $this->generateExternalImage();
        } else {
            $this->debug(3, 'Got request for internal image. Starting generateInternalImage()');
            $image = $this->generateInternalImage();
        }
        $this->releaseLock($lock, $lockFile);

        if (!$image) {
            return false;
        }
        $this->sendImageHeaders($image['type'], strlen($image['data']), $this->cacheFileMTime());
        echo $image['data'];
        return true;
    }

    /**
     * Open and exclusively lock a lock file (blocking). The holder deletes the file in releaseLock(),
     * so a lock taken on a file that was deleted in the meantime is retried on the new file.
     * A retry only happens after another request has finished with the lock, so this always makes progress.
     * Waiting is limited by waitFor(); after that the request fails as busy (503).
     * @return resource|false
     */
    protected function acquireLock($path)
    {
        for ($try = 0; $try < 1000; $try++) {
            $fh = @fopen($path, 'c');
            if (!$fh) {
                return false;
            }
            $locked = $this->waitFor(function () use ($fh) {
                return flock($fh, LOCK_EX | LOCK_NB);
            });
            if (!$locked) {
                fclose($fh);
                $this->isBusy = true;
                return false;
            }
            $locked = fstat($fh);
            clearstatcache(true, $path);
            $current = @stat($path);
            if ($current && $locked['ino'] === $current['ino'] && $locked['dev'] === $current['dev']) {
                return $fh;
            }
            fclose($fh);
        }
        return false;
    }

    /**
     * Call $attempt until it returns something other than false, sleeping in between.
     * The time spent waiting here (for the same image being generated, or a free processing slot) is added up per
     * request and limited to `processingWaitTimeout` in total; the request's own work (e.g. downloading) is not counted.
     * @return mixed the result of $attempt, or false when the waiting time ran out
     */
    protected function waitFor(callable $attempt)
    {
        $start = microtime(true);
        $left  = max(0, (float) self::config('processingWaitTimeout')) - $this->waitedSeconds;
        while (true) {
            $result = $attempt();
            $waited = microtime(true) - $start;
            if ($result !== false || $waited >= $left) {
                $this->waitedSeconds += $waited;
                return $result;
            }
            usleep(mt_rand(20000, 60000));
        }
    }

    protected function releaseLock($fh, $path = null)
    {
        if (!$fh) {
            return;
        }
        if ($path !== null) {
            @unlink($path); // before unlocking, so that waiting requests notice that the file was replaced
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /**
     * Limit how many images are decoded and resized at the same time (`maxConcurrentProcessing`),
     * so that a page with dozens of new thumbnails does not make dozens of PHP workers use ~`memoryLimit` each.
     * @return resource|null|false a slot lock, null when there is no limit, false when no slot became free in time
     */
    protected function acquireProcessingSlot()
    {
        $max = (int) self::config('maxConcurrentProcessing');
        if ($max <= 0) {
            return null;
        }
        $handles  = [];
        for ($i = 0; $i < $max; $i++) {
            $handles[$i] = @fopen(sprintf('%s/%s_slot_%d.lock', $this->cacheDirectory, self::config('fileCache.prefix'), $i), 'c');
        }
        if (!array_filter($handles)) {
            $this->debug(1, 'Could not create the processing slot files, so processing without a limit.');
            return null;
        }
        $slot = $this->waitFor(function () use ($handles) {
            foreach ($handles as $i => $fh) {
                if ($fh && flock($fh, LOCK_EX | LOCK_NB)) {
                    return $i;
                }
            }
            return false;
        });
        foreach ($handles as $i => $fh) {
            if ($fh && $i !== $slot) {
                fclose($fh);
            }
        }
        if ($slot === false) {
            return false;
        }
        $this->debug(3, "Got processing slot $slot");
        return $handles[$slot];
    }

    protected function generateCacheFilePath($seedString) {
        $cachefilePath = sprintf(
            '%s/%s%s%s%s',
            $this->cacheDirectory,
            self::config('fileCache.prefix'),
            $this->isExternalURL ? '_ext_' : '_int_',
            md5($seedString),
            self::config('fileCache.suffix')
        );
        $this->debug(2, 'Cache file is: ' . $cachefilePath);
        return $cachefilePath;
    }

    protected function isExternalImageAllowed() {
        // `allowExternal` => false (the original TimThumb's ALLOW_EXTERNAL) turns external images off regardless of `allowedSites`.
        if (!self::config('allowedSites') || !self::config('allowExternal', true)) {
            $this->error('You are not allowed to fetch images from an external website.');
            return false;
        }

        // Without curl the checked address can not be pinned (DNS rebinding), so external images are not supported.
        if (!extension_loaded('curl')) {
            $this->error('External images require the PHP curl extension, which is not loaded.');
            return false;
        }

        if ($this->isAllowedSiteUrl($this->src)) {
            return true;
        }

        $this->error(
            'You may not fetch images from that site. To enable this site in timthumb, you can either add it to `allowedSites`.'
        );
        return false;
    }

    /**
     * Whether the URL is http(s) on the default port and its host is allowed by `allowedSites`.
     * An entry matches the host exactly; an entry starting with a dot ('.example.com')
     * also matches example.com and all of its subdomains.
     */
    protected function isAllowedSiteUrl($url)
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $givenSite = strtolower($parts['host']);
        $givenPort = isset($parts['port'])
            ? (int) $parts['port']
            : (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        foreach (self::config('allowedSites') as $allowedSite) {
            $allowedSite = strtolower(trim($allowedSite));
            // An entry may specify a port ('localhost:9000'); otherwise only the standard ports 80/443 are allowed.
            $allowedPorts = [80, 443];
            if (preg_match('/^(.+):(\d+)$/', $allowedSite, $m) && strpos($m[1], ':') === false) {
                $allowedSite  = $m[1];
                $allowedPorts = [(int) $m[2]];
            }
            if ($allowedSite === '' || $allowedSite === '.') {
                continue;
            }
            if (!in_array($givenPort, $allowedPorts, true)) {
                continue;
            }
            if ($allowedSite[0] === '.') {
                $domain = substr($allowedSite, 1);
                $isMatch = $givenSite === $domain || substr($givenSite, -strlen($allowedSite)) === $allowedSite;
            } else {
                $isMatch = $givenSite === $allowedSite;
            }
            if ($isMatch) {
                $this->debug(3, sprintf('URL hostname %s matches %s so allowing.', $givenSite, $allowedSite));
                return true;
            }
        }
        return false;
    }

    /**
     * Rebuild the URL from the parts parse_url() sees, so that the HTTP client connects to exactly
     * the host that was checked (URL parsers disagree on inputs containing "\", "@", "#" etc.).
     * @return string|false
     */
    protected function buildSafeUrl($url)
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host   = strtolower($parts['host']);
        $isIpv6 = $host[0] === '[' && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        if (!in_array($scheme, ['http', 'https'], true) || (!$isIpv6 && !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host))) {
            return false;
        }
        $path  = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $path . $query)) {
            $path  = str_replace(' ', '%20', $path);
            $query = str_replace(' ', '%20', $query);
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $path . $query)) {
                return false;
            }
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port . ($path[0] === '/' ? '' : '/') . $path . $query;
    }

    /**
     * Resolve the host of a URL and make sure it does not point to a private/reserved address.
     * @return string|null|false the IP to connect to, null when the check is disabled, false when blocked
     */
    protected function resolvePublicIp($url)
    {
        if (self::config('allowPrivateIPs')) {
            return null;
        }
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = @gethostbynamel($host) ?: [];
            $aaaa = function_exists('dns_get_record') ? @dns_get_record($host, DNS_AAAA) : [];
            foreach ($aaaa ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if (!$ips) {
            return false;
        }
        // Every address must be public, otherwise a round-robin record could still reach an internal host.
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                $this->debug(1, sprintf('Blocked external URL: %s resolves to non-public address %s', $host, $ip));
                return false;
            }
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip; // prefer IPv4
            }
        }
        return $ips[0];
    }

    /**
     * Whether an IP address is publicly routable.
     * IPv6 addresses that embed an IPv4 address (IPv4-mapped, IPv4-compatible, NAT64) are checked as that IPv4 address,
     * and ranges the filter flags do not cover on every PHP version (e.g. CGNAT 100.64.0.0/10) are blocked explicitly.
     */
    protected function isPublicIp($ip)
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16) {
            $prefix = substr($bin, 0, 12);
            if ($prefix === str_repeat("\0", 10) . "\xff\xff"                   // ::ffff:a.b.c.d
                || $prefix === str_repeat("\0", 12)                             // ::a.b.c.d (also :: and ::1)
                || $prefix === "\x00\x64\xff\x9b" . str_repeat("\0", 8)        // 64:ff9b::a.b.c.d (NAT64)
            ) {
                $bin = substr($bin, 12);
                $ip  = inet_ntop($bin);
            }
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            $flags |= FILTER_FLAG_GLOBAL_RANGE; // PHP 8.2+
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return false;
        }

        $blocked = strlen($bin) === 4
            ? ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
               '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4']
            : ['::/127', 'fc00::/7', 'fe80::/10', 'ff00::/8'];
        foreach ($blocked as $cidr) {
            list($net, $bits) = explode('/', $cidr);
            $netBin = inet_pton($net);
            $bytes  = intdiv((int) $bits, 8);
            $rest   = (int) $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return false;
            }
            $mask = (0xff << (8 - $rest)) & 0xff;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    protected function getCacheDirectory() {
        if (!self::config('fileCache.directory')) {
            return sys_get_temp_dir();
        }

        if (!is_dir(self::config('fileCache.directory'))) {
            @mkdir(self::config('fileCache.directory'));
            if (!is_dir(self::config('fileCache.directory'))) {
                $this->error('Could not create the file cache directory.');
                return false;
            }
        }
        if (!is_writable(self::config('fileCache.directory'))) {
            $this->error('The file cache directory is not writable.');
            return false;
        }

        if (self::config('fileCache.directory') !== sys_get_temp_dir()) {
            $indexFile = self::config('fileCache.directory') . '/index.html';
            if (!is_file($indexFile) && !touch($indexFile)) {
                $this->error(
                    'Could not create the index.html file - to fix this create an empty file named index.html file in the cache directory.'
                );
                return false;
            }
            if (!$this->createHtaccessFile()) {
                $this->error("Could not create the .htaccess file in the cache directory..");
                return false;
            }
        }

        return self::config('fileCache.directory');
    }

    protected function createHtaccessFile()
    {
        $htaccessFilePath = self::config('fileCache.directory') . '/.htaccess';
        if (is_file($htaccessFilePath)) {
            return true;
        }
        $htaccessContent =
"# Disable directory browsing
Options -Indexes
# Deny access to all files in this directory
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
";

        return (bool) file_put_contents($htaccessFilePath, $htaccessContent);
    }

    /**
     * Send the recorded errors: the configured not-found / error image, a generated error image, or as a last resort HTML.
     */
    protected function handleErrors()
    {
        if (!$this->errors) {
            return;
        }
        if (headers_sent()) {
            // Part of an image has already been sent; nothing sensible can be added to the response.
            $this->debug(1, 'Errors after the response was started: ' . implode(' / ', $this->errors));
            return;
        }

        $status = $this->is404() ? 404 : ($this->isBusy ? 503 : 400);

        if (self::config('notFoundImage') && $this->is404()) {
            if ($this->serveImg($this->resolveConfiguredImage(self::config('notFoundImage')), $status)) {
                return;
            }

            $this->error(
                'Additionally, the 404 image that is configured could not be found or there was an error serving it.'
            );
        }

        if (self::config('errorImage')) {
            if ($this->serveImg($this->resolveConfiguredImage(self::config('errorImage')), $status)) {
                return;
            }

            $this->error(
                'Additionally, the error image that is configured could not be found or there was an error serving it.'
            );
        }

        // timthumb is usually embedded with <img>, where an HTML error page is invisible, so draw the errors as an image.
        if ($this->serveErrorImage($status)) {
            return;
        }

        // GD is not available: fall back to a plain HTML error page.
        http_response_code($status);
        if (!self::config('debug.displayErrorMessages')) {
            return;
        }
        $html = '<ul>';
        foreach ($this->errors as $err) {
            $html .= '<li>' . htmlentities($err) . '</li>';
        }
        $html .= '</ul>';
        echo '<h1>A TimThumb error has occured</h1>';
        echo 'The following error(s) occured:<br />' . $html . '<br />';
        echo '<br />Query String : ' . htmlentities(self::server('QUERY_STRING'), ENT_QUOTES);
        echo '<br />TimThumb version : ' . $this->version;
    }

    /**
     * Draw the collected errors into a PNG and send it.
     * With `debug.displayErrorMessages` off, only a generic message is drawn so no details leak.
     * The image uses the requested `w` / `h` (within `maxWidth` / `maxHeight`) so the page layout is kept.
     */
    protected function serveErrorImage($status)
    {
        if (!extension_loaded('gd') || !function_exists('imagepng')) {
            return false;
        }

        $font       = 2;
        $charWidth  = imagefontwidth($font);
        $lineHeight = imagefontheight($font) + 2;
        $padding    = 6;

        $reqWidth  = (int) self::param('w', 0);
        $reqHeight = (int) self::param('h', 0);
        $width     = min($reqWidth > 0 ? max(16, $reqWidth) : 400, max(16, (int) self::config('maxWidth')));

        if (self::config('debug.displayErrorMessages')) {
            $messages = array_merge(['TimThumb error:'], $this->errors);
        } else {
            $messages = ['Image error'];
        }
        $perLine = max(1, intdiv($width - 2 * $padding, $charWidth));
        $lines   = [];
        foreach ($messages as $message) {
            // The built-in GD font only covers ASCII.
            $message = preg_replace('/[^\x20-\x7e]/', '?', trim((string) $message));
            foreach (explode("\n", wordwrap($message, $perLine, "\n", true)) as $line) {
                $lines[] = $line;
            }
        }

        $textHeight = count($lines) * $lineHeight + 2 * $padding;
        $height     = min(
            $reqHeight > 0 ? max(16, $reqHeight) : max(100, $textHeight),
            max(16, (int) self::config('maxHeight'))
        );

        // The error may itself be caused by low memory, so do not risk a fatal error while drawing it.
        if (!$this->hasEnoughMemoryFor(0, 0, $width, $height)) {
            return false;
        }

        $image = imagecreatetruecolor($width, $height);
        if (!$image) {
            return false;
        }
        $background = imagecolorallocate($image, 0xf8, 0xd7, 0xda);
        $border     = imagecolorallocate($image, 0xc0, 0x39, 0x2b);
        $text       = imagecolorallocate($image, 0x72, 0x1c, 0x24);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
        imagerectangle($image, 0, 0, $width - 1, $height - 1, $border);
        $y = $padding;
        foreach ($lines as $line) {
            if ($y + $lineHeight > $height - $padding + 2) {
                break;
            }
            imagestring($image, $font, $padding, $y, $line, $text);
            $y += $lineHeight;
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        if ($png === false || $png === '') {
            return false;
        }

        http_response_code($status);
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo $png;
        return true;
    }

    /**
     * `notFoundImage` / `errorImage` may be an absolute filesystem path or a path under the document root.
     */
    protected function resolveConfiguredImage($path)
    {
        if (is_file($path)) {
            return $path;
        }
        $docRoot = $this->calcDocRoot();
        if ($docRoot && is_file($docRoot . '/' . ltrim($path, '/'))) {
            return $docRoot . '/' . ltrim($path, '/');
        }
        return false;
    }

    protected function tryBrowserCache()
    {
        if (!self::config('browserCache.enable')) {
            $this->debug(3, 'Browser caching is disabled');
            return false;
        }

        //We've already checked if the real file exists in the constructor
        if (!is_file($this->cachefilePath)) {
            //If we don't have something cached, regenerate the cached image.
            return false;
        }

        $mtime = @filemtime($this->cachefilePath);
        $this->debug(3, sprintf("Cached file's modification time is %s", $mtime));

        if (!$mtime) return false;

        $etag = sprintf('"%s"', $mtime);
        if ($etag === self::server('HTTP_IF_NONE_MATCH')) {
            $this->debug(1, 'Returning 304 not modified');
            $this->debug(3, 'File has not been modified since last get, so serving a 304.');
            http_response_code(304);
            header(sprintf('ETag: %s', $etag));
            return true;
        }
        header(sprintf('ETag: %s', $etag));
        return false;
    }

    protected function tryServerCache()
    {
        $this->debug(3, 'Trying server cache');
        if (!is_file($this->cachefilePath)) {
            return false;
        }

        $this->debug(3, sprintf('Cachefile %s exists', $this->cachefilePath));
        if ($this->isExternalURL) {
            $this->debug(
                3,
                'This is an external request, so checking if the cachefile is empty which means the request failed previously.'
            );
            if (filesize($this->cachefilePath) < 1) {
                $this->debug(3, 'Found an empty cachefile indicating a failed earlier request. Checking how old it is.');
                //Fetching error occured previously
                if (self::now() - @filemtime($this->cachefilePath) > self::config('waitBetweenFetchErrors')) {
                    $this->debug(
                        3,
                        sprintf(
                            'File is older than %d seconds. Deleting and returning false so app can try and load file.',
                            self::config('waitBetweenFetchErrors')
                        )
                    );
                    @unlink($this->cachefilePath);
                    return false; //to indicate we didn't serve from cache and app should try and load
                }

                $this->debug(3, 'Empty cachefile is still fresh so returning message saying we had an error fetching this image from remote host.');
                $this->set404();
                $this->error('An error occured fetching image.');
                return false;
            }
        } else {
            $this->debug(3, sprintf(
                'Trying to serve cachefile %s',
                $this->cachefilePath
            ));
        }
        if ($this->serveCacheFile()) {
            $this->debug(3, 'Succesfully served cachefile ' . $this->cachefilePath);
            return true;
        }

        $this->debug(
            3,
            "Failed to serve cachefile " . $this->cachefilePath . " - Deleting it from cache."
        );
        // Nothing has been sent yet, so remove the broken (or vanished) cache file and let the image be recreated.
        @unlink($this->cachefilePath);
        return false;
    }

    /**
     * @return array|false ['type' => 'jpg'|'png'|'gif', 'data' => image bytes]
     */
    protected function generateInternalImage()
    {
        $this->debug(3, "Local image path is $this->localImagePath");
        $fileSize = filesize($this->localImagePath);
        if ($fileSize > self::config('maxFileSize')) {
            return $this->error("The file you specified is greater than the maximum allowed file size.");
        }
        if ($fileSize <= 0) {
            return $this->error("The file you specified is <= 0 bytes.");
        }
        $this->debug(3, "Calling processImageAndWriteToCache() for local image.");
        return $this->processImageAndWriteToCache($this->localImagePath);
    }

    /**
     * Delete cache files older than `fileCache.maxFileAge`, at most once per `fileCache.timeBetweenCleans`.
     * Runs as a shutdown function: when cleaning is due, the response is finished first so that the visitor does not wait.
     */
    public function cleanCache()
    {
        if (!$this->cacheDirectory || self::config('fileCache.timeBetweenCleans') < 0) {
            return false;
        }

        $this->debug(3, "cleanCache() called");
        $lastCleanFile = $this->cacheDirectory . '/timthumb_cacheLastCleanTime.touch';

        //If this is a new timthumb installation we need to create the file
        if (!is_file($lastCleanFile)) {
            $this->debug(1, "File tracking last clean doesn't exist. Creating $lastCleanFile");
            if (!@touch($lastCleanFile)) {
                $this->debug(1, "Could not create cache clean timestamp file.");
            }
            return false;
        }
        if (@filemtime($lastCleanFile) >= (self::now() - self::config('fileCache.timeBetweenCleans'))) {
            $this->debug(3, "Cache was cleaned less than " . self::config('fileCache.timeBetweenCleans') . " seconds ago so no cleaning needed.");
            return false;
        }

        $this->debug(
            1,
            sprintf(
                "Cache was last cleaned more than %d seconds ago. Cleaning now.",
                self::config('fileCache.timeBetweenCleans')
            )
        );
        // Very slight race condition here, but worst case we'll have 2 or 3 servers cleaning the cache simultaneously once a day.
        if (!@touch($lastCleanFile)) {
            $this->debug(1, "Could not update cache clean timestamp file.");
            return false;
        }

        // Send the response to the visitor before the (possibly slow) directory scan.
        ignore_user_abort(true);
        while (ob_get_level() > 0 && @ob_end_flush()) {
            // send PHP's output buffers (e.g. `output_buffering`) first
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            flush();
        }

        // readdir() instead of glob(), so that a large cache directory is not loaded into memory at once.
        $dh = @opendir($this->cacheDirectory);
        if (!$dh) {
            return false;
        }
        // Cache files (and lock files left by a crashed request) are named <prefix>_int_<md5><suffix>[.lock]
        // (or _ext_); matching the whole name also works with an empty suffix and leaves other files alone,
        // including those of another installation that uses a different prefix in a shared directory.
        $cachePattern = '/^' . preg_quote((string) self::config('fileCache.prefix'), '/')
            . '_(?:int|ext)_[0-9a-f]{32}' . preg_quote((string) self::config('fileCache.suffix'), '/') . '(\.lock)?$/';
        $timeAgo      = self::now() - self::config('fileCache.maxFileAge');
        while (($entry = readdir($dh)) !== false) {
            $isCacheFile = preg_match($cachePattern, $entry, $m) === 1;
            if (!$isCacheFile && strpos($entry, 'timthumb_tmpimg_') !== 0) { // temporary files left by a crashed request
                continue;
            }
            $file = $this->cacheDirectory . '/' . $entry;
            if (@filemtime($file) >= $timeAgo) {
                continue;
            }
            $this->debug(3, "Deleting cache file $file older than max age");
            if ($isCacheFile && !empty($m[1])) {
                $this->deleteUnusedLockFile($file);
            } else {
                @unlink($file);
            }
        }
        closedir($dh);
        return true;
    }

    /**
     * Delete a lock file only if nobody holds it, in the same way releaseLock() does,
     * so that cleaning never lets two requests generate the same image at once.
     */
    protected function deleteUnusedLockFile($file)
    {
        $fh = @fopen($file, 'r');
        if (!$fh) {
            return;
        }
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            // The file may have been replaced since it was opened (released and created again by another
            // request); only delete it if the path still points to the file that is locked here.
            $locked = fstat($fh);
            clearstatcache(true, $file);
            $current = @stat($file);
            if ($current && $locked['ino'] === $current['ino'] && $locked['dev'] === $current['dev']) {
                @unlink($file);
            }
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    // zc (zoom/crop) modes
    const ZC_RESIZE        = 0; // Resize to the exact size, ignoring the aspect ratio
    const ZC_CROP          = 1; // Crop and resize to fill the size (default)
    const ZC_FIT_BORDERS   = 2; // Resize to fit inside the size and fill the rest with the canvas colour
    const ZC_FIT_NO_BORDER = 3; // Resize to fit inside the size; the canvas shrinks to the image

    // f (filter) IDs => [GD filter constant, number of arguments]
    const FILTERS = [
        1  => ['IMG_FILTER_NEGATE', 0],
        2  => ['IMG_FILTER_GRAYSCALE', 0],
        3  => ['IMG_FILTER_BRIGHTNESS', 1],
        4  => ['IMG_FILTER_CONTRAST', 1],
        5  => ['IMG_FILTER_COLORIZE', 4],
        6  => ['IMG_FILTER_EDGEDETECT', 0],
        7  => ['IMG_FILTER_EMBOSS', 0],
        8  => ['IMG_FILTER_GAUSSIAN_BLUR', 0],
        9  => ['IMG_FILTER_SELECTIVE_BLUR', 0],
        10 => ['IMG_FILTER_MEAN_REMOVAL', 0],
        11 => ['IMG_FILTER_SMOOTH', 1],
    ];

    /**
     * Resize the image, store it in the cache file and return it.
     * The caller must hold the lock of the cache file.
     * @param array|null $sData the result of getimagesize() if the caller already has it
     * @return array|false ['type' => 'jpg'|'png'|'gif', 'data' => image bytes]
     */
    protected function processImageAndWriteToCache($localImage, $sData = null)
    {
        $sData = $sData ?: @getimagesize($localImage);
        if (!$sData) {
            return $this->error("The image being resized is not a valid gif, jpg or png.");
        }
        [$origWidth, $origHeight, $origType] = $sData;
        $mimeType = $sData['mime'] ?? '';

        $this->debug(3, "Mime type of image is $mimeType");
        $imgType = $this->imageTypeFromMime($mimeType);
        if (!$imgType) {
            return $this->error("The image being resized is not a valid gif, jpg or png.");
        }

        $options = $this->readResizeOptions($origWidth, $origHeight);

        // set memory limit to be able to have enough space to resize larger images
        $this->setMemoryLimit();

        // Refuse images that would not fit in memory once decoded (e.g. a small file with huge dimensions),
        // instead of dying with a fatal "Allowed memory size exhausted" error.
        if (!$this->hasEnoughMemoryFor($origWidth, $origHeight, $options['width'], $options['height'])) {
            return $this->error('The image is too large to process (not enough memory).');
        }

        // Wait for a processing slot, so that only a few images are decoded at the same time.
        $slot = $this->acquireProcessingSlot();
        if ($slot === false) {
            $this->isBusy = true;
            return $this->error('The server is busy processing other images. Please try again.');
        }

        $image = $this->openImage($imgType, $localImage);
        if ($image === false) {
            $this->releaseLock($slot);
            return $this->error('Unable to open image.');
        }

        // Encode into memory: the result is written to the cache once and served from memory.
        $data = $this->renderImage($image, $imgType, $origType, $options);
        // Free the decoded image and the slot before the (slower) file operations.
        $image = null;
        $this->releaseLock($slot);
        if ($data === false || $data === '') {
            return $this->error('Could not encode the image.');
        }

        if ($imgType === 'png' && (self::config('png.optipngEnabled') || self::config('png.pngcrushEnabled'))) {
            $data = $this->optimizePng($data);
        }

        if (!$this->writeCacheFile($imgType, $data)) {
            return false;
        }
        return ['type' => $imgType, 'data' => $data];
    }

    /**
     * Read the resize options from the query string (falling back to `default.*`).
     * The width and height are limited to the original size and to `maxWidth` / `maxHeight`; 0 means "keep the aspect ratio".
     */
    protected function readResizeOptions($origWidth, $origHeight)
    {
        $width  = abs((int) self::param('w', 0));
        $height = abs((int) self::param('h', 0));
        // set default width and height if neither are set already
        if ($width == 0 && $height == 0) {
            $width  = (int) self::config('default.w');
            $height = (int) self::config('default.h');
        }

        return [
            // ensure size limits can not be abused
            'width'             => (int) min($origWidth, $width, self::config('maxWidth')),
            'height'            => (int) min($origHeight, $height, self::config('maxHeight')),
            'zoomCrop'          => (int) self::param('zc', self::config('default.zc')),
            'quality'           => max(0, min(100, abs((int) self::param('q', self::config('default.q'))))),
            'align'             => self::param('a', 'c'),
            'filters'           => self::param('f', self::config('default.f')),
            'sharpen'           => (bool) self::param('s', self::config('default.s')),
            'canvasColor'       => $this->normalizeHexColor(self::param('cc', self::config('default.cc'))),
            'canvasTransparent' => (bool) self::param('ct', '1'),
        ];
    }

    /**
     * '#abc' / 'abc' / 'aabbcc' => 'aabbcc'. Anything else falls back to `default.cc` (and to white if that is invalid too).
     */
    protected function normalizeHexColor($color)
    {
        foreach ([$color, self::config('default.cc'), 'ffffff'] as $candidate) {
            $candidate = ltrim((string) $candidate, '#');
            // e.g. 'xyz' used to become black, because hexdec() ignores the invalid characters
            if (preg_match('/^[0-9a-f]{6}$/i', $candidate)) {
                return $candidate;
            }
            if (preg_match('/^[0-9a-f]{3}$/i', $candidate)) {
                return $candidate[0] . $candidate[0] . $candidate[1] . $candidate[1] . $candidate[2] . $candidate[2];
            }
        }
        return 'ffffff';
    }

    /**
     * Draw the resized image (canvas, crop, filters, sharpen) and encode it.
     * @return string|false the encoded image
     */
    protected function renderImage($image, $imgType, $origType, array $options)
    {
        $layout = $this->calculateLayout(
            imagesx($image),
            imagesy($image),
            $options['width'],
            $options['height'],
            $options['zoomCrop'],
            $options['align']
        );

        // If is a png and `png.isTransparent` is false then keep the alpha transparency,
        // otherwise fill the background with the canvas colour.
        $useTransparentCanvas = $imgType === 'png' && !self::config('png.isTransparent') && $options['canvasTransparent'];
        $canvas = $this->createCanvas($layout['canvasWidth'], $layout['canvasHeight'], $options['canvasColor'], $useTransparentCanvas);

        imagecopyresampled(
            $canvas,
            $image,
            $layout['dstX'],
            $layout['dstY'],
            $layout['srcX'],
            $layout['srcY'],
            $layout['dstWidth'],
            $layout['dstHeight'],
            $layout['srcWidth'],
            $layout['srcHeight']
        );

        $this->applyFilters($canvas, $options['filters']);
        if ($options['sharpen']) {
            $this->sharpen($canvas);
        }

        //Straight from Wordpress core code. Reduces filesize by up to 70% for PNG's
        // Skipped when the canvas uses alpha transparency: imagetruecolortopalette() drops the alpha channel,
        // which would turn the transparent background opaque.
        if (!$useTransparentCanvas && (IMAGETYPE_PNG == $origType || IMAGETYPE_GIF == $origType) && !imageistruecolor($image) && imagecolortransparent($image) >= 0) {
            imagetruecolortopalette($canvas, false, imagecolorstotal($image));
        }

        ob_start();
        if ($imgType === 'jpg') {
            imagejpeg($canvas, null, $options['quality']);
        } elseif ($imgType === 'png') {
            // q (0-100) is mapped to the zlib compression level (0-9)
            imagepng($canvas, null, (int) floor($options['quality'] * 0.09));
        } else {
            imagegif($canvas);
        }
        return ob_get_clean();
    }

    /**
     * Work out the canvas size and which part of the source goes where on it.
     * @param int $width / $height       size of the source image
     * @param int $newWidth / $newHeight requested size; one of them may be 0 to keep the aspect ratio
     * @return array canvasWidth, canvasHeight, dstX, dstY, dstWidth, dstHeight, srcX, srcY, srcWidth, srcHeight
     */
    protected function calculateLayout($width, $height, $newWidth, $newHeight, $zoomCrop, $align)
    {
        // generate new w/h if not provided
        if ($newWidth && !$newHeight) {
            $newHeight = (int) max(1, floor($height * ($newWidth / $width)));
        } elseif ($newHeight && !$newWidth) {
            $newWidth = (int) max(1, floor($width * ($newHeight / $height)));
        }

        // Height of the image when it is scaled to the requested width
        $scaledHeight = $height * ($newWidth / $width);
        $dstX = $dstY = 0;

        if ($zoomCrop == self::ZC_FIT_NO_BORDER) {
            // The canvas is shrunk to the scaled image
            if ($scaledHeight > $newHeight) {
                $newWidth = (int) max(1, round($width * ($newHeight / $height)));
            } else {
                $newHeight = (int) max(1, round($scaledHeight));
            }
        }
        $canvasWidth  = $newWidth;
        $canvasHeight = $newHeight;

        if ($zoomCrop == self::ZC_FIT_BORDERS) {
            // The canvas keeps the requested size and the scaled image is centred on it
            if ($scaledHeight > $newHeight) {
                $newWidth = (int) max(1, round($width * ($newHeight / $height)));
                $dstX     = (int) round(($canvasWidth - $newWidth) / 2);
            } else {
                $newHeight = (int) max(1, round($scaledHeight));
                $dstY      = (int) round(($canvasHeight - $newHeight) / 2);
            }
        }

        $srcX = $srcY = 0;
        $srcWidth  = $width;
        $srcHeight = $height;

        if ($zoomCrop > 0) {
            // Crop the source to the aspect ratio of the destination
            $cmpX = $width / $newWidth;
            $cmpY = $height / $newHeight;
            if ($cmpX > $cmpY) {
                $srcWidth = (int) round($width / $cmpX * $cmpY);
                $srcX     = (int) round(($width - ($width / $cmpX * $cmpY)) / 2);
            } elseif ($cmpY > $cmpX) {
                $srcHeight = (int) round($height / $cmpY * $cmpX);
                $srcY      = (int) round(($height - ($height / $cmpY * $cmpX)) / 2);
            }

            // positional cropping (a=t, b, l, r or combinations such as tl)
            if (strpos($align, 't') !== false) {
                $srcY = 0;
            }
            if (strpos($align, 'b') !== false) {
                $srcY = $height - $srcHeight;
            }
            if (strpos($align, 'l') !== false) {
                $srcX = 0;
            }
            if (strpos($align, 'r') !== false) {
                $srcX = $width - $srcWidth;
            }
        }

        return [
            'canvasWidth'  => $canvasWidth,
            'canvasHeight' => $canvasHeight,
            'dstX'         => $dstX,
            'dstY'         => $dstY,
            'dstWidth'     => $newWidth,
            'dstHeight'    => $newHeight,
            'srcX'         => $srcX,
            'srcY'         => $srcY,
            'srcWidth'     => $srcWidth,
            'srcHeight'    => $srcHeight,
        ];
    }

    /**
     * Create a truecolor canvas filled with the colour (fully transparent when $transparent is true).
     */
    protected function createCanvas($width, $height, $hexColor, $transparent)
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        $color = imagecolorallocatealpha(
            $canvas,
            hexdec(substr($hexColor, 0, 2)),
            hexdec(substr($hexColor, 2, 2)),
            hexdec(substr($hexColor, 4, 2)),
            $transparent ? 127 : 0
        );
        imagefill($canvas, 0, 0, $color);
        imagesavealpha($canvas, true);
        return $canvas;
    }

    /**
     * Apply the `f` filters, e.g. "2|3,20" = grayscale, then brightness 20.
     */
    protected function applyFilters($canvas, $filters)
    {
        if (!$filters || !function_exists('imagefilter')) {
            return;
        }
        foreach (explode('|', $filters) as $fl) {
            $settings = explode(',', $fl);
            // Look up by the raw string so malformed IDs such as "3junk" are ignored, not cast to 3.
            $filterId = $settings[0];
            if (!isset(self::FILTERS[$filterId])) {
                continue;
            }
            [$filterName, $argCount] = self::FILTERS[$filterId];
            $filter = constant($filterName);
            // imagefilter() rejects extra arguments since PHP 8, so pass exactly the required number.
            // The smooth filter takes a float weight (e.g. f=11,0.5); the others take ints.
            $args = array_map(
                $filter === IMG_FILTER_SMOOTH ? 'floatval' : 'intval',
                array_pad(array_slice($settings, 1, $argCount), $argCount, 0)
            );
            imagefilter($canvas, $filter, ...$args);
        }
    }

    protected function sharpen($canvas)
    {
        $matrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];
        imageconvolution($canvas, $matrix, 8, 0);
    }

    /**
     * Store the encoded image as the cache file. rename() replaces the old file atomically (also on Windows),
     * so readers always find a complete file.
     */
    protected function writeCacheFile($imgType, $data)
    {
        $this->debug(3, "Writing the cache file.");
        $tempFilePath = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        // The file starts with a PHP guard followed by the 3 letter image type and the PHP closing tag (6 bytes),
        // so it can not be executed or served directly.
        $header = $this->filePrependSecurityBlock . $imgType . ' ?' . '>';
        // An array is written piece by piece, so the image data is not copied once more in memory.
        // A short write (e.g. the disk is full) must not be published as a cache file.
        if (!$tempFilePath || file_put_contents($tempFilePath, [$header, $data]) !== strlen($header) + strlen($data)) {
            if ($tempFilePath) {
                @unlink($tempFilePath);
            }
            return $this->error('Could not write the cache file.');
        }
        if (!rename($tempFilePath, $this->cachefilePath)) {
            @unlink($tempFilePath);
            return $this->error('Could not write the cache file.');
        }
        return true;
    }

    /**
     * Run optipng or pngcrush (whichever is enabled) on PNG data.
     */
    protected function optimizePng($data)
    {
        $tempFilePath = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        if (!$tempFilePath) {
            return $data;
        }
        if (file_put_contents($tempFilePath, $data) === false) {
            @unlink($tempFilePath);
            return $data;
        }
        $optipng = self::config('png.optipngPath');
        if (self::config('png.optipngEnabled') && $optipng && is_file($optipng)) {
            $this->handleOptiPng($tempFilePath);
        } elseif (self::config('png.pngcrushEnabled')) {
            // also used when optipng is enabled but its path is not valid
            $this->handlePngCrush($tempFilePath);
        }
        $optimized = file_get_contents($tempFilePath);
        @unlink($tempFilePath);
        return $optimized ?: $data;
    }

    protected function handleOptiPng($tempFilePath) {

        if (!self::config('png.optipngPath') || !is_file(self::config('png.optipngPath'))) {
            $this->debug(3, "optipng is not enabled or optipngPath is not set.");
            return;
        }

        $this->debug(3, "optipng'ing $tempFilePath");
        $orgSize = filesize($tempFilePath);
        $this->runCommand([self::config('png.optipngPath'), '-o1', $tempFilePath]); //you can use up to -o7 but it really slows things down
        clearstatcache();
        $afterSize = filesize($tempFilePath);
        $sizeDrop = $orgSize - $afterSize;
        if ($sizeDrop < 0) {
            $this->debug(1, "optipng increased size! Difference was: $sizeDrop");
            return;
        }
        if ($sizeDrop == 0) {
            $this->debug(1, "optipng did not change image size.");
            return;
        }
        $this->debug(1, "optipng reduced size by $sizeDrop");
    }

    /**
     * Run an external command without going through a shell and return its combined output.
     */
    protected function runCommand(array $command)
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return '';
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $output;
    }

    protected function handlePngCrush($tempFilePath) {
        $pngCrushPath = self::config('png.pngcrushPath');
        if (!$pngCrushPath || !is_file($pngCrushPath)) {
            $this->debug(3, "pngcrush is not enabled or pngcrushPath is not set.");
            return;
        }

        $pngCrushFilePath = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        $this->debug(3, "pngcrush'ing $tempFilePath to $pngCrushFilePath");
        $out = $this->runCommand([$pngCrushPath, $tempFilePath, $pngCrushFilePath]);
        clearstatcache();
        if (!is_file($pngCrushFilePath) || filesize($pngCrushFilePath) === 0) {
            @unlink($pngCrushFilePath);
            $this->debug(3, "pngcrush failed with output:" . print_r($out, true));
            return;
        }

        $sizeDrop = filesize($tempFilePath) - filesize($pngCrushFilePath);
        if ($sizeDrop <= 0) {
            $this->debug(1, "pngcrush did not reduce file size. Difference was $sizeDrop bytes.");
            unlink($pngCrushFilePath);
            return;
        }

        $this->debug(1, "pngcrush was succesful and gave a $sizeDrop byte size reduction");
        rename($pngCrushFilePath, $tempFilePath);
    }

    /**
     * The directory local images are served from: LOCAL_FILE_BASE_DIRECTORY, DOCUMENT_ROOT, or (when the server
     * does not set DOCUMENT_ROOT, e.g. some IIS setups) the script path with the script's URL path removed.
     * @return string without a trailing slash (except for '/'), or '' when it can not be determined
     */
    protected function calcDocRoot()
    {
        if ($this->docRoot !== null) {
            return $this->docRoot;
        }

        $docRoot = defined('LOCAL_FILE_BASE_DIRECTORY') ? constant('LOCAL_FILE_BASE_DIRECTORY') : self::server('DOCUMENT_ROOT');

        // The script's URL path. PHP_SELF also contains PATH_INFO (/timthumb.php/anything), which the client
        // controls; with it the cut below would be longer and the root wider than the real document root.
        $scriptName = self::server('SCRIPT_NAME');
        if ($scriptName === '') {
            $phpSelf    = self::server('PHP_SELF');
            $pathInfo   = self::server('PATH_INFO');
            $scriptName = ($pathInfo !== '' && substr($phpSelf, -strlen($pathInfo)) === $pathInfo)
                ? substr($phpSelf, 0, -strlen($pathInfo))
                : $phpSelf;
        }
        $scriptPaths = [
            'SCRIPT_FILENAME' => self::server('SCRIPT_FILENAME'),
            'PATH_TRANSLATED' => str_replace('\\\\', '\\', self::server('PATH_TRANSLATED')),
        ];
        foreach ($scriptPaths as $source => $scriptPath) {
            if ($docRoot || $scriptPath === '' || $scriptName === '') {
                continue;
            }
            $scriptPath = str_replace('\\', '/', $scriptPath);
            // Only derive the root when the file path really ends with the URL path (case-insensitive for Windows).
            if (strlen($scriptPath) <= strlen($scriptName)
                || substr_compare($scriptPath, $scriptName, -strlen($scriptName), strlen($scriptName), true) !== 0
            ) {
                $this->debug(1, "Could not derive the document root from $source ($scriptPath) and the script name ($scriptName).");
                continue;
            }
            $docRoot = substr($scriptPath, 0, -strlen($scriptName));
            $this->debug(3, "DOCUMENT_ROOT is not set. Generated docRoot using $source and the script name as: $docRoot");
        }

        if ($docRoot !== '/') {
            $docRoot = rtrim((string) $docRoot, '/');
        }
        $this->debug(3, "Doc root is: " . $docRoot);
        return $this->docRoot = $docRoot;
    }

    protected function getLocalImagePath($src)
    {
        $docRoot = $this->calcDocRoot();
        $src = ltrim($src, '/'); //strip off the leading '/'
        if (!$docRoot) {
            $this->debug(
                3,
                "We have no document root set, so as a last resort, lets check if the image is in the current dir and serve that."
            );
            //We don't support serving images outside the current dir if we don't have a doc root for security reasons.
            $file = preg_replace('@^.*?([^/\\\\]+)$@', '$1', $src); //strip off any path info and just leave the filename.
            if (is_file($file)) {
                return realpath($file);
            }
            return $this->error(
                "Could not find your website document root and the file specified doesn't exist in timthumbs directory. We don't support serving files outside timthumb's directory without a document root for security reasons."
            );
        }

        $realDocRoot = realpath($docRoot);
        if (!$realDocRoot || !is_dir($realDocRoot)) {
            return $this->error("Server path does not exist. Ensure variable \$_SERVER['DOCUMENT_ROOT'] is set correctly");
        }

        //Candidate locations: under docRoot, as an absolute path, then relative to each directory between docRoot and this script.
        $candidates = [$docRoot . '/' . $src, '/' . $src];
        $scriptDir = str_replace('\\', '/', dirname(self::server('SCRIPT_FILENAME')));
        // Normalize both sides to "/" so the prefix check also works with Windows paths (C:\inetpub\wwwroot)
        $base = rtrim(str_replace('\\', '/', $docRoot), '/');
        $relative = (stripos($scriptDir, $base) === 0) ? substr($scriptDir, strlen($base)) : '';
        foreach (explode('/', $relative) as $sub) {
            if ($sub !== '') {
                $base .= '/' . $sub;
            }
            $candidates[] = $base . '/' . $src;
        }

        foreach (array_unique($candidates) as $candidate) {
            $this->debug(3, "Trying file as: " . $candidate);
            $real = realpath($candidate);
            if (!$real || !is_file($real)) {
                continue;
            }
            // The resolved path (symlinks and ../ resolved) must be inside the document root.
            if (!$this->isInsideDirectory($real, $realDocRoot)) {
                $this->debug(1, "Security block: The file specified occurs outside the document root.");
                continue;
            }
            $this->debug(3, "Found file as: " . $real);
            return $real;
        }
        return false;
    }

    protected function isInsideDirectory($path, $directory)
    {
        $path      = str_replace('\\', '/', $path);
        $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';
        return strpos($path, $directory) === 0;
    }

    protected function toDelete($tempFilePath)
    {
        $this->debug(3, "Scheduling file $tempFilePath to delete on destruct.");
        $this->toDeletes[] = $tempFilePath;
    }

    /**
     * @return array|false ['type' => 'jpg'|'png'|'gif', 'data' => image bytes]
     */
    protected function generateExternalImage()
    {
        $tempFilePath = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        if (!$tempFilePath) {
            // A local problem: do not write the failure marker, which would block the image for `waitBetweenFetchErrors`.
            return $this->error('Could not create a temporary file in the cache directory.');
        }
        $this->debug(3, "Fetching external image into temporary file $tempFilePath");
        $this->toDelete($tempFilePath);
        #fetch file here
        if (!$this->fetchAndSaveImageFromUrl($this->src, $tempFilePath)) {
            @unlink($this->cachefilePath);
            touch($this->cachefilePath);
            $this->debug(3, "Error fetching URL: " . $this->lastURLError);
            $this->error("Error reading the URL you specified from remote host." . $this->lastURLError);
            return false;
        }

        $sData    = @getimagesize($tempFilePath);
        $mimeType = $sData['mime'] ?? '';
        if (!$this->imageTypeFromMime($mimeType)) {
            $this->debug(3, "Remote file has invalid mime type: $mimeType");
            @unlink($this->cachefilePath);
            touch($this->cachefilePath);
            $this->error("The remote file is not a valid image. Mimetype = '" . $mimeType . "'");
            return false;
        }

        return $this->processImageAndWriteToCache($tempFilePath, $sData);
    }

    public function curlWrite($curlHandle, $data, $fileHandle)
    {
        $dataSize = strlen($data);
        if ($dataSize + ftell($fileHandle) > self::config('maxFileSize')) {
            $this->lastURLError = 'The remote file is greater than the maximum allowed file size.';
            return 0; // abort the transfer
        }
        fwrite($fileHandle, $data);
        return $dataSize;
    }

    /**
     * Send the cache file. Returns false (without sending anything) if it is missing or broken.
     */
    protected function serveCacheFile()
    {
        $this->debug(3, "Serving {$this->cachefilePath}");
        $fp = @fopen($this->cachefilePath, 'rb');
        if (!$fp) {
            $this->debug(1, "Could not open cachefile {$this->cachefilePath}");
            return false;
        }
        $stat = fstat($fp);

        $securityBlockSize = strlen($this->filePrependSecurityBlock);
        $headerSize        = $securityBlockSize + 6;
        fseek($fp, $securityBlockSize, SEEK_SET);
        $imgType = fread($fp, 3);
        if ($stat['size'] <= $headerSize || !in_array($imgType, self::IMAGE_TYPES, true)) {
            fclose($fp);
            return false;
        }
        fseek($fp, $headerSize, SEEK_SET);
        $this->sendImageHeaders($imgType, $stat['size'] - $headerSize, $stat['mtime']);
        fpassthru($fp);
        fclose($fp);
        return true;
    }

    protected function cacheFileMTime()
    {
        clearstatcache(true, $this->cachefilePath);
        return @filemtime($this->cachefilePath) ?: self::now();
    }

    /**
     * @param string $imgType 'jpg', 'png' or 'gif'
     */
    protected function sendImageHeaders($imgType, $dataSize, $mtime)
    {
        $mimeType = array_search($imgType, self::IMAGE_TYPES, true) ?: 'application/octet-stream';
        $gmdate_expires = gmdate('D, d M Y H:i:s', self::now() + (int) self::config('browserCache.maxAge')) . ' GMT';
        // send content headers then display image
        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: none'); //Changed this because we don't accept range requests
        header('Content-Length: ' . $dataSize);
        $etag = '"' . $mtime . '"';
        header(sprintf('ETag: %s', $etag));
        if (!self::config('browserCache.enable')) {
            $this->debug(3, "Browser cache is disabled so setting non-caching headers.");
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header("Pragma: no-cache");
            header('Expires: ' . gmdate('D, d M Y H:i:s', self::now()) . ' GMT');
        } else {
            $this->debug(3, "Browser caching is enabled");
            header('Cache-Control: max-age=' . self::config('browserCache.maxAge') . ', must-revalidate');
            header('Expires: ' . $gmdate_expires);
        }
        return true;
    }

    /**
     * The supported image types, keyed by the MIME type getimagesize() reports.
     * The value is the 3-letter type stored in the cache file header.
     */
    const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
    ];

    /**
     * @return string|null 'jpg', 'png' or 'gif', or null for an unsupported type
     */
    protected function imageTypeFromMime($mimeType)
    {
        return self::IMAGE_TYPES[strtolower((string) $mimeType)] ?? null;
    }

    protected function openImage($imgType, $src)
    {
        switch ($imgType) {
            case 'jpg':
                return @imagecreatefromjpeg($src);
            case 'png':
                $image = @imagecreatefrompng($src);
                if ($image) {
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                }
                return $image;
            case 'gif':
                return @imagecreatefromgif($src);
        }
        return false;
    }

    protected function getClientIP()
    {
        $forwardedFor = trim(explode(',', self::server('HTTP_X_FORWARDED_FOR', ''))[0]);
        $ip = $forwardedFor ?: self::server('HTTP_CLIENT_IP') ?: self::server('REMOTE_ADDR');

        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'illegal';
        }

        return $ip;
    }

    protected function debug($level, $msg)
    {
        if (!$level || $this->debugLevel < $level) {
            return;
        }

        $now = microtime(true);
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        error_log(
            sprintf(
                "TimThumb Debug line %d [%.6f : %.6f]: %s",
                $caller['line'] ?? 0,
                ($now - (float) self::server('REQUEST_TIME_FLOAT', $now)),
                ($this->lastBenchTime ? $now - $this->lastBenchTime : 0),
                $msg
            )
        );
        $this->lastBenchTime = $now;
    }

    protected function error($err)
    {
        $this->debug(3, "Adding error message: $err");
        $this->errors[] = $err;
        return false;
    }

    protected function getMimeType($file)
    {
        $info = @getimagesize($file);
        if (isset($info['mime'])) {
            return $info['mime'];
        }
        return '';
    }

    /**
     * Estimate the memory GD needs for the source image and the canvas.
     * A truecolor GD image uses about 4 bytes per pixel plus row overhead, so ~5 bytes per pixel is used.
     * The output is encoded into an output buffer and then copied into a string; in the worst case
     * (e.g. a PNG that does not compress) each is about as large as the raw canvas, so 2 x 4 bytes per
     * output pixel are added.
     */
    protected function hasEnoughMemoryFor($srcWidth, $srcHeight, $dstWidth, $dstHeight)
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || (int) $limit <= 0) {
            return true; // unlimited
        }
        // A missing destination dimension is computed later, so assume the configured maximum for it.
        $dstWidth  = $dstWidth ?: (int) self::config('maxWidth');
        $dstHeight = $dstHeight ?: (int) self::config('maxHeight');
        $needed    = $srcWidth * $srcHeight * 5 + $dstWidth * $dstHeight * (5 + 2 * 4);
        $available = $this->returnBytes($limit) - memory_get_usage();
        $this->debug(3, sprintf('Estimated memory needed: %d bytes, available: %d bytes', $needed, $available));
        return $needed < $available;
    }

    protected function setMemoryLimit()
    {
        $inimem   = ini_get('memory_limit');
        $inibytes = $this->returnBytes($inimem);
        $ourbytes = $this->returnBytes(self::config('memoryLimit'));

        if ($inibytes < $ourbytes) {
            ini_set('memory_limit', self::config('memoryLimit'));
            $this->debug(
                3,
                sprintf("Increased memory from %s to %s", $inimem, self::config('memoryLimit'))
            );
            return;
        }

        $this->debug(
            3,
            sprintf(
                "Not adjusting memory size because the current setting is %s and our size of %s is smaller.",
                $inimem,
                self::config('memoryLimit')
            )
        );
    }

    protected function returnBytes($size_str)
    {
        $units = [
            'k' => 1024,
            'm' => 1024 * 1024,
            'g' => 1024 * 1024 * 1024
        ];

        $unit = strtolower(substr($size_str, -1));
        $size = (int)$size_str;

        return isset($units[$unit]) ? $size * $units[$unit] : $size;
    }

    protected function fetchAndSaveImageFromUrl($url, $tempFilePath)
    {
        $this->lastURLError = false;
        // curl is required for external images (checked in isExternalImageAllowed()).
        // Redirects are followed manually so that every hop is checked against `allowedSites`.
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $result = $this->curlFetch($url, $tempFilePath);
            if ($result === false) {
                return false;
            }
            if ($result === true) {
                return true;
            }
            // $result is the redirect target
            if (!$this->isAllowedSiteUrl($result)) {
                $this->lastURLError = ' Redirected to a site that is not in `allowedSites`.';
                return false;
            }
            $url = $result;
        }
        $this->lastURLError = ' Too many redirects.';
        return false;
    }

    /**
     * Fetch one URL with curl (without following redirects).
     * @return bool|string true on success, false on failure, or the redirect target URL
     */
    protected function curlFetch($url, $tempFilePath)
    {
        $url = $this->buildSafeUrl($url);
        if ($url === false) {
            $this->lastURLError = ' Invalid URL supplied.';
            return false;
        }
        $this->debug(3, "Fetching url with curl: $url");
        $ip = $this->resolvePublicIp($url);
        if ($ip === false) {
            $this->lastURLError = ' The host resolves to a private or reserved IP address.';
            return false;
        }
        $fileHandle = fopen($tempFilePath, 'wb');
        if (!$fileHandle) {
            $this->error("Could not open temporary file for writing.");
            return false;
        }

        $curlHandle = curl_init($url);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, self::config('curlTimeout'));
        curl_setopt($curlHandle, CURLOPT_USERAGENT, (string) self::config('curlUserAgent'));
        curl_setopt($curlHandle, CURLOPT_HEADER, false);
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, false);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($curlHandle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if ($ip !== null) {
            // Connect to the address we have just checked, so DNS can not be changed in between (DNS rebinding)
            $parts = parse_url($url);
            $port  = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
            $host  = trim($parts['host'], '[]');
            $addr  = strpos($ip, ':') !== false ? "[$ip]" : $ip;
            curl_setopt($curlHandle, CURLOPT_RESOLVE, ["$host:$port:$addr"]);
        }
        curl_setopt($curlHandle, CURLOPT_WRITEFUNCTION, function ($curlHandle, $data) use ($fileHandle) {
            return $this->curlWrite($curlHandle, $data, $fileHandle);
        });
        $response   = curl_exec($curlHandle);
        $httpStatus = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $redirectTo = (string) curl_getinfo($curlHandle, CURLINFO_REDIRECT_URL);
        if ($response === false && !$this->lastURLError) {
            $this->lastURLError = ' ' . curl_error($curlHandle);
        }
        fclose($fileHandle);
        // curl_close() has had no effect since PHP 8.0 and is deprecated as of PHP 8.5.
        // The handle is released automatically when $curlHandle goes out of scope.

        if ($response === false) {
            return false;
        }
        if ($httpStatus >= 300 && $httpStatus < 400 && $redirectTo !== '') {
            $this->debug(3, "Redirected to $redirectTo");
            return $redirectTo;
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            if ($httpStatus === 404) {
                $this->set404();
            }
            $this->lastURLError = " HTTP status $httpStatus";
            return false;
        }
        return true;
    }

    protected function serveImg($file, $status = 200)
    {
        if (!$file || !is_file($file)) {
            return false;
        }
        $mime = $this->getMimeType($file);
        if (!$mime) {
            return false;
        }

        http_response_code($status);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header("Pragma: no-cache");
        $bytes = @readfile($file);
        if ($bytes === false) {
            $content = @file_get_contents($file);
            if ($content === false) {
                return false;
            }
            echo $content;
        }
        return true;
    }

    protected function set404()
    {
        $this->is404 = true;
    }

    protected function is404()
    {
        return $this->is404;
    }

    protected function isAllowedReferer()
    {
        if (!self::server('HTTP_REFERER')) {
            return false;
        }

        $myhost = '@^https?://(?:www\.)?' . preg_quote($this->myHost, '@') . '(?:$|/)@i';
        if (!preg_match($myhost, self::server('HTTP_REFERER'))) {
            return false;
        }

        return true;
    }

    /**
     * Whether `blockExternalLeechers` is on and the request does not come from a page of this site.
     */
    protected function isHotlinkBlocked()
    {
        return self::config('blockExternalLeechers') && !$this->isAllowedReferer();
    }

    /**
     * Send the red "No Hotlinking" GIF (`blockExternalLeechers`) with 403 Forbidden.
     */
    protected function serveHotlinkImage()
    {
        // base64 encoded red image that says 'no hotlinkers'
        $imgData = base64_decode("R0lGODlhUAAMAIAAAP8AAP///yH5BAAHAP8ALAAAAABQAAwAAAJpjI+py+0Po5y0OgAMjjv01YUZ\nOGplhWXfNa6JCLnWkXplrcBmW+spbwvaVr/cDyg7IoFC2KbYVC2NQ5MQ4ZNao9Ynzjl9ScNYpneb\nDULB3RP6JuPuaGfuuV4fumf8PuvqFyhYtjdoeFgAADs=");
        http_response_code(403);
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($imgData));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo $imgData;
    }

    /**
     * Read a config value using dot notation, e.g. self::config('fileCache.directory').
     */
    protected static function config($key, $default = null)
    {
        if (static::$mergedConfig === null) {
            static::$mergedConfig = static::mergedConfig();
        }
        $value = static::$mergedConfig;
        foreach (explode('.', $key) as $subkey) {
            if (!isset($value[$subkey])) {
                return $default;
            }
            $value = $value[$subkey];
        }
        return $value;
    }

    /**
     * Read a query parameter. Array input such as ?w[]=1 is ignored to avoid TypeErrors in string/number functions.
     */
    protected static function param($key, $default = '')
    {
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
            return $default;
        }
        return $_GET[$key];
    }

    public static function server($key, $default = '')
    {
        return $_SERVER[strtoupper($key)] ?? $default;
    }

    /**
     * The request time, fixed for the whole request.
     */
    protected static function now()
    {
        static $now = null;
        if ($now === null) {
            $now = time();
        }
        return $now;
    }
}
