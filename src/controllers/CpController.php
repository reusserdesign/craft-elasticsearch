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

namespace lhs\elasticsearch\controllers;

use Craft;
use yii\web\Response;
use craft\web\Request;
use craft\records\Site;
use craft\web\Controller;
use yii\helpers\VarDumper;
use craft\helpers\UrlHelper;
use lhs\elasticsearch\Elasticsearch;
use lhs\elasticsearch\models\IndexableElementModel;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;

/**
 * Control Panel controller
 */
class CpController extends Controller
{
    /** @var ElasticsearchPlugin */
    public $plugin;

    protected array|bool|int $allowAnonymous = ['testConnection', 'reindexPerformAction'];

    public function init(): void
    {
        parent::init();

        $this->plugin = ElasticsearchPlugin::getInstance();
    }

    /**
     * Test the elasticsearch connection
     *
     * @throws \yii\web\ForbiddenHttpException If the user doesn't have the utility:refresh-elasticsearch-index permission
     */
    public function actionTestConnection(): Response
    {
        $this->requirePermission('utility:refresh-elasticsearch-index');

        $elasticsearchPlugin = Elasticsearch::getInstance();
        assert($elasticsearchPlugin !== null, "Elasticsearch::getInstance() should always return the plugin instance when called from the plugin's code.");

        $settings = $elasticsearchPlugin->getSettings();

        if ($elasticsearchPlugin->service->testConnection() === true) {
            Craft::$app->session->setNotice(
                Craft::t(
                    Elasticsearch::PLUGIN_HANDLE,
                    'Successfully connected to {elasticsearchEndpoint}',
                    ['elasticsearchEndpoint' => $settings->elasticsearchEndpoint]
                )
            );
        } else {
            Craft::$app->session->setError(
                Craft::t(
                    Elasticsearch::PLUGIN_HANDLE,
                    'Could not establish connection with {elasticsearchEndpoint}',
                    ['elasticsearchEndpoint' => $settings->elasticsearchEndpoint]
                )
            );
        }

        return $this->redirect(UrlHelper::cpUrl('utilities/elasticsearch-utilities'));
    }

    /**
     * Reindex Craft entries into Elasticsearch (called from utility panel)
     *
     * @throws \Exception If reindexing an entry fails
     * @throws \yii\web\BadRequestHttpException if the request body is missing a `params` property
     * @throws \yii\web\ForbiddenHttpException if the user doesn't have access to the Elasticsearch utility
     */
    public function actionReindexPerformAction(): Response
    {
        $this->requirePermission('utility:refresh-elasticsearch-index');

        $request = Craft::$app->getRequest();
        $params = $request->getRequiredBodyParam('params');
        if (!empty($params['queue'])) {
            $siteIds = $this->getSiteIds($request);
            foreach ($siteIds as $siteId) {
                $job = new \lhs\elasticsearch\jobs\ReIndexElementsJob;
                $job->siteId = $siteId;
                \craft\helpers\Queue::push(
                    job: $job,
                    priority: 10,
                    ttr: $job->getTtr(),
                );
            }

            // Spawn background queue runner to prevent blocking web request
            $this->spawnQueueRunner();

            return $this->asJson(
                [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'Queueing job (running in background)',
                ]
            );
        }

        // Return the ids of entries to process
        if (!empty($params['start'])) {
            try {
                $siteIds = $this->getSiteIds($request);
                Elasticsearch::getInstance()->indexManagementService->recreateSiteIndex(...$siteIds);
            } catch (\Exception $e) {
                return $this->asErrorJson($e->getMessage());
            }

            return $this->getReindexQueue($siteIds);
        }

        // Process the given element
        $reason = $this->reindexElement();

        if ($reason !== null) {
            return $this->asJson(
                [
                    'success' => true,
                    'skipped' => true,
                    'reason' => $reason,
                ]
            );
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * @param  int[]  $siteIds  The numeric ids of sites to be re-indexed
     */
    protected function getReindexQueue(array $siteIds): Response
    {
        /** @var Elasticsearch $plugin */
        $plugin = Elasticsearch::getInstance();

        $indexableElementModels = $plugin->service->getIndexableElementModels($siteIds);

        // Re-format $indexableElementModels to keep the JS part as close as possible to Craft SearchIndexUtility's
        array_walk(
            $indexableElementModels,
            static function (&$element) {
                $element = ['params' => $element->toArray()];
            }
        );

        return $this->asJson(
            [
                'entries' => [$indexableElementModels],
            ]
        );
    }

    /**
     * When using Garnish's CheckboxSelect component, the field value is "*" when the all checkbox is selected.
     * This value gets passed to the controller.
     *
     * This methods converts the "*" value into an array containing the id of all sites.
     *
     *
     * @return int[]
     */
    protected function getSiteIds(Request $request): array
    {
        $siteIds = $request->getParam('params.sites', '*');

        if ($siteIds === '*') {
            $siteIds = Site::find()->select('id')->column();
        }

        return $siteIds;
    }

    /**
     * @return string|null A string explaining why the entry wasn't reindexed or `null` if it was reindexed
     *
     * @throws \Exception If reindexing the entry fails for some reason.
     * @throws \yii\web\BadRequestHttpException if the request body is missing a `params` property
     */
    protected function reindexElement(): ?string
    {
        $request = Craft::$app->getRequest();

        $model = new IndexableElementModel;
        $model->elementId = $request->getRequiredBodyParam('params.elementId');
        $model->siteId = $request->getRequiredBodyParam('params.siteId');
        $model->type = $request->getRequiredBodyParam('params.type');
        $element = $model->getElement();

        try {
            return Elasticsearch::getInstance()->elementIndexerService->indexElement($element);
        } catch (\Exception $e) {
            Craft::error("Error while re-indexing element {$element->url}: {$e->getMessage()}", __METHOD__);
            Craft::error(VarDumper::dumpAsString($e), __METHOD__);

            throw $e;
        }
    }

    /**
     * Spawn a background process to run the queue
     *
     * This prevents blocking the web request when queue jobs are heavy.
     * Note: Uses exec() with only system-generated paths (no user input).
     */
    protected function spawnQueueRunner(): void
    {
        $phpBinary = PHP_BINARY;
        $craftPath = Craft::getAlias('@root/craft');

        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($craftPath) . ' queue/run --verbose';

        if (function_exists('exec')) {
            exec($cmd . ' > /dev/null 2>&1 &');
            Craft::info('Spawned background queue runner for Elasticsearch indexing', __METHOD__);
        } else {
            Craft::warning('exec() is disabled, queue will run on next web request', __METHOD__);
        }
    }
}
