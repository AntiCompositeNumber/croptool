<?php

namespace CropTool;

use Imagick;
use ImagickPixel;
use pastuhov\Command\Command;

class Image
{
    protected $editor;
    protected $thumbWidth = 800;
    protected $thumbHeight = 800;
    protected $filePermission = 0664;  // user + group: rw, other: r

    public $path;
    public $mime;
    public $orientation;
    public $samplingFactor;
    public $width;
    public $height;

    public function __construct(ImageEditor $editor, $path, $mime)
    {
        $this->editor = $editor;
        $this->path = $path;
        $this->mime = $mime;
        $this->load();
    }

    protected function load()
    {
        if ($this->mime != 'image/jpeg') {
            $this->orientation = 0;
            $sz = getimagesize($this->path);
            $this->width = $sz[0];
            $this->height = $sz[1];
        } else {
            $exif = @exif_read_data($this->path, 'IFD0');
            $this->orientation = (isset($exif) && isset($exif['Orientation'])) ? intval($exif['Orientation']) : 0;

            $image = new Imagick($this->path);
            $sf = explode(',', $image->getImageProperty('jpeg:sampling-factor'));
            $this->samplingFactor = $sf[0];

            switch ($this->orientation) {
                case Imagick::ORIENTATION_UNDEFINED:    // 0
                case Imagick::ORIENTATION_TOPLEFT:      // 1 : no rotation
                case Imagick::ORIENTATION_BOTTOMRIGHT:  // 3 : 180 deg
                    $this->width = $image->getImageWidth();
                    $this->height = $image->getImageHeight();
                    break;

                case Imagick::ORIENTATION_RIGHTTOP:     // 6 : 90 deg CW
                case Imagick::ORIENTATION_LEFTBOTTOM:   // 8 : 90 deg CCW
                    $this->width = $image->getImageHeight();
                    $this->height = $image->getImageWidth();
                    break;

                default:
                    throw new \RuntimeException('Unsupported EXIF orientation "' . $this->orientation . '"');
            }

            $image->destroy();
        }

        if (!$this->width || !$this->height) {

            // @TODO: This should move to a safer place:
            // unlink($this->path);

            throw new \RuntimeException('Invalid image file ' . $this->path . '. Refreshing the page might help in some cases.');
        }
    }

    public function saveImage($im, $destPath)
    {
        // Imagick will copy metadata to the destination file.
        if ($this->mime == 'image/png') {
            // ImageMagick will sometimes optimize PNG files with unfortunate results:
            // https://github.com/danmichaelo/croptool/issues/111
            // To avoid this, we try to preserve the original PNG color space
            // (https://en.wikipedia.org/wiki/Portable_Network_Graphics#Pixel_format)
            $pngInfo = $this->get_png_imageinfo($this->path);
            if (is_array($pngInfo) && isset($pngInfo['color'])) {
                if ($pngInfo['color'] == 2) {
                    return $im->writeImage('png24:' . $destPath);
                } else if ($pngInfo['color'] == 6) {
                    return $im->writeImage('png32:' . $destPath);
                }
            }
        }
        return $im->writeImage($destPath);
    }

    protected function getCropCoordinates($x, $y, $width, $height, $rotation)
    {
        // Find the size of the rectangle that can contain the rotated image
        $h0 = $this->height;
        $w0 = $this->width;
        $t = deg2rad($rotation);
        $w1 = abs($w0 * cos($t)) + abs($h0 * sin($t));
        $h1 = abs($h0 * cos($t)) + abs($w0 * sin($t));

        // Remember:
        // - Origin is in the upper left corner
        // - Positive x is rightwards
        // - Positive y is downwards

        switch ($this->orientation) {
            case Imagick::ORIENTATION_UNDEFINED:    // 0
            case Imagick::ORIENTATION_TOPLEFT:      // 1 : no rotation
                // No rotation
                $rect = array(
                    'x' => $x,
                    'y' => $y,
                    'width' => $width,
                    'height' => $height
                );
                break;

            case Imagick::ORIENTATION_BOTTOMRIGHT:  // 3 : 180 deg
                // Image rotated 180 deg
                $rect = array(
                    'x' => $w1 - $x - $width,
                    'y' => $h1 - $y - $height,
                    'width' => $width,
                    'height' => $height
                );
                break;

            case Imagick::ORIENTATION_RIGHTTOP:     // 6 : 90 deg CW
                // Image rotated 90 deg CCW
                $rect = array(
                    'x' => $y,
                    'y' => $w1 - $x - $width,
                    'width' => $height,
                    'height' => $width
                );
                break;

            case Imagick::ORIENTATION_LEFTBOTTOM:   // 8 : 90 deg CCW
                // Image rotated 90 deg CW
                $rect = array(
                    'x' => $h1 - $y - $height,
                    'y' => $x,
                    'width' => $height,
                    'height' => $width
                );
                break;

            default:
                die('Unsupported EXIF orientation');
        }

        // Make sure the selection is constrained by the image dimensions.
        // x and y should not be < 0
        if ($rect['x'] < 0) {
            $rect['width'] = $rect['width'] + $rect['x'];
            $rect['x'] = 0;
        }
        if ($rect['y'] < 0) {
            $rect['height'] = $rect['height'] + $rect['y'];
            $rect['y'] = 0;
        }

        // x + width and y + height should not be > image width and height respectively
        if ($this->flipped()) {
            $rect['width'] = min($h1 - $rect['x'], $rect['width']);
            $rect['height'] = min($w1 - $rect['y'], $rect['height']);
        } else {
            $rect['width'] = min($w1 - $rect['x'], $rect['width']);
            $rect['height'] = min($h1 - $rect['y'], $rect['height']);
        }

        // The whole selection is outside the image. No way to fix that
        if ($rect['width'] < 0 || $rect['height'] < 0) {
            throw new \RuntimeException('Invalid crop region');
        }

        return $rect;
    }

