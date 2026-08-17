<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\console\controllers;

use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\exceptions\IndexElementException;
use lhs\elasticsearch\models\IndexableElementModel;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use lhs\elasticsearch\records\ElasticsearchRecord;

/**
 * Manage Craft Elasticsearch indexes from the command line
 */
class ElasticsearchController extends Controller
{
    /** @var ElasticsearchPlugin */
    public $plugin;

    /**
     * @var bool Whether to dispatch the reindex to the queue as chunked jobs
     * (the same strategy the control panel utility uses) instead of indexing
     * synchronously in this process. Each chunk runs as its own queue job, so
     * `queue/run` executes it in an isolated child process — avoiding the
     * memory exhaustion a single long-running reindex process hits.
     */
    public $queue = false;

    public function init(): void
    {
        parent::init();

        $this->plugin = ElasticsearchPlugin::getInstance();
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'reindex-all') {
            $options[] = 'queue';
        }

        return $options;
    }

    /**
     * Reindex entries, assets, products & digital products in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexAll(): int
    {
        if ($this->queue) {
            return $this->queueReindexAll();
        }

        $indexableElementModels = $this->plugin->service->getIndexableElementModels();

        return $this->reindexElementsBlueGreen($indexableElementModels);
    }

    /**
     * Push a dispatcher ReIndexElementsJob per site, mirroring the control
     * panel utility's queue path. The dispatcher fans out chunk jobs that
     * `queue/run` executes in isolated child processes, so no single process
     * has to hold the whole index in memory. Chain with the queue runner to
     * finish the work in the same cron:
     *
     *     craft elasticsearch/elasticsearch/reindex-all --queue && craft queue/run
     */
    protected function queueReindexAll(): int
    {
        $siteIds = \craft\records\Site::find()->select('id')->column();

        foreach ($siteIds as $siteId) {
            $job = new \lhs\elasticsearch\jobs\ReIndexElementsJob;
            $job->siteId = $siteId;
            \craft\helpers\Queue::push(
                job: $job,
                priority: 10,
                ttr: $job->getTtr(),
            );

            $this->stdout("Queued reindex dispatcher for site #{$siteId}." . PHP_EOL, Console::FG_GREEN);
        }

        $this->stdout('Run `craft queue/run` to process the jobs.' . PHP_EOL, Console::FG_YELLOW);

        return ExitCode::OK;
    }

    /**
     * Reindex entries in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexEntries(): int
    {
        $elementDescriptors = $this->plugin->service->getIndexableEntryModels();

        return $this->reindexElements($elementDescriptors, 'entries');
    }

    /**
     * Reindex assets in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexAssets(): int
    {
        $elementDescriptors = $this->plugin->service->getIndexableAssetModels();

        return $this->reindexElements($elementDescriptors, 'assets');
    }

    /**
     * Reindex products in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexProducts(): int
    {
        $elementDescriptors = $this->plugin->service->getIndexableProductModels();

        return $this->reindexElements($elementDescriptors, 'products');
    }

    /**
     * Reindex digital products in Elasticsearch
     * @return int A shell exit code. 0 indicates success, anything else indicates an error
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    public function actionReindexDigitalProducts(): int
    {
        $elementDescriptors = $this->plugin->service->getIndexableDigitalProductModels();

        return $this->reindexElements($elementDescriptors, 'digitalProducts');
    }


    /**
     * Remove index & create an empty one for all sites
     *
     * @throws IndexElementException If an error occurs while recreating the indices on the Elasticsearch instance
     */
    public function actionRecreateEmptyIndexes(): void
    {
        ElasticsearchPlugin::getInstance()->indexManagementService->recreateIndexesForAllSites();
    }

    /**
     * @param IndexableElementModel[] $indexableElementModels
     * @param string                  $type
     * @return int A shell exit code
     * @throws IndexElementException If an error occurs while reindexing the entries
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    /**
     * Reindex every site's data into its inactive blue/green target, then
     * atomically swap the alias per site. Fires EVENT_BEFORE_REINDEX before
     * each site's pass, so external integrations can reserve chunks and
     * write supplemental data into the same target.
     *
     * If a listener reserves additional chunks, the swap for that site is
     * deferred until those reservations report in via the queue — this
     * command will exit without having swapped, and a message is printed
     * telling the operator to run `craft queue/run` to finish.
     *
     * @param IndexableElementModel[] $indexableElementModels
     * @return int A shell exit code
     */
    protected function reindexElementsBlueGreen(array $indexableElementModels): int
    {
        $this->stdout(PHP_EOL);
        $this->stdout("Craft Elasticsearch plugin | Reindex everything (blue/green)", Console::FG_GREEN);
        $this->stdout(PHP_EOL);

        // Group models by site so each site gets its own rebuild target.
        $bySite = [];
        foreach ($indexableElementModels as $model) {
            $bySite[$model->siteId][] = $model;
        }

        $totalErrors = 0;
        $pendingSwaps = [];

        foreach ($bySite as $siteId => $siteModels) {
            $this->stdout(PHP_EOL . "Site #{$siteId}: " . count($siteModels) . " elements" . PHP_EOL);

            $indexManagement = $this->plugin->indexManagementService;
            $indexManagement->ensureAlias($siteId);
            $targetSuffix = $indexManagement->getInactiveSuffix($siteId);
            $targetIndex = $indexManagement->createPhysicalIndex($siteId, $targetSuffix);

            // Reserve 1 chunk for this command's synchronous loop. Listeners
            // may reserve more inside EVENT_BEFORE_REINDEX.
            $state = $indexManagement->beginReindex($siteId, $targetIndex, $targetSuffix, 1);
            $runId = $state['runId'];

            $errorCount = 0;
            $processed = 0;
            $total = count($siteModels);
            Console::startProgress(0, $total);

            foreach ($siteModels as $model) {
                $errorMessage = $this->reindexElementToTarget($model, $targetIndex);
                Console::updateProgress(++$processed, $total);
                if ($errorMessage !== null) {
                    $errorCount++;
                    $this->stderr($errorMessage);
                }
            }

            Console::endProgress();
            $totalErrors += $errorCount;

            // Report this command's chunk. If no listener reserved extra
            // chunks the service will swap the alias immediately; otherwise
            // it'll swap when the queued listener jobs finish reporting.
            $swapped = $indexManagement->reportChunkComplete($siteId, $runId);
            if (!$swapped) {
                $pendingSwaps[] = $siteId;
            }
        }

        $exitCode = $totalErrors === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;

        $this->stdout(PHP_EOL);
        $message = $this->ansiFormat($totalErrors === 0 ? 'Done' : 'Done with errors', $totalErrors === 0 ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout($message . PHP_EOL);

        if (!empty($pendingSwaps)) {
            $sites = implode(', ', array_map(static fn($id) => '#' . $id, $pendingSwaps));
            $this->stdout(
                PHP_EOL .
                "Alias swap deferred for site(s) {$sites}: external listeners reserved" . PHP_EOL .
                "additional chunks. Run `craft queue/run` to process them — the alias" . PHP_EOL .
                "will swap automatically once their jobs report completion." . PHP_EOL,
                Console::FG_YELLOW
            );
        }

        return $exitCode;
    }

    /**
     * Reindex a single element into a pinned physical index (used by the
     * blue/green console path).
     */
    protected function reindexElementToTarget(IndexableElementModel $model, string $targetIndex): ?string
    {
        try {
            $element = $model->getElement();
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return ElasticsearchPlugin::getInstance()->elementIndexerService->indexElement($element, $targetIndex);
    }

    protected function reindexElements(array $indexableElementModels, string $type): int
    {
        $this->stdout(PHP_EOL);
        $this->stdout("Craft Elasticsearch plugin | Reindex $type", Console::FG_GREEN);
        $this->stdout(PHP_EOL);

        // Reindex elements
        $elementCount = count($indexableElementModels);
        $processedElementCount = 0;
        $errorCount = 0;
        Console::startProgress(0, $elementCount);

        foreach ($indexableElementModels as $indexableElementModel) {
            $errorMessage = $this->reindexElement($indexableElementModel);

            if ($errorMessage === null) {
                Console::updateProgress(++$processedElementCount, $elementCount);
            } else {
                $errorCount++;
                Console::updateProgress(++$processedElementCount, $elementCount);
                $this->stderr($errorMessage);
            }
        }

        Console::endProgress();
        $exitCode = $errorCount === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;

        // Print summary message
        $this->stdout(PHP_EOL);
        $message = $this->ansiFormat('Done', Console::FG_GREEN);
        if ($exitCode > 0) {
            $message = $this->ansiFormat('Done with errors', Console::FG_RED);
        }
        $this->stdout($message);
        $this->stdout(PHP_EOL);

        (new ElasticsearchRecord)->trigger(ElasticsearchRecord::EVENT_AFTER_INDEX);

        return $exitCode;
    }

    /**
     * @param IndexableElementModel $indexableElementModel
     * @return string|null `null` if the element was successfully reindexed, an error message explaining why it wasn't otherwise
     * @throws IndexElementException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     * @throws \yii\elasticsearch\Exception
     */
    protected function reindexElement(IndexableElementModel $indexableElementModel): ?string
    {
        try {
            $element = $indexableElementModel->getElement();
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return ElasticsearchPlugin::getInstance()->elementIndexerService->indexElement($element);
    }
}
