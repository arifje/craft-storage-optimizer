<?php

namespace arifje\craftstorageoptimizer\utilities;

use arifje\craftstorageoptimizer\StorageOptimizer;
use arifje\craftstorageoptimizer\services\Insights;
use Craft;
use craft\base\Utility;
use craft\helpers\UrlHelper;

class GifUsage extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('storage-optimizer', 'GIF Usage');
    }

    public static function id(): string
    {
        return 'storage-optimizer-gif-usage';
    }

    public static function contentHtml(): string
    {
        $insights = StorageOptimizer::getInstance()->insights;
        $latestRun = $insights->latestRun();
        $gifAssetReport = null;

        if ($latestRun !== null) {
            $gifAssetReport = $insights->gifAssetReport(
                (int)$latestRun['id'],
                (int)self::queryParam('gifPage', 1),
                (int)self::queryParam('gifPerPage', Insights::DEFAULT_REPORT_LIMIT),
                (string)self::queryParam('gifSort', 'size'),
                (string)self::queryParam('gifDir', 'desc')
            );
            $gifAssetReport = self::withReportUrls($gifAssetReport);
        }

        return Craft::$app->getView()->renderTemplate('storage-optimizer/utilities/gif-usage.twig', [
            'latestRun' => $latestRun,
            'gifAssetReport' => $gifAssetReport,
            'topAssets' => $gifAssetReport['rows'] ?? [],
            'defaultBatchSize' => Insights::DEFAULT_BATCH_SIZE,
            'defaultDeleteBatchSize' => Insights::DEFAULT_DELETE_BATCH_SIZE,
        ]);
    }

    private static function withReportUrls(array $report): array
    {
        $report['sortUrls'] = [];

        foreach ($report['sortColumns'] as $column) {
            $nextDirection = $report['sort'] === $column && $report['direction'] === 'asc' ? 'desc' : 'asc';
            $report['sortUrls'][$column] = self::reportUrl([
                'gifSort' => $column,
                'gifDir' => $nextDirection,
                'gifPage' => 1,
            ]);
        }

        $report['paginationUrls'] = [
            'first' => self::reportUrl(['gifPage' => 1]),
            'previous' => self::reportUrl(['gifPage' => max(1, (int)$report['page'] - 1)]),
            'next' => self::reportUrl(['gifPage' => min((int)$report['totalPages'], (int)$report['page'] + 1)]),
            'last' => self::reportUrl(['gifPage' => (int)$report['totalPages']]),
        ];
        $report['perPageOptions'] = [];

        foreach ([25, 50, 100] as $perPage) {
            $report['perPageOptions'][] = [
                'value' => $perPage,
                'url' => self::reportUrl([
                    'gifPerPage' => $perPage,
                    'gifPage' => 1,
                ]),
            ];
        }

        return $report;
    }

    private static function reportUrl(array $params): string
    {
        $request = Craft::$app->getRequest();
        $pathInfo = method_exists($request, 'getPathInfo') ? $request->getPathInfo() : 'utilities/storage-optimizer-gif-usage';
        $queryParams = method_exists($request, 'getQueryParams') ? $request->getQueryParams() : [];

        return UrlHelper::cpUrl($pathInfo, array_merge($queryParams, $params));
    }

    private static function queryParam(string $name, $default = null)
    {
        $request = Craft::$app->getRequest();

        return method_exists($request, 'getQueryParam') ? $request->getQueryParam($name, $default) : $default;
    }
}
