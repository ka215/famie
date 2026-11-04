<?php

putenv('FAMIE_DB_TEST_RUN=1');
$_ENV['FAMIE_DB_TEST_RUN'] = '1';
$_SERVER['FAMIE_DB_TEST_RUN'] = '1';

require __DIR__.'/../vendor/autoload.php';
