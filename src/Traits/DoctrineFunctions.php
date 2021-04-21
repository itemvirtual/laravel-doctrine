<?php

namespace Itemvirtual\LaravelDoctrine\Traits;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ORM\Tools\Setup;
use Illuminate\Support\Facades\File;

trait DoctrineFunctions
{

    /**
     * Create a Entity Manager for reading Entity files
     * @return EntityManager
     * @throws \Doctrine\DBAL\Exception
     * @throws \Doctrine\ORM\ORMException
     */
    public function getAnnotationEntityManager()
    {
        $paths = [config('laravel-doctrine.entities_path')];
        $isDevMode = config('laravel-doctrine.development_mode', false);

        $dbParams = array(
            'driver' => 'pdo_mysql',
            "host" => config('laravel-doctrine.db_host'),
            "port" => config('laravel-doctrine.db_port'),
            'user' => config('laravel-doctrine.db_username'),
            'password' => config('laravel-doctrine.db_password'),
            'dbname' => config('laravel-doctrine.db_database'),
        );

        $cache = new ArrayCache();

        $reader = new AnnotationReader();
        $driver = new AnnotationDriver($reader, $paths);

        $config = Setup::createAnnotationMetadataConfiguration($paths, $isDevMode);
        $config->setMetadataCacheImpl($cache);
        $config->setQueryCacheImpl($cache);
        $config->setMetadataDriverImpl($driver);

        $entityManager = EntityManager::create($dbParams, $config);
        $entityManager->getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        return $entityManager;
    }

    /**
     * Create a Entity Manager for reading XML files
     *
     * @return EntityManager
     * @throws \Doctrine\ORM\ORMException
     */
    public function getXMLEntityManager()
    {
        $paths = [config('laravel-doctrine.xml_mappings_path')];
        $isDevMode = config('laravel-doctrine.development_mode', false);

        $config = Setup::createXMLMetadataConfiguration($paths, $isDevMode);

        $dbParams = array(
            'driver' => 'pdo_mysql',
            "host" => config('laravel-doctrine.db_host'),
            "port" => config('laravel-doctrine.db_port'),
            'user' => config('laravel-doctrine.db_username'),
            'password' => config('laravel-doctrine.db_password'),
            'dbname' => config('laravel-doctrine.db_database'),
        );

        return EntityManager::create($dbParams, $config);
    }

    /**
     * Returns the Metadata to create Entities or update database
     * depending wich $EntityManager is passed
     *
     * @param $EntityManager
     * @return \Doctrine\Persistence\Mapping\ClassMetadata[]
     */
    public function getMetaData($EntityManager)
    {
        $ClassMetadataFactory = new DisconnectedClassMetadataFactory();
        $ClassMetadataFactory->setEntityManager($EntityManager);
        return $ClassMetadataFactory->getAllMetadata();
    }

    /**
     * Create a .gitgnore file, ignoring all directory content, in the given path
     * @param $path
     */
    private function addGitignoreFile($path)
    {
        if (!File::exists($path . '/.gitignore')) {
            File::put($path . '/.gitignore', '*' . PHP_EOL . '!.gitignore');
        }
    }
}