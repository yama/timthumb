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
    public $version = '2.10.0'; // Version of this script

    public static $config = [
        'debug' => [
            'level'  => 0, // Debug level (written to error_log). 1 is less noisy, while 3 is the most verbose. 0 disables it
            'displayErrorMessages' => true, // Show error details in the error image. Set to false to show only a generic error image (good for production websites)
        ],
        'memoryLimit'   => '256M', // Minimum PHP memory limit while resizing (only raised, never lowered). GD needs roughly width x height x 5 bytes, so a 24MP photo needs ~120MB
        'maxFileSize'   => 15728640, // 15 Megs is 15728640. This is the max internal or external file size that we'll process.
        'curlTimeout'   => 20, // Timeout in seconds for fetching external images. External images require the PHP curl extension.
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
    protected $docRoot         = '';
    protected $lastURLError    = false;
    protected $localImagePath  = '';
    protected $localImageMTime = 0;
    protected $url             = false;
    protected $myHost          = '';
    protected $isExternalURL   = false;
    protected $cachefilePath   = '';
    protected $errors          = [];
    protected $toDeletes       = [];
    protected $cacheDirectory  = '';
    protected $lastBenchTime   = 0;
    //Generally if timthumb.php is modifed (upgraded) then the salt changes and all cache files are recreated. This is a backup mechanism to force regen.
    protected $fileCacheVersion = 1;
    //Designed to have three letter mime type, space, question mark and greater than symbol appended. 6 bytes total.
    protected $filePrependSecurityBlock = "<?php die('Execution denied!'); //";
    protected $curlDataWritten = 0;
    protected $curlFH = null;

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

    public function __construct()
    {
        if (!extension_loaded('gd')) {
            $this->error('GD extension is not loaded');
            return;
        }

        date_default_timezone_set('UTC');
        $this->debug(1, sprintf('Starting new request from %s to %s', $this->getClientIP(), self::server('REQUEST_URI')));
        $this->cacheDirectory = $this->getCacheDirectory();

        //Clean the cache before we do anything because we don't want the first visitor after self::config('fileCache.timeBetweenCleans') expires to get a stale image.
        $this->cleanCache();

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
            $this->error('No image specified');
            return;
        }
        if (self::config('blockExternalLeechers') && !$this->isAllowedReferer()) {
            echo $this->dispRedImage();
            exit;
        }

        if (preg_match('@^https?://[^/]+@i', $this->src)) {
            $this->debug(2, 'Is a request for an external URL: ' . $this->src);
            $this->isExternalURL = true;
            if (!$this->isExternalImageAllowed()) {
                return;
            }
        } else {
            $this->debug(2, 'Is a request for an internal file: ' . $this->src);
        }
        //On windows systems I'm assuming fileinode returns an empty string or a number that doesn't change. Check this.
        $salt = @filemtime(__FILE__) . '-' . @fileinode(__FILE__);
        $this->debug(3, 'Salt is: ' . $salt);

        if ($this->isExternalURL) {
            $this->cachefilePath = $this->generateCacheFilePath(
                $salt . $this->cacheKeyParams() . $this->fileCacheVersion
            );
            return;
        }

        $this->localImagePath = $this->getLocalImagePath($this->src);
        if (!$this->localImagePath) {
            $this->debug(1, 'Could not find the local image: ' . $this->localImagePath);
            $this->error('Could not find the internal image you specified.');
            $this->set404();
            return;
        }

        $this->debug(1, 'Local image path is ' . $this->localImagePath);
        $this->localImageMTime = @filemtime($this->localImagePath);
        //We include the mtime of the local file in case in changes on disk.
        $this->cachefilePath = $this->generateCacheFilePath(
            $salt . $this->localImageMTime . $this->localImagePath . $this->cacheKeyParams() . $this->fileCacheVersion
        );
    }

    /**
     * Build the part of the cache key that depends on the request.
     * Only parameters that affect the output are used, so that arbitrary
     * extra query parameters can not be used to fill the cache directory.
     */
    protected function cacheKeyParams()
    {
        $params = [];
        foreach (['src', 'w', 'h', 'zc', 'q', 'a', 'f', 's', 'cc', 'ct'] as $key) {
            $params[$key] = self::param($key, null);
        }
        return serialize($params);
    }

    public function __destruct()
    {
        foreach ($this->toDeletes as $del) {
            $this->debug(2, 'Deleting temp file ' . $del);
            @unlink($del);
        }
    }

    public function start()
    {
        $this->handleErrors();
        if ($this->tryBrowserCache()) {
            exit;
        }
        $this->handleErrors();
        if (self::config('fileCache.enabled') && $this->tryServerCache()) {
            exit;
        }
        $this->handleErrors();
        $this->run();
        $this->handleErrors();
        exit;
    }

    public function run()
    {
        if (!$this->isExternalURL) {
            $this->debug(3, 'Got request for internal image. Starting serveInternalImage()');
            return $this->serveInternalImage();
        }

        if (!self::config('allowedSites') || !self::config('allowExternal', true)) {
            $this->debug(
                1,
                'Got a request for an external image but there are no allowed sites specified in the `allowedSites` option, so returning an error message.'
            );
            $this->error('You are not allowed to fetch images from an external website.');
            return false;
        }

        $this->debug(3, 'Got request for external image. Starting serveExternalImage.');
        return $this->serveExternalImage();
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
        if (!self::config('allowedSites')) {
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

        if (self::config('fileCache.directory') !== sys_get_temp_dir()) {
            if (!touch(self::config('fileCache.directory') . '/index.html')) {
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

    protected function handleErrors()
    {
        if (!$this->errors) {
            return false;
        }

        $status = $this->is404() ? 404 : 400;

        if (self::config('notFoundImage') && $this->is404()) {
            if ($this->serveImg($this->resolveConfiguredImage(self::config('notFoundImage')), $status)) {
                exit;
            }

            $this->error(
                'Additionally, the 404 image that is configured could not be found or there was an error serving it.'
            );
        }

        if (self::config('errorImage')) {
            if ($this->serveImg($this->resolveConfiguredImage(self::config('errorImage')), $status)) {
                exit;
            }

            $this->error(
                'Additionally, the error image that is configured could not be found or there was an error serving it.'
            );
        }

        // timthumb is usually embedded with <img>, where an HTML error page is invisible, so draw the errors as an image.
        if ($this->serveErrorImage($status)) {
            exit;
        }

        // GD is not available: fall back to a plain HTML error page.
        http_response_code($status);
        if (!self::config('debug.displayErrorMessages')) {
            exit;
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

        exit;
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
            exit;
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
        //Image serving failed. We can't retry at this point, but lets remove it from cache so the next request recreates it
        @unlink($this->cachefilePath);
        return true;
    }

    protected function serveInternalImage()
    {
        $this->debug(3, "Local image path is $this->localImagePath");
        if (!$this->localImagePath) {
            $this->sanityFail("localImage not set after verifying it earlier in the code.");
            return false;
        }
        $fileSize = filesize($this->localImagePath);
        if ($fileSize > self::config('maxFileSize')) {
            $this->error("The file you specified is greater than the maximum allowed file size.");
            return false;
        }
        if ($fileSize <= 0) {
            $this->error("The file you specified is <= 0 bytes.");
            return false;
        }
        $this->debug(3, "Calling processImageAndWriteToCache() for local image.");
        if (!$this->processImageAndWriteToCache($this->localImagePath)) {
            return false;
        }

        $this->serveCacheFile();
        return true;
    }

    protected function cleanCache()
    {
        if (self::config('fileCache.timeBetweenCleans') < 0) {
            return true;
        }

        $this->debug(3, "cleanCache() called");
        $lastCleanFile = $this->cacheDirectory . '/timthumb_cacheLastCleanTime.touch';

        //If this is a new timthumb installation we need to create the file
        if (!is_file($lastCleanFile)) {
            $this->debug(1, "File tracking last clean doesn't exist. Creating $lastCleanFile");
            if (!touch($lastCleanFile)) {
                $this->error("Could not create cache clean timestamp file.");
            }
            return false;
        }
        //Cache was last cleaned more than 1 day ago
        if (@filemtime($lastCleanFile) < (self::now() - self::config('fileCache.timeBetweenCleans'))) {
            $this->debug(
                1,
                sprintf(
                    "Cache was last cleaned more than %d seconds ago. Cleaning now.",
                    self::config('fileCache.timeBetweenCleans')
                )
            );
            // Very slight race condition here, but worst case we'll have 2 or 3 servers cleaning the cache simultaneously once a day.
            if (!touch($lastCleanFile)) {
                $this->error("Could not create cache clean timestamp file.");
            }
            $files = glob($this->cacheDirectory . '/*' . self::config('fileCache.suffix'));
            if ($files) {
                $timeAgo = self::now() - self::config('fileCache.maxFileAge');
                foreach ($files as $file) {
                    if (@filemtime($file) < $timeAgo) {
                        $this->debug(
                            3,
                            sprintf(
                                "Deleting cache file $file older than max age: %d seconds",
                                self::config('fileCache.maxFileAge')
                            )
                        );
                        @unlink($file);
                    }
                }
            }
            return true;
        }

        $this->debug(3, "Cache was cleaned less than " . self::config('fileCache.timeBetweenCleans') . " seconds ago so no cleaning needed.");
        return false;
    }

    protected function processImageAndWriteToCache($localImage)
    {
        $sData      = @getimagesize($localImage);
        if (!$sData) {
            return $this->error("The image being resized is not a valid gif, jpg or png.");
        }
        $origWidth  = $sData[0];
        $origHeight = $sData[1];
        $origType   = $sData[2];
        $mimeType   = $sData['mime'] ?? '';

        $this->debug(3, "Mime type of image is $mimeType");
        if (!preg_match('@^image/(?:gif|jpg|jpeg|png)$@i', $mimeType)) {
            return $this->error("The image being resized is not a valid gif, jpg or png.");
        }

        // get standard input properties
        $new_width    = abs((int) self::param('w', 0));
        $new_height   = abs((int) self::param('h', 0));
        $zoom_crop    = (int) self::param('zc', self::config('default.zc'));
        $quality      = max(0, min(100, abs((int) self::param('q', self::config('default.q')))));
        $align        = self::param('a', 'c');
        $filters      = self::param('f', self::config('default.f'));
        $sharpen      = (bool) self::param('s', self::config('default.s'));
        $canvas_color = ltrim(
            self::param('cc', self::config('default.cc')),
            '#'
        );
        $canvas_trans = (bool) self::param('ct', '1');

        // set default width and height if neither are set already
        if ($new_width == 0 && $new_height == 0) {
            $new_width = (int) self::config('default.w');
            $new_height = (int) self::config('default.h');
        }

        // ensure size limits can not be abused
        $new_width  = (int) min($origWidth, $new_width, self::config('maxWidth'));
        $new_height = (int) min($origHeight, $new_height, self::config('maxHeight'));

        // set memory limit to be able to have enough space to resize larger images
        $this->setMemoryLimit();

        // Refuse images that would not fit in memory once decoded (e.g. a small file with huge dimensions),
        // instead of dying with a fatal "Allowed memory size exhausted" error.
        if (!$this->hasEnoughMemoryFor($origWidth, $origHeight, $new_width, $new_height)) {
            return $this->error('The image is too large to process (not enough memory).');
        }

        // open the existing image
        $image = $this->openImage($mimeType, $localImage);
        if ($image === false) {
            return $this->error('Unable to open image.');
        }

        // Get original width and height
        $width = imagesx($image);
        $height = imagesy($image);
        $origin_x = 0;
        $origin_y = 0;

        // generate new w/h if not provided
        if ($new_width && !$new_height) {
            $new_height = (int) max(1, floor($height * ($new_width / $width)));
        } elseif ($new_height && !$new_width) {
            $new_width = (int) max(1, floor($width * ($new_height / $height)));
        }

        // scale down (canvas is resized to fit the image)
        if ($zoom_crop == 3) {

            $final_height = $height * ($new_width / $width);

            if ($final_height > $new_height) {
                $new_width = (int) max(1, round($width * ($new_height / $height)));
            } else {
                $new_height = (int) max(1, round($final_height));
            }
        }

        // create a new true color image
        $canvas = imagecreatetruecolor($new_width, $new_height);
        imagealphablending($canvas, false);

        if (strlen($canvas_color) == 3) { //if is 3-char notation, edit string into 6-char notation
            $canvas_color = sprintf(
                "%s%s%s",
                str_repeat(substr($canvas_color, 0, 1), 2),
                str_repeat(substr($canvas_color, 1, 1), 2),
                str_repeat(substr($canvas_color, 2, 1), 2)
            );
        } elseif (strlen($canvas_color) != 6) {
            $canvas_color = self::config('default.cc'); // on error return default canvas color
        }

        // Create a new transparent color for image
        // If is a png and PNG_IS_TRANSPARENT is false then remove the alpha transparency
        // (and if is set a canvas color show it in the background)
        $useTransparentCanvas = $mimeType === 'image/png' && !self::config('png.isTransparent') && $canvas_trans;
        $color = imagecolorallocatealpha(
            $canvas,
            hexdec(substr($canvas_color, 0, 2)),
            hexdec(substr($canvas_color, 2, 2)),
            hexdec(substr($canvas_color, 4, 2)),
            $useTransparentCanvas ? 127 : 0
        );

        // Completely fill the background of the new image with allocated color.
        imagefill($canvas, 0, 0, $color);

        // scale down and add borders
        if ($zoom_crop == 2) {

            $final_height = $height * ($new_width / $width);

            if ($final_height > $new_height) {
                $canvas_width = $new_width;
                $new_width = (int) max(1, round($width * ($new_height / $height)));
                $origin_x = (int) round(($canvas_width - $new_width) / 2);
            } else {
                $canvas_height = $new_height;
                $new_height = (int) max(1, round($final_height));
                $origin_y = (int) round(($canvas_height - $new_height) / 2);
            }
        }

        // Restore transparency blending
        imagesavealpha($canvas, true);

        if ($zoom_crop > 0) {

            $src_x = $src_y = 0;
            $src_w = $width;
            $src_h = $height;

            $cmp_x = $width / $new_width;
            $cmp_y = $height / $new_height;

            // calculate x or y coordinate and width or height of source
            if ($cmp_x > $cmp_y) {
                $src_w = (int) round($width / $cmp_x * $cmp_y);
                $src_x = (int) round(($width - ($width / $cmp_x * $cmp_y)) / 2);
            } elseif ($cmp_y > $cmp_x) {
                $src_h = (int) round($height / $cmp_y * $cmp_x);
                $src_y = (int) round(($height - ($height / $cmp_y * $cmp_x)) / 2);
            }

            // positional cropping!
            if ($align) {
                if (strpos($align, 't') !== false) {
                    $src_y = 0;
                }
                if (strpos($align, 'b') !== false) {
                    $src_y = $height - $src_h;
                }
                if (strpos($align, 'l') !== false) {
                    $src_x = 0;
                }
                if (strpos($align, 'r') !== false) {
                    $src_x = $width - $src_w;
                }
            }
            imagecopyresampled(
                $canvas,
                $image,
                $origin_x,
                $origin_y,
                $src_x,
                $src_y,
                $new_width,
                $new_height,
                $src_w,
                $src_h
            );
        } else {
            // copy and resize part of an image with resampling
            imagecopyresampled(
                $canvas,
                $image,
                0,
                0,
                0,
                0,
                $new_width,
                $new_height,
                $width,
                $height
            );
        }

        if ($filters && function_exists('imagefilter') && defined('IMG_FILTER_NEGATE')) {
            $imageFilters = [
                1 => [IMG_FILTER_NEGATE, 0],
                2 => [IMG_FILTER_GRAYSCALE, 0],
                3 => [IMG_FILTER_BRIGHTNESS, 1],
                4 => [IMG_FILTER_CONTRAST, 1],
                5 => [IMG_FILTER_COLORIZE, 4],
                6 => [IMG_FILTER_EDGEDETECT, 0],
                7 => [IMG_FILTER_EMBOSS, 0],
                8 => [IMG_FILTER_GAUSSIAN_BLUR, 0],
                9 => [IMG_FILTER_SELECTIVE_BLUR, 0],
                10 => [IMG_FILTER_MEAN_REMOVAL, 0],
                11 => [IMG_FILTER_SMOOTH, 1],
            ];
            // apply filters to image
            $filterList = explode('|', $filters);
            foreach ($filterList as $fl) {
                $settings = explode(',', $fl);
                // Look up by the raw string so malformed IDs such as "3junk" are ignored, not cast to 3.
                $filterId = $settings[0];
                if (!isset($imageFilters[$filterId])) {
                    continue;
                }
                [$filter, $argCount] = $imageFilters[$filterId];
                // imagefilter() rejects extra arguments since PHP 8, so pass exactly the required number.
                // The smooth filter takes a float weight (e.g. f=11,0.5); the others take ints.
                $args = array_map(
                    $filter === IMG_FILTER_SMOOTH ? 'floatval' : 'intval',
                    array_pad(array_slice($settings, 1, $argCount), $argCount, 0)
                );
                imagefilter($canvas, $filter, ...$args);
            }
        }

        // sharpen image
        if ($sharpen && function_exists('imageconvolution')) {

            $sharpenMatrix = [
                [-1, -1, -1],
                [-1, 16, -1],
                [-1, -1, -1],
            ];

            $divisor = 8;
            $offset = 0;

            imageconvolution($canvas, $sharpenMatrix, $divisor, $offset);
        }
        //Straight from Wordpress core code. Reduces filesize by up to 70% for PNG's
        // Skipped when the canvas uses alpha transparency: imagetruecolortopalette() drops the alpha channel,
        // which would turn the transparent background opaque.
        if (!$useTransparentCanvas && (IMAGETYPE_PNG == $origType || IMAGETYPE_GIF == $origType) && function_exists('imageistruecolor') && !imageistruecolor($image) && imagecolortransparent($image) >= 0) {
            imagetruecolortopalette($canvas, false, imagecolorstotal($image));
        }

        $tempFilePath = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        if (preg_match('@^image/(?:jpg|jpeg)$@i', $mimeType)) {
            $imgType = 'jpg';
            imagejpeg($canvas, $tempFilePath, $quality);
        } elseif (preg_match('@^image/png$@i', $mimeType)) {
            $imgType = 'png';
            imagepng($canvas, $tempFilePath, (int) floor($quality * 0.09));
            if (self::config('png.optipngEnabled')) {
                $this->handleOptiPng($tempFilePath);
            } elseif (self::config('png.pngcrushEnabled')) {
                $this->handlePngCrush($tempFilePath);
            }
        } elseif (preg_match('@^image/gif$@i', $mimeType)) {
            $imgType = 'gif';
            imagegif($canvas, $tempFilePath);
        } else {
            return $this->sanityFail("Could not match mime type after verifying it previously.");
        }

        $this->debug(3, "Rewriting image with security header.");
        $secureTempFilePath = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        file_put_contents(
            $secureTempFilePath,
            $this->filePrependSecurityBlock . $imgType . ' ?' . '>'
        ); //6 extra bytes, first 3 being image type
        file_put_contents(
            $secureTempFilePath,
            file_get_contents($tempFilePath, false, stream_context_create()),
            FILE_APPEND
        );
        @unlink($tempFilePath);
        $this->debug(3, "Locking and replacing cache file.");
        $lockFile = $this->cachefilePath . '.lock';
        $fh = fopen($lockFile, 'w');
        if (!$fh) {
            @unlink($secureTempFilePath);
            return $this->error("Could not open the lockfile for writing an image.");
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            @unlink($lockFile);
            @unlink($secureTempFilePath);
            return $this->error("Could not get a lock for writing.");
        }
        @unlink($this->cachefilePath); //rename generally overwrites, but doing this in case of platform specific quirks. File might not exist yet.
        rename($secureTempFilePath, $this->cachefilePath);
        flock($fh, LOCK_UN);
        fclose($fh);

        @unlink($lockFile);
        $this->debug(3, "Done image replace with security header. Cleaning up and running cleanCache()");
        return true;
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

    protected function calcDocRoot()
    {
        $docRoot = defined('LOCAL_FILE_BASE_DIRECTORY') ? constant('LOCAL_FILE_BASE_DIRECTORY') : self::server('DOCUMENT_ROOT');

        if (!$docRoot) {
            $this->debug(3, "DOCUMENT_ROOT is not set. This is probably windows. Starting search 1.");
            if (self::server('SCRIPT_FILENAME')) {
                $docRoot = str_replace('\\', '/', substr(self::server('SCRIPT_FILENAME'), 0, 0 - strlen(self::server('PHP_SELF'))));
                $this->debug(3, "Generated docRoot using SCRIPT_FILENAME and PHP_SELF as: $docRoot");
            }
        }
        if (!$docRoot) {
            $this->debug(3, "DOCUMENT_ROOT still is not set. Starting search 2.");
            if (self::server('PATH_TRANSLATED')) {
                $docRoot = str_replace(
                    '\\',
                    '/',
                    substr(
                        str_replace('\\\\', '\\', self::server('PATH_TRANSLATED')),
                        0,
                        0 - strlen(self::server('PHP_SELF'))
                    )
                );
                $this->debug(3, "Generated docRoot using PATH_TRANSLATED and PHP_SELF as: $docRoot");
            }
        }
        if ($docRoot && self::server('DOCUMENT_ROOT') !== '/') {
            $docRoot = rtrim($docRoot, '/');
        }
        $this->debug(3, "Doc root is: " . $docRoot);
        return $docRoot;
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
                return $this->realpath($file);
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

    protected function realpath($path)
    {
        return realpath($path);
    }

    protected function toDelete($tempFilePath)
    {
        $this->debug(3, "Scheduling file $tempFilePath to delete on destruct.");
        $this->toDeletes[] = $tempFilePath;
    }

    protected function serveExternalImage()
    {
        if (!preg_match('@^https?://[a-zA-Z0-9\-.]+@i', $this->src)) {
            $this->error("Invalid URL supplied.");
            return false;
        }
        $tempFilePath = tempnam($this->cacheDirectory, 'timthumb');
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

        $mimeType = $this->getMimeType($tempFilePath);
        if (!preg_match("@^image/(?:jpg|jpeg|gif|png)$@i", $mimeType)) {
            $this->debug(3, "Remote file has invalid mime type: $mimeType");
            @unlink($this->cachefilePath);
            touch($this->cachefilePath);
            $this->error("The remote file is not a valid image. Mimetype = '" . $mimeType . "'");
            return false;
        }

        if (!$this->processImageAndWriteToCache($tempFilePath)) {
            return false;
        }

        $this->debug(3, "Image processed succesfully. Serving from cache");
        return $this->serveCacheFile();
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

    protected function serveCacheFile()
    {
        $this->debug(3, "Serving {$this->cachefilePath}");
        if (!is_file($this->cachefilePath)) {
            $this->error("serveCacheFile called in timthumb but we couldn't find the cached file.");
            return false;
        }

        $fp = fopen($this->cachefilePath, 'rb');
        if (!$fp) {
            $this->error("Could not open cachefile.");
            return false;
        }

        $securityBlockSize = strlen($this->filePrependSecurityBlock);
        fseek($fp, $securityBlockSize, SEEK_SET);

        $this->sendImageHeaders(
            fread($fp, 3),
            filesize($this->cachefilePath) - ($securityBlockSize + 6)
        );

        fseek($fp, 3, SEEK_CUR);
        while (!feof($fp)) {
            $bytesSent = @fread($fp, 8192);
            if ($bytesSent === false) {
                $this->error("Error reading from cachefile.");
                break;
            }
            echo $bytesSent;
        }

        fclose($fp);
        return true;
    }

    protected function sendImageHeaders($mimeType, $dataSize)
    {
        if (!preg_match('@^image/@i', $mimeType)) {
            $mimeType = 'image/' . $mimeType;
        }
        if (strtolower($mimeType) === 'image/jpg') {
            $mimeType = 'image/jpeg';
        }
        $gmdate_expires = gmdate('D, d M Y H:i:s', self::now() + (int) self::config('browserCache.maxAge')) . ' GMT';
        // send content headers then display image
        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: none'); //Changed this because we don't accept range requests
        header('Content-Length: ' . $dataSize);
        $etag = '"' . filemtime($this->cachefilePath) . '"';
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

    protected function openImage($mimeType, $src)
    {
        if ($mimeType === 'image/jpeg') {
            return imagecreatefromjpeg($src);
        }

        if ($mimeType === 'image/png') {
            $image = imagecreatefrompng($src);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            return $image;
        }

        if ($mimeType === 'image/gif') {
            return imagecreatefromgif($src);
        }

        $this->error("Unrecognised mimeType");
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
        if (!$level || self::config('debug.level') < $level) {
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

    protected function sanityFail($msg)
    {
        return $this->error(
            "There is a problem in the timthumb code. Message: Please report this error at <a href='https://code.google.com/archive/p/timthumb/'>timthumb's bug tracking page</a>: $msg"
        );
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
        $needed    = ($srcWidth * $srcHeight + $dstWidth * $dstHeight) * 5;
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
        $url = str_replace(' ', '%20', $url);
        if (extension_loaded('curl')) {
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

        // External images are rejected earlier in isExternalImageAllowed() when curl is missing.
        $this->lastURLError = ' External images require the PHP curl extension.';
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
        $ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36";
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, self::config('curlTimeout'));
        curl_setopt($curlHandle, CURLOPT_USERAGENT, $ua);
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

    // base64 encoded red image that says 'no hotlinkers'
    // nothing to worry about! :)
    protected function dispRedImage()
    {
        $imgData = base64_decode("R0lGODlhUAAMAIAAAP8AAP///yH5BAAHAP8ALAAAAABQAAwAAAJpjI+py+0Po5y0OgAMjjv01YUZ\nOGplhWXfNa6JCLnWkXplrcBmW+spbwvaVr/cDyg7IoFC2KbYVC2NQ5MQ4ZNao9Ynzjl9ScNYpneb\nDULB3RP6JuPuaGfuuV4fumf8PuvqFyhYtjdoeFgAADs=");
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($imgData));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: ' . gmdate('D, d M Y H:i:s', self::server('REQUEST_TIME')));
        return $imgData;
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
        $key = strtoupper($key);
        return $_SERVER[$key] ?? $default;
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
