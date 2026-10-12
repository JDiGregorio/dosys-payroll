<?php

require dirname(__DIR__).'/vendor/autoload.php';

if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('REFUSING TO RUN TESTS: cached Laravel config may point to a development or production database. Run artisan config:clear first.');
}
