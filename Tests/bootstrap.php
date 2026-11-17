<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

set_exception_handler(function ($exception) {
    fwrite(STDERR, 'FAIL '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$checks = 0;
function check($condition, $label)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo 'PASS '.$label.PHP_EOL;
}
