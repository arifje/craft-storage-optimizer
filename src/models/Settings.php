<?php

namespace arifje\craftstorageoptimizer\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $convertOnAssetSave = true;
    public bool $replaceAssetReferences = true;
    public int $queueDelay = 300;
    public string $gif2webpPath = 'gif2webp';
    public string $compressionMode = 'lossy';
    public int $quality = 80;
    public int $method = 4;
    public bool $minimizeOutputSize = true;
    public bool $multiThreaded = true;
    public bool $skipLargerWebp = true;
    public int $minimumSavingsPercent = 0;

    public function rules(): array
    {
        return [
            [['convertOnAssetSave', 'replaceAssetReferences', 'minimizeOutputSize', 'multiThreaded', 'skipLargerWebp'], 'boolean'],
            [['queueDelay', 'quality', 'method', 'minimumSavingsPercent'], 'integer'],
            [['queueDelay'], 'integer', 'min' => 0],
            [['quality'], 'integer', 'min' => 0, 'max' => 100],
            [['method'], 'integer', 'min' => 0, 'max' => 6],
            [['minimumSavingsPercent'], 'integer', 'min' => 0, 'max' => 100],
            [['gif2webpPath'], 'string', 'max' => 255],
            [['compressionMode'], 'in', 'range' => ['lossless', 'lossy', 'mixed']],
        ];
    }
}
