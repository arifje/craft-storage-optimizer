<?php

namespace arifje\craftstorageoptimizer\controllers;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\services\AssetUsage;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class AssetUsageController extends Controller
{
    public function actionScan(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:asset-optimizer-usage');

        $request = Craft::$app->getRequest();
        $batchSize = (int)$request->getBodyParam('batchSize', AssetUsage::DEFAULT_BATCH_SIZE);
        $volumeId = (int)$request->getBodyParam('volumeId') ?: null;
        $result = StorageOptimizer::getInstance()->assetUsage->queueScan($batchSize, $volumeId);

        if ($result['queued']) {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Asset usage scan queued.'));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'An asset usage scan is already running.'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionDeleteGhosts(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:asset-optimizer-usage');

        $request = Craft::$app->getRequest();
        $runId = (int)$request->getBodyParam('runId') ?: null;
        $batchSize = (int)$request->getBodyParam('batchSize', AssetUsage::DEFAULT_DELETE_BATCH_SIZE);
        $result = StorageOptimizer::getInstance()->assetUsage->queueDeleteGhosts($runId, $batchSize);

        if ($result['queued']) {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Ghost asset deletion queued.'));
        } elseif ($result['reason'] === 'delete-already-active') {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Ghost asset deletion is already running.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('storage-optimizer', 'Could not queue ghost asset deletion: {reason}', [
                'reason' => $result['reason'],
            ]));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionCancelDeleteGhosts(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:asset-optimizer-usage');

        $runId = (int)Craft::$app->getRequest()->getBodyParam('runId') ?: null;
        $result = StorageOptimizer::getInstance()->assetUsage->cancelDeleteGhosts($runId);

        if ($result['canceled']) {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Ghost asset deletion canceled.'));
        } elseif ($result['reason'] === 'no-active-delete') {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'There is no active ghost asset deletion to cancel.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('storage-optimizer', 'Could not cancel ghost asset deletion: {reason}', [
                'reason' => $result['reason'],
            ]));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionClear(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:asset-optimizer-usage');

        $deleted = StorageOptimizer::getInstance()->assetUsage->clearSnapshots();
        Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Deleted {count} asset usage scan snapshots.', [
            'count' => $deleted,
        ]));

        return $this->redirectToPostedUrl();
    }
}
