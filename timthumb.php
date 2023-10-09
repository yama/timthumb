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
if (__FILE__ !== serverv('SCRIPT_FILENAME')) {
    return;
}

$timthumb = new timthumb();
$timthumb->start();

class timthumb
{
    public $version = '2.9.0'; // Version of this script

    public static $config = [
        'debug' => [
            'level'  => 1, // Debug level 1 is less noisy, while 3 is the most verbose. Set to 0 to disable
            'displayErrorMessages' => true, // Display error messages. Set to false to turn off errors (good for production websites)
        ],
        'memoryLimit'   => '30M', // Set PHP memory limit
        'maxFileSize'   => 15728640, // 15 Megs is 15728640. This is the max internal or external file size that we'll process.
        'curlTimeout'   => 20, // Timeout duration for Curl. This only applies if you have Curl installed and aren't using PHP's default URL fetching mechanism.
        'allowedSites'  => [], // Allowed external websites. Example: ['usercontent.google.com', 'img.youtube.com']

        'browserCache' => [
            'maxAge' => 60*60*24*10, // Time to cache in the browser
            'enable' => true, // Use for testing if you want to disable all browser caching
        ],
        'fileCache' => [
            'enabled'           => true, // Should we store resized/modified images on disk to speed things up?
            'timeBetweenCleans' => 60*60*24, // How often the cache is cleaned
            'maxFileAge'        => 60*60*24, // How old does a file have to be to be deleted from the cache
            'suffix'            => '.cache', // What to put at the end of all files in the cache directory so we can identify them
            'prefix'            => 'timthumb', // What to put at the beg of all files in the cache directory so we can identify them
            'directory'         => './cache', // Directory where images are cached. Left blank it will use the system temporary directory (which is better for security)
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
            'isTransparent'   => false, // Define if a png image should have a transparent background color. Use False value if you want to display a custom coloured canvas_colour
            'optipngEnabled'  => false,
            'optipngPath'     => '/usr/bin/optipng', //This will run first because it gives better compression than pngcrush.
            'pngcrushEnabled' => false,
            'pngcrushPath'    => '/usr/bin/pngcrush', //This will only run if `png.optipngPath` is not set or is not valid
        ],

        'waitBetweenFetchErrors' => 3600, // Time to wait between errors fetching remote file
        'blockExternalLeechers'  => false, // If the image is being loaded on an external site, display a red "No Hotlinking" gif.
        'notFoundImage'          => '', // Image to serve if any 404 occurs
        'errorImage'             => '', // Image to serve if an error occurs instead of showing error message
    ];

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
        return static::defaultConfig();
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
        $this->debug(1, sprintf('Starting new request from %s to %s', $this->getClientIP(), serverv('REQUEST_URI')));
        $this->cacheDirectory = $this->getCacheDirectory();

        //Clean the cache before we do anything because we don't want the first visitor after config('fileCache.timeBetweenCleans') expires to get a stale image.
        $this->cleanCache();

        $this->myHost = preg_replace('@^www\.@i', '', serverv('HTTP_HOST'));
        $this->src    = preg_replace('@https?://(?:www\.)?' . $this->myHost . '@i', '', getv('src'));

