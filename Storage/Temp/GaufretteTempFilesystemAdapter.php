<?php


namespace SRIO\RestUploadBundle\Storage\Temp;


use Gaufrette\Adapter\Local;
use Gaufrette\Filesystem;
use SRIO\RestUploadBundle\Storage\GaufretteFilesystemAdapter;

class GaufretteTempFilesystemAdapter extends GaufretteFilesystemAdapter implements TempFilesystemInterface
{

    /**
     * GaufretteLocalFilesystemAdapter constructor.
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        if (!($filesystem->getAdapter() instanceof Local)) {
            throw new \LogicException("Local filesystem has no local adapter");
        }

        parent::__construct($filesystem);
    }
}