<?php

namespace arifje\giftowebp\console;

use arifje\giftowebp\GifToWebp;
use arifje\giftowebp\services\Conversions;
use craft\console\Controller;

abstract class BaseCommandController extends Controller
{
    protected function conversions(): Conversions
    {
        return GifToWebp::getInstance()->conversions;
    }

    protected function writeResult(array $result): void
    {
        foreach ($result as $key => $value) {
            $this->stdout(sprintf('%s: %s%s', $key, $value, PHP_EOL));
        }
    }
}
