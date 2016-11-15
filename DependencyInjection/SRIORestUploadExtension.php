<?php

namespace SRIO\RestUploadBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;
use SRIO\RestUploadBundle\DependencyInjection\Factory\StorageFactory;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class SRIORestUploadExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('srio_rest_upload.upload_type_parameter', $config['upload_type_parameter']);
        $container->setParameter('srio_rest_upload.resumable_entity_class', $config['resumable_entity_class']);
        $container->setParameter('srio_rest_upload.default_storage', $config['default_storage']);

        $factory = new StorageFactory();

        $this->createStorageVoter($container, $config['storage_voter']);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('processors.xml');
        $loader->load('handlers.xml');
        $loader->load('strategy.xml');
        $loader->load('storage.xml');

        if (isset($config['temp_storage'])) $this->createTempStorage($factory, $container, $config['temp_storage']);

        $this->createStorageServices($factory, $container, $config['storages']);
    }

    /**
     * Create storage services.
     *
     * @param StorageFactory $factory
     * @param ContainerBuilder $container
     * @param array $storageDefinitions
     */
    private function createStorageServices(StorageFactory $factory, ContainerBuilder $container, array $storageDefinitions)
    {
        $voterDefinition = $container->getDefinition('srio_rest_upload.storage_voter');


        foreach ($storageDefinitions as $name => $storage) {
            $id = $this->createStorage($factory, $container, $name, $storage);
            $voterDefinition->addMethodCall('addStorage', array(new Reference($id)));
        }
    }

    /**
     * Create a single storage service.
     *
     * @param StorageFactory $factory
     * @param ContainerBuilder $containerBuilder
     * @param $name
     * @param array $config
     *
     * @return string
     */
    private function createStorage(StorageFactory $factory, ContainerBuilder $containerBuilder, $name, array $config)
    {
        $id = sprintf('srio_rest_upload.storage.%s', $name);

        $config['name'] = $name;
        $factory->create($containerBuilder, $id, $config);

        return $id;
    }

    /**
     * Create the storage voter.
     *
     * @param ContainerBuilder $builder
     * @param $service
     */
    private function createStorageVoter(ContainerBuilder $builder, $service)
    {
        $definition = new DefinitionDecorator($service);
        $builder->setDefinition('srio_rest_upload.storage_voter', $definition);
    }

    private function createTempStorage(StorageFactory $factory, ContainerBuilder $container, $local_storage_adapter)
    {
        if (isset($local_storage_adapter['type']) && isset($local_storage_adapter['filesystem'])) {
            $id = 'srio_rest_upload.temp.storage';

            $factory->createTemp($container, $id, array_replace(['name' => 'temp.local'], $local_storage_adapter));

            $storageHandler = $container->getDefinition('srio_rest_upload.storage_voter.default');

            $storageHandler->replaceArgument(1, new Reference($id));
        }
    }
}
