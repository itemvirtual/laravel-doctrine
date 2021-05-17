<?php

namespace Itemvirtual\LaravelDoctrine\Traits;

use Illuminate\Support\Facades\File;

trait HelperFunctions
{

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

    /**
     * Removes the beginning of the given path to make it relative to project
     * @param $path
     * @return string
     */
    public function getRelativePath($path)
    {
        return str_replace(base_path() . '/', '', $path);
    }

}