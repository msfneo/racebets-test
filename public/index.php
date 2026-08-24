<?php

declare(strict_types=1);

use App\Container;
use App\Http\Kernel;
use App\Http\Request;
use App\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

(new Kernel(new Container()))
    ->handle(Request::fromGlobals())
    ->send();
