<?php

$root = dirname(__DIR__);
$config = require $root.'/backend/config/i18n.php';
$catalogs = [];
foreach (array_keys($config['locales']) as $locale) {
    foreach (glob($root.'/backend/lang/'.$config['locales'][$locale]['translation'].'/*.php') as $path) {
        $catalogs[$locale][pathinfo($path, PATHINFO_FILENAME)] = require $path;
    }
}
echo json_encode(['config' => $config, 'catalogs' => $catalogs], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
