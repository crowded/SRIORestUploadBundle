<?php

namespace SRIO\RestUploadBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanTempFolderCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('sriorest_upload:clean:temp')
            ->addOption('all', 'a', InputOption::VALUE_OPTIONAL, 'Set to true if all files should be deleted', false)
            ->setDescription('Cleans the folder where the temporarily files are stored when a resumable upload was not finished.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tempStorage = $this->getContainer()->get('srio_rest_upload.storage_handler')->getTempStorage();
        if(!is_null($tempStorage))
        {
            $qb = $this->getContainer()->get('doctrine')->getRepository($this->getContainer()->getParameter('srio_rest_upload.resumable_entity_class'))->createQueryBuilder('rus');

            $createdAt = (new \DateTime())->setTimestamp(strtotime('two hours ago'));

            if($input->getOption('all')) {
                $createdAt = new \DateTime();
            }

            $qb->where('rus.createdAt > :createdAt')->setParameter('createdAt', $createdAt);

            foreach($qb->getQuery()->getResult() as $session)
            {
                if($tempStorage->getFilesystem()->has($session->getFilePath())) {
                    $tempStorage->getFilesystem()->delete($session->getFilePath());
                    $output->writeln(sprintf('File %s was deleted', $session->getFilePath()));
                }
            }
        }
    }
}
