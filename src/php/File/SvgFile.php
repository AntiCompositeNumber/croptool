<?php

namespace CropTool\File;

class SvgFile extends File implements FileInterface
{
    protected $supportedMimeTypes = [
        'image/svg+xml' => '.svg',
    ];

    static public function readViewBox($xml)
    {
        if (!isset($xml->attributes()->viewBox)) {
            throw new \RuntimeException('SVG file without viewBox');
        }

        // The viewBox numbers are separated by whitespace and/or a comma
        return preg_split('/[\s,]+/', $xml->attributes()->viewBox);
    }

    public function fetchPage($pageno = 0)
    {
        $path = parent::fetchPage($pageno);

        /*----------------------------------------------------------
         * Normalize the use of "viewBox", "width" and "height"
         *----------------------------------------------------------*/

        $xml = simplexml_load_file($path);
        $attrs = $xml->attributes();

        if (!isset($attrs->viewBox)) {
            if (!isset($attrs->width) || !isset($attrs->height)) {
                throw new \RuntimeException('SVG file contains neither "viewBox" nor "width" + "height".');
            }

            // Define viewBox from "width" and "height"
            // Note that "width" and "height" have units, while viewBox does not.
            $width = (int)filter_var($attrs->width, FILTER_SANITIZE_NUMBER_INT);
            $height = (int)filter_var($attrs->height, FILTER_SANITIZE_NUMBER_INT);
            $xml->addAttribute('viewBox', "0 0 $width $height");
        }

        $viewBox = static::readViewBox($xml);

        // Set width+height in pixels from viewBox.
        // This will make the SVG display correctly in Cropper.js,
        // but we should remove them afterwards.
        unset($attrs->width);
        unset($attrs->height);
        $xml->addAttribute('width', $viewBox[2] . 'px');
        $xml->addAttribute('height', $viewBox[3] . 'px');

        file_put_contents($path, $xml->asXML());
    }

    static public function readMetadata($path)
    {
        $xml = simplexml_load_file($path);

        $viewBox = static::readViewBox($xml);

        $width = (int) $viewBox[2];
        $height = (int) $viewBox[3];

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    static public function crop($srcPath, $destPath, $method, $coords, $rotation)
    {
        $xml = simplexml_load_file($srcPath);
        $viewBox = static::readViewBox($xml);

        $x = $viewBox[0] + $coords['x'];  // Do we need this? Need to test on file with viewBox offset
        $y = $viewBox[1] + $coords['y'];  // Do we need this? Need to test on file with viewBox offset

        $width = $coords['width'];
        $height = $coords['height'];

        $attrs = $xml->attributes();

        // "Crop" the file by setting the "viewBox"
        $xml->attributes()->viewBox = "$x $y $width $height";

        // Remove "width" + "height"
        if (isset($attrs->width) && isset($attrs->height)) {
            unset($attrs->width);
            unset($attrs->height);
        }

        file_put_contents($destPath, $xml->asXML());
    }
}
