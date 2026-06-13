<?php

namespace arifje\craftstorageoptimizer\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $convertOnAssetSave = true;
    public int $queueDelay = 300;
    public string $gif2webpPath = 'gif2webp';
    public int $quality = 80;
    public int $method = 4;
    public bool $multiThreaded = true;

    public function rules(): array
    {
        return [
            [['convertOnAssetSave', 'multiThreaded'], 'boolean'],
            [['queueDelay', 'quality', 'method'], 'integer'],
            [['queueDelay'], 'integer', 'min' => 0],
            [['quality'], 'integer', 'min' => 0, 'max' => 100],
            [['method'], 'integer', 'min' => 0, 'max' => 6],
            [['gif2webpPath'], 'string', 'max' => 255],
        ];
    }
}
