<?php

namespace SRIO\RestUploadBundle\Upload;

use SRIO\RestUploadBundle\Exception\UploadException;
use SRIO\RestUploadBundle\Exception\UploadProcessorException;
use SRIO\RestUploadBundle\Processor\ProcessorInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

class UploadHandler
{
    /**
     * @var string
     */
    protected $uploadTypeParameter;

    /**
     * @var array
     */
    protected $processors = array();

    /**
     * @var array
     */
    protected $acceptedMimeTypes = array();

    /**
     * Constructor.
     *
     * @param $uploadTypeParameter
     * @param array $acceptedMimeTypes
     */
    public function __construct($uploadTypeParameter, $acceptedMimeTypes = null)
    {
        $this->uploadTypeParameter = $uploadTypeParameter;
        $this->acceptedMimeTypes = $acceptedMimeTypes;
    }

    /**
     * Add an upload processor.
     *
     * @param $uploadType
     * @param ProcessorInterface $processor
     *
     * @throws \LogicException
     */
    public function addProcessor($uploadType, ProcessorInterface $processor)
    {
        if (array_key_exists($uploadType, $this->processors)) {
            throw new \LogicException(sprintf(
                'A processor is already registered for type %s',
                $uploadType
            ));
        }

        $this->processors[$uploadType] = $processor;
    }

    /**
     * @return array
     */
    public function getAcceptedMimeTypes()
    {
        return $this->acceptedMimeTypes;
    }

    /**
     * @param array $acceptedMimeTypes
     */
    public function setAcceptedMimeTypes($acceptedMimeTypes)
    {
        $this->acceptedMimeTypes = $acceptedMimeTypes;
    }

    /**
     * @param array $acceptedMimeTypes
     */
    public function addAcceptedMimeTypes($acceptedMimeTypes)
    {
        $this->acceptedMimeTypes = array_replace($this->acceptedMimeTypes, $acceptedMimeTypes);
    }

    /**
     * @param string $acceptedMimeType
     */
    public function addAcceptedMimeType($acceptedMimeType)
    {
        $this->acceptedMimeTypes[] = $acceptedMimeType;
    }

    /**
     * Handle the upload request.
     *
     * @param Request                               $request
     * @param \Symfony\Component\Form\FormInterface $form
     * @param array                                 $config
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadException
     *
     * @return UploadResult
     */
    public function handleRequest(Request $request, FormInterface $form = null, array $config = array())
    {
        try {
            $processor = $this->getProcessor($request, $config);

            return $processor->handleUpload($request, $form, $config, $this->acceptedMimeTypes);
        } catch (UploadException $e) {
            if ($form != null) {
                $form->addError(new FormError($e->getMessage()));
            }

            $result = new UploadResult();
            $result->setException($e);
            $result->setForm($form);

            return $result;
        }
    }

    /**
     * Get the upload processor.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array                                     $config
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadProcessorException
     *
     * @return ProcessorInterface
     */
    protected function getProcessor(Request $request, array $config)
    {
        $uploadType = $request->get($this->getUploadTypeParameter($config));

        if (!array_key_exists($uploadType, $this->processors)) {
            throw new UploadProcessorException(sprintf(
                'Unknown upload processor for upload type %s',
                $uploadType
            ));
        }

        return $this->processors[$uploadType];
    }

    /**
     * Get the current upload type parameter.
     *
     * @param array $extraConfiguration
     *
     * @internal param $parameter
     * @internal param $config
     *
     * @return mixed
     */
    protected function getUploadTypeParameter(array $extraConfiguration)
    {
        return array_key_exists('uploadTypeParameter', $extraConfiguration)
            ? $extraConfiguration['uploadTypeParameter']
            : $this->uploadTypeParameter;
    }
}
