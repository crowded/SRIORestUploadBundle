<?php

namespace SRIO\RestUploadBundle\Voter;

use SRIO\RestUploadBundle\Exception\UploadException;
use SRIO\RestUploadBundle\Storage\FileStorage;
use SRIO\RestUploadBundle\Upload\UploadContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * This storage voter has the role to chose the storage
 * that will be used for the current file upload.
 */
class StorageVoter
{
    /**
     * @var FileStorage[]
     */
    protected $storages = array();

    /**
     * @var string
     */
    protected $defaultStorage;

    /**
     * @var FileStorage
     */
    protected $tempStorage;

    /**
     * Constructor.
     *
     * @param             $defaultStorage
     * @param FileStorage $localStorage
     */
    public function __construct($defaultStorage = null, FileStorage $localStorage = null)
    {
        $this->defaultStorage = $defaultStorage;
        $this->tempStorage = $localStorage;
    }

    /**
     * Add a storage.
     *
     * @param FileStorage $storage
     *
     * @throws \RuntimeException
     */
    public function addStorage(FileStorage $storage)
    {
        if (array_key_exists($storage->getName(), $this->storages)) {
            throw new \RuntimeException(sprintf(
                'Storage with name %s already exists',
                $storage->getName()
            ));
        }

        $this->storages[$storage->getName()] = $storage;
    }

    /**
     * Get the best storage based on request and/or parameters.
     *
     * @param UploadContext $context
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadException
     * @throws \RuntimeException
     *
     * @return FileStorage
     */
    public function getStorage(UploadContext $context)
    {
        if (count($this->storages) == 0) {
            throw new UploadException('No storage found');
        }

        if(!is_null($this->tempStorage) && $context->isUnfinished()) {
            return $this->getTempStorage();
        }

        return $this->getFinishedStorage($context);
    }

    protected function getFinishedStorage(UploadContext $context)
    {
        if (($storageName = $context->getStorageName()) !== null
            || (($storageName = $this->defaultStorage) !== null)) {
            if (!array_key_exists($storageName, $this->storages)) {
                throw new \RuntimeException(sprintf(
                    'Storage with name %s do not exists',
                    $storageName
                ));
            }

            return $this->storages[$storageName];
        }

        return current($this->storages);
    }

    /**
     * @param UploadContext $context
     *
     * @return array|bool
     */
    public function getAcceptedMimeTypes(UploadContext $context)
    {
        if($this->getFinishedStorage($context)->checkMimeType()) {
            return $this->getFinishedStorage($context)->getAcceptedMimeTypes();
        }

        return false;
    }

    /**
     * Returns the temp storage
     *
     * @return FileStorage
     */
    public function getTempStorage()
    {
        return $this->tempStorage;
    }
}
