<?php
/**
 * TimThumb by Ben Gillbanks and Mark Maunder
 * Based on work done by Tim McDaniels and Darren Hoyt
 * http://code.google.com/p/timthumb/
 *
 * GNU General Public License, version 2
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * Examples and documentation available on the project homepage
 * http://www.binarymoon.co.uk/projects/timthumb/
 *
 */

const VERSION = '2.8.16'; // Version of this script
if(!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}

timthumb::start();

class timthumb {
    protected $src = '';
    protected $is404 = false;
    protected $docRoot = '';
    protected $lastURLError = false;
    protected $localImage = '';
    protected $localImageMTime = 0;
    protected $url = false;
    protected $myHost = '';
    protected $isURL = false;
    protected $cachefile = '';
    protected $errors = array();
    protected $toDeletes = array();
    protected $cacheDirectory = '';
    protected $startTime = 0;
    protected $lastBenchTime = 0;
    protected $cropTop = false;
    protected $salt = '';
    //Generally if timthumb.php is modifed (upgraded) then the salt changes and all cache files are recreated. This is a backup mechanism to force regen.
    protected $fileCacheVersion = 1;
    //Designed to have three letter mime type, space, question mark and greater than symbol appended. 6 bytes total.
    protected $filePrependSecurityBlock = "<?php die('Execution denied!'); //";
    protected static $curlDataWritten = 0;
    protected static $curlFH = false;
    protected $init_rs;

    public function __construct(){
        date_default_timezone_set('UTC');
        $this->loadConfig();
        $this->debug(1, sprintf('Starting new request from %s to %s', $this->getIP(), serverv('REQUEST_URI')));
        $this->calcDocRoot();
        //On windows systems I'm assuming fileinode returns an empty string or a number that doesn't change. Check this.
        $this->salt = @filemtime(__FILE__) . '-' . @fileinode(__FILE__);
        $this->debug(3, 'Salt is: ' . $this->salt);
        if(CONF::$FILE_CACHE_DIRECTORY){
            if(! is_dir(CONF::$FILE_CACHE_DIRECTORY)){
                @mkdir(CONF::$FILE_CACHE_DIRECTORY);
                if(! is_dir(CONF::$FILE_CACHE_DIRECTORY)){
                    $this->error('Could not create the file cache directory.');
                    $this->init_rs = false;
                    return;
                }
            }
            $this->cacheDirectory = CONF::$FILE_CACHE_DIRECTORY;
            if (!touch($this->cacheDirectory . '/index.html')) {
                $this->error('Could not create the index.html file - to fix this create an empty file named index.html file in the cache directory.');
            }
        } else {
            $this->cacheDirectory = sys_get_temp_dir();
        }
        //Clean the cache before we do anything because we don't want the first visitor after CONF::$FILE_CACHE_TIME_BETWEEN_CLEANS expires to get a stale image. 
        $this->cleanCache();

        $this->myHost = preg_replace('/^www\./i', '', serverv('HTTP_HOST'));
        $this->src = getv('src');
        $this->url = parse_url($this->src);
        $this->src = preg_replace('/https?:\/\/(?:www\.)?' . $this->myHost . '/i', '', $this->src);

        if(strlen($this->src) <= 3){
            $this->error('No image specified');
            $this->init_rs = false;
            return;
        }
        if(CONF::$BLOCK_EXTERNAL_LEECHERS && serverv('HTTP_REFERER')) {
            $this->dispRedImage();
        }
        if(preg_match('/^https?:\/\/[^\/]+/i', $this->src)){
            $this->debug(2, 'Is a request for an external URL: ' . $this->src);
            $this->isURL = true;
        } else {
            $this->debug(2, 'Is a request for an internal file: ' . $this->src);
        }
        if($this->isURL && (! CONF::$ALLOW_EXTERNAL)){
            $this->error('You are not allowed to fetch images from an external website.');
            $this->init_rs = false;
            return;
        }
        if($this->isURL){
            if(CONF::$ALLOW_ALL_EXTERNAL_SITES){
                $this->debug(2, 'Fetching from all external sites is enabled.');
            } else {
                $this->debug(2, 'Fetching only from selected external sites is enabled.');
                $allowed = false;
                foreach(CONF::$ALLOWED_SITES as $site){
                    if (strtolower(substr($this->url['host'],-strlen($site)-1)) === strtolower('.' . $site) || (strtolower($this->url['host'])===strtolower($site))) {
                        $this->debug(3, sprintf(
                            'URL hostname %s matches %s so allowing.'
                            , $this->url['host']
                            , $site
                        ));
                        $allowed = true;
                    }
                }
                if(! $allowed){
                    $this->error(
                        sprintf(
                            'You may not fetch images from that site. To enable this site in timthumb, you can either add it to $ALLOWED_SITES and set %s=true. Or you can set %s=true, depending on your security needs.'
                            , CONF::$ALLOW_EXTERNAL
                            , CONF::$ALLOW_ALL_EXTERNAL_SITES
                        )
                    );
                    $this->init_rs = false;
                    return;
                }
            }
        }

        $cachePrefix = ($this->isURL ? '_ext_' : '_int_');
        if($this->isURL){
            $arr = explode('&', serverv('QUERY_STRING'));
            asort($arr);
            $this->cachefile = sprintf(
                '%s/%s%s%s%s',
                $this->cacheDirectory,
                CONF::$FILE_CACHE_PREFIX,
                $cachePrefix,
                md5($this->salt . implode('', $arr) . $this->fileCacheVersion),
                CONF::$FILE_CACHE_SUFFIX
            );
        } else {
            $this->localImage = $this->getLocalImagePath($this->src);
            if(! $this->localImage){
                $this->debug(1, 'Could not find the local image: ' . $this->localImage);
                $this->error('Could not find the internal image you specified.');
                $this->set404();
                $this->init_rs = false;
                return;
            }
            $this->debug(1, 'Local image path is ' . $this->localImage);
            $this->localImageMTime = @filemtime($this->localImage);
            //We include the mtime of the local file in case in changes on disk.
            $this->cachefile = sprintf(
                '%s/%s%s%s%s',
                $this->cacheDirectory,
                CONF::$FILE_CACHE_PREFIX,
                $cachePrefix,
                md5($this->salt . $this->localImageMTime . serverv('QUERY_STRING','') . $this->fileCacheVersion),
                CONF::$FILE_CACHE_SUFFIX
            );
        }
        $this->debug(2, 'Cache file is: ' . $this->cachefile);
        $this->init_rs = false;
    }
    public function __destruct(){
        foreach($this->toDeletes as $del){
            $this->debug(2, 'Deleting temp file ' . $del);
            @unlink($del);
        }
    }

