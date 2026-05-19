<?php

/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 *
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\jobs;

use Craft;
use craft\queue\BaseJob;
use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\events\ReindexEvent;
use lhs\elasticsearch\records\ElasticsearchRecord;
use lhs\elasticsearch\models\IndexableElementModel;
use lhs\elasticsearch\services\IndexManagementService;

/**
 * Reindex elements in Elasticsearch, chunked into batches. Uses a blue/green
 * strategy: the dispatcher provisions the inactive physical index, every chunk
 * writes to that pinned target, and the final chunk swaps the alias and
 * clears the in-progress marker.
 */
class ReIndexElementsJob extends BaseJob
{
    /** @var int Id of the site */
    public $siteId;

    /*** @var int Id of the element to index */
    public $elementId;

    /*** @var string Type of Element to index */
    public $type;

    /** @var int Number of elements per chunk */
    public $chunkSize = 250;

    /** @var int Starting offset for this chunk (0 = dispatch chunks) */
    public $offset = 0;

    /** @var int Total element count (set when dispatching chunks) */
    public $totalElements = 0;

    /** @var bool Whether this is the dispatcher job (creates chunk jobs) */
    public $isDispatcher = true;

    /** @var string|null Run identifier shared across all chunks of this reindex */
    public $runId;

    /** @var string|null Full name of the physical index this chunk should write to */
    public $targetIndex;

    /** @var string|null Blue/green suffix ('a' or 'b') of the target index */
    public $targetSuffix;

    /**
     * {@inheritdoc}
     */
    public function execute($queue): void
    {
        /** @var Elasticsearch $plugin */
        $plugin = Elasticsearch::getInstance();

        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($this->siteId);
        $sites->setCurrentSite($site);

        if ($this->isDispatcher) {
            $this->dispatchChunks($queue, $plugin);
        } else {
            $this->processChunk($queue, $plugin);
        }
    }

    /**
     * Dispatch individual chunk jobs
     */
    protected function dispatchChunks($queue, Elasticsearch $plugin): void
    {
        $indexableElementModels = $plugin->service->getIndexableElementModels();
        $totalElements = count($indexableElementModels);

        // Prepare the blue/green target.
        $indexManagement = $plugin->indexManagementService;
        $indexManagement->ensureAlias($this->siteId);
        $targetSuffix = $indexManagement->getInactiveSuffix($this->siteId);
        $targetIndex = $indexManagement->createPhysicalIndex($this->siteId, $targetSuffix);

        if ($totalElements === 0) {
            // Nothing to index — open and immediately close a zero-chunk run.
            // beginReindex fires EVENT_BEFORE_REINDEX so external integrations
            // still get a chance to enqueue supplemental work and reserve
            // their own chunks before the swap.
            $state = $indexManagement->beginReindex($this->siteId, $targetIndex, $targetSuffix, 0);
            if (($state['expectedChunks'] ?? 0) === 0) {
                $indexManagement->swapAlias($this->siteId, $targetSuffix);
                $indexManagement->clearReindexState($this->siteId);
                $event = new ReindexEvent([
                    'siteId'       => $this->siteId,
                    'runId'        => $state['runId'],
                    'aliasName'    => ElasticsearchRecord::aliasName(),
                    'targetIndex'  => $targetIndex,
                    'targetSuffix' => $targetSuffix,
                ]);
                $indexManagement->trigger(IndexManagementService::EVENT_AFTER_REINDEX, $event);
                (new ElasticsearchRecord)->trigger(ElasticsearchRecord::EVENT_AFTER_INDEX, $event);
            }
            $this->setProgress($queue, 1, 'No elements to index');
            return;
        }

        $totalChunks = (int)ceil($totalElements / $this->chunkSize);

        // Publish the in-progress marker (and fire EVENT_BEFORE_REINDEX) so
        // external listeners can hook in and live IndexElementJobs dual-write
        // into the rebuild target while chunks are still running.
        $state = $indexManagement->beginReindex($this->siteId, $targetIndex, $targetSuffix, $totalChunks);
        $runId = $state['runId'];

        $this->setProgress($queue, 0, "Queuing {$totalChunks} chunk jobs for {$totalElements} elements...");

        for ($i = 0; $i < $totalChunks; $i++) {
            $offset = $i * $this->chunkSize;

            Craft::$app->getQueue()->push(new self([
                'siteId'        => $this->siteId,
                'elementId'     => $this->elementId,
                'type'          => $this->type,
                'chunkSize'     => $this->chunkSize,
                'offset'        => $offset,
                'totalElements' => $totalElements,
                'isDispatcher'  => false,
                'runId'         => $runId,
                'targetIndex'   => $targetIndex,
                'targetSuffix'  => $targetSuffix,
            ]));

            $this->setProgress(
                $queue,
                ($i + 1) / $totalChunks,
                "Queued chunk " . ($i + 1) . " of {$totalChunks}"
            );
        }
    }

