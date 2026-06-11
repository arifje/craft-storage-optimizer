<?php

namespace arifje\giftowebp;

use arifje\giftowebp\models\Settings;
use arifje\giftowebp\services\Conversions;
use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\events\ModelEvent;
use yii\base\Event;

/**
 * @property Conversions $conversions
 * @method Settings getSettings()
 */
class GifToWebp extends Plugin
{
    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.0.0';

    public static ?self $plugin = null;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        $this->setComponents([
            'conversions' => Conversions::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'arifje\\giftowebp\\console\\controllers';
        }

        $this->registerAssetSaveHandler();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('gif-to-webp/_settings.twig', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerAssetSaveHandler(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event): void {
                $asset = $event->sender;

                if (!$asset instanceof Asset) {
                    return;
                }

                $settings = $this->getSettings();

                if (!$settings->convertOnAssetSave || $this->conversions->isSavingGeneratedAsset()) {
                    return;
                }

                if (!$this->conversions->isGifAsset($asset)) {
                    return;
                }

                try {
                    $this->conversions->queueAsset($asset);
                } catch (\Throwable $e) {
                    Craft::error(
                        sprintf('Could not enqueue GIF to WebP conversion for asset %s: %s', $asset->id, $e->getMessage()),
                        __METHOD__
                    );
                }
            }
        );
    }
}
