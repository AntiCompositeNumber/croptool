<?php

namespace CropTool;

use Psr\Log\LoggerInterface as Logger;
use pastuhov\Command\Command;

class File implements FileInterface
{
    protected $publicDir;
    protected $filesDir;
    protected $url;
    protected $sha1;
    protected $mime;
    protected $fileExt;
    protected $multipage = false;
    protected $logger;

    protected $supportedMimeTypes = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/gif' => '.gif',
        'image/tiff' => '.tiff',
        'image/vnd.djvu' => '.djvu',
        'application/pdf' => '.pdf',
    ];

    public function __construct($publicDir, $filesDir, ImageInfoResponse $imageinfo, Logger $logger)
    {
        $this->publicDir = $publicDir;
        $this->filesDir = $filesDir;
        $this->url = $imageinfo->url;
        $this->sha1 = $imageinfo->sha1;
        $this->mime = $imageinfo->mime;
        $this->logger = $logger;

        $this->fileExt = $this->getFileExt($this->mime);
    }

    public function getPublicDir()
    {
        return $this->publicDir;
    }

    protected function getFileExt($mime)
    {
        if (!isset($this->supportedMimeTypes[$mime])) {
            throw new InvalidMimeTypeException('The mime type is not supported: ' . $mime);
        }
        return $this->supportedMimeTypes[$mime];
    }

    protected function pageSuffix($pageno=0)
    {
        return $pageno > 0 ? '.page' . $pageno . '.jpg' : '';
    }

    public function getShortSha1()
    {
        return substr($this->sha1, 0, 7);
    }

    public function getRelativePath($suffix = '')
    {
        return $this->filesDir . $this->sha1 . $suffix . $this->fileExt;
    }

    public function getAbsolutePath($suffix = '')
    {
        return $this->publicDir . $this->getRelativePath($suffix);
    }

    public function getAbsolutePathForPage($pageno, $suffix = '')
    {
        return $this->getAbsolutePath($suffix) . $this->pageSuffix($pageno);
    }

    public function getRelativePathForPage($pageno, $suffix = '')
    {
        return $this->getRelativePath($suffix) . $this->pageSuffix($pageno);
    }

    public function exists($pageno=0, $suffix = '')
    {
        $path = $this->getAbsolutePathForPage($pageno, $suffix);

        return file_exists($path);
    }

    public function fetch()
    {
        if ($this->exists()) {
            return;
        }

        $path = $this->getAbsolutePath();

        Command::exec('wget -O {out} {url}', [
           'out' => $path,
           'url' => $this->url,
        ]);

        $fsize = filesize($path);

        $this->logMsg("Fetched {$fsize} bytes from {$this->url}");

        if (!$fsize) {
            if (file_exists($path)) {
                // Remove the partial download
                unlink($path);
            }
            throw new \RuntimeException(
                "Could not fetch {$this->url} before the server closed the connection. " .
                "Please retry in a moment."
            );
        }

        if (chmod($path, 0664) === false) {
            throw new \RuntimeException('Failed to change permissions for file.');
        }
    }

    protected function logMsg($msg)
    {
        $this->logger->info('[{sha1}] {msg}', ['sha1' => $this->getShortSha1(), 'msg' => $msg]);
    }

    public function fetchPage($pageno = 0)
    {
        $this->fetch();

        if ($pageno != 0) {
            throw new \RuntimeException('This is not a multipage file.');
        }

        return $this->getAbsolutePathForPage($pageno);
    }
}
