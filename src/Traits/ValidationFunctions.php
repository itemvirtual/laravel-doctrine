<?php

namespace Itemvirtual\LaravelDoctrine\Traits;

use Exception;
use Illuminate\Support\Facades\File;


trait ValidationFunctions
{
    /**
     * Validate the xml-mappings configuration
     * @return bool
     */
    public function validateMappingsPath()
    {
        try {
            $this->checkMappingsPath();
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
            return false;
        }
        return true;
    }

    /**
     * @return bool
     * @throws Exception
     */
    private function checkMappingsPath()
    {
        $mappingsDir = trim(config('laravel-doctrine.xml_mappings_path'), '/');
        $mappingsPath = str_replace(base_path() . '/', '', config('laravel-doctrine.xml_mappings_path'));

        // Must exist config xml_mappings_path
        if (!$mappingsDir) {
            throw new Exception('The destination path "xml_mappings_path" is mandatory');
        }

        // xml_mappings_path must be within the app directory
        if (strpos(config('laravel-doctrine.xml_mappings_path'), base_path()) !== 0) {
            throw new Exception('The "xml_mappings_path" destination path ' . $mappingsPath . ' must be within the app directory');
        }

        // To avoid errors, if xml_mappings_path does not exist, create it
        if (!File::isDirectory(config('laravel-doctrine.xml_mappings_path'))) {
            File::makeDirectory(config('laravel-doctrine.xml_mappings_path'), 0755, true);
        }

        return true;
    }
}
