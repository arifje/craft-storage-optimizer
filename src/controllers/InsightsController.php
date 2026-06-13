<?php

namespace arifje\craftstorageoptimizer\controllers;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\services\Insights;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class InsightsController extends Controller
{
    public function actionScan(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:storage-optimizer-gif-usage');

        $batchSize = (int)Craft::$app->getRequest()->getBodyParam('batchSize', Insights::DEFAULT_BATCH_SIZE);
        $result = StorageOptimizer::getInstance()->insights->queueScan($batchSize);

        if ($result['queued']) {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'GIF usage scan queued.'));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'A GIF usage scan is already running.'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionClear(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:storage-optimizer-gif-usage');

        $deleted = StorageOptimizer::getInstance()->insights->clearSnapshots();
        Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Deleted {count} GIF usage scan snapshots.', [
            'count' => $deleted,
        ]));

        return $this->redirectToPostedUrl();
    }

    public function actionDeleteUnused(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:storage-optimizer-gif-usage');

        $request = Craft::$app->getRequest();
        $runId = (int)$request->getBodyParam('runId') ?: null;
        $batchSize = (int)$request->getBodyParam('batchSize', Insights::DEFAULT_DELETE_BATCH_SIZE);
        $result = StorageOptimizer::getInstance()->insights->queueDeleteUnused($runId, $batchSize);

        if ($result['queued']) {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Unused GIF asset deletion queued.'));
        } elseif ($result['reason'] === 'delete-already-active') {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Unused GIF asset deletion is already running.'));
        } elseif ($result['reason'] === 'delete-already-completed') {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Unused GIF asset deletion already completed for this snapshot. Run a new scan before deleting again.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('storage-optimizer', 'Could not queue unused GIF asset deletion: {reason}', [
                'reason' => $result['reason'],
            ]));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionCancelDeleteUnused(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:storage-optimizer-gif-usage');

        $runId = (int)Craft::$app->getRequest()->getBodyParam('runId') ?: null;
        $result = StorageOptimizer::getInstance()->insights->cancelDeleteUnused($runId);

        if ($result['canceled']) {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'Unused GIF asset deletion canceled.'));
        } elseif ($result['reason'] === 'no-active-delete') {
            Craft::$app->getSession()->setNotice(Craft::t('storage-optimizer', 'There is no active unused GIF asset deletion to cancel.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('storage-optimizer', 'Could not cancel unused GIF asset deletion: {reason}', [
                'reason' => $result['reason'],
            ]));
        }

        return $this->redirectToPostedUrl();
    }
}
