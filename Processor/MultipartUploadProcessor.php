<?php

namespace SRIO\RestUploadBundle\Processor;

use SRIO\RestUploadBundle\Storage\FileStorage;
use Symfony\Component\HttpFoundation\Request;
use SRIO\RestUploadBundle\Exception\UploadProcessorException;
use SRIO\RestUploadBundle\Upload\UploadResult;

class MultipartUploadProcessor extends AbstractUploadProcessor
{
    /**
     * {@inheritdoc}
     *
     * @param Request $request
     *
     * @throws \Exception|\SRIO\RestUploadBundle\Exception\UploadException
     *
     * @return \SRIO\RestUploadBundle\Upload\UploadResult
     */
    public function handleRequest(Request $request)
    {
        // Check that needed headers exists
        $this->checkHeaders($request);

        // Create the response
        $result = new UploadResult();
        $result->setRequest($request);
        $result->setConfig($this->config);
        $result->setForm($this->form);

        // Submit form data
        if ($this->form != null) {
            // Get formData
            $formData = $this->getFormData($request);
            $formData = $this->createFormData($formData);

            $this->form->submit($formData);
        }

        if ($this->form === null || $this->form->isValid()) {
            list($contentType, $content) = $this->getContent($request);

            $file = $this->storageHandler->store($result, $content, array(
                'metadata' => array(
                    FileStorage::METADATA_CONTENT_TYPE => $contentType,
                ),
            ));

            $result->setFile($file);
            $this->storageHandler->finishStore($result);
        }

        return $result;
    }

    /**
     * Get the form data from the request.
     *
     * Note: MUST be called before getContent, and just one time.
     *
     * @param Request $request
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadProcessorException
     *
     * @return array
     */
    protected function getFormData(Request $request)
    {
        list($boundaryContentType, $boundaryContent) = $this->getPart($request);

        $expectedContentType = 'application/json';
        if (substr($boundaryContentType, 0, strlen($expectedContentType)) != $expectedContentType) {
            throw new UploadProcessorException(sprintf(
                'Expected content type of first part is %s. Found %s',
                $expectedContentType,
                $boundaryContentType
            ));
        }

        $jsonContent = json_decode($boundaryContent, true);
        if ($jsonContent === null) {
            throw new UploadProcessorException('Unable to parse JSON');
        }

        return $jsonContent;
    }

    /**
     * Get the content part of the request.
     *
     * Note: MUST be called after getFormData, and just one time.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param Request                                   $request
     *
     * @return array
     */
    protected function getContent(Request $request)
    {
        return $this->getPart($request);
    }

    /**
     * Check multipart headers.
     *
     * @param Request $request
     * @param array   $headers
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadProcessorException
     */
    protected function checkHeaders(Request $request, array $headers = array())
    {
        list($contentType) = $this->parseContentTypeAndBoundary($request);

        $expectedContentType = 'multipart/related';
        if ($contentType != $expectedContentType) {
            throw new UploadProcessorException(sprintf(
                'Content-Type must be %s',
                $expectedContentType
            ));
        }

        parent::checkHeaders($request, array('Content-Type', 'Content-Length'));
    }

    /**
     * Get a part of request.
     *
     * @param Request $request
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadProcessorException
     *
     * @return array
     */
    protected function getPart(Request $request)
    {
        list($contentType, $boundary) = $this->parseContentTypeAndBoundary($request);
        $content = $this->getRequestPart($request, $boundary);

        if (empty($content)) {
            throw new UploadProcessorException(sprintf('An empty content found'));
        }

        $headerLimitation = strpos($content, "\r\n\r\n") + 1;
        if ($headerLimitation == -1) {
            throw new UploadProcessorException('Unable to determine headers limit');
        }

        $contentType = null;
        $headersContent = substr($content, 0, $headerLimitation);
        $headersContent = trim($headersContent);
        $body = substr($content, $headerLimitation);
        $body = trim($body);

        foreach (explode("\r\n", $headersContent) as $header) {
            $parts = explode(':', $header);
            if (count($parts) != 2) {
                continue;
            }

            $name = trim($parts[0]);
            if (strtolower($name) == 'content-type') {
                $contentType = trim($parts[1]);
                break;
            }
        }

        return array($contentType, $body);
    }

    /**
     * Get part of a resource.
     *
     * @param Request $request
     * @param $boundary
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadProcessorException
     *
     * @return string
     */
    protected function getRequestPart(Request $request, $boundary)
    {
        $contentHandler = $this->getRequestContentHandler($request);

        $delimiter = '--'.$boundary."\r\n";
        $endDelimiter = '--'.$boundary.'--';
        $boundaryCount = 0;
        $content = '';
        while (!$contentHandler->eof()) {
            $line = $contentHandler->gets();
            if ($line === false) {
                throw new UploadProcessorException('An error appears while reading input');
            }

            if ($boundaryCount == 0) {
                if ($line != $delimiter) {
                    if ($contentHandler->getCursor() == strlen($line)) {
                        throw new UploadProcessorException('Expected boundary delimiter');
                    }
                } else {
                    continue;
                }

                ++$boundaryCount;
            } elseif ($line == $delimiter) {
                break;
            } elseif ($line == $endDelimiter || $line == $endDelimiter."\r\n") {
                break;
            }

            $content .= $line;
        }

        return trim($content);
    }

    /**
     * Parse the content type and boudary from Content-Type header.
     *
     * @param Request $request
     *
     * @return array
     *
     * @throws \SRIO\RestUploadBundle\Exception\UploadProcessorException
     */
    protected function parseContentTypeAndBoundary(Request $request)
    {
        $contentParts = explode(';', $request->headers->get('Content-Type'));
        if (count($contentParts) != 2) {
            throw new UploadProcessorException('Boundary may be missing');
        }

        $contentType = trim($contentParts[0]);
        $boundaryPart = trim($contentParts[1]);

        $shouldStart = 'boundary=';
        if (substr($boundaryPart, 0, strlen($shouldStart)) != $shouldStart) {
            throw new UploadProcessorException('Boundary is not set');
        }

        $boundary = substr($boundaryPart, strlen($shouldStart));
        if (substr($boundary, 0, 1) == '"' && substr($boundary, -1) == '"') {
            $boundary = substr($boundary, 1, -1);
        }

        return array($contentType, $boundary);
    }
}
