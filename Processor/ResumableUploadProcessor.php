<?php

namespace SRIO\RestUploadBundle\Processor;

use SRIO\RestUploadBundle\Storage\FileStorage;
use SRIO\RestUploadBundle\Storage\FileAdapterInterface;
use SRIO\RestUploadBundle\Upload\UploadContext;
use SRIO\RestUploadBundle\Upload\UploadResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManager;
use SRIO\RestUploadBundle\Entity\ResumableUploadSession;
use SRIO\RestUploadBundle\Exception\UploadProcessorException;
use SRIO\RestUploadBundle\Upload\StorageHandler;

class ResumableUploadProcessor extends AbstractUploadProcessor
{
    /**
     * @var string
     */
    const PARAMETER_UPLOAD_ID = 'uploadId';

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var string
     */
    protected $resumableEntity;

    /**
     * @var FileStorage
     */
    protected $tempStorage;

    /**
     * @var int
     */
    protected $doTempOnMinBytes;

    /**
     * Constructor.
     *
     * @param StorageHandler $storageHandler
     * @param EntityManager  $em
     * @param string         $resumableEntity
     * @param FileStorage    $tempStorage
     * @param int            $doTempOnMinBytes
     */
    public function __construct(StorageHandler $storageHandler, EntityManager $em, $resumableEntity, FileStorage $tempStorage, $doTempOnMinBytes)
    {
        parent::__construct($storageHandler);

        $this->em               = $em;
        $this->resumableEntity  = $resumableEntity;
        $this->tempStorage      = $tempStorage;
        $this->doTempOnMinBytes = $doTempOnMinBytes;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @throws \Exception|\SRIO\RestUploadBundle\Exception\UploadException
     *
     * @return UploadResult
     */
    public function handleRequest(Request $request)
    {
        if (empty($this->resumableEntity)) {
            throw new UploadProcessorException(sprintf(
                'You must configure the "%s" option',
                'resumable_entity'
            ));
        }

        if ($request->query->has(self::PARAMETER_UPLOAD_ID)) {
            $this->checkHeaders($request, array('Content-Length'));

            $uploadId = $request->query->get(self::PARAMETER_UPLOAD_ID);

            $repository = $this->getRepository();
            $resumableUpload = $repository->findOneBy(array(
                'sessionId' => $uploadId,
            ));

            if ($resumableUpload == null) {
                throw new UploadProcessorException('Unable to find upload session');
            }

            return $this->handleResume($request, $resumableUpload);
        }

        return $this->handleStartSession($request);
    }

    /**
     * Handle a start session.
     *
     * @param Request $request
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadProcessorException
     *
     * @return UploadResult
     */
    protected function handleStartSession(Request $request)
    {
        // Check that needed headers exists
        $this->checkHeaders($request, array('Content-Type', 'X-Upload-Content-Type', 'X-Upload-Content-Length'));
        $expectedContentType = 'application/json';
        if (substr($request->headers->get('Content-Type'), 0, strlen($expectedContentType)) != $expectedContentType) {
            throw new UploadProcessorException(sprintf(
                'Expected content type is %s. Found %s',
                $expectedContentType,
                $request->headers->get('Content-Type')
            ));
        }

        // Create the result object
        $result = new UploadResult();
        $result->setRequest($request);
        $result->setConfig($this->config);
        $result->setForm($this->form);

        $formData = array();
        if ($this->form != null) {
            // Submit form data
            $data = json_decode($request->getContent(), true);
            $formData = $this->createFormData($data);
            $this->form->submit($formData);
        }

        if ($this->form == null || $this->form->isValid()) {
            // Form is valid, store it
            $repository = $this->getRepository();
            $className = $repository->getClassName();

            // Create file from storage handler
            $file = $this->storageHandler->store($result, '', array(
                'metadata' => array(
                    FileStorage::METADATA_CONTENT_TYPE => $request->headers->get('X-Upload-Content-Type'),
                ),
            ));

            /** @var $resumableUpload ResumableUploadSession */
            $resumableUpload = new $className();

            $resumableUpload->setData(serialize($formData));
            $resumableUpload->setStorageName($file->getStorage()->getName());
            $resumableUpload->setFilePath($file->getFile()->getName());
            $resumableUpload->setSessionId($this->createSessionId());
            $resumableUpload->setContentType($request->headers->get('X-Upload-Content-Type'));
            $resumableUpload->setContentLength($request->headers->get('X-Upload-Content-Length'));
            $resumableUpload->setCreatedAt(new \DateTime());

            // Store resumable session
            $this->em->persist($resumableUpload);
            $this->em->flush($resumableUpload);

            // Compute redirect location path
            $location = $request->getPathInfo().'?'.http_build_query(array_merge($request->query->all(), array(
                self::PARAMETER_UPLOAD_ID => $resumableUpload->getSessionId(),
            )));

            $response = new Response(null);
            $response->headers->set('Location', $location);

            $result->setResponse($response);
        }

        return $result;
    }

    /**
     * Handle an upload resume.
     *
     * @param Request                $request
     * @param ResumableUploadSession $uploadSession
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadProcessorException
     *
     * @return UploadResult
     */
    protected function handleResume(Request $request, ResumableUploadSession $uploadSession)
    {
        $filePath = $uploadSession->getFilePath();

        $context = new UploadContext();
        $context->setStorageName($uploadSession->getStorageName());

        $filesystem = $this->storageHandler->getFilesystem($context);
        if ($this->needsTempFile($uploadSession)) {
            $filesystem = $this->tempStorage;
            $filePath = $this->createTempName($uploadSession->getSessionId());
        }
        
        $contentLength = $request->headers->get('Content-Length');
        if ($request->headers->has('Content-Range')) {
            $range = $this->parseContentRange($request->headers->get('Content-Range'));

            if ($range['total'] != $uploadSession->getContentLength()) {
                throw new UploadProcessorException(sprintf(
                    'File size must be "%d", range total length is %d',
                    $uploadSession->getContentLength(),
                    $range['total']
                ));
            } elseif ($range['start'] === '*') {
                if ($contentLength == 0) {
                    $file = $filesystem->get($filePath);
                    return $this->requestUploadStatus($context, $uploadSession, $file, $range);
                }

                throw new UploadProcessorException('Content-Length must be 0 if asking upload status');
            }
            
            $uploaded = $filesystem->getSize($filePath);
            if ($range['start'] != $uploaded) {
                throw new UploadProcessorException(sprintf(
                    'Unable to start at %d while uploaded is %d',
                    $range['start'],
                    $uploaded
                ));
            }
        } else {
            $range = array(
                'start' => 0,
                'end' => $uploadSession->getContentLength() - 1,
                'total' => $uploadSession->getContentLength() - 1
            );
        }

        // Handle upload from
        $handler = $this->getRequestContentHandler($request);
        
        $stream = $filesystem->getStreamCopy($filePath);
        fseek($stream, $range['start']);
        $wrote = 0;
        while (!$handler->eof()) {
            if (($bytes = fwrite($stream, $handler->gets())) !== false) {
                $wrote += $bytes;
            } else {
                throw new UploadProcessorException('Unable to write to file');
            }
        }

        $filesystem->writeStream($filePath, $stream, array('overwrite' => true));
        
        // Get file in context and its size
        $file = $this->tempStorage->getFilesystem()->get($filePath);
        $size = $file->getSize();

        // If upload is completed, create the upload file, else
        // return like the request upload status
        if ($size < $uploadSession->getContentLength()) {
            fclose($stream);
            return $this->requestUploadStatus($context, $uploadSession, $file, $range);
        } elseif ($size == $uploadSession->getContentLength()) {
            rewind($stream);
            return $this->handleCompletedUpload($context, $uploadSession, $stream);
        } else {
            $this->deleteTempFile($uploadSession);
            throw new UploadProcessorException('Written file size is greater that expected Content-Length');
        }
    }

    /**
     * Handle a completed upload.
     *
     * @param \SRIO\RestUploadBundle\Upload\UploadContext $context
     * @param ResumableUploadSession                      $uploadSession
     * @param resource                                    $completedStream
     * @return UploadResult
     */
    protected function handleCompletedUpload(UploadContext $context, ResumableUploadSession $uploadSession, $completedStream)
    {
        $result = new UploadResult();
        $result->setForm($this->form);

        if ($this->form != null) {
            // Submit the form data
            $formData = unserialize($uploadSession->getData());
            $this->form->submit($formData);
        }
        
        if ($this->form == null || $this->form->isValid()) {
            $uploadedFile = $this->storageHandler->storeStream($context, $completedStream, array(
                'metadata' => array(
                    FileStorage::METADATA_CONTENT_TYPE => $uploadSession->getContentType()
                )
            ));
            fclose($completedStream);
            $this->deleteTempFile($uploadSession);
            $result->setStorageName($uploadedFile->getStorage()->getName());
            $result->setFile($uploadedFile);
        }

        return $result;
    }

    /**
     * Return the upload status.
     *
     * @param \SRIO\RestUploadBundle\Upload\UploadContext $context
     * @param ResumableUploadSession                      $uploadSession
     * @param FileAdapterInterface                        $file
     * @param array                                       $range
     *
     * @return UploadResult
     */
    protected function requestUploadStatus(UploadContext $context, ResumableUploadSession $uploadSession, FileAdapterInterface $file, array $range)
    {
        if (!$file->exists()) {
            $length = 0;
        } else {
            $length = $file->getSize();
        }

        $response = new Response(null, $length == $range['total'] ? 201 : 308);

        if ($length < 1) {
            $length = 1;
        }

        $response->headers->set('Range', '0-'.($length - 1));

        $result = new UploadResult();
        $result->setResponse($response);

        return $result;
    }

    /**
     * Parse the Content-Range header.
     *
     * It returns an array with these keys:
     * - `start` Start index of range
     * - `end`   End index of range
     * - `total` Total number of bytes
     *
     * @param string $contentRange
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadProcessorException
     *
     * @return array
     */
    protected function parseContentRange($contentRange)
    {
        $contentRange = trim($contentRange);
        if (!preg_match('#^bytes (\*|([0-9]+)-([0-9]+))/([0-9]+)$#', $contentRange, $matches)) {
            throw new UploadProcessorException('Invalid Content-Range header. Must start with "bytes ", range and total length');
        }

        $range = array(
            'start' => $matches[1] === '*' ? '*' : ($matches[2] === '' ? null : (int) $matches[2]),
            'end' => $matches[3] === '' ? null : (int) $matches[3],
            'total' => (int) $matches[4]
        );

        if (empty($range['total'])) {
            throw new UploadProcessorException('Content-Range total length not found');
        }
        if ($range['start'] === '*') {
            if ($range['end'] !== null) {
                throw new UploadProcessorException('Content-Range end must not be present if start is "*"');
            }
        } elseif ($range['start'] === null || $range['end'] === null) {
            throw new UploadProcessorException('Content-Range end or start is empty');
        } elseif ($range['start'] > $range['end']) {
            throw new UploadProcessorException('Content-Range start must be lower than end');
        } elseif ($range['end'] > $range['total']) {
            throw new UploadProcessorException('Content-Range end must be lower or equal to total length');
        }

        return $range;
    }
    
    /**
     * Get resumable upload session entity repository.
     *
     * @return \Doctrine\ORM\EntityRepository
     */
    protected function getRepository()
    {
        return $this->em->getRepository($this->resumableEntity);
    }

    /**
     * @param ResumableUploadSession $uploadSession
     *
     * @return bool
     */
    protected function needsTempFile(ResumableUploadSession $uploadSession)
    {
        return $this->doTempOnMinBytes >= $uploadSession->getContentLength();
    }

    /**
     * Delete temp file.
     *
     * @param ResumableUploadSession $uploadSession
     *
     * @return bool
     */
    protected function deleteTempFile(ResumableUploadSession $uploadSession)
    {
        return $this->tempStorage->getFilesystem()->delete($this->createTempName($uploadSession->getSessionId()));
    }
    
    /**
     * Create temp file name.
     * 
     * @param $sessionId
     *
     * @return string
     */
    protected function createTempName($sessionId)
    {
        return $sessionId . 'tmp';
    }
    
    /**
     * Create a session ID.
     */
    protected function createSessionId()
    {
        return uniqid();
    }
}
