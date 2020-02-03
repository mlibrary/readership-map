<?php

include_once __DIR__ . '/../vendor/autoload.php';

$classLoader = new \Composer\Autoload\ClassLoader();
$classLoader->addPsr4("Readership\\Map\\", __DIR__ . '/../src/Readership/Map', true);
$classLoader->register();
