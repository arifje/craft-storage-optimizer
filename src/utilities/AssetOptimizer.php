<?php

namespace arifje\craftstorageoptimizer\utilities;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\services\AssetUsage;
use Craft;
use craft\base\Utility;
use craft\helpers\UrlHelper;

class AssetOptimizer extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('storage-optimizer', 'Asset Optimizer');
    }

    public static function id(): string
    {
        return 'asset-optimizer-usage';
    }

    public static function contentHtml(): string
    {
        $assetUsage = StorageOptimizer::getInstance()->assetUsage;
        $latestRun = $assetUsage->latestRun();
        $request = Craft::$app->getRequest();
        $ghostAssetReport = null;

        if ($latestRun !== null) {
            $ghostAssetReport = $assetUsage->ghostAssetReport(
                (int)$latestRun['id'],
                (int)self::queryParam('assetPage', 1),
                (int)self::queryParam('assetPerPage', AssetUsage::DEFAULT_REPORT_LIMIT),
                (string)self::queryParam('assetSort', 'size'),
                (string)self::queryParam('assetDir', 'desc')
            );
            $ghostAssetReport = self::withReportUrls($ghostAssetReport);
        }

        return Craft::$app->getView()->renderTemplate('storage-optimizer/utilities/asset-optimizer.twig', [
            'latestRun' => $latestRun,
            'ghostAssetReport' => $ghostAssetReport,
            'topGhostAssets' => $ghostAssetReport['rows'] ?? [],
            'defaultBatchSize' => AssetUsage::DEFAULT_BATCH_SIZE,
            'defaultDeleteBatchSize' => AssetUsage::DEFAULT_DELETE_BATCH_SIZE,
            'volumeOptions' => array_merge([
                [
                    'label' => Craft::t('storage-optimizer', 'All volumes'),
                    'value' => '',
                ],
            ], $assetUsage->availableVolumes()),
        ]);
    }

    private static function withReportUrls(array $report): array
    {
        $report['sortUrls'] = [];

        foreach ($report['sortColumns'] as $column) {
            $nextDirection = $report['sort'] === $column && $report['direction'] === 'asc' ? 'desc' : 'asc';
            $report['sortUrls'][$column] = self::reportUrl([
                'assetSort' => $column,
                'assetDir' => $nextDirection,
                'assetPage' => 1,
            ]);
        }

        $report['paginationUrls'] = [
            'first' => self::reportUrl(['assetPage' => 1]),
            'previous' => self::reportUrl(['assetPage' => max(1, (int)$report['page'] - 1)]),
            'next' => self::reportUrl(['assetPage' => min((int)$report['totalPages'], (int)$report['page'] + 1)]),
            'last' => self::reportUrl(['assetPage' => (int)$report['totalPages']]),
        ];
        $report['perPageOptions'] = [];

        foreach ([25, 50, 100] as $perPage) {
            $report['perPageOptions'][] = [
                'value' => $perPage,
                'url' => self::reportUrl([
                    'assetPerPage' => $perPage,
                    'assetPage' => 1,
                ]),
            ];
        }

        return $report;
    }

    private static function reportUrl(array $params): string
    {
        $request = Craft::$app->getRequest();
        $pathInfo = method_exists($request, 'getPathInfo') ? $request->getPathInfo() : 'utilities/asset-optimizer-usage';
        $queryParams = method_exists($request, 'getQueryParams') ? $request->getQueryParams() : [];

        return UrlHelper::cpUrl($pathInfo, array_merge($queryParams, $params));
    }

    private static function queryParam(string $name, $default = null)
    {
        $request = Craft::$app->getRequest();

        return method_exists($request, 'getQueryParam') ? $request->getQueryParam($name, $default) : $default;
    }
}
