<?php

namespace SRIO\RestUploadBundle\Storage;

class UploadedFile
{
    /**
     * @var FileStorage
     */
    protected $storage;

    /**
     * @var FileAdapterInterface
     */
    protected $file;

    /**
     * @var string
     */
    protected $mimeType;

    /**
     * @param FileStorage          $storage
     * @param FileAdapterInterface $file
     */
    public function __construct(FileStorage $storage, FileAdapterInterface $file)
    {
        $this->storage = $storage;
        $this->file = $file;
    }

    /**
     * @return FileAdapterInterface
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * @return \SRIO\RestUploadBundle\Storage\FileStorage
     */
    public function getStorage()
    {
        return $this->storage;
    }

    /**
     * @return string
     */
    public function getMimeType()
    {
        return $this->mimeType;
    }

    /**
     * @param string $mimeType
     */
    public function setMimeType($mimeType)
    {
        $this->mimeType = $mimeType;
    }
}
