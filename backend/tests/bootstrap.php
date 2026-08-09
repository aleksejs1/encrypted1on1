<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// PHPUnit doesn't go through bin/console's symfony/runtime bootstrapping, so
// .env -> .env.test loading has to happen explicitly here.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
