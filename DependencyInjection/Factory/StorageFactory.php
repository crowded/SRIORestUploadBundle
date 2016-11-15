<?php

namespace SRIO\RestUploadBundle\DependencyInjection\Factory;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Reference;

class StorageFactory
{
    /**
     * Create the storage service.
     * @param ContainerBuilder $container
     * @param $id
     * @param array $config
     */
    public function create(ContainerBuilder $container, $id, array $config)
    {
        $this->createStorage($container, $id, $config);
    }

    /**
     * Create the temp storage service.
     * @param ContainerBuilder $container
     * @param $id
     * @param array $config
     */
    public function createTemp(ContainerBuilder $container, $id, array $config)
    {
        $this->createStorage($container, $id, $config, true);
    }

    protected function createStorage(ContainerBuilder $container, $id, array $config, $temp = false)
    {
        $adapterId = $config['filesystem'] . '.adapter';

        $prefix = 'srio_rest_upload.storage';
        if($temp) {
            $prefix .= '.temp';
            $adapterId .= '.temp';
        }

        if ($config['type'] === 'gaufrette') {
            $adapterDefinition = new DefinitionDecorator($prefix . '.gaufrette_adapter');
            $adapterDefinition->setPublic(false);
            $adapterDefinition->replaceArgument(0, new Reference($config['filesystem']));

            $container->setDefinition($adapterId, $adapterDefinition);
        } elseif ($config['type'] === 'flysystem') {
            $adapterDefinition = new DefinitionDecorator($prefix . '.flysystem_adapter');
            $adapterDefinition->setPublic(false);
            $adapterDefinition->replaceArgument(0, new Reference($config['filesystem']));

            $container->setDefinition($adapterId, $adapterDefinition);
        }

        $accepted_mime_types = null;

        if (isset($config['accepted_mime_types']) && is_array($config['accepted_mime_types']) && count($config['accepted_mime_types']) > 0) {
            $accepted_mime_types = $config['accepted_mime_types'];
        }

        $container
            ->setDefinition($id, new Definition('SRIO\RestUploadBundle\Storage\FileStorage'))
            ->addArgument($config['name'])
            ->addArgument(new Reference($adapterId))
            ->addArgument(new Reference($config['storage_strategy']))
            ->addArgument(new Reference($config['naming_strategy']))
            ->addArgument($accepted_mime_types);
    }
}
