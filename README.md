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
