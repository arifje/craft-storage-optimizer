<?php

namespace arifje\craftstorageoptimizer;

use arifje\craftstorageoptimizer\jobs\RepairElementReferencesJob;
use arifje\craftstorageoptimizer\models\Settings;
use arifje\craftstorageoptimizer\services\AssetUsage;
use arifje\craftstorageoptimizer\services\Conversions;
use arifje\craftstorageoptimizer\services\Insights;
use arifje\craftstorageoptimizer\utilities\AssetOptimizer;
use arifje\craftstorageoptimizer\utilities\GifUsage;
use arifje\craftstorageoptimizer\variables\StorageOptimizerVariable;
use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\events\RegisterComponentTypesEvent;
use craft\events\ModelEvent;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use yii\base\Event;

/**
 * @property Conversions $conversions
 * @property Insights $insights
 * @property AssetUsage $assetUsage
 * @method Settings getSettings()
 */
class StorageOptimizer extends Plugin
{
    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.3.0';

    public static ?self $plugin = null;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        $this->setComponents([
            'conversions' => Conversions::class,
            'insights' => Insights::class,
            'assetUsage' => AssetUsage::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'arifje\\craftstorageoptimizer\\console\\controllers';
        }

        $this->registerTwigVariable();
        $this->registerUtility();
        $this->registerAssetSaveHandler();
        $this->registerElementReferenceRepairHandler();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('storage-optimizer/_settings.twig', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                $event->sender->set('storageOptimizer', StorageOptimizerVariable::class);
                $event->sender->set('gifToWebp', StorageOptimizerVariable::class);
            }
        );
    }

    private function registerUtility(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITY_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = AssetOptimizer::class;
                $event->types[] = GifUsage::class;
            }
        );
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
                        sprintf('Could not enqueue GIF media conversion for asset %s: %s', $asset->id, $e->getMessage()),
                        __METHOD__
                    );
                }
            }
        );
    }

    private function registerElementReferenceRepairHandler(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event): void {
                $element = $event->sender;

                if (!$element instanceof Element || $element instanceof Asset || $element->id === null) {
                    return;
                }

                $settings = $this->getSettings();

                if (!$settings->replaceAssetReferences || !$settings->convertToWebp) {
                    return;
                }

                try {
                    $queue = Craft::$app->getQueue();
                    $job = new RepairElementReferencesJob([
                        'sourceElementId' => (int)$element->id,
                    ]);

                    if (method_exists($queue, 'delay')) {
                        $queue->delay(5)->push($job);
                    } else {
                        $queue->push($job);
                    }
                } catch (\Throwable $e) {
                    Craft::error(
                        sprintf('Could not enqueue GIF reference repair for element %s: %s', $element->id, $e->getMessage()),
                        __METHOD__
                    );
                }
            }
        );
    }
}
