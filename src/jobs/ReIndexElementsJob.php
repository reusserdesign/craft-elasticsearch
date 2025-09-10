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
 * Reindex a single entry
 */
class ReIndexElementsJob extends BaseJob
{
    /** @var int Id of the site */
    public $siteId;

    /*** @var int Id of the element to index */
    public $elementId;

    /*** @var string Type of Element to index */
    public $type;

    /**
     * {@inheritdoc}
     */
    public function execute($queue): void
    {
        /** @var Elasticsearch $plugin */
        $plugin = Elasticsearch::getInstance();

        $indexableElementModels = $plugin->service->getIndexableElementModels();

        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($this->siteId);
        $sites->setCurrentSite($site);

        $elementCount = count($indexableElementModels);
        $processedElementCount = 0;
        $errorCount = 0;
        // Console::startProgress(0, $elementCount);

        foreach ($indexableElementModels as $i => $indexableElementModel) {
            $errorMessage = $this->reindexElement($indexableElementModel);

            $this->setProgress(
                $queue,
                ($i + 1) / $elementCount,
                \Craft::t('app', '{step, number} of {total, number}', [
                    'step' => $i + 1,
                    'total' => $elementCount,
                ])
            );

            if ($errorMessage === null) {
                // Console::updateProgress(++$processedElementCount, $elementCount);
            } else {
                $errorCount++;
                // Console::updateProgress(++$processedElementCount, $elementCount);
                $this->stderr($errorMessage);
            }
        }

        (new ElasticsearchRecord)->trigger(ElasticsearchRecord::EVENT_AFTER_INDEX);
    }

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     *
     * @return string The default task description
     */
    protected function defaultDescription(): string
    {
        $type = ($pos = strrpos($this->type, '\\')) ? substr($this->type, $pos + 1) : $this->type;

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
        return 60 * 60;
    }
}
