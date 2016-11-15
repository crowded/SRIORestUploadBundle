<?php


namespace SRIO\RestUploadBundle\Storage\Temp;


use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use SRIO\RestUploadBundle\Storage\FlysystemFilesystemAdapter;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;

class FlysystemTempFilesystemAdapter extends FlysystemFilesystemAdapter implements TempFilesystemInterface
{
    /**
     * GaufretteLocalFilesystemAdapter constructor.
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        if (!($filesystem->getAdapter() instanceof Local)) {
            throw new \LogicException("The configured temporarily filesystem has no local filesystem adapter");
        }
        parent::__construct($filesystem);
    }

    public function getMimeType($path)
    {
        $fullPath = $this->getAdapter()->applyPathPrefix($path);

        return MimeTypeGuesser::getInstance()->guess($fullPath);
    }
}