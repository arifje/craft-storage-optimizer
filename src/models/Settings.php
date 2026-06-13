<?php

namespace arifje\craftstorageoptimizer\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $convertOnAssetSave = true;
    public bool $convertToWebp = true;
    public bool $convertToMp4 = false;
    public bool $replaceAssetReferences = true;
    public int $queueDelay = 300;
    public string $gif2webpPath = 'gif2webp';
    public string $ffmpegPath = 'ffmpeg';
    public string $compressionMode = 'lossy';
    public int $quality = 80;
    public int $method = 4;
    public bool $minimizeOutputSize = true;
    public bool $multiThreaded = true;
    public bool $skipLargerWebp = true;
    public int $minimumSavingsPercent = 0;
    public int $mp4Crf = 23;
    public string $mp4Preset = 'medium';
    public bool $mp4FastStart = true;
    public bool $skipLargerMp4 = true;
    public int $minimumMp4SavingsPercent = 0;

    public function rules(): array
    {
        return [
            [['convertOnAssetSave', 'convertToWebp', 'convertToMp4', 'replaceAssetReferences', 'minimizeOutputSize', 'multiThreaded', 'skipLargerWebp', 'mp4FastStart', 'skipLargerMp4'], 'boolean'],
            [['queueDelay', 'quality', 'method', 'minimumSavingsPercent', 'mp4Crf', 'minimumMp4SavingsPercent'], 'integer'],
            [['queueDelay'], 'integer', 'min' => 0],
            [['quality'], 'integer', 'min' => 0, 'max' => 100],
            [['method'], 'integer', 'min' => 0, 'max' => 6],
            [['minimumSavingsPercent'], 'integer', 'min' => 0, 'max' => 100],
            [['mp4Crf'], 'integer', 'min' => 0, 'max' => 51],
            [['minimumMp4SavingsPercent'], 'integer', 'min' => 0, 'max' => 100],
            [['gif2webpPath', 'ffmpegPath'], 'string', 'max' => 255],
            [['compressionMode'], 'in', 'range' => ['lossless', 'lossy', 'mixed']],
            [['mp4Preset'], 'in', 'range' => ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow']],
        ];
    }
}