    public static function start(){
        $tim = new timthumb();
        $tim->handleErrors();
        $tim->securityChecks();
        if($tim->tryBrowserCache()){
            exit;
        }
        $tim->handleErrors();
        if(CONF::$FILE_CACHE_ENABLED && $tim->tryServerCache()){
            exit;
        }
        $tim->handleErrors();
        $tim->run();
        $tim->handleErrors();
        exit;
    }

    public function run(){
        if(!$this->isURL) {
            $this->debug(3, 'Got request for internal image. Starting serveInternalImage()');
            $this->serveInternalImage();
            return true;
        }
        if (!CONF::$ALLOW_EXTERNAL) {
            $this->debug(
                1
                , sprintf(
                    'Got a request for an external image but %s is disabled so returning error msg.'
                    , CONF::$ALLOW_EXTERNAL
                )
            );
            $this->error('You are not allowed to fetch images from an external website.');
            return false;
        }
        $this->debug(3, 'Got request for external image. Starting serveExternalImage.');
        if (!getv('webshot')) {
            $this->debug(3, "webshot is NOT set so we're going to try to fetch a regular image.");
            $this->serveExternalImage();
            return true;
        }
        if (CONF::$WEBSHOT_ENABLED) {
            $this->debug(3, "webshot param is set, so we're going to take a webshot.");
            $this->serveWebshot();
        } else {
            $this->error(
                sprintf(
                    'You added the webshot parameter but webshots are disabled on this server. You need to set %s == true to enable webshots.'
                    , CONF::$WEBSHOT_ENABLED
                )
            );
        }
        return true;
    }
    protected function handleErrors(){
        if($this->haveErrors()){
            if(CONF::$NOT_FOUND_IMAGE && $this->is404()){
                if($this->serveImg(CONF::$NOT_FOUND_IMAGE)){
                    exit;
                }

                $this->error('Additionally, the 404 image that is configured could not be found or there was an error serving it.');
            }
            if(CONF::$ERROR_IMAGE){
                if($this->serveImg(CONF::$ERROR_IMAGE)){
                    exit;
                }

                $this->error('Additionally, the error image that is configured could not be found or there was an error serving it.');
            }
            $this->serveErrors();
            exit;
        }
        return false;
    }
    protected function tryBrowserCache(){

        if(CONF::$BROWSER_CACHE_DISABLE) {
            $this->debug(3, 'Browser caching is disabled'); return false;
        }

        //We've already checked if the real file exists in the constructor
        if(! is_file($this->cachefile)){
            //If we don't have something cached, regenerate the cached image.
            return false;
        }

        $mtime = @filemtime($this->cachefile);
        $this->debug(3, sprintf("Cached file's modification time is %s", $mtime));

        if(! $mtime) return false;

        $etag = sprintf('"%s"', $mtime);
        if($etag===filter_input(INPUT_SERVER, 'HTTP_IF_NONE_MATCH')) {
            $this->debug(1, 'Returning 304 not modified');
            $this->debug(3, 'File has not been modified since last get, so serving a 304.');
            header('Content-Length: 0');
            header ('HTTP', true, 304);
            exit;
        }
        header(sprintf('ETag: %s', $etag));
        return false;
    }
    protected function tryServerCache(){
        $this->debug(3, 'Trying server cache');
        if(!is_file($this->cachefile)) {
            return false;
        }

        $this->debug(3, sprintf('Cachefile %s exists', $this->cachefile));
        if ($this->isURL) {
            $this->debug(
                3,
                'This is an external request, so checking if the cachefile is empty which means the request failed previously.'
            );
            if (filesize($this->cachefile) < 1) {
                $this->debug(3, 'Found an empty cachefile indicating a failed earlier request. Checking how old it is.');
                //Fetching error occured previously
                if (now() - @filemtime($this->cachefile) > CONF::$WAIT_BETWEEN_FETCH_ERRORS) {
                    $this->debug(3, sprintf(
                            'File is older than %d seconds. Deleting and returning false so app can try and load file.'
                            , CONF::$WAIT_BETWEEN_FETCH_ERRORS)
                    );
                    @unlink($this->cachefile);
                    return false; //to indicate we didn't serve from cache and app should try and load
                }

                $this->debug(3, 'Empty cachefile is still fresh so returning message saying we had an error fetching this image from remote host.');
                $this->set404();
                $this->error('An error occured fetching image.');
                return false;
            }
        } else {
            $this->debug(3, sprintf(
                'Trying to serve cachefile %s'
                , $this->cachefile
            ));
        }
        if ($this->serveCacheFile()) {
            $this->debug(3, 'Succesfully served cachefile ' . $this->cachefile);
            return true;
        }

        $this->debug(
            3,
            "Failed to serve cachefile " . $this->cachefile . " - Deleting it from cache."
        );
        //Image serving failed. We can't retry at this point, but lets remove it from cache so the next request recreates it
        @unlink($this->cachefile);
        return true;
    }

    protected function error($err){
        $this->debug(3, "Adding error message: $err");
        $this->errors[] = $err;
        return false;

    }
    protected function haveErrors(){
        return count($this->errors) > 0;
    }
    protected function serveErrors(){
        header (serverv('SERVER_PROTOCOL') . ' 400 Bad Request');
        if ( ! CONF::$DISPLAY_ERROR_MESSAGES ) {
            return;
        }
        $html = '<ul>';
        foreach($this->errors as $err){
            $html .= '<li>' . htmlentities($err) . '</li>';
        }
        $html .= '</ul>';
        echo '<h1>A TimThumb error has occured</h1>The following error(s) occured:<br />' . $html . '<br />';
        echo '<br />Query String : ' . htmlentities( serverv('QUERY_STRING'), ENT_QUOTES );
        echo '<br />TimThumb version : ' . VERSION . '</pre>';
    }
    protected function serveInternalImage(){
        $this->debug(3, "Local image path is $this->localImage");
        if(! $this->localImage){
            $this->sanityFail("localImage not set after verifying it earlier in the code.");
            return false;
        }
        $fileSize = filesize($this->localImage);
        if($fileSize > CONF::$MAX_FILE_SIZE){
            $this->error("The file you specified is greater than the maximum allowed file size.");
            return false;
        }
        if($fileSize <= 0){
            $this->error("The file you specified is <= 0 bytes.");
            return false;
        }
        $this->debug(3, "Calling processImageAndWriteToCache() for local image.");
        if($this->processImageAndWriteToCache($this->localImage)){
            $this->serveCacheFile();
            return true;
        }

        return false;
    }