    /**
     * Process a single chunk of elements
     */
    protected function processChunk($queue, Elasticsearch $plugin): void
    {
        $indexableElementModels = $plugin->service->getIndexableElementModels();
        $chunk = array_slice($indexableElementModels, $this->offset, $this->chunkSize);
        $chunkCount = count($chunk);

        if ($chunkCount === 0) {
            $this->setProgress($queue, 1, 'Empty chunk, skipping');
            $this->markChunkComplete($plugin);
            return;
        }

        $chunkNumber = ((int)($this->offset / $this->chunkSize)) + 1;
        $errorCount = 0;

        foreach ($chunk as $i => $indexableElementModel) {
            $errorMessage = $this->reindexElement($indexableElementModel);

            $this->setProgress(
                $queue,
                ($i + 1) / $chunkCount,
                "Chunk {$chunkNumber}: " . ($i + 1) . " of {$chunkCount}"
            );

            if ($errorMessage !== null) {
                $errorCount++;
                $this->stderr($errorMessage);
            }
        }

        $this->markChunkComplete($plugin);
    }

    /**
     * Notify the index management service that this chunk is finished. The
     * service handles atomic increment, alias swap, and event firing.
     */
    protected function markChunkComplete(Elasticsearch $plugin): void
    {
        if (empty($this->runId)) {
            // Pre-blue/green job in flight (shouldn't happen post-deploy) — be safe.
            (new ElasticsearchRecord)->trigger(ElasticsearchRecord::EVENT_AFTER_INDEX);
            return;
        }

        $plugin->indexManagementService->reportChunkComplete($this->siteId, $this->runId);
    }

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn't set.
     *
     * @return string The default task description
     */
    protected function defaultDescription(): string
    {
        $type = ($pos = strrpos($this->type, '\\')) ? substr($this->type, $pos + 1) : $this->type;

        if (!$this->isDispatcher) {
            $chunkNumber = ((int)($this->offset / $this->chunkSize)) + 1;
            return Craft::t(
                Elasticsearch::PLUGIN_HANDLE,
                sprintf(
                    'Index %s (site #%d) chunk %d in Elasticsearch',
                    $type,
                    $this->siteId,
                    $chunkNumber
                )
            );
        }

        return Craft::t(
            Elasticsearch::PLUGIN_HANDLE,
            sprintf(
                'Index %s (site #%d) in Elasticsearch',
                $type,
                $this->siteId
            )
        );
    }

    /**
     * @return string|null `null` if the element was successfully reindexed, an error message explaining why it wasn't otherwise
     *
     * @throws \lhs\elasticsearch\exceptions\IndexElementException
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

        return Elasticsearch::getInstance()->elementIndexerService->indexElement($element, $this->targetIndex);
    }

    public function getTtr()
    {
        if (!$this->isDispatcher) {
            return 30 * 60; // 30 min per chunk
        }

        return 60 * 60;
    }
}
