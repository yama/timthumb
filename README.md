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

### f – image filters
Filters are controlled through the 'f' query variable. By specifying parameters, various filter effects are translated.

Some filters require arguments such as color values or filter strength (amount of contrast, etc.), so you need to pass the filter ID followed by the arguments in a comma-separated list. For instance, the brightness filter (ID 3) requires one argument – so to set the brightness strength to 10, it would look like this:

```
f=1,10
```

The image filters and arguments that are available are as follows:

- `1` = Negate – Invert colours
- `2` = Grayscale – turn the image into shades of grey
- `3` = Brightness – Adjust brightness of image. Requires 1 argument to specify the amount of brightness to add. Values can be negative to make the image darker.
- `4` = Contrast – Adjust contrast of image. Requires 1 argument to specify the amount of contrast to apply. Values greater than 0 will reduce the contrast and less than 0 will increase the contrast.
- `5` = Colorize/ Tint – Apply a colour wash to the image. Requires the most parameters of all filters. The arguments are RGBA
- `6` = Edge Detect – Detect the edges on an image
- `7` = Emboss – Emboss the image (give it a kind of depth), can look nice when combined with the colorize filter above.
- `8` = Gaussian Blur – blur the image, unfortunately, you can’t specify the amount, but you can apply the same filter multiple times (as shown in the demos)
- `9` = Selective Blur – a different type of blur. Not sure what the difference is, but this blur is less strong than the Gaussian blur.
- `10` = Mean Removal – Uses mean removal to create a “sketchy” effect.
- `11` = Smooth – Makes the image smoother.

### Specifying Multiple Filters at Once

You can chain multiple filters together. To do this, simply use the pipe character to separate multiple filters and pass the whole to TimThumb. For example, the following value applies a brightness of 10 to a grayscale image.

```
f=2|1,10
```
