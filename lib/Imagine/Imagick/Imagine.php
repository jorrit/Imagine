<?php

/*
 * This file is part of the Imagine package.
 *
 * (c) Bulat Shakirzyanov <mallluhuct@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Imagine\Imagick;

use Imagine\Exception\NotSupportedException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\BoxInterface;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Exception\InvalidArgumentException;
use Imagine\Exception\RuntimeException;
use Imagine\Image\Palette\CMYK;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Palette\Grayscale;
use Imagine\File\LoaderInterface;
use Imagine\File\Loader;

/**
 * Imagine implementation using the Imagick PHP extension
 */
final class Imagine extends AbstractImagine
{
    /**
     * @throws RuntimeException
     */
    public function __construct()
    {
        if (!class_exists('Imagick')) {
            throw new RuntimeException('Imagick not installed');
        }

        $version = $this->getVersion(new \Imagick());

        if (version_compare('6.2.9', $version) > 0) {
            throw new RuntimeException(sprintf('ImageMagick version 6.2.9 or higher is required, %s provided', $version));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function open($path)
    {
        $loader = $path instanceof LoaderInterface ? $path : new Loader($path);
        $path = $loader->getPath();

        try {
            $imagick = new \Imagick();
            if ($loader->isLocalFile()) {
                // Don't pass the file name to the constructor - it may break PHP on Windows - see https://github.com/mkoppanen/imagick/issues/252
                $imagick->readImageBlob($loader->getData(), $path);
            } else {
                $imagick->readImageBlob($loader->getData());
            }
            $image = new Image($imagick, $this->createPalette($imagick), $this->getMetadataReader()->readFile($loader));
        } catch (\ImagickException $e) {
            throw new RuntimeException(sprintf('Unable to open image %s', $path), $e->getCode(), $e);
        }

        return $image;
    }

    /**
     * {@inheritdoc}
     */
    public function create(BoxInterface $size, ColorInterface $color = null)
    {
        $width  = $size->getWidth();
        $height = $size->getHeight();

        $palette = null !== $color ? $color->getPalette() : new RGB();
        $color = null !== $color ? $color : $palette->color('fff');

        try {
            $pixel = new \ImagickPixel((string) $color);
            $pixel->setColorValue(\Imagick::COLOR_ALPHA, $color->getAlpha() / 100);

            $imagick = new \Imagick();
            $imagick->newImage($width, $height, $pixel);
            $imagick->setImageMatte(true);
            $imagick->setImageBackgroundColor($pixel);

            if (version_compare('6.3.1', $this->getVersion($imagick)) < 0) {
                // setImageOpacity was replaced with setImageAlpha in php-imagick v3.4.3
                if (method_exists($imagick, 'setImageAlpha')) {
                    $imagick->setImageAlpha($pixel->getColorValue(\Imagick::COLOR_ALPHA));
                } else {
                    $imagick->setImageOpacity($pixel->getColorValue(\Imagick::COLOR_ALPHA));
                }
            }

            $pixel->clear();
            $pixel->destroy();

            return new Image($imagick, $palette, new MetadataBag());
        } catch (\ImagickException $e) {
            throw new RuntimeException('Could not create empty image', $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function load($string)
    {
        try {
            $imagick = new \Imagick();

            $imagick->readImageBlob($string);
            $imagick->setImageMatte(true);

            return new Image($imagick, $this->createPalette($imagick), $this->getMetadataReader()->readData($string));
        } catch (\ImagickException $e) {
            throw new RuntimeException('Could not load image from string', $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($resource)
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Variable does not contain a stream resource');
        }

        $content = stream_get_contents($resource);

        try {
            $imagick = new \Imagick();
            $imagick->readImageBlob($content);
        } catch (\ImagickException $e) {
            throw new RuntimeException('Could not read image from resource', $e->getCode(), $e);
        }

        return new Image($imagick, $this->createPalette($imagick), $this->getMetadataReader()->readData($content, $resource));
    }

    /**
     * {@inheritdoc}
     */
    public function font($file, $size, ColorInterface $color)
    {
        return new Font(new \Imagick(), $file, $size, $color);
    }

    /**
     * Returns the palette corresponding to an \Imagick resource colorspace
     *
     * @param \Imagick $imagick
     *
     * @return CMYK|Grayscale|RGB
     *
     * @throws NotSupportedException
     */
    private function createPalette(\Imagick $imagick)
    {
        switch ($imagick->getImageColorspace()) {
            case \Imagick::COLORSPACE_RGB:
            case \Imagick::COLORSPACE_SRGB:
                return new RGB();
            case \Imagick::COLORSPACE_CMYK:
                return new CMYK();
            case \Imagick::COLORSPACE_GRAY:
                return new Grayscale();
            default:
                throw new NotSupportedException('Only RGB and CMYK colorspace are currently supported');
        }
    }

    /**
     * Returns ImageMagick version
     *
     * @param \Imagick $imagick
     *
     * @return string
     */
    private function getVersion(\Imagick $imagick)
    {
        $v = $imagick->getVersion();
        list($version) = sscanf($v['versionString'], 'ImageMagick %s %04d-%02d-%02d %s %s');

        return $version;
    }
}