    public function flipped()
    {
        switch ($this->orientation) {
            case Imagick::ORIENTATION_UNDEFINED:    // 0
            case Imagick::ORIENTATION_TOPLEFT:      // 1 : no rotation
                return false;

            case Imagick::ORIENTATION_BOTTOMRIGHT:  // 3 : 180 deg
                return false;


            case Imagick::ORIENTATION_RIGHTTOP:     // 6 : 90 deg CW
                return true;

            case Imagick::ORIENTATION_LEFTBOTTOM:   // 8 : 90 deg CCW
                return true;

            default:
                die('Unsupported EXIF orientation');
        }
    }

    /**
     * @param $destPath
     * @param $method
     * @param $x
     * @param $y
     * @param $width
     * @param $height
     * @param $rotation
     * @return Image
     */
    public function crop($destPath, $method, $x, $y, $width, $height, $rotation)
    {
        if (file_exists($destPath)) {
            unlink($destPath);
        }

        // Get coords orientated in the same direction as the image:
        $coords = $this->getCropCoordinates($x, $y, $width, $height, $rotation);

        if ($method == 'gif') {
            $this->gifCrop($destPath, $coords, $rotation);
        } elseif ($method == 'lossless') {
            $this->losslessCrop($destPath, $coords, $rotation);
        } elseif ($method == 'precise') {
            $this->preciseCrop($destPath, $coords, $rotation);
        } else {
            throw new \RuntimeException('Unknown crop method specified');
        }

        chmod($destPath, $this->filePermission);

        return new Image($this->editor, $destPath, $this->mime);
    }

    public function preciseCrop($destPath, $coords, $rotation)
    {
        $image = new Imagick($this->path);

        $image->setImagePage(0, 0, 0, 0);  // Reset virtual canvas, like +repage
        if ($rotation) {
            $image->rotateImage(new \ImagickPixel('#00000000'), $rotation);
            $image->setImagePage(0, 0, 0, 0);  // Reset virtual canvas, like +repage
        }
        $image->cropImage($coords['width'], $coords['height'], $coords['x'], $coords['y']);
        $image->setImagePage(0, 0, 0, 0);  // Reset virtual canvas, like +repage
        $this->saveImage($image, $destPath);
        $image->destroy();
    }

    public function gifCrop($destPath, $coords, $rotation)
    {
        $dim = $coords['width'] . 'x' . $coords['height'] . '+' . $coords['x'] .'+' . $coords['y'] . '!';

        $rotate = $rotation ? '-rotate ' . intval($rotation) . ' +repage' : '';

        Command::exec('convert {src} ' . $rotate . ' -crop {dim} {dest}', [
            'src' => $this->path,
            'dest' => $destPath,
            'dim' => $dim,
        ]);
    }

    public function losslessCrop($destPath, $coords, $rotation)
    {
        $dim = $coords['width'] . 'x' . $coords['height'] . '+' . $coords['x'] .'+' . $coords['y'];
        $rotate = '';
        if ($rotation) {
            if (!in_array($rotation, [90, 180, 270])) {
                throw new \RuntimeException('Rotation angle for lossless crop must be 90, 180 or 270.');
            }

            $rotate = '-rotate ' . $rotation;
        }


        Command::exec($this->editor->getPathToJpegTran() . ' -copy all ' . $rotate . ' -crop {dim} {src} > {dest}', [
            'src' => $this->path,
            'dest' => $destPath,
            'dim' => $dim,
        ]);
    }

