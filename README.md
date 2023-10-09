# TimThumb Image Resizer

TimThumb is a simple, flexible, lightweight PHP script that resizes images. You provide it with a set of parameters, and it outputs a thumbnail image that you can display on your site. One of its standout features is its straightforward functionality which makes it a great choice for developers looking for an easy-to-use image resizing solution.

TimThumb has seen a massive amount of use across the WordPress world, and a few months after its release, [Ben Gillbanks](https://github.com/binarymoon) took over development from Tim, who is a friend of Darren Hoyts. Darren Hoyts is the individual who created the original script. Since Ben took over, there have been a whole host of changes, bug fixes, and additions, making TimThumb more secure and robust.

Around the year 2010, Ben has distanced himself from the development of TimThumb. The difficulties in addressing security concerns led to a stagnation in development, although it's important to note that an alternative, phpThumb, also had serious vulnerabilities pointed out on several occasions, indicating that TimThumb was not particularly vulnerable in comparison. The complex and large-scale nature of phpThumb might cause hesitation in updating to a safer latest version. On the other hand, updating TimThumb is as simple as overwriting a single file.

Due to these reasons, I have taken over the source code and continue to make improvements to TimThumb. I hope to see an increase in TimThumb users moving forward.

## part 1: Getting Started

TimThumb has been built with a focus on being lightweight, simple, understandable, and secure.

In this post, I will explain the basic usage of the script, namely the minimal parameters required for operation, along with some examples of use.

### Basic Setup

Setting up TimThumb is very easy. All you need to do is place 'timthumb.php' on your server. Although a directory is needed to save the cache files, this will be automatically generated when you run timthumb for the first time. If it doesn't get generated, create a directory manually named 'cache' and set the permissions to 775.

### Basic Usage

TimThumb can be used by just specifying the URL of the image. For example, it would look like this:

```html
<img src="/path/to/timthumb.php?src=/images/image.jpg" />
```

### Basic Parameters

The following parameters are mainly used. With these, you can resize almost any size.

```html
<img src="/path/to/timthumb.php?src=/images/image.jpg&w=200&h=200&q=83" />
```

- `src` The only required parameter. Specifies the path to the image. You can also specify the URL of an image from an external site (※configuration required).
- `w` and `h` Width and height. Optional. If omitted, it will be trimmed/resized to default dimensions (100 x 100).
- `q` Quality. Specifies the compression level of the image. The default is 85. You can specify 100, but it won't make the image any larger or more beautiful than the original.

With just these parameters, you can easily integrate it into your site.

## Part 2: External Site Images

You can trim and resize images located on external sites. The fetched images are saved as cache within your site, so from the second time onwards, there is no access to the external site. Efforts have been made to make it as simple to use as possible, but with security in mind, a minimal additional configuration is necessary.

### Setting

Rename `timthumb-config.php.sample` to `timthumb-config.php`. Open this file and add the following line.

```php
'allowedSites'  => ['img.youtube.com','tinypic.com'],
```
In the sample, it's already written in a commented-out state, so you can simply uncomment it. By enabling this setting, you can use images from external sites.

### Usage

The usage is simple. Just like when resizing images within your site, specify the image URL with the `src` parameter.

## Part 3: Image Filters

You can apply various effects to images such as changes in brightness and contrast, monochromatization, blur, and emboss.

### f – image filters (Filters derived from PHP built-in function imagefilter())

Filters are controlled through the 'f' query variable. By specifying parameters, various filter effects are translated.

Some filters require arguments such as color values or filter strength (amount of contrast, etc.), so you need to pass the filter ID followed by the arguments in a comma-separated list. For instance, the brightness filter (ID 3) requires one argument – so to set the brightness strength to 10, it would look like this:

```
&f=3,10
```

The image filters and arguments that are available are as follows:

- `&f=1` Negate – Invert colours
- `&f=2` Grayscale – turn the image into shades of grey
- `&f=3` Brightness – Adjust brightness of image. Requires 1 argument to specify the amount of brightness to add. Values can be negative to make the image darker.
- `&f=4` Contrast – Adjust contrast of image. Requires 1 argument to specify the amount of contrast to apply. Values greater than 0 will reduce the contrast and less than 0 will increase the contrast.
- `&f=5` Colorize/ Tint – Apply a colour wash to the image. Requires the most parameters of all filters. The arguments are RGBA
- `&f=6` Edge Detect – Detect the edges on an image
- `&f=7` Emboss – Emboss the image (give it a kind of depth), can look nice when combined with the colorize filter above.
- `&f=8` Gaussian Blur – blur the image, unfortunately, you can’t specify the amount, but you can apply the same filter multiple times (as shown in the demos)
- `&f=9` Selective Blur – a different type of blur. Not sure what the difference is, but this blur is less strong than the Gaussian blur.
- `&f=10` Mean Removal – Uses mean removal to create a “sketchy” effect.
- `&f=11` Smooth – Makes the image smoother.

### Specifying Multiple Filters at Once

You can chain multiple filters together. To do this, simply use the pipe character to separate multiple filters and pass the whole to TimThumb. For example, the following value applies a brightness of 10 to a grayscale image.

```
&f=2|3,10
```

### s - Sharpen Image Filter

This is a filter to sharpen the image. It does not use the PHP built-in imagefilter function, so its implementation is separate from the other filters mentioned above.

To use it, add `&s=1` to the TimThumb query string.

## Part 4: Moving the Crop Location

### TimThumb Cropping Alignment/ Positioning

Specifying this parameter allows you to align the cropping area to various edges of the image. It doesn’t use precise positioning with X, Y coordinates but aligns nicely. It should work almost flawlessly.

### Usage

To align the cropping, you need to add a parameter to the query string.

- `&a=c` : center position (this is the default)
- `&a=t` : top align
- `&a=r` : right align
- `&a=b` : bottom align
- `&a=l` : left align
- `&a=tr` : top right align
- `&a=tl` : top left align
- `&a=br` : bottom right align
- `&a=bl` : bottom left align

Here is an example of how to use it:

``````
timthumb.php?src=image.jpg&w=100&h=100&a=t
``````

TimThumb not only provides the functionality to resize images but also has the ability to crop images with different aspect ratios. For instance, it can extract a square image from a rectangular image and generate it as a profile picture. In this context, the only parameter in this section that seems particularly useful is '&a=c'. However, with this setting alone, we frequently received feedback from users such as "the fit is poor" or "it's unusable as content." The parameter to address this issue is the 'zc parameter', which will be explained in the next section.

## Part 5: Proportional Scaling

The specified crop position (&a=*) was a highly requested feature, however, in practice, it lacked practicality, with most cases being adequately handled by the default `&a=c`.

As the user base expanded, a feature that came into demand was proportional scaling in conjunction with cropping. To be honest, it took some time to understand what users were seeking with this new request. Ultimately, what was being asked for was to 'just right' scale the image to fit the necessary dimensions while maintaining the image's aspect ratio. Simple scaling alone led to issues like unsightly margins, aspect ratio distortion, unnatural cropping, etc., making it difficult to 'fit just right'.

From such observations, the `&zc` parameter was born.

### Proportional Image Scaling

The usage is straightforward. For instance, by just providing `&zc=2`, borders are applied as needed, and a fitting thumbnail image is generated.

### &zc = Zoom & Crop

The zc parameter was introduced to TimThumb about three months after its initial release. The reason behind its conception was that the original version's scaling was purely scaling, lacking a cropping feature. Without this, images could distort terribly.

The new scaling modes added by the &zc parameter are as follows:

- `0` Resize to the specified dimensions (no cropping)
- `1` Resize and crop to the dimensions (default)
- `2` Proportionally resize the image so the entire image fits the specified dimensions, adding borders as needed
- `3` Proportionally resize the scaled image to the dimensions, ensuring no border gaps occur

## Part 6: List of Parameters

| Parameter | Origin           | Value                 | Description                                             |
|-----------|------------------|-----------------------|------------------------------------------------------|
| src       | source           | URL of the image      | Instructs TimThumb which image to resize             |
| w         | width            | Width after resizing  | To scale proportionally, omit the width (height is required) |
| h         | height           | Height after resizing | To scale proportionally, omit the height (width is required) |
| q         | quality          | 0～100                | Compression quality. The higher the value, the better the image looks. It's not recommended to go above 95 as the image may become too large |
| a         | alignment        | c, t, l, r, b, tl, tr, bl, br | Alignment for cropping. c = center, t = top, b = bottom, r = right, l = left. Positions can be combined to create diagonal positions |
| zc        | zoom/crop        | 0, 1, 2, 3            | Changes settings for cropping and scaling            |
| f         | filters          | Too many to mention   | Apply image filters to modify the resized image. For example, you can change brightness/contrast or blur the image |
| s         | sharpen          |                       | Applying a sharp filter makes the resized image appear slightly sharper |
| cc        | canvas colour    | Hex color value (#ffffff) | Changes the background color. Mostly used when changing zoom or trimming settings, and it might add borders to the image |
| ct        | canvas transparency | True (1)          | Utilizes transparency, making the background color null |

## Part 7: Configuration

The query string parameters are used to specify processing for each image, but the settings introduced here are for specifying the behavior of TimThumb itself. First, rename the file named 'timthumb-config.php.sample' to 'timthumb-config.php'. Then, extract the necessary settings from the CONF configuration within the 'timthumb.php' file, and write them into the 'timthumb-config.php' file.

- `debug`
    - `level`
        - Default Value: 1
        - Comment: Debug level 1 is less noisy, while 3 is the most verbose. Set to 0 to disable
    - `displayErrorMessages`
        - Default Value: true
        - Comment: Display error messages. Set to false to turn off errors (good for production websites)
- `memoryLimit`
    - Default Value: '30M'
    - Comment: Set PHP memory limit
- `maxFileSize`
    - Default Value: 15728640
    - Comment: 15 Megs is 15728640. This is the max internal or external file size that we'll process.
- `curlTimeout`
    - Default Value: 20
    - Comment: Timeout duration for Curl. This only applies if you have Curl installed and aren't using PHP's default URL fetching mechanism.
- `allowedSites`
    - Default Value: []
    - Comment: Allowed external websites. Example: ['usercontent.google.com', 'img.youtube.com']
- `browserCache`
    - `maxAge`
        - Default Value: 60*60*24*10
        - Comment: Time to cache in the browser
    - `enable`
        - Default Value: true
        - Comment: Use for testing if you want to disable all browser caching
- `fileCache`
    - `enabled`
        - Default Value: true
        - Comment: Should we store resized/modified images on disk to speed things up?
    - `timeBetweenCleans`
        - Default Value: 60*60*24
        - Comment: How often the cache is cleaned
    - `maxFileAge`
        - Default Value: 60*60*24
        - Comment: How old does a file have to be to be deleted from the cache
    - `suffix`
        - Default Value: '.cache'
        - Comment: What to put at the end of all files in the cache directory so we can identify them
    - `prefix`
        - Default Value: 'timthumb'
        - Comment: What to put at the beg of all files in the cache directory so we can identify them
    - `directory`
        - Default Value: './cache'
        - Comment: Directory where images are cached. Left blank it will use the system temporary directory (which is better for security)
- `maxWidth`
    - Default Value: 1920
    - Comment: Maximum image width
- `maxHeight`
    - Default Value: 1920
    - Comment: Maximum image height
- `default`
    - `q`
        - Default Value: 90
        - Comment: Default image quality
    - `zc`
        - Default Value: 1
        - Comment: Default zoom/crop setting
    - `f`
        - Default Value: ''
        - Comment: Default image filters
    - `s`
        - Default Value: 0
        - Comment: Default sharpen value
    - `cc`
        - Default Value: 'ffffff'
        - Comment: Default canvas colour
    - `width`
        - Default Value: 200
        - Comment: Default thumbnail width
    - `height`
        - Default Value: 200
        - Comment: Default thumbnail height
- `png`
    - `isTransparent`
        - Default Value: false
        - Comment: Define if a png image should have a transparent background color. Use False value if you want to display a custom coloured canvas_colour
    - `optipngEnabled`
        - Default Value: false
        - Comment:
    - `optipngPath`
        - Default Value: '/usr/bin/optipng'
        - Comment: This will run first because it gives better compression than pngcrush.
    - `pngcrushEnabled`
        - Default Value: false
        - Comment:
    - `pngcrushPath`
        - Default Value: '/usr/bin/pngcrush'
        - Comment: This will only run if `png.optipngPath` is not set or is not valid
- `webshot`
    - `enabled`
        - Default Value: false
        - Comment: Beta feature. Adding webshot=1 to your query string will cause the script to return a browser screenshot rather than try to fetch an image.
    - `cutyCapt`
        - Default Value: '/usr/local/bin/CutyCapt'
        - Comment: The path to CutyCapt.
    - `xvfb`
        - Default Value: '/usr/bin/xvfb-run'
        - Comment: The path to the Xvfb server
    - `screenX`
        - Default Value: '1024'
        - Comment: 1024 works ok
    - `screenY`
        - Default Value: '768'
        - Comment: 768 works ok
    - `colorDepth`
        - Default Value: '24'
        - Comment: I haven't tested anything besides 24
    - `imageFormat`
        - Default Value: 'png'
        - Comment: png is about 2.5 times the size of jpg but is a LOT better quality
    - `timeout`
        - Default Value: '20'
        - Comment: Seconds to wait for a webshot
    - `userAgent`
        - Default Value: "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0"
        - Comment: I hate to do this, but a non-browser robot user agent might not show what humans see. So we pretend to be Firefox
    - `javascriptOn`
        - Default Value: true
        - Comment: Setting to false might give you a slight speedup and block ads. But it could cause other issues.
    - `javaOn`
        - Default Value: false
        - Comment: Have only tested this as false
    - `pluginsOn`
        - Default Value: true
        - Comment: Enable flash and other plugins
    - `proxy`
        - Default Value: ''
        - Comment: In case you're behind a proxy server.
    - `xvfbRunning`
        - Default Value: false
        - Comment: ADVANCED: Enable this if you've got Xvfb running in the background.
- `waitBetweenFetchErrors`
    - Default Value: 3600
    - Comment: Time to wait between errors fetching remote file
- `blockExternalLeechers`
    - Default Value: false
    - Comment: If the image or webshot is being loaded on an external site, display a red "No Hotlinking" gif.
- `notFoundImage`
    - Default Value: ''
    - Comment: Image to serve if any 404 occurs
- `errorImage`
    - Default Value: ''
    - Comment: Image to serve if an error occurs instead of showing error message