    protected function cleanCache(){
        if (CONF::$FILE_CACHE_TIME_BETWEEN_CLEANS < 0) {
            return true;
        }
        $this->debug(3, "cleanCache() called");
        $lastCleanFile = $this->cacheDirectory . '/timthumb_cacheLastCleanTime.touch';

        //If this is a new timthumb installation we need to create the file
        if(! is_file($lastCleanFile)){
            $this->debug(1, "File tracking last clean doesn't exist. Creating $lastCleanFile");
            if (!touch($lastCleanFile)) {
                $this->error("Could not create cache clean timestamp file.");
            }
            return false;
        }
        //Cache was last cleaned more than 1 day ago
        if(@filemtime($lastCleanFile) < (now() - CONF::$FILE_CACHE_TIME_BETWEEN_CLEANS) ){
            $this->debug(
                1,
                sprintf(
                    "Cache was last cleaned more than %d seconds ago. Cleaning now.",
                    CONF::$FILE_CACHE_TIME_BETWEEN_CLEANS
                )
            );
            // Very slight race condition here, but worst case we'll have 2 or 3 servers cleaning the cache simultaneously once a day.
            if (!touch($lastCleanFile)) {
                $this->error("Could not create cache clean timestamp file.");
            }
            $files = glob($this->cacheDirectory . '/*' . CONF::$FILE_CACHE_SUFFIX);
            if ($files) {
                $timeAgo = now() - CONF::$FILE_CACHE_MAX_FILE_AGE;
                foreach($files as $file){
                    if(@filemtime($file) < $timeAgo){
                        $this->debug(
                            3,
                            sprintf(
                                "Deleting cache file $file older than max age: %d seconds",
                                CONF::$FILE_CACHE_MAX_FILE_AGE
                            )
                        );
                        @unlink($file);
                    }
                }
            }
            return true;
        }

        $this->debug(3, "Cache was cleaned less than " . CONF::$FILE_CACHE_TIME_BETWEEN_CLEANS . " seconds ago so no cleaning needed.");
        return false;
    }
    protected function processImageAndWriteToCache($localImage){
        $sData = getimagesize($localImage);
        $origWidth  = $sData[0];
        $origHeight = $sData[1];
        $origType = $sData[2];
        $mimeType = $sData['mime'];

        $this->debug(3, "Mime type of image is $mimeType");
        if(! preg_match('@^image/(?:gif|jpg|jpeg|png)$@i', $mimeType)){
            return $this->error("The image being resized is not a valid gif, jpg or png.");
        }

        if (!function_exists ('imagecreatetruecolor')) {
            return $this->error(
                'GD Library Error: imagecreatetruecolor does not exist - please contact your webhost and ask them to install the GD library'
            );
        }

        if (function_exists ('imagefilter') && defined ('IMG_FILTER_NEGATE')) {
            $imageFilters = array (
                1 => array (IMG_FILTER_NEGATE, 0),
                2 => array (IMG_FILTER_GRAYSCALE, 0),
                3 => array (IMG_FILTER_BRIGHTNESS, 1),
                4 => array (IMG_FILTER_CONTRAST, 1),
                5 => array (IMG_FILTER_COLORIZE, 4),
                6 => array (IMG_FILTER_EDGEDETECT, 0),
                7 => array (IMG_FILTER_EMBOSS, 0),
                8 => array (IMG_FILTER_GAUSSIAN_BLUR, 0),
                9 => array (IMG_FILTER_SELECTIVE_BLUR, 0),
                10 => array (IMG_FILTER_MEAN_REMOVAL, 0),
                11 => array (IMG_FILTER_SMOOTH, 0),
            );
        }

        // get standard input properties        
        $new_width =  (int) abs (getv('w', 0));
        $new_height = (int) abs (getv('h', 0));
        $zoom_crop = (int) getv('zc', CONF::$DEFAULT_ZC);
        $quality = (int) abs (getv('q', CONF::$DEFAULT_Q));
        $align = $this->cropTop ? 't' : getv('a', 'c');
        $filters = getv('f', CONF::$DEFAULT_F);
        $sharpen = (bool) getv('s', CONF::$DEFAULT_S);
        $canvas_color = getv('cc', CONF::$DEFAULT_CC);
        $canvas_trans = (bool) getv('ct', '1');

        // set default width and height if neither are set already
        if ($new_width == 0 && $new_height == 0) {
            $new_width = (int) CONF::$DEFAULT_WIDTH;
            $new_height = (int) CONF::$DEFAULT_HEIGHT;
        }

        // ensure size limits can not be abused
        $new_width  = min ($origWidth, $new_width, CONF::$MAX_WIDTH);
        $new_height = min ($origHeight, $new_height, CONF::$MAX_HEIGHT);

        // set memory limit to be able to have enough space to resize larger images
        $this->setMemoryLimit();

        // open the existing image
        $image = $this->openImage ($mimeType, $localImage);
        if ($image === false) {
            return $this->error('Unable to open image.');
        }

        // Get original width and height
        $width = imagesx ($image);
        $height = imagesy ($image);
        $origin_x = 0;
        $origin_y = 0;

        // generate new w/h if not provided
        if ($new_width && !$new_height) {
            $new_height = floor ($height * ($new_width / $width));
        } else if ($new_height && !$new_width) {
            $new_width = floor ($width * ($new_height / $height));
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
        $canvas = imagecreatetruecolor ($new_width, $new_height);
        imagealphablending ($canvas, false);

        if (strlen($canvas_color) == 3) { //if is 3-char notation, edit string into 6-char notation
            $canvas_color = sprintf(
                "%s%s%s",
                str_repeat(substr($canvas_color, 0, 1), 2),
                str_repeat(substr($canvas_color, 1, 1), 2),
                str_repeat(substr($canvas_color, 2, 1), 2)
            );
        } else if (strlen($canvas_color) != 6) {
            $canvas_color = CONF::$DEFAULT_CC; // on error return default canvas color
        }

        $canvas_color_R = hexdec (substr ($canvas_color, 0, 2));
        $canvas_color_G = hexdec (substr ($canvas_color, 2, 2));
        $canvas_color_B = hexdec (substr ($canvas_color, 4, 2));

        // Create a new transparent color for image
        // If is a png and PNG_IS_TRANSPARENT is false then remove the alpha transparency 
        // (and if is set a canvas color show it in the background)
        if(preg_match('@^image/png$@i', $mimeType) && !CONF::$PNG_IS_TRANSPARENT && $canvas_trans){
            $color = imagecolorallocatealpha ($canvas, $canvas_color_R, $canvas_color_G, $canvas_color_B, 127);
        }else{
            $color = imagecolorallocatealpha ($canvas, $canvas_color_R, $canvas_color_G, $canvas_color_B, 0);
        }


        // Completely fill the background of the new image with allocated color.
        imagefill ($canvas, 0, 0, $color);

        // scale down and add borders
        if ($zoom_crop == 2) {

            $final_height = $height * ($new_width / $width);

            if ($final_height > $new_height) {

                $origin_x = $new_width / 2;
                $new_width = $width * ($new_height / $height);
                $origin_x = round ($origin_x - ($new_width / 2));

            } else {

                $origin_y = $new_height / 2;
                $new_height = $final_height;
                $origin_y = round ($origin_y - ($new_height / 2));

            }

        }

        // Restore transparency blending
        imagesavealpha ($canvas, true);

        if ($zoom_crop > 0) {

            $src_x = $src_y = 0;
            $src_w = $width;
            $src_h = $height;

            $cmp_x = $width / $new_width;
            $cmp_y = $height / $new_height;

            // calculate x or y coordinate and width or height of source
            if ($cmp_x > $cmp_y) {

                $src_w = round ($width / $cmp_x * $cmp_y);
                $src_x = round (($width - ($width / $cmp_x * $cmp_y)) / 2);

            } else if ($cmp_y > $cmp_x) {

                $src_h = round ($height / $cmp_y * $cmp_x);
                $src_y = round (($height - ($height / $cmp_y * $cmp_x)) / 2);

            }

            // positional cropping!
            if ($align) {
                if (strpos ($align, 't') !== false) {
                    $src_y = 0;
                }
                if (strpos ($align, 'b') !== false) {
                    $src_y = $height - $src_h;
                }
                if (strpos ($align, 'l') !== false) {
                    $src_x = 0;
                }
                if (strpos ($align, 'r') !== false) {
                    $src_x = $width - $src_w;
                }
            }
            imagecopyresampled (
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
            imagecopyresampled (
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

        if ($filters != '' && function_exists ('imagefilter') && defined ('IMG_FILTER_NEGATE')) {
            // apply filters to image
            $filterList = explode ('|', $filters);
            foreach ($filterList as $fl) {
                $settings = explode (',', $fl);
                if (isset ($imageFilters[$settings[0]])) {
                    for ($i = 0; $i < 4; $i ++) {
                        if (isset ($settings[$i])) {
                            $settings[$i] = (int)$settings[$i];
                        } else {
                            $settings[$i] = null;
                        }
                    }
                    $filter = $imageFilters[$settings[0]][0];
                    switch ($imageFilters[$settings[0]][1]) {
                        case 1: // IMG_FILTER_NEGATE
                            imagefilter ($canvas, $filter, $settings[1]);
                            break;
                        case 2: // IMG_FILTER_GRAYSCALE
                            imagefilter ($canvas, $filter, $settings[1], $settings[2]);
                            break;
                        case 3: // IMG_FILTER_BRIGHTNESS
                            imagefilter ($canvas, $filter, $settings[1], $settings[2], $settings[3]);
                            break;
                        case 4: // IMG_FILTER_CONTRAST
                            imagefilter ($canvas, $filter, $settings[1], $settings[2], $settings[3], $settings[4]);
                            break;
                        default:
                            imagefilter ($canvas, $filter);
                            break;
                    }
                }
            }
        }

        // sharpen image
        if ($sharpen && function_exists ('imageconvolution')) {

            $sharpenMatrix = array (
                array (-1,-1,-1),
                array (-1,16,-1),
                array (-1,-1,-1),
            );

            $divisor = 8;
            $offset = 0;

            imageconvolution ($canvas, $sharpenMatrix, $divisor, $offset);

        }
        //Straight from Wordpress core code. Reduces filesize by up to 70% for PNG's
        if ( (IMAGETYPE_PNG == $origType || IMAGETYPE_GIF == $origType) && function_exists('imageistruecolor') && !imageistruecolor( $image ) && imagecolortransparent( $image ) > 0 ){
            imagetruecolortopalette( $canvas, false, imagecolorstotal( $image ) );
        }

        $tempfile = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        if(preg_match('@^image/(?:jpg|jpeg)$@i', $mimeType)){
            $imgType = 'jpg';
            imagejpeg($canvas, $tempfile, $quality);
        } else if(preg_match('@^image/png$@i', $mimeType)){
            $imgType = 'png';
            imagepng($canvas, $tempfile, floor($quality * 0.09));
        } else if(preg_match('@^image/gif$@i', $mimeType)){
            $imgType = 'gif';
            imagegif($canvas, $tempfile);
        } else {
            return $this->sanityFail("Could not match mime type after verifying it previously.");
        }

        if($imgType === 'png' && CONF::$OPTIPNG_ENABLED && CONF::$OPTIPNG_PATH && @is_file(CONF::$OPTIPNG_PATH)){
            $exec = CONF::$OPTIPNG_PATH;
            $this->debug(3, "optipng'ing $tempfile");
            $presize = filesize($tempfile);
            $out = `$exec -o1 $tempfile`; //you can use up to -o7 but it really slows things down
            clearstatcache();
            $aftersize = filesize($tempfile);
            $sizeDrop = $presize - $aftersize;
            if($sizeDrop > 0){
                $this->debug(1, "optipng reduced size by $sizeDrop");
            } else if($sizeDrop < 0){
                $this->debug(1, "optipng increased size! Difference was: $sizeDrop");
            } else {
                $this->debug(1, "optipng did not change image size.");
            }
        } elseif($imgType === 'png' && CONF::$PNGCRUSH_ENABLED && CONF::$PNGCRUSH_PATH && @is_file(CONF::$PNGCRUSH_PATH)){
            $exec = CONF::$PNGCRUSH_PATH;
            $tempfile2 = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
            $this->debug(3, "pngcrush'ing $tempfile to $tempfile2");
            $out = `$exec $tempfile $tempfile2`;
            if(is_file($tempfile2)){
                $sizeDrop = filesize($tempfile) - filesize($tempfile2);
                if($sizeDrop > 0){
                    $this->debug(1, "pngcrush was succesful and gave a $sizeDrop byte size reduction");
                    $todel = $tempfile;
                    $tempfile = $tempfile2;
                } else {
                    $this->debug(1, "pngcrush did not reduce file size. Difference was $sizeDrop bytes.");
                    $todel = $tempfile2;
                }
            } else {
                $this->debug(3, "pngcrush failed with output: $out");
                $todel = $tempfile2;
            }
            @unlink($todel);
        }

        $this->debug(3, "Rewriting image with security header.");
        $tempfile4 = tempnam($this->cacheDirectory, 'timthumb_tmpimg_');
        $context = stream_context_create ();
        $fp = fopen($tempfile,'r',0,$context);
        file_put_contents($tempfile4, $this->filePrependSecurityBlock . $imgType . ' ?' . '>'); //6 extra bytes, first 3 being image type 
        file_put_contents($tempfile4, $fp, FILE_APPEND);
        fclose($fp);
        @unlink($tempfile);
        $this->debug(3, "Locking and replacing cache file.");
        $lockFile = $this->cachefile . '.lock';
        $fh = fopen($lockFile, 'w');
        if(! $fh){
            return $this->error("Could not open the lockfile for writing an image.");
        }
        if(flock($fh, LOCK_EX)){
            @unlink($this->cachefile); //rename generally overwrites, but doing this in case of platform specific quirks. File might not exist yet.
            rename($tempfile4, $this->cachefile);
            flock($fh, LOCK_UN);
            fclose($fh);
            @unlink($lockFile);
        } else {
            fclose($fh);
            @unlink($lockFile);
            @unlink($tempfile4);
            return $this->error("Could not get a lock for writing.");
        }
        $this->debug(3, "Done image replace with security header. Cleaning up and running cleanCache()");
        imagedestroy($canvas);
        imagedestroy($image);
        return true;
    }
    protected function calcDocRoot(){
        $docRoot = defined('LOCAL_FILE_BASE_DIRECTORY') ? LOCAL_FILE_BASE_DIRECTORY : @ serverv('DOCUMENT_ROOT');

        if(!$docRoot){
            $this->debug(3, "DOCUMENT_ROOT is not set. This is probably windows. Starting search 1.");
            if(serverv('SCRIPT_FILENAME')){
                $docRoot = str_replace( '\\', '/', substr(serverv('SCRIPT_FILENAME'), 0, 0-strlen(serverv('PHP_SELF'))));
                $this->debug(3, "Generated docRoot using SCRIPT_FILENAME and PHP_SELF as: $docRoot");
            }
        }
        if(!$docRoot){
            $this->debug(3, "DOCUMENT_ROOT still is not set. Starting search 2.");
            if(serverv('PATH_TRANSLATED')){
                $docRoot = str_replace(
                    '\\',
                    '/',
                    substr(
                        str_replace('\\\\', '\\', serverv('PATH_TRANSLATED')),
                        0,
                        0-strlen(serverv('PHP_SELF'))
                    )
                );
                $this->debug(3, "Generated docRoot using PATH_TRANSLATED and PHP_SELF as: $docRoot");
            }
        }
        if($docRoot && serverv('DOCUMENT_ROOT') !== '/') {
            $docRoot = rtrim($docRoot,'/');
        }
        $this->debug(3, "Doc root is: " . $docRoot);
        $this->docRoot = $docRoot;

    }
    protected function getLocalImagePath($src){
        $src = ltrim($src, '/'); //strip off the leading '/'
        if(! $this->docRoot){
            $this->debug(
                3,
                "We have no document root set, so as a last resort, lets check if the image is in the current dir and serve that."
            );
            //We don't support serving images outside the current dir if we don't have a doc root for security reasons.
            $file = preg_replace('@^.*?([^/\\\\]+)$@', '$1', $src); //strip off any path info and just leave the filename.
            if(is_file($file)){
                return $this->realpath($file);
            }
            return $this->error(
                "Could not find your website document root and the file specified doesn't exist in timthumbs directory. We don't support serving files outside timthumb's directory without a document root for security reasons."
            );
        }

        if ( ! is_dir( $this->docRoot ) ) {
            $this->error("Server path does not exist. Ensure variable \$_SERVER['DOCUMENT_ROOT'] is set correctly");
        }

        //Do not go past this point without docRoot set

        //Try src under docRoot
        if(is_file($this->docRoot . '/' . $src)) {
            $this->debug(3, "Found file as " . $this->docRoot . '/' . $src);
            $real = $this->realpath($this->docRoot . '/' . $src);
            if(stripos($real, $this->docRoot) === 0){
                return $real;
            }

            $this->debug(1, "Security block: The file specified occurs outside the document root.");
            //allow search to continue
        }
        //Check absolute paths and then verify the real path is under doc root
        $absolute = $this->realpath('/' . $src);
        if($absolute && is_file($absolute)){ //realpath does file_exists check, so can probably skip the exists check here
            $this->debug(3, "Found absolute path: $absolute");
            if(! $this->docRoot){ $this->sanityFail("docRoot not set when checking absolute path."); }
            if(stripos($absolute, $this->docRoot) === 0){
                return $absolute;
            }

            $this->debug(1, "Security block: The file specified occurs outside the document root.");
            //and continue search
        }

        $base = $this->docRoot;

        // account for Windows directory structure
        if (strpos(serverv('SCRIPT_FILENAME'), ':') !== false) {
            $sub_directories = explode('\\', str_replace($this->docRoot, '', serverv('SCRIPT_FILENAME')));
        } else {
            $sub_directories = explode('/', str_replace($this->docRoot, '', serverv('SCRIPT_FILENAME')));
        }

        foreach ($sub_directories as $sub){
            $base .= $sub . '/';
            $this->debug(3, "Trying file as: " . $base . $src);
            if(is_file($base . $src)){
                $this->debug(3, "Found file as: " . $base . $src);
                $real = $this->realpath($base . $src);
                if(stripos($real, $this->realpath($this->docRoot)) === 0){
                    return $real;
                }

                $this->debug(1, "Security block: The file specified occurs outside the document root.");
                //And continue search
            }
        }
        return false;
    }
    protected function realpath($path){
        //try to remove any relative paths
        $remove_relatives = '@\w+/\.\./@';
        while(preg_match($remove_relatives,$path)){
            $path = preg_replace($remove_relatives, '', $path);
        }
        //if any remain use PHP realpath to strip them out, otherwise return $path
        //if using realpath, any symlinks will also be resolved
        return preg_match('#^\.\./|/\.\./#', $path) ? realpath($path) : $path;
    }
    protected function toDelete($name){
        $this->debug(3, "Scheduling file $name to delete on destruct.");
        $this->toDeletes[] = $name;
    }
    protected function serveWebshot(){
        $this->debug(3, "Starting serveWebshot");
        $instr = "Please follow the instructions at http://code.google.com/p/timthumb/ to set your server up for taking website screenshots.";
        if(! is_file(CONF::$WEBSHOT_CUTYCAPT)){
            return $this->error("CutyCapt is not installed. $instr");
        }
        if(! is_file(CONF::$WEBSHOT_XVFB)){
            return $this->Error("Xvfb is not installed. $instr");
        }
        if(! preg_match('@^https?://[a-zA-Z0-9.\-]+@i', $this->src)){
            return $this->error("Invalid URL supplied.");
        }
        $cuty = CONF::$WEBSHOT_CUTYCAPT;
        $xv = CONF::$WEBSHOT_XVFB;
        $screenX = CONF::$WEBSHOT_SCREEN_X;
        $screenY = CONF::$WEBSHOT_SCREEN_Y;
        $colDepth = CONF::$WEBSHOT_COLOR_DEPTH;
        $format = CONF::$WEBSHOT_IMAGE_FORMAT;
        $timeout = CONF::$WEBSHOT_TIMEOUT * 1000;
        $ua = CONF::$WEBSHOT_USER_AGENT;
        $jsOn = CONF::$WEBSHOT_JAVASCRIPT_ON ? 'on' : 'off';
        $javaOn = CONF::$WEBSHOT_JAVA_ON ? 'on' : 'off';
        $pluginsOn = CONF::$WEBSHOT_PLUGINS_ON ? 'on' : 'off';
        $proxy = CONF::$WEBSHOT_PROXY ? ' --http-proxy=' . CONF::$WEBSHOT_PROXY : '';
        $tempfile = tempnam($this->cacheDirectory, 'timthumb_webshot');
        $url = $this->src;
        $url = preg_replace('@[^A-Za-z0-9\-._:/?&+;=]+@', '', $url); //RFC 3986 plus ()$ chars to prevent exploit below. Plus the following are also removed: @*!~#[]',
        // 2014 update by Mark Maunder: This exploit: http://cxsecurity.com/issue/WLB-2014060134
        // uses the $(command) shell execution syntax to execute arbitrary shell commands as the web server user. 
        // So we're now filtering out the characters: '$', '(' and ')' in the above regex to avoid this. 
        // We are also filtering out chars rarely used in URLs but legal accoring to the URL RFC which might be exploitable. These include: @*!~#[]',
        // We're doing this because we're passing this URL to the shell and need to make very sure it's not going to execute arbitrary commands. 
        if(CONF::$WEBSHOT_XVFB_RUNNING){
            putenv('DISPLAY=:100.0');
            $command = sprintf(
                '%s %s --max-wait=%d --user-agent="%s" --javascript=%s --java=%s --plugins=%s --js-can-open-windows=off --url="%s" --out-format=%s --out=%s',
                $cuty,
                $proxy,
                $timeout,
                $ua,
                $jsOn,
                $javaOn,
                $pluginsOn,
                $url,
                $format,
                $tempfile
            );
        } else {
            $command = sprintf(
                '%s --server-args="-screen 0, %sx%sx%s" %s %s --max-wait=%d --user-agent="%s" --javascript=%s --java=%s --plugins=%s --js-can-open-windows=off --url="%s" --out-format=%s --out=%s',
                $xv,
                $screenX,
                $screenY,
                $colDepth,
                $cuty,
                $proxy,
                $timeout,
                $ua,
                $jsOn,
                $javaOn,
                $pluginsOn,
                $url,
                $format,
                $tempfile
            );
        }
        $this->debug(3, "Executing command: $command");
        $out = `$command`;
        $this->debug(3, "Received output: $out");
        if(! is_file($tempfile)){
            $this->set404();
            return $this->error("The command to create a thumbnail failed.");
        }
        $this->cropTop = true;
        if($this->processImageAndWriteToCache($tempfile)){
            $this->debug(3, "Image processed succesfully. Serving from cache");
            return $this->serveCacheFile();
        }

        return false;
    }
    protected function serveExternalImage(){
        if(! preg_match('@^https?://[a-zA-Z0-9\-.]+@i', $this->src)){
            $this->error("Invalid URL supplied.");
            return false;
        }
        $tempfile = tempnam($this->cacheDirectory, 'timthumb');
        $this->debug(3, "Fetching external image into temporary file $tempfile");
        $this->toDelete($tempfile);
        #fetch file here
        if(! $this->getURL($this->src, $tempfile)){
            @unlink($this->cachefile);
            touch($this->cachefile);
            $this->debug(3, "Error fetching URL: " . $this->lastURLError);
            $this->error("Error reading the URL you specified from remote host." . $this->lastURLError);
            return false;
        }

        $mimeType = $this->getMimeType($tempfile);
        if(! preg_match("@^image/(?:jpg|jpeg|gif|png)$@i", $mimeType)){
            $this->debug(3, "Remote file has invalid mime type: $mimeType");
            @unlink($this->cachefile);
            touch($this->cachefile);
            $this->error("The remote file is not a valid image. Mimetype = '" . $mimeType . "'" . $tempfile);
            return false;
        }
        if($this->processImageAndWriteToCache($tempfile)){
            $this->debug(3, "Image processed succesfully. Serving from cache");
            return $this->serveCacheFile();
        }

        return false;
    }
    public static function curlWrite($h, $d){
        fwrite(self::$curlFH, $d);
        self::$curlDataWritten += strlen($d);
        if(self::$curlDataWritten > CONF::$MAX_FILE_SIZE){
            return 0;
        }

        return strlen($d);
    }
    protected function serveCacheFile(){
        $this->debug(3, "Serving {$this->cachefile}");
        if(! is_file($this->cachefile)){
            $this->error("serveCacheFile called in timthumb but we couldn't find the cached file.");
            return false;
        }
        $fp = fopen($this->cachefile, 'rb');
        if(! $fp){ return $this->error("Could not open cachefile."); }
        fseek($fp, strlen($this->filePrependSecurityBlock), SEEK_SET);
        $imgType = fread($fp, 3);
        fseek($fp, 3, SEEK_CUR);
        if(ftell($fp) != strlen($this->filePrependSecurityBlock) + 6){
            @unlink($this->cachefile);
            return $this->error("The cached image file seems to be corrupt.");
        }
        $imageDataSize = filesize($this->cachefile) - (strlen($this->filePrependSecurityBlock) + 6);
        $this->sendImageHeaders($imgType, $imageDataSize);
        $bytesSent = @fpassthru($fp);
        fclose($fp);
        if($bytesSent > 0){
            return true;
        }
        $content = file_get_contents ($this->cachefile);
        if ($content != false) {
            $content = substr($content, strlen($this->filePrependSecurityBlock) + 6);
            echo $content;
            $this->debug(3, "Served using file_get_contents and echo");
            return true;
        }

        $this->error("Cache file could not be loaded.");
        return false;
    }
    protected function sendImageHeaders($mimeType, $dataSize){
        if(! preg_match('@^image/@i', $mimeType)){
            $mimeType = 'image/' . $mimeType;
        }
        if(strtolower($mimeType) === 'image/jpg'){
            $mimeType = 'image/jpeg';
        }
        $gmdate_expires = gmdate ('D, d M Y H:i:s', strtotime ('now +10 days')) . ' GMT';
        // send content headers then display image
        header ('Content-Type: ' . $mimeType);
        header ('Accept-Ranges: none'); //Changed this because we don't accept range requests
        header ('Content-Length: ' . $dataSize);
        $etag = '"' . filemtime($this->cachefile) . '"';
        header(sprintf('ETag: %s', $etag));
        if(CONF::$BROWSER_CACHE_DISABLE){
            $this->debug(3, "Browser cache is disabled so setting non-caching headers.");
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header("Pragma: no-cache");
            header('Expires: ' . gmdate ('D, d M Y H:i:s', now()));
        } else {
            $this->debug(3, "Browser caching is enabled");
            header('Cache-Control: max-age=' . CONF::$BROWSER_CACHE_MAX_AGE . ', must-revalidate');
            header('Expires: ' . $gmdate_expires);
        }
        return true;
    }
    protected function securityChecks(){
    }
    protected function openImage($mimeType, $src){
        switch ($mimeType) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg ($src);
                break;

            case 'image/png':
                $image = imagecreatefrompng ($src);
                imagealphablending( $image, true );
                imagesavealpha( $image, true );
                break;

            case 'image/gif':
                $image = imagecreatefromgif ($src);
                break;

            default:
                $this->error("Unrecognised mimeType");
        }

        return $image;
    }
    protected function getIP(){
        $rem = serverv("REMOTE_ADDR");
        $ff = serverv("HTTP_X_FORWARDED_FOR");
        $ci = serverv("HTTP_CLIENT_IP");
        if(preg_match('/^(?:192\.168|172\.16|10\.|127\.)/', $rem)){
            if($ff){ return $ff; }
            if($ci){ return $ci; }
            return $rem;
        }

        if($rem){ return $rem; }
        if($ff){ return $ff; }
        if($ci){ return $ci; }
        return "UNKNOWN";
    }
    protected function debug($level, $msg){
        if(CONF::$DEBUG_ON===false)     return;
        if(CONF::$DEBUG_LEVEL < $level) return;

        $execTime = sprintf('%.6f', microtime(true) - serverv('REQUEST_TIME_FLOAT'));
        $tick = sprintf('%.6f', 0);
        if($this->lastBenchTime > 0){
            $tick = sprintf('%.6f', microtime(true) - $this->lastBenchTime);
        }
        $this->lastBenchTime = microtime(true);
        error_log("TimThumb Debug line " . __LINE__ . " [$execTime : $tick]: $msg");
    }
    protected function sanityFail($msg){
        return $this->error(
            "There is a problem in the timthumb code. Message: Please report this error at <a href='http://code.google.com/p/timthumb/issues/list'>timthumb's bug tracking page</a>: $msg"
        );
    }
    protected function getMimeType($file){
        $info = getimagesize($file);
        if(is_array($info) && $info['mime']){
            return $info['mime'];
        }
        return '';
    }
    protected function setMemoryLimit(){
        $inimem = ini_get('memory_limit');
        $inibytes = timthumb::returnBytes($inimem);
        $ourbytes = timthumb::returnBytes(CONF::$MEMORY_LIMIT);
        if($inibytes < $ourbytes){
            ini_set ('memory_limit', CONF::$MEMORY_LIMIT);
            $this->debug(3, "Increased memory from $inimem to " . CONF::$MEMORY_LIMIT);
        } else {
            $this->debug(3, "Not adjusting memory size because the current setting is " . $inimem . " and our size of " . CONF::$MEMORY_LIMIT . " is smaller.");
        }
    }
    protected static function returnBytes($size_str){
        switch (substr ($size_str, -1))
        {
            case 'M': case 'm': return (int)$size_str * 1024 * 1024;
            case 'K': case 'k': return (int)$size_str * 1024;
            case 'G': case 'g': return (int)$size_str * 1024 * 1024 * 1024;
            default: return $size_str;
        }
    }

    protected function getURL($url, $tempfile){
        $this->lastURLError = false;
        $url = preg_replace('/ /', '%20', $url);
        if(function_exists('curl_init')){
            $this->debug(3, "Curl is installed so using it to fetch URL.");
            self::$curlFH = fopen($tempfile, 'w');
            if(! self::$curlFH){
                $this->error("Could not open $tempfile for writing.");
                return false;
            }
            self::$curlDataWritten = 0;
            $this->debug(3, "Fetching url with curl: $url");
            $curl = curl_init($url);
            curl_setopt ($curl, CURLOPT_TIMEOUT, CONF::$CURL_TIMEOUT);
            curl_setopt ($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/534.30 (KHTML, like Gecko) Chrome/12.0.742.122 Safari/534.30");
            curl_setopt ($curl, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt ($curl, CURLOPT_HEADER, 0);
            curl_setopt ($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt ($curl, CURLOPT_WRITEFUNCTION, 'timthumb::curlWrite');
            @curl_setopt ($curl, CURLOPT_FOLLOWLOCATION, true);
            @curl_setopt ($curl, CURLOPT_MAXREDIRS, 10);

            $curlResult = curl_exec($curl);
            fclose(self::$curlFH);
            $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if($httpStatus == 404){
                $this->set404();
            }
            if($httpStatus == 302){
                $this->error("External Image is Redirecting. Try alternate image url");
                return false;
            }
            if($curlResult){
                curl_close($curl);
                return true;
            }

            $this->lastURLError = curl_error($curl);
            curl_close($curl);
            return false;
        }

        $img = @file_get_contents ($url);
        if($img === false){
            $err = error_get_last();
            if(is_array($err) && $err['message']){
                $this->lastURLError = $err['message'];
            } else {
                $this->lastURLError = $err;
            }
            if(strpos($this->lastURLError, '404') !== false){
                $this->set404();
            }

            return false;
        }
        if(! file_put_contents($tempfile, $img)){
            $this->error("Could not write to $tempfile.");
            return false;
        }
        return true;

    }
    protected function serveImg($file){
        $s = getimagesize($file);
        if(! ($s && $s['mime'])){
            return false;
        }
        header ('Content-Type: ' . $s['mime']);
        header ('Content-Length: ' . filesize($file) );
        header ('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header ("Pragma: no-cache");
        $bytes = @readfile($file);
        if($bytes > 0){
            return true;
        }
        $content = @file_get_contents ($file);
        if ($content != false){
            echo $content;
            return true;
        }
        return false;

    }
    protected function set404(){
        $this->is404 = true;
    }
    protected function is404(){
        return $this->is404;
    }

    // base64 encoded red image that says 'no hotlinkers'
    // nothing to worry about! :)
    protected function dispRedImage() {
        $myhost = '@^https?://(?:www\.)?' . $this->myHost . '(?:$|/)@i';
        if(preg_match($myhost, serverv('HTTP_REFERER'))) {
            return;
        }

        $imgData = base64_decode("R0lGODlhUAAMAIAAAP8AAP///yH5BAAHAP8ALAAAAABQAAwAAAJpjI+py+0Po5y0OgAMjjv01YUZ\nOGplhWXfNa6JCLnWkXplrcBmW+spbwvaVr/cDyg7IoFC2KbYVC2NQ5MQ4ZNao9Ynzjl9ScNYpneb\nDULB3RP6JuPuaGfuuV4fumf8PuvqFyhYtjdoeFgAADs=");
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($imgData));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: ' . gmdate ('D, d M Y H:i:s', serverv('REQUEST_TIME')));
        echo $imgData;
        return false;
    }

    protected function loadConfig() {
        //Load a config file if it exists. Otherwise, use the values below
        $path = sprintf('%s/%s-config.php', __DIR__, basename(__FILE__, '.php'));
        if( is_file($path)) {
            require_once($path);
        }
    }
}

class CONF {
    public static $DEBUG_ON       = false; // Enable debug logging to web server error log (STDERR)
    public static $DEBUG_LEVEL    = 1; // Debug level 1 is less noisy and 3 is the most noisy
    public static $MEMORY_LIMIT   = '30M'; // Set PHP memory limit
    public static $BLOCK_EXTERNAL_LEECHERS = false; // If the image or webshot is being loaded on an external site, display a red "No Hotlinking" gif.
    public static $DISPLAY_ERROR_MESSAGES = true; // Display error messages. Set to false to turn off errors (good for production websites)
    //Image fetching and caching
    public static $ALLOW_EXTERNAL = false; // Allow image fetching from external websites. Will check against ALLOWED_SITES if ALLOW_ALL_EXTERNAL_SITES is false
    public static $ALLOW_ALL_EXTERNAL_SITES = false; // Less secure. 
    public static $FILE_CACHE_ENABLED = TRUE; // Should we store resized/modified images on disk to speed things up?
    public static $FILE_CACHE_TIME_BETWEEN_CLEANS = 86400; // How often the cache is cleaned 

    public static $FILE_CACHE_MAX_FILE_AGE = 86400; // How old does a file have to be to be deleted from the cache
    public static $FILE_CACHE_SUFFIX = '.timthumb.txt'; // What to put at the end of all files in the cache directory so we can identify them
    public static $FILE_CACHE_PREFIX = 'timthumb'; // What to put at the beg of all files in the cache directory so we can identify them
    public static $FILE_CACHE_DIRECTORY = './cache'; // Directory where images are cached. Left blank it will use the system temporary directory (which is better for security)
    public static $MAX_FILE_SIZE = 10485760; // 10 Megs is 10485760. This is the max internal or external file size that we'll process.  
    public static $CURL_TIMEOUT = 20; // Timeout duration for Curl. This only applies if you have Curl installed and aren't using PHP's default URL fetching mechanism.
    public static $WAIT_BETWEEN_FETCH_ERRORS = 3600; // Time to wait between errors fetching remote file

    //Browser caching
    public static $BROWSER_CACHE_MAX_AGE = 864000; // Time to cache in the browser
    public static $BROWSER_CACHE_DISABLE = false; // Use for testing if you want to disable all browser caching

    //Image size and defaults
    public static $MAX_WIDTH = 1920; // Maximum image width
    public static $MAX_HEIGHT = 1920; // Maximum image height
    public static $NOT_FOUND_IMAGE = ''; // Image to serve if any 404 occurs 
    public static $ERROR_IMAGE = ''; // Image to serve if an error occurs instead of showing error message 
    public static $PNG_IS_TRANSPARENT = FALSE; // Define if a png image should have a transparent background color. Use False value if you want to display a custom coloured canvas_colour 
    public static $DEFAULT_Q = 90; // Default image quality.
    public static $DEFAULT_ZC = 1; // Default zoom/crop setting.
    public static $DEFAULT_F = ''; // Default image filters.
    public static $DEFAULT_S = 0; // Default sharpen value.
    public static $DEFAULT_CC = 'ffffff'; // Default canvas colour.
    public static $DEFAULT_WIDTH = 100; // Default thumbnail width.
    public static $DEFAULT_HEIGHT = 100; // Default thumbnail height.

    //These are now disabled by default because the file sizes of PNGs (and GIFs) are much smaller than we used to generate. 
    //They only work for PNGs. GIFs and JPEGs are not affected.
    public static $OPTIPNG_ENABLED = false;
    public static $OPTIPNG_PATH = '/usr/bin/optipng'; //This will run first because it gives better compression than pngcrush. 
    public static $PNGCRUSH_ENABLED = false;
    public static $PNGCRUSH_PATH = '/usr/bin/pngcrush'; //This will only run if OPTIPNG_PATH is not set or is not valid

    public static $WEBSHOT_ENABLED = false;            //Beta feature. Adding webshot=1 to your query string will cause the script to return a browser screenshot rather than try to fetch an image.
    public static $WEBSHOT_CUTYCAPT = '/usr/local/bin/CutyCapt'; //The path to CutyCapt. 
    public static $WEBSHOT_XVFB = '/usr/bin/xvfb-run';        //The path to the Xvfb server
    public static $WEBSHOT_SCREEN_X = '1024';           //1024 works ok
    public static $WEBSHOT_SCREEN_Y = '768';            //768 works ok
    public static $WEBSHOT_COLOR_DEPTH = '24';          //I haven't tested anything besides 24
    public static $WEBSHOT_IMAGE_FORMAT = 'png';        //png is about 2.5 times the size of jpg but is a LOT better quality
    public static $WEBSHOT_TIMEOUT = '20';              //Seconds to wait for a webshot
    public static $WEBSHOT_USER_AGENT = "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0"; //I hate to do this, but a non-browser robot user agent might not show what humans see. So we pretend to be Firefox
    public static $WEBSHOT_JAVASCRIPT_ON = true;        //Setting to false might give you a slight speedup and block ads. But it could cause other issues.
    public static $WEBSHOT_JAVA_ON = false;             //Have only tested this as fase
    public static $WEBSHOT_PLUGINS_ON = true;           //Enable flash and other plugins
    public static $WEBSHOT_PROXY = '';                  //In case you're behind a proxy server. 
    public static $WEBSHOT_XVFB_RUNNING = false;        //ADVANCED: Enable this if you've got Xvfb running in the background.
    public static $ALLOWED_SITES = array();
}

function getv($key, $default='') {
    if (!isset($_GET[$key])) {
        return $default;
    }
    return $_GET[$key];
}

function serverv($key, $default='') {
    if (!isset($_SERVER[strtoupper($key)])) {
        return $default;
    }
    return $_SERVER[strtoupper($key)];
}

function now() {
    static $now = null;
    if($now) {
        return $now;
    }
    $now = time();
    return $now;
}