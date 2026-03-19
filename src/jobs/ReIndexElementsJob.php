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
use lhs\elasticsearch\records\ElasticsearchRecord;
use lhs\elasticsearch\models\IndexableElementModel;

/**
 * Reindex elements in Elasticsearch, chunked into batches
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
    public $chunkSize = 100;

    /** @var int Starting offset for this chunk (0 = dispatch chunks) */
    public $offset = 0;

    /** @var int Total element count (set when dispatching chunks) */
    public $totalElements = 0;

    /** @var bool Whether this is the dispatcher job (creates chunk jobs) */
    public $isDispatcher = true;

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

        if ($totalElements === 0) {
            $this->setProgress($queue, 1, 'No elements to index');
            return;
        }

        $totalChunks = ceil($totalElements / $this->chunkSize);

        $this->setProgress($queue, 0, "Queuing {$totalChunks} chunk jobs for {$totalElements} elements...");

        for ($i = 0; $i < $totalChunks; $i++) {
            $offset = $i * $this->chunkSize;

            Craft::$app->getQueue()->push(new self([
                'siteId' => $this->siteId,
                'elementId' => $this->elementId,
                'type' => $this->type,
                'chunkSize' => $this->chunkSize,
                'offset' => $offset,
                'totalElements' => $totalElements,
                'isDispatcher' => false,
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
            return;
        }

        $chunkNumber = ($this->offset / $this->chunkSize) + 1;
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

        // Fire the after-index event on the last chunk
        $isLastChunk = ($this->offset + $this->chunkSize) >= $this->totalElements;
        if ($isLastChunk) {
            (new ElasticsearchRecord)->trigger(ElasticsearchRecord::EVENT_AFTER_INDEX);
        }
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
            $chunkNumber = ($this->offset / $this->chunkSize) + 1;
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

        return Elasticsearch::getInstance()->elementIndexerService->indexElement($element);
    }

    public function getTtr()
    {
        if (!$this->isDispatcher) {
            return 30 * 60; // 30 min per chunk
        }

        return 60 * 60;
    }
}