    protected function genThumb($thumbPath, $maxWidth, $maxHeight)
    {
        $im = new Imagick();
        $im->readImage($this->path);

        switch ($this->orientation) {
            case Imagick::ORIENTATION_UNDEFINED:    // 0
            case Imagick::ORIENTATION_TOPLEFT:      // 1 : no rotation
                break;

            case Imagick::ORIENTATION_BOTTOMRIGHT:  // 3 : 180 deg
                $im->rotateImage(new ImagickPixel(), 180);
                break;

            case Imagick::ORIENTATION_RIGHTTOP:     // 6 : 90 deg CW
                $im->rotateImage(new ImagickPixel(), 90);
                break;

            case Imagick::ORIENTATION_LEFTBOTTOM:   // 8 : 90 deg CCW
                $im->rotateImage(new ImagickPixel(), -90);
                break;

            default:
                // we should never get here, this is checked in load() as well
                die('Unsupported EXIF orientation');
        }

        // Now that it's auto-rotated, make sure the EXIF data is correct, so
        // thumbnailImage doesn't try to autorotate the image
        $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);

        if ($im->getImageWidth() > $maxWidth || $im->getImageHeight() > $maxHeight) {
            $im->thumbnailImage($maxWidth, $maxHeight, true);
        }

        $w = $im->getImageWidth();
        $h = $im->getImageHeight();

        $im->setImageCompressionQuality(75);
        $im->stripImage();

        $this->saveImage($im, $thumbPath);
        $im->destroy();

        return array($w, $h);
    }

    /**
     * @param $thumbPath
     * @return Image|null
     */
    public function thumb($thumbPath)
    {
        // Unlink first, in case a new thumb is not needed
        if (file_exists($thumbPath)) {
            unlink($thumbPath);
        }

        $mime = $this->editor->mimeFromPath($thumbPath);

        if ($mime == 'image/gif') {
            // We never create thumbnails for GIFs
            return null;
        } else if ($this->mime == 'image/tiff' or $this->orientation > 0) {
            // We always create a thumbnail
            // - if orientation > 1 because not all browsers respects EXIF orientation,
            // - or if the file is a tiff file, since most browser don't support tiff.
        } else if ($this->width <= $this->thumbWidth && $this->height <= $this->thumbHeight) {
            // Otherwise, we check if the dimensions exceeds the thumb dimensions.
            return null;
        }

        $this->genThumb($thumbPath, $this->thumbWidth, $this->thumbHeight);
        chmod($thumbPath, $this->filePermission);

        return new Image($this->editor, $thumbPath, $mime);
    }

    /**
     * Get image-information from PNG file
     *
     * php's getimagesize does not support additional image information
     * from PNG files like channels or bits.
     *
     * get_png_imageinfo() can be used to obtain this information
     * from PNG files.
     *
     * @author Tom Klingenberg <lastflood.net>
     * @license Apache 2.0
     * @version 0.1.0
     * @link http://www.libpng.org/pub/png/spec/iso/index-object.html#11IHDR
     *
     * @param string $file filename
     * @return array|bool image information, FALSE on error
     */
    function get_png_imageinfo($file) {
        if (empty($file)) return false;

        $info = unpack('a8sig/Nchunksize/A4chunktype/Nwidth/Nheight/Cbit-depth/'.
            'Ccolor/Ccompression/Cfilter/Cinterface',
            file_get_contents($file,0,null,0,29))
        ;

        if (empty($info)) return false;

        if ("\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"!=array_shift($info))
            return false; // no PNG signature.

        if (13 != array_shift($info))
            return false; // wrong length for IHDR chunk.

        if ('IHDR'!==array_shift($info))
            return false; // a non-IHDR chunk singals invalid data.

        $color = $info['color'];

        $type = array(0=>'Greyscale', 2=>'Truecolour', 3=>'Indexed-colour',
            4=>'Greyscale with alpha', 6=>'Truecolour with alpha');

        if (empty($type[$color]))
            return false; // invalid color value

        $info['color-type'] = $type[$color];

        $samples = ((($color%4)%3)?3:1)+($color>3);

        $info['channels'] = $samples;
        $info['bits'] = $info['bit-depth'];

        return $info;
    }
}
