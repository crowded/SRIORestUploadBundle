<?php
namespace SRIO\RestUploadBundle\Tests\Command;

use SRIO\RestUploadBundle\Command\CleanTempFolderCommand;
use SRIO\RestUploadBundle\Tests\Upload\AbstractUploadTestCase;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CleanTeampFolderCommandTest extends AbstractUploadTestCase
{
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

    protected function createInvalidResumableUpload()
    {
        $client = $this->startSession($this->getNewTempClient(), 'lorem.txt', 'text/plain');

        $response = $client->getResponse();
        $location = $response->headers->get('Location');
        $content = $this->getResource($client, 'apple.gif');
        $client->request('PUT', $location, array(), array(), array(
            'CONTENT_TYPE' => 'image/gif',
            'CONTENT_LENGTH' => strlen($content),
        ), $content);

        $this->assertResponseHasErrors($client);
    }

    public function testCleanTempCommand()
    {
        $this->createInvalidResumableUpload();

        $application = $this->getApplication();

        $application->add(new CleanTempFolderCommand());

        $command = $application->find('sriorest_upload:clean:temp');

        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command'  => $command->getName(),
        ), array(
            'all' => true
        ));

        $qb = self::$kernel->getContainer()->get('doctrine')->getRepository(self::$kernel->getContainer()->getParameter('srio_rest_upload.resumable_entity_class'))->createQueryBuilder('rus');
        $tempStorage = self::$kernel->getContainer()->get('srio_rest_upload.storage_voter.default')->getTempStorage();
        foreach($qb->getQuery()->getResult() as $session)
        {
            $this->assertFalse($tempStorage->getFilesystem()->has($session->getFilePath()));
        }
    }

    protected function getApplication()
    {
        $options = array('environment' => isset($_SERVER['TEST_FILESYSTEM']) ? strtolower($_SERVER['TEST_FILESYSTEM']).'_temp' : 'gaufrette_temp');
        self::bootKernel($options);

        return new Application(self::$kernel);
    }
}