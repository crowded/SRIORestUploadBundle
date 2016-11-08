<?php

namespace SRIO\RestUploadBundle\Tests\Upload;

use Symfony\Bundle\FrameworkBundle\Client;

class ResumableUploadTest extends AbstractUploadTestCase
{
    public function testCompleteUpload()
    {
        $client = $this->startSession($this->getNewClient(), 'apple.gif', 'image/gif');

        $response = $client->getResponse();
        $location = $response->headers->get('Location');
        $content = $this->getResource($client, 'apple.gif');
        $client->request('PUT', $location, array(), array(), array(
            'CONTENT_TYPE' => 'image/gif',
            'CONTENT_LENGTH' => strlen($content),
        ), $content);

        $this->assertSuccessful($client, $content);
    }

    public function testChunkedUpload()
    {
        $client = $this->startSession($this->getNewClient(), 'apple.gif', 'image/gif');

        $content = $this->chunkedUpload($client, 'apple.gif', 'image/gif');

        $this->assertSuccessful($client, $content);
    }

    protected function chunkedUpload(Client $client, $resource, $mimetype)
    {
        $response = $client->getResponse();
        $location = $response->headers->get('Location');
        $content = $this->getResource($client, $resource);
        $chunkSize = 256;

        for ($start = 0; $start < strlen($content); $start += $chunkSize) {
            $part = substr($content, $start, $chunkSize);
            $end = $start + strlen($part) - 1;
            $client->request('PUT', $location, array(), array(), array(
                'CONTENT_TYPE' => $mimetype,
                'CONTENT_LENGTH' => strlen($part),
                'HTTP_Content-Range' => 'bytes '.$start.'-'.$end.'/'.strlen($content),
            ), $part);

            $response = $client->getResponse();
            if (($start + $chunkSize) < strlen($content)) {
                $this->assertEquals(308, $response->getStatusCode());
                $this->assertEquals('0-'.$end, $response->headers->get('Range'));

                $client->request('PUT', $location, array(), array(), array(
                    'CONTENT_LENGTH' => 0,
                    'HTTP_Content-Range' => 'bytes */'.strlen($content),
                ));

                $response = $client->getResponse();
                $this->assertEquals(308, $response->getStatusCode());
                $this->assertEquals('0-'.$end, $response->headers->get('Range'));
            }
        }

        return $content;
    }

    /**
     * @param Client $client
     *
     * @param        $resource
     * @param        $mimetype
     *
     * @return Client
     */
    protected function startSession(Client $client, $resource, $mimetype)
    {
        $content = $this->getResource($client, $resource);
        $parameters = array('name' => 'test');
        $json = json_encode($parameters);

        $client->request('POST', '/upload?uploadType=resumable', array(), array(), array(
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => strlen($json),
            'HTTP_X-Upload-Content-Type' => $mimetype,
            'HTTP_X-Upload-Content-Length' => strlen($content),
        ), $json);

        $response = $client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Location'));
        $this->assertEquals(0, $response->headers->get('Content-Length', 0));

        return $client;
    }

    protected function assertSuccessful(Client $client, $content)
    {
        $response = $client->getResponse();

        $this->assertEquals(200, $response->getStatusCode());
        $jsonContent = json_decode($response->getContent(), true);
        $this->assertNotEmpty($jsonContent);
        $this->assertTrue(array_key_exists('path', $jsonContent));
        $this->assertTrue(array_key_exists('size', $jsonContent));
        $this->assertTrue(array_key_exists('name', $jsonContent));
        $this->assertEquals('test', $jsonContent['name']);
        $this->assertEquals(strlen($content), $jsonContent['size']);

        $filePath = $this->getUploadedFilePath($client).$jsonContent['path'];
        $this->assertTrue(file_exists($filePath));
        $this->assertEquals($content, file_get_contents($filePath));
        $this->assertTrue(array_key_exists('id', $jsonContent));
        $this->assertNotEmpty($jsonContent['id']);
    }

    public function testCompleteUploadWithMimeTypeChecking()
    {
        $client = $this->startSession($this->getNewTempClient(), 'apple.gif', 'image/gif');

        $response = $client->getResponse();
        $location = $response->headers->get('Location');
        $content = $this->getResource($client, 'apple.gif');
        $client->request('PUT', $location, array(), array(), array(
            'CONTENT_TYPE' => 'image/gif',
            'CONTENT_LENGTH' => strlen($content),
        ), $content);

        $this->assertSuccessful($client, $content);
    }

    public function testCompleteUploadWithUnacceptedMimeTypeChecking()
    {
        $client = $this->startSession($this->getNewTempClient(), 'lorem.txt', 'text/plain');

        $response = $client->getResponse();
        $location = $response->headers->get('Location');
        $content = $this->getResource($client, 'lorem.txt');
        $client->request('PUT', $location, array(), array(), array(
            'CONTENT_TYPE' => 'text/plain',
            'CONTENT_LENGTH' => strlen($content),
        ), $content);

        $this->assertResponseHasErrors($client);
    }

    public function testCompleteUploadWithUnacceptedMimeTypeCheckingWithAcceptedContentType()
    {
        $client = $this->startSession($this->getNewTempClient(), 'lorem.txt', 'image/gif');

        $response = $client->getResponse();
        $location = $response->headers->get('Location');
        $content = $this->getResource($client, 'lorem.txt');
        $client->request('PUT', $location, array(), array(), array(
            'CONTENT_TYPE' => 'image/gif',
            'CONTENT_LENGTH' => strlen($content),
        ), $content);

        $this->assertResponseHasErrors($client);
    }

    public function testChunkedUploadWithMimeTypeChecking()
    {
        $resource = 'apple.gif';
        $mimetypes = 'image/gif';
        $client = $this->startSession($this->getNewTempClient(), $resource, $mimetypes);

        $content = $this->chunkedUpload($client, $resource, $mimetypes);

        $this->assertSuccessful($client, $content);
    }

    public function testChunkedUploadWithUnacceptedMimeTypeChecking()
    {
        $resource = 'lorem.txt';
        $mimetypes = 'text/plain';
        $client = $this->startSession($this->getNewTempClient(), $resource, $mimetypes);

        $this->chunkedUpload($client, $resource, $mimetypes);

        $this->assertResponseHasErrors($client);
    }

    public function testChunkedUploadWithUnacceptedMimeTypeCheckingWithAcceptedContentType()
    {
        $resource = 'lorem.txt';
        $mimetypes = 'image/gif';
        $client = $this->startSession($this->getNewTempClient(), $resource, $mimetypes);

        $this->chunkedUpload($client, $resource, $mimetypes);

        $this->assertResponseHasErrors($client);
    }
}
