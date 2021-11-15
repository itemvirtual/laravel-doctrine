<?php

namespace Itemvirtual\LaravelDoctrine\Traits;

use Illuminate\Support\Facades\File;


trait ValidationFunctions
{
    private $entitiesDir;
    private $entitiesPath;
    private $mappingsDir;
    private $mappingsPath;

    public function validateDirectories()
    {
        try {
            $this->checkMappingsAndEntitiesDirectories();
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
            return 0;
        }
        return 1;
    }

    /**
     * Create a Entity Manager for reading Entity files
     * @return bool
     * @throws \Exception
     */
    private function checkMappingsAndEntitiesDirectories()
    {
        $this->entitiesDir = trim(config('laravel-doctrine.entities_path'), '/');
        $this->entitiesPath = str_replace(base_path() . '/', '', config('laravel-doctrine.entities_path'));
        $this->mappingsDir = trim(config('laravel-doctrine.xml_mappings_path'), '/');
        $this->mappingsPath = str_replace(base_path() . '/', '', config('laravel-doctrine.xml_mappings_path'));

        // Must exist config entities_path
        if (!$this->entitiesDir) {
            throw new \Exception('The destination path "entities_path" is mandatory');
        }

        // Must exist config xml_mappings_path
        if (!$this->mappingsDir) {
            throw new \Exception('The destination path "xml_mappings_path" is mandatory');
        }

        // entities_path must be within the app directory
        if (strpos(config('laravel-doctrine.entities_path'), base_path()) !== 0) {
            throw new \Exception('The "entities_path" destination path ' . $this->entitiesPath . ' must be within the app directory');
        }

        // xml_mappings_path must be within the app directory
        if (strpos(config('laravel-doctrine.xml_mappings_path'), base_path()) !== 0) {
            throw new \Exception('The "xml_mappings_path" destination path ' . $this->mappingsPath . ' must be within the app directory');
        }

        // Cannot be the same path
        if ($this->entitiesDir == $this->mappingsDir) {
            throw new \Exception('The destination paths "entities_path" and "xml_mappings_path" in config.laravel-doctrine cannot be the same');
        }

        // To avoid errors, if xml_mappings_path does not exist, create it
        if (!File::isDirectory(config('laravel-doctrine.xml_mappings_path'))) {
            File::makeDirectory(config('laravel-doctrine.xml_mappings_path'));
        }

        return true;
    }
}