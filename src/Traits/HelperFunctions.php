<?php

namespace Itemvirtual\LaravelDoctrine\Traits;


trait HelperFunctions
{

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