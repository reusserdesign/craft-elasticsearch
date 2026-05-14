<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

namespace lhs\elasticsearch\services;


use Craft;
use craft\base\Component;
use craft\records\Site;
use lhs\elasticsearch\Elasticsearch as ElasticsearchPlugin;
use lhs\elasticsearch\events\ErrorEvent;
use lhs\elasticsearch\exceptions\IndexElementException;
use lhs\elasticsearch\records\ElasticsearchRecord;
use yii\helpers\Json;

/**
 */
class IndexManagementService extends Component
{
    const SUFFIX_A = 'a';
    const SUFFIX_B = 'b';

    /** @var ElasticsearchPlugin */
    public $plugin;

    public function init(): void
    {
        parent::init();

        $this->plugin = ElasticsearchPlugin::getInstance();
    }

    /**
     * Create an Elasticsearch index for the given site. Ensures the alias
     * structure (alias → physical `__a` index) exists. Safe to call repeatedly.
     */
    public function createSiteIndex(int $siteId): void
    {
        Craft::info("Creating an Elasticsearch index for the site #{$siteId}", __METHOD__);

        $this->ensureAlias($siteId);
    }

    /**
     * Remove both physical indices and the alias for the given site.
     */
    public function removeSiteIndex(int $siteId): void
    {
        Craft::info("Removing the Elasticsearch index for the site #{$siteId}", __METHOD__);
        ElasticsearchRecord::$siteId = $siteId;

        $db = ElasticsearchRecord::getDb();
        $command = $db->createCommand();
        $alias = ElasticsearchRecord::aliasName();

        // Drop the alias if present (deleting an index that backs an alias
        // also removes that alias binding, but we want to handle the legacy
        // case where the alias name is itself a concrete index).
        foreach ([self::SUFFIX_A, self::SUFFIX_B] as $suffix) {
            $physical = ElasticsearchRecord::physicalIndexName($suffix);
            if ($command->indexExists($physical)) {
                $command->deleteIndex($physical);
            }
        }

        if ($command->indexExists($alias)) {
            // Legacy concrete index sharing the alias name.
            $command->deleteIndex($alias);
        }
    }

    /**
     * Wipe the site's indices and recreate an empty alias structure. The
     * subsequent reindex job will populate the inactive index and swap.
     */
    public function recreateSiteIndex(int ...$siteIds): void
    {
        foreach ($siteIds as $siteId) {
            try {
                $this->removeSiteIndex($siteId);
                $this->createSiteIndex($siteId);
                $this->clearReindexState($siteId);
            } catch (\yii\elasticsearch\Exception $e) {
                $this->triggerErrorEvent($e);
            }
        }
    }

    /**
     * Create an empty Elasticsearch index for all sites. Existing indexes will be deleted and recreated.
     * @throws IndexElementException If the Elasticsearch index of a site cannot be recreated
     */
    public function recreateIndexesForAllSites(): void
    {
        $siteIds = Site::find()->select('id')->column();

        if (!empty($siteIds)) {
            try {
                $this->recreateSiteIndex(...$siteIds);
            } catch (\Exception $e) {
                throw new IndexElementException(
                    Craft::t(
                        ElasticsearchPlugin::PLUGIN_HANDLE,
                        'Cannot recreate empty indexes for all sites'
                    ), 0, $e
                );
            }
        }

        Craft::$app->getCache()->delete(ElasticsearchService::getSyncCachekey()); // Invalidate cache
    }

    /**
     * Returns the suffix ('a' or 'b') of the physical index the alias currently
     * targets, or `null` if no alias exists yet for this site.
     */
    public function getActiveSuffix(int $siteId): ?string
    {
        ElasticsearchRecord::$siteId = $siteId;
        $alias = ElasticsearchRecord::aliasName();
        $db = ElasticsearchRecord::getDb();

        try {
            $response = $db->get(['_alias', $alias]);
        } catch (\yii\elasticsearch\Exception $e) {
            // 404 — alias doesn't exist
            return null;
        }

        if (!is_array($response)) {
            return null;
        }

        foreach ($response as $indexName => $_) {
            if (str_ends_with($indexName, '__' . self::SUFFIX_A)) {
                return self::SUFFIX_A;
            }
            if (str_ends_with($indexName, '__' . self::SUFFIX_B)) {
                return self::SUFFIX_B;
            }
        }

        return null;
    }

    /**
     * Returns the suffix of the physical index that is NOT currently aliased —
     * the safe target for a blue/green rebuild. Defaults to 'b' when no alias
     * exists yet (so a fresh install builds 'b' first, leaving 'a' for the
     * next cycle).
     */
    public function getInactiveSuffix(int $siteId): string
    {
        $active = $this->getActiveSuffix($siteId);
        if ($active === self::SUFFIX_A) {
            return self::SUFFIX_B;
        }
        if ($active === self::SUFFIX_B) {
            return self::SUFFIX_A;
        }

        return self::SUFFIX_B;
    }

    /**
     * Make sure an alias exists for this site. Handles three cases:
     *   1. Alias already in place — no-op.
     *   2. Fresh install (nothing exists) — create `__a` and point alias at it.
     *   3. Legacy install (concrete index with the alias name) — leave it
     *      alone; the next reindex will build `__b`, swap, and the legacy
     *      concrete index is dropped during the swap.
     */
    public function ensureAlias(int $siteId): void
    {
        ElasticsearchRecord::$siteId = $siteId;
        $alias = ElasticsearchRecord::aliasName();
        $db = ElasticsearchRecord::getDb();
        $command = $db->createCommand();

        // Case 1: alias already exists.
        if ($this->getActiveSuffix($siteId) !== null) {
            return;
        }

        // Case 3: legacy concrete index — defer; let the next swap handle it.
        if ($command->indexExists($alias)) {
            return;
        }

        // Case 2: build __a and alias it.
        $this->createPhysicalIndex($siteId, self::SUFFIX_A);
        $this->pointAliasTo($siteId, self::SUFFIX_A);
    }

    /**
     * Creates (or recreates) a specific physical index for this site, without
     * touching the alias. Used by reindex jobs to build the inactive target.
     *
     * Returns the full physical index name.
     */
    public function createPhysicalIndex(int $siteId, string $suffix): string
    {
        ElasticsearchRecord::$siteId = $siteId;
        $physical = ElasticsearchRecord::physicalIndexName($suffix);

        $db = ElasticsearchRecord::getDb();
        $command = $db->createCommand();
        if ($command->indexExists($physical)) {
            $command->deleteIndex($physical);
        }

        $previousOverride = ElasticsearchRecord::$overrideIndexName;
        ElasticsearchRecord::$overrideIndexName = $physical;
        try {
            $esRecord = new ElasticsearchRecord();
            $esRecord->createESIndex();
        } finally {
            ElasticsearchRecord::$overrideIndexName = $previousOverride;
        }

        return $physical;
    }

    /**
     * Atomically point the site's alias at the given suffix's physical index,
     * removing any prior bindings. Also drops the legacy concrete index (when
     * the alias name was previously a real index) and the now-stale inactive
     * physical index.
     */
    public function swapAlias(int $siteId, string $newSuffix): void
    {
        ElasticsearchRecord::$siteId = $siteId;
        $alias = ElasticsearchRecord::aliasName();
        $newPhysical = ElasticsearchRecord::physicalIndexName($newSuffix);
        $oldSuffix = $newSuffix === self::SUFFIX_A ? self::SUFFIX_B : self::SUFFIX_A;
        $oldPhysical = ElasticsearchRecord::physicalIndexName($oldSuffix);

        $db = ElasticsearchRecord::getDb();
        $command = $db->createCommand();

        // Legacy: if a concrete index is sitting on the alias name, it has to
        // go before we can create the alias.
        if ($command->indexExists($alias) && $this->getActiveSuffix($siteId) === null) {
            $command->deleteIndex($alias);
        }

        $actions = [];
        if ($this->getActiveSuffix($siteId) !== null) {
            $actions[] = ['remove' => ['index' => '*', 'alias' => $alias]];
        }
        $actions[] = ['add' => ['index' => $newPhysical, 'alias' => $alias]];

        $db->post('_aliases', [], Json::encode(['actions' => $actions]));

        // Drop the stale physical index (if it exists).
        if ($command->indexExists($oldPhysical)) {
            $command->deleteIndex($oldPhysical);
        }
    }

    /**
     * Clear any in-progress reindex state for the given site.
     */
    public function clearReindexState(int $siteId): void
    {
        Craft::$app->getCache()->delete(self::reindexCacheKey($siteId));
    }

    /**
     * Cache key holding the in-progress reindex state for a site.
     */
    public static function reindexCacheKey(int $siteId): string
    {
        return 'lhs.elasticsearch.reindex.' . $siteId;
    }

    protected function triggerErrorEvent(\yii\elasticsearch\Exception $e): void
    {
        if (
            isset($e->errorInfo['responseBody']['error']['reason'])
            && $e->errorInfo['responseBody']['error']['reason'] === 'No processor type exists with name [attachment]'
        ) {
            /** @noinspection NullPointerExceptionInspection */
            ElasticsearchPlugin::getInstance()->trigger(
                ElasticsearchPlugin::EVENT_ERROR_NO_ATTACHMENT_PROCESSOR,
                new ErrorEvent($e)
            );
        }
    }
}
