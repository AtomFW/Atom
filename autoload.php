<?php

spl_autoload_register(function ($class_name) {
    try {
        $autoloads = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . str_replace("\\", DIRECTORY_SEPARATOR, $class_name) . ".php";
        if (file_exists( $autoloads )) {
            require_once ( $autoloads );
            return true;
        }

        echo ("AUTOLOAD: this class not exist: " . $class_name);
        
    } catch (\Throwable $th) {
        throw new Exception("AUTOLOAD: this class not exist: " . $class_name, 1);
    }
});
