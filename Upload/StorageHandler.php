<?php

namespace SRIO\RestUploadBundle\Upload;

use SRIO\RestUploadBundle\Exception\UploadException;
use SRIO\RestUploadBundle\Storage\FileStorage;
use SRIO\RestUploadBundle\Storage\FilesystemAdapterInterface;
use SRIO\RestUploadBundle\Storage\Local\TempFilesystemInterface;
use SRIO\RestUploadBundle\Storage\UploadedFile;
use SRIO\RestUploadBundle\Voter\StorageVoter;

/**
 * This class defines the storage handler.
 */
class StorageHandler
{
    /**
     * @var \SRIO\RestUploadBundle\Voter\StorageVoter
     */
    protected $voter;

    /**
     * @var FileStorage
     */
    protected $tempStorage;

    /**
     * Constructor.
     *
     * @param StorageVoter $voter
     * @param FileStorage $localStorage
     */
    public function __construct(StorageVoter $voter, FileStorage $localStorage = null)
    {
        $this->voter = $voter;
        $this->tempStorage = $localStorage;
    }

    /**
     * Store a file's content.
     *
     * @param UploadContext $context
     * @param string        $contents
     * @param array         $config
     * @param bool          $overwrite
     *
     * @return UploadedFile
     */
    public function store(UploadContext $context, $contents, array $config = array(), $overwrite = false)
    {
        return $this->getStorage($context)->store($context, $contents, $config, $overwrite);
    }

    /**
     * Store a file's content.
     *
     * @param UploadContext $context
     * @param resource      $resource
     * @param array         $config
     * @param bool          $overwrite
     *
     * @return UploadedFile
     */
    public function storeStream(UploadContext $context, $resource, array $config = array(), $overwrite = false)
    {
        return $this->getStorage($context)->storeStream($context, $resource, $config, $overwrite);
    }

    /**
     * @return FilesystemAdapterInterface
     */
    public function getFilesystem(UploadContext $context)
    {
        return $this->getStorage($context)->getFilesystem();
    }

    /**
     * Finishes the storage procedure. When a temp filesystem was used the file is moved from temp to the actual filesystem
     *
     * @param UploadContext $context
     *
     * @return UploadedFile
     */
    public function finishStore(UploadContext $context)
    {
        if(!is_null($this->tempStorage)) {
            $tempFileName = $context->getFile()->getFile()->getName();
            $stream = $this->tempStorage->getFilesystem()->readStream($tempFileName);
            rewind($stream);

            $uploadedFile = $this->getStorage($context, true)->storeStream($context, $stream);

            $context->setFile($uploadedFile);

            $this->tempStorage->getFilesystem()->delete($tempFileName);

            return $uploadedFile;
        }else{
            return $context->getFile();
        }
    }

    /**
     * Get storage by upload context.
     *
     * @param UploadContext $context
     *
     * @param bool $nonTemp
     * @return FileStorage
     * @throws UploadException
     */
    public function getStorage(UploadContext $context, $nonTemp = false)
    {
        if(!is_null($this->tempStorage) && !$nonTemp) {
            $storage = $this->getTempStorage();
        }else{
            $storage = $this->voter->getStorage($context);
        }

        if (!$storage instanceof FileStorage) {
            throw new UploadException('Storage returned by voter isn\'t instanceof FileStorage');
        }

        return $storage;
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
