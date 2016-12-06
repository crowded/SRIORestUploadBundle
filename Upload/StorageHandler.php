<?php

namespace SRIO\RestUploadBundle\Upload;

use SRIO\RestUploadBundle\Exception\UploadException;
use SRIO\RestUploadBundle\Storage\FileStorage;
use SRIO\RestUploadBundle\Storage\FilesystemAdapterInterface;
use SRIO\RestUploadBundle\Storage\Temp\TempFilesystemInterface;
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
     * Constructor.
     *
     * @param StorageVoter $voter
     */
    public function __construct(StorageVoter $voter)
    {
        $this->voter = $voter;
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
        $mimeType = $this->checkMimeType($context);

        $fileStorage = $this->getStorage($context);

        if($fileStorage->getFilesystem() instanceof TempFilesystemInterface) {
            $tempFileName = $context->getFile()->getFile()->getName();
            $stream = $fileStorage->getFilesystem()->readStream($tempFileName);
            rewind($stream);

            $context->setUnfinished(false);

            $uploadedFile = $this->getStorage($context)->storeStream($context, $stream, array_replace_recursive($context->getConfig(), array(
                FileStorage::METADATA_CONTENT_TYPE => $mimeType,
            )));
            $uploadedFile->setMimeType($mimeType);

            $context->setFile($uploadedFile);

            $fileStorage->getFilesystem()->delete($tempFileName);

            return $uploadedFile;
        }else{
            $context->setUnfinished(false);

            $file = $context->getFile();
            $file->setMimeType($mimeType);

            return $file;
        }
    }

    /**
     * Checks if the file is one of the desired mime types if these mime types are set.
     *
     * @param UploadContext $context
     *
     * @return string
     * @throws UploadException
     */
    protected function checkMimeType(UploadContext $context)
    {
        $filesystem = $this->getStorage($context)->getFilesystem();
        $file = $context->getFile()->getFile();
        $mimeType = $filesystem->getMimeType($file->getName());

        $acceptedMimeTypes = $this->voter->getAcceptedMimeTypes($context);

        if ($acceptedMimeTypes && !in_array($mimeType, $acceptedMimeTypes)) {
            $filesystem->delete($file->getName());
            throw new UploadException(sprintf('Mime-type %s is not accepted', $mimeType));
        }

        return $mimeType;
    }

    /**
     * Get storage by upload context.
     *
     * @param UploadContext $context
     *
     * @return FileStorage
     * @throws UploadException
     */
    public function getStorage(UploadContext $context)
    {
        $storage = $this->voter->getStorage($context);

        if (!$storage instanceof FileStorage) {
            throw new UploadException('Storage returned by voter isn\'t instanceof FileStorage');
        }

        return $storage;
    }
}
