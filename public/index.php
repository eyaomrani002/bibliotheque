<?php

use App\Kernel;

// In development increase execution time to avoid premature timeout
// (adjust or remove for production)
@ini_set('max_execution_time', '300'); // 5 minutes
@set_time_limit(300);

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
