<?php

declare(strict_types=1);

use ManufacturingErp\WebApp;

require dirname(__DIR__) . '/src/bootstrap.php';

(new WebApp())->handle();