        if (strlen($this->src) <= 3) {
            $this->error('No image specified');
            return;
        }
        if (config('blockExternalLeechers') && !$this->isAllowedReferer()) {
            echo $this->dispRedImage();
            return;
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
            $arr = explode('&', serverv('QUERY_STRING'));
            asort($arr);
            $this->cachefilePath = $this->generateCacheFilePath(
                $salt . implode('', $arr) . $this->fileCacheVersion
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
            $salt . $this->localImageMTime . serverv('QUERY_STRING', '') . $this->fileCacheVersion
        );
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
        if (config('fileCache.enabled') && $this->tryServerCache()) {
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

        if (!config('allowedSites') || !config('allowExternal', true)) {
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
            config('fileCache.prefix'),
            $this->isExternalURL ? '_ext_' : '_int_',
            md5($seedString),
            config('fileCache.suffix')
        );
        $this->debug(2, 'Cache file is: ' . $cachefilePath);
        return $cachefilePath;
    }

    protected function isExternalImageAllowed() {
        if (!config('allowedSites')) {
            $this->error('You are not allowed to fetch images from an external website.');
            return false;
        }

        $this->debug(2, 'Fetching only from selected external sites is enabled.');
        foreach (config('allowedSites') as $allowedSite) {
            $givenSite = parse_url(getv('src'))['host'];
            $isMatch = preg_match(
                sprintf('/^%1$s$|^\\.%1$s$/i', preg_quote($allowedSite, '/')),
                $givenSite
            );
            if ($isMatch) {
                $this->debug(3, sprintf('URL hostname %s matches %s so allowing.', $givenSite, $allowedSite));
                return true;
            }
        }

        $this->error(
            'You may not fetch images from that site. To enable this site in timthumb, you can either add it to `allowedSites`.'
        );
        return false;
    }

    protected function getCacheDirectory() {
        if (!config('fileCache.directory')) {
            return sys_get_temp_dir();
        }

        if (!is_dir(config('fileCache.directory'))) {
            @mkdir(config('fileCache.directory'));
            if (!is_dir(config('fileCache.directory'))) {
                $this->error('Could not create the file cache directory.');
                return false;
            }
        }

        if (config('fileCache.directory') !== sys_get_temp_dir()) {
            if (!touch(config('fileCache.directory') . '/index.html')) {
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

        return config('fileCache.directory');
    }

    protected function createHtaccessFile()
    {
        $htaccessFilePath = config('fileCache.directory') . '/.htaccess';
        if (is_file($htaccessFilePath)) {
            return true;
        }
        $htaccessContent =
"# Disable directory browsing
Options -Indexes
# Deny access to all files in this directory
Deny from all
";

        return (bool) file_put_contents($htaccessFilePath, $htaccessContent);
    }

    protected function handleErrors()
    {
        if (!$this->errors) {
            return false;
        }

        if (config('notFoundImage') && $this->is404()) {
            if ($this->serveImg(config('notFoundImage'))) {
                exit;
            }

            $this->error(
                'Additionally, the 404 image that is configured could not be found or there was an error serving it.'
            );
        }

        if (config('errorImage')) {
            if ($this->serveImg(config('errorImage'))) {
                exit;
            }

            $this->error(
                'Additionally, the error image that is configured could not be found or there was an error serving it.'
            );
        }

        header(serverv('SERVER_PROTOCOL') . ' 400 Bad Request');
        if (!config('debug.displayErrorMessages')) {
            return;
        }
        $html = '<ul>';
        foreach ($this->errors as $err) {
            $html .= '<li>' . htmlentities($err) . '</li>';
        }
        $html .= '</ul>';
        echo '<h1>A TimThumb error has occured</h1>';
        echo 'The following error(s) occured:<br />' . $html . '<br />';
        echo '<br />Query String : ' . htmlentities(serverv('QUERY_STRING'), ENT_QUOTES);
        echo '<br />TimThumb version : ' . $this->version . '</pre>';

        exit;
    }

    protected function tryBrowserCache()
    {
        if (!config('browserCache.enable')) {
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
        if ($etag === filter_input(INPUT_SERVER, 'HTTP_IF_NONE_MATCH')) {
            $this->debug(1, 'Returning 304 not modified');
            $this->debug(3, 'File has not been modified since last get, so serving a 304.');
            header('Content-Length: 0');
            header('HTTP', true, 304);
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
                if (now() - @filemtime($this->cachefilePath) > config('waitBetweenFetchErrors')) {
                    $this->debug(
                        3,
                        sprintf(
                            'File is older than %d seconds. Deleting and returning false so app can try and load file.',
                            config('waitBetweenFetchErrors')
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
        if ($fileSize > config('maxFileSize')) {
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
        if (config('fileCache.timeBetweenCleans') < 0) {
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
        if (@filemtime($lastCleanFile) < (now() - config('fileCache.timeBetweenCleans'))) {
            $this->debug(
                1,
                sprintf(
                    "Cache was last cleaned more than %d seconds ago. Cleaning now.",
                    config('fileCache.timeBetweenCleans')
                )
            );
            // Very slight race condition here, but worst case we'll have 2 or 3 servers cleaning the cache simultaneously once a day.
            if (!touch($lastCleanFile)) {
                $this->error("Could not create cache clean timestamp file.");
            }
            $files = glob($this->cacheDirectory . '/*' . config('fileCache.suffix'));
            if ($files) {
                $timeAgo = now() - config('fileCache.maxFileAge');
                foreach ($files as $file) {
                    if (@filemtime($file) < $timeAgo) {
                        $this->debug(
                            3,
                            sprintf(
                                "Deleting cache file $file older than max age: %d seconds",
                                config('fileCache.maxFileAge')
                            )
                        );
                        @unlink($file);
                    }
                }
            }
            return true;
        }

        $this->debug(3, "Cache was cleaned less than " . config('fileCache.timeBetweenCleans') . " seconds ago so no cleaning needed.");
        return false;
    }

    protected function processImageAndWriteToCache($localImage)
    {
        $sData      = getimagesize($localImage);
        $origWidth  = $sData[0] ?? null;
        $origHeight = $sData[1] ?? null;
        $origType   = $sData[2] ?? null;
        $mimeType   = $sData['mime'] ?? null;

        $this->debug(3, "Mime type of image is $mimeType");
        if (!preg_match('@^image/(?:gif|jpg|jpeg|png)$@i', $mimeType)) {
            return $this->error("The image being resized is not a valid gif, jpg or png.");
        }

        // get standard input properties
        $new_width    = (int) abs(getv('w', 0));
        $new_height   = (int) abs(getv('h', 0));
        $zoom_crop    = (int) getv('zc', config('default.zc'));
        $quality      = (int) abs(getv('q', config('default.q')));
        $align        = getv('a', 'c');
        $filters      = getv('f', config('default.f'));
        $sharpen      = (bool) getv('s', config('default.s'));
        $canvas_color = ltrim(
            getv('cc', config('default.cc')),
            '#'
        );
        $canvas_trans = (bool) getv('ct', '1');

        // set default width and height if neither are set already
        if ($new_width == 0 && $new_height == 0) {
            $new_width = (int) config('default.w');
            $new_height = (int) config('default.h');
        }

        // ensure size limits can not be abused
        $new_width  = min($origWidth, $new_width, config('maxWidth'));
        $new_height = min($origHeight, $new_height, config('maxHeight'));

        // set memory limit to be able to have enough space to resize larger images
        $this->setMemoryLimit();

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
            $new_height = floor($height * ($new_width / $width));
        } elseif ($new_height && !$new_width) {
            $new_width = floor($width * ($new_height / $height));
        }

        // scale down and add borders
        if ($zoom_crop == 3) {

            $final_height = $height * ($new_width / $width);

            if ($final_height > $new_height) {
                $new_width = $width * ($new_height / $height);
            } else {
                $new_height = $final_height;
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
            $canvas_color = config('default.cc'); // on error return default canvas color
        }

        // Create a new transparent color for image
        // If is a png and PNG_IS_TRANSPARENT is false then remove the alpha transparency
        // (and if is set a canvas color show it in the background)
        $color = imagecolorallocatealpha(
            $canvas,
            hexdec(substr($canvas_color, 0, 2)),
            hexdec(substr($canvas_color, 2, 2)),
            hexdec(substr($canvas_color, 4, 2)),
            ($mimeType === 'image/png' && !config('png.isTransparent') && $canvas_trans)
                ? 127
                : 0
        );

        // Completely fill the background of the new image with allocated color.
        imagefill($canvas, 0, 0, $color);

        // scale down and add borders
        if ($zoom_crop == 2) {

            $final_height = $height * ($new_width / $width);

            if ($final_height > $new_height) {
                $new_width = $width * ($new_height / $height);
                $origin_x = round(($new_width / 2) - ($new_width / 2));
            } else {
                $new_height = $final_height;
                $origin_y = round(($new_height / 2) - ($new_height / 2));
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
                $src_w = round($width / $cmp_x * $cmp_y);
                $src_x = round(($width - ($width / $cmp_x * $cmp_y)) / 2);
            } elseif ($cmp_y > $cmp_x) {
                $src_h = round($height / $cmp_y * $cmp_x);
                $src_y = round(($height - ($height / $cmp_y * $cmp_x)) / 2);
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
                11 => [IMG_FILTER_SMOOTH, 0],
            ];
            // apply filters to image
            $filterList = explode('|', $filters);
            foreach ($filterList as $fl) {
                $settings = explode(',', $fl);
                if (!isset($imageFilters[$settings[0]])) {
                    continue;
                }
                for ($i = 0; $i < 4; $i++) {
                    $settings[$i] = isset($settings[$i]) ? (int)$settings[$i] : null;
                }
                $filter = $imageFilters[$settings[0]][0];
                $args = array_slice($settings, 1);
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
        if ((IMAGETYPE_PNG == $origType || IMAGETYPE_GIF == $origType) && function_exists('imageistruecolor') && !imageistruecolor($image) && imagecolortransparent($image) > 0) {
            imagetruecolortopalette($canvas, false, imagecolorstotal($image));
        }

        $tempFilePath = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        if (preg_match('@^image/(?:jpg|jpeg)$@i', $mimeType)) {
            $imgType = 'jpg';
            imagejpeg($canvas, $tempFilePath, $quality);
        } elseif (preg_match('@^image/png$@i', $mimeType)) {
            $imgType = 'png';
            imagepng($canvas, $tempFilePath, floor($quality * 0.09));
            if (config('png.optipngEnabled')) {
                $this->handleOptiPng($tempFilePath);
            } elseif (config('png.pngcrushEnabled')) {
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

        if (!config('png.optipngPath') || !is_file(config('png.optipngPath'))) {
            $this->debug(3, "optipng is not enabled or optipngPath is not set.");
            return;
        }

        $this->debug(3, "optipng'ing $tempFilePath");
        $orgSize = filesize($tempFilePath);
        exec(config('png.optipngPath') . ' -o1 ' . $tempFilePath); //you can use up to -o7 but it really slows things down
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

    protected function handlePngCrush($tempFilePath) {
        $pngCrushPath = config('png.pngcrushPath');
        if (!$pngCrushPath || !is_file($pngCrushPath)) {
            $this->debug(3, "pngcrush is not enabled or pngcrushPath is not set.");
            return;
        }

        $pngCrushFilePath = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        $this->debug(3, "pngcrush'ing $tempFilePath to $pngCrushFilePath");
        exec("$pngCrushPath $tempFilePath $pngCrushFilePath", $out);
        if (!is_file($pngCrushFilePath)) {
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
        $docRoot = defined('LOCAL_FILE_BASE_DIRECTORY') ? constant('LOCAL_FILE_BASE_DIRECTORY') : serverv('DOCUMENT_ROOT');

        if (!$docRoot) {
            $this->debug(3, "DOCUMENT_ROOT is not set. This is probably windows. Starting search 1.");
            if (serverv('SCRIPT_FILENAME')) {
                $docRoot = str_replace('\\', '/', substr(serverv('SCRIPT_FILENAME'), 0, 0 - strlen(serverv('PHP_SELF'))));
                $this->debug(3, "Generated docRoot using SCRIPT_FILENAME and PHP_SELF as: $docRoot");
            }
        }
        if (!$docRoot) {
            $this->debug(3, "DOCUMENT_ROOT still is not set. Starting search 2.");
            if (serverv('PATH_TRANSLATED')) {
                $docRoot = str_replace(
                    '\\',
                    '/',
                    substr(
                        str_replace('\\\\', '\\', serverv('PATH_TRANSLATED')),
                        0,
                        0 - strlen(serverv('PHP_SELF'))
                    )
                );
                $this->debug(3, "Generated docRoot using PATH_TRANSLATED and PHP_SELF as: $docRoot");
            }
        }
        if ($docRoot && serverv('DOCUMENT_ROOT') !== '/') {
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

        if (!is_dir($docRoot)) {
            $this->error("Server path does not exist. Ensure variable \$_SERVER['DOCUMENT_ROOT'] is set correctly");
        }

        //Do not go past this point without docRoot set

        //Try src under docRoot
        if (is_file($docRoot . '/' . $src)) {
            $this->debug(3, "Found file as " . $docRoot . '/' . $src);
            $real = $this->realpath($docRoot . '/' . $src);
            if (stripos($real, $docRoot) === 0) {
                return $real;
            }

            $this->debug(1, "Security block: The file specified occurs outside the document root.");
            //allow search to continue
        }
        //Check absolute paths and then verify the real path is under doc root
        $absolute = $this->realpath('/' . $src);
        if ($absolute && is_file($absolute)) { //realpath does file_exists check, so can probably skip the exists check here
            $this->debug(3, "Found absolute path: $absolute");
            if (!$docRoot) {
                $this->sanityFail("docRoot not set when checking absolute path.");
            }
            if (stripos($absolute, $docRoot) === 0) {
                return $absolute;
            }

            $this->debug(1, "Security block: The file specified occurs outside the document root.");
            //and continue search
        }

        // account for Windows directory structure
        if (strpos(serverv('SCRIPT_FILENAME'), ':') !== false) {
            $sub_directories = explode('\\', str_replace($docRoot, '', serverv('SCRIPT_FILENAME')));
        } else {
            $sub_directories = explode('/', str_replace($docRoot, '', serverv('SCRIPT_FILENAME')));
        }

        $base = $docRoot;
        foreach ($sub_directories as $sub) {
            $base .= $sub . '/';

            $this->debug(3, "Trying file as: " . $base . $src);
            if (!is_file($base . $src)) {
                continue;
            }

            $this->debug(3, "Found file as: " . $base . $src);
            $real = $this->realpath($base . $src);
            if (stripos($real, $this->realpath($docRoot)) !== 0) {
                $this->debug(1, "Security block: The file specified occurs outside the document root.");
            }

            return $real;
        }
        return false;
    }

    protected function realpath($path)
    {
        //try to remove any relative paths
        $remove_relatives = '@\w+/\.\./@';
        while (preg_match($remove_relatives, $path)) {
            $path = preg_replace($remove_relatives, '', $path);
        }
        //if any remain use PHP realpath to strip them out, otherwise return $path
        //if using realpath, any symlinks will also be resolved
        return preg_match('#^\.\./|/\.\./#', $path) ? realpath($path) : $path;
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
            $this->error("The remote file is not a valid image. Mimetype = '" . $mimeType . "'" . $tempFilePath);
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
        fwrite($fileHandle, $data);
        $dataSize = strlen($data);
        if ($dataSize + ftell($fileHandle) > config('maxFileSize')) {
            return 0;
        }
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
        $gmdate_expires = gmdate('D, d M Y H:i:s', strtotime('now +10 days')) . ' GMT';
        // send content headers then display image
        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: none'); //Changed this because we don't accept range requests
        header('Content-Length: ' . $dataSize);
        $etag = '"' . filemtime($this->cachefilePath) . '"';
        header(sprintf('ETag: %s', $etag));
        if (!config('browserCache.enable')) {
            $this->debug(3, "Browser cache is disabled so setting non-caching headers.");
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header("Pragma: no-cache");
            header('Expires: ' . gmdate('D, d M Y H:i:s', now()));
        } else {
            $this->debug(3, "Browser caching is enabled");
            header('Cache-Control: max-age=' . config('browserCache.maxAge') . ', must-revalidate');
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
        $forwardedFor = trim(explode(',', serverv('HTTP_X_FORWARDED_FOR', ''))[0]);
        $ip = $forwardedFor ?: serverv('HTTP_CLIENT_IP') ?: serverv('REMOTE_ADDR');

        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'illegal';
        }

        return $ip;
    }

    protected function debug($level, $msg)
    {
        if (!$level || config('debug.level') < $level) {
            return;
        }

        $this->lastBenchTime = microtime(true);
        error_log(
            sprintf(
                "TimThumb Debug line %d [%.6f : %.6f]: %s",
                __LINE__,
                (microtime(true) - serverv('REQUEST_TIME_FLOAT')),
                ($this->lastBenchTime ? microtime(true) - $this->lastBenchTime : 0),
                $msg
            )
        );
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
        $info = getimagesize($file);
        if (isset($info['mime'])) {
            return $info['mime'];
        }
        return '';
    }

    protected function setMemoryLimit()
    {
        $inimem   = ini_get('memory_limit');
        $inibytes = $this->returnBytes($inimem);
        $ourbytes = $this->returnBytes(config('memoryLimit'));

        if ($inibytes < $ourbytes) {
            ini_set('memory_limit', config('memoryLimit'));
            $this->debug(
                3,
                sprintf("Increased memory from %s to %s", $inimem, config('memoryLimit'))
            );
            return;
        }

        $this->debug(
            3,
            sprintf(
                "Not adjusting memory size because the current setting is %s and our size of %s is smaller.",
                $inimem,
                config('memoryLimit')
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
            $this->debug(3, "Curl is installed so using it to fetch URL.");
            $this->curlDataWritten = 0;
            $this->debug(3, "Fetching url with curl: $url");

            $curlHandle = curl_init($url);
            $fileHandle = fopen($tempFilePath, 'wb');
            if (!$fileHandle) {
                $this->error("Could not open $tempFilePath for writing.");
                return false;
            }

            $ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36";
            curl_setopt($curlHandle, CURLOPT_TIMEOUT, config('curlTimeout'));
            curl_setopt($curlHandle, CURLOPT_USERAGENT, $ua);
            curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curlHandle, CURLOPT_HEADER, 0);
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, 5);
            curl_setopt($curlHandle, CURLOPT_MAXREDIRS, 10);
            curl_setopt($curlHandle, CURLOPT_WRITEFUNCTION, function ($curlHandle, $data) use ($fileHandle) {
                return $this->curlWrite($curlHandle, $data, $fileHandle);
            });
            $response = curl_exec($curlHandle);
            $httpStatus = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

            if ($response === false) {
                $this->lastURLError = curl_error($curlHandle);
                curl_close($curlHandle);
                fclose($fileHandle);
                return false;
            }

            if ($httpStatus == 302) {
                $this->error("External Image is Redirecting. Try alternate image url");
                curl_close($curlHandle);
                fclose($fileHandle);
                return false;
            }

            if ($httpStatus == 404) {
                $this->set404();
            }

            curl_close($curlHandle);
            fclose($fileHandle);
            return true;
        }

        $img = @file_get_contents($url);
        if ($img === false) {
            $err = error_get_last();
            $this->lastURLError
                = (!is_array($err) || !$err['message'])
                    ? $err
                    : $err['message']
            ;
            if (strpos($this->lastURLError, '404') !== false) {
                $this->set404();
            }
            return false;
        }

        if (!file_put_contents($tempFilePath, $img)) {
            $this->error("Could not write to $tempFilePath.");
            return false;
        }

        return true;
    }

    protected function serveImg($file)
    {
        if (!is_file($file)) {
            return false;
        }
        $mime = mime_content_type($file);
        if (!$mime) {
            return false;
        }

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
        if (!serverv('HTTP_REFERER')) {
            return false;
        }

        $myhost = '@^https?://(?:www\.)?' . $this->myHost . '(?:$|/)@i';
        if (!preg_match($myhost, serverv('HTTP_REFERER'))) {
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
        header('Expires: ' . gmdate('D, d M Y H:i:s', serverv('REQUEST_TIME')));
        return $imgData;
    }
}

function config($key, $default = null) {
    static $config = null;
    if ($config === null) {
        $config = timthumb::mergedConfig();
    }
    $keys = explode('.', $key);
    $value = $config;
    foreach ($keys as $subkey) {
        if (!isset($value[$subkey])) {
            return $default;
        }
        $value = $value[$subkey];
    }
    return $value;
}

function getv($key, $default = '')
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    return $_GET[$key];
}

function serverv($key, $default = '')
{
    if (!isset($_SERVER[strtoupper($key)])) {
        return $default;
    }
    return $_SERVER[strtoupper($key)];
}

function now()
{
    static $now = null;
    if ($now) {
        return $now;
    }
    $now = time();
    return $now;
}
