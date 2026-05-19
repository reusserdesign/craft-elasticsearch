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
use lhs\elasticsearch\events\ReindexEvent;
use lhs\elasticsearch\exceptions\IndexElementException;
use lhs\elasticsearch\records\ElasticsearchRecord;
use yii\helpers\Json;

/**
 */
class IndexManagementService extends Component
{
    const SUFFIX_A = 'a';
    const SUFFIX_B = 'b';

    /**
     * Fired after the inactive physical index has been provisioned for a
     * blue/green rebuild and before chunk jobs are queued. Listeners may
     * enqueue their own jobs that write into the target index, and should
     * call {@see reserveChunks()} so the alias swap waits for them.
     */
    const EVENT_BEFORE_REINDEX = 'beforeReindex';

    /**
     * Fired after the alias has been swapped to the newly built index. The
     * event payload is a {@see ReindexEvent} (with `targetIndex` describing
     * the index that is now live).
     */
    const EVENT_AFTER_REINDEX = 'afterReindex';

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
        $activeSuffix = $this->getActiveSuffix($siteId);

        // Build a single atomic actions list: drop the legacy concrete index
        // (if any) AND repoint the alias in one request, so searches never
        // see a moment where the alias name resolves to nothing.
        $actions = [];

        if ($activeSuffix === null && $command->indexExists($alias)) {
            // Legacy install: a concrete index is sitting on the alias name.
            // `remove_index` deletes it as part of the same atomic call.
            $actions[] = ['remove_index' => ['index' => $alias]];
        } elseif ($activeSuffix !== null) {
            $actions[] = ['remove' => ['index' => '*', 'alias' => $alias]];
        }

        $actions[] = ['add' => ['index' => $newPhysical, 'alias' => $alias]];

        $db->post('_aliases', [], Json::encode(['actions' => $actions]));

        // Drop the stale physical index outside the atomic block; the alias is
        // already pointing at the new one so this can't affect search.
        if ($command->indexExists($oldPhysical)) {
            $command->deleteIndex($oldPhysical);
        }
    }

    /**
     * Returns the in-progress reindex state for a site, or `null` if none.
     * Shape: ['runId', 'targetIndex', 'targetSuffix', 'expectedChunks',
     *         'completedChunks', 'startedAt'].
     */
    public function getReindexState(int $siteId): ?array
    {
        $state = Craft::$app->getCache()->get(self::reindexCacheKey($siteId));
        return is_array($state) ? $state : null;
    }

    /**
     * Begin tracking a new blue/green run. Initializes the cache marker so
     * live writes can dual-write and chunk jobs can report completion.
     *
     * Fires {@see EVENT_BEFORE_REINDEX} so listeners can enqueue supplemental
     * jobs and reserve additional chunks.
     *
     * @return array The state record (so the dispatcher can plumb runId/target).
     */
    public function beginReindex(int $siteId, string $targetIndex, string $targetSuffix, int $expectedChunks): array
    {
        $runId = uniqid('esreindex_', true);
        $state = [
            'runId'           => $runId,
            'targetIndex'     => $targetIndex,
            'targetSuffix'    => $targetSuffix,
            'expectedChunks'  => $expectedChunks,
            'completedChunks' => 0,
            'startedAt'       => time(),
        ];

        Craft::$app->getCache()->set(self::reindexCacheKey($siteId), $state, 24 * 60 * 60);

        ElasticsearchRecord::$siteId = $siteId;
        $this->trigger(self::EVENT_BEFORE_REINDEX, new ReindexEvent([
            'siteId'       => $siteId,
            'runId'        => $runId,
            'aliasName'    => ElasticsearchRecord::aliasName(),
            'targetIndex'  => $targetIndex,
            'targetSuffix' => $targetSuffix,
        ]));

        return $state;
    }

    /**
     * Reserve additional chunks for an in-progress run. Intended for external
     * integrations that enqueue their own jobs from an `EVENT_BEFORE_REINDEX`
     * listener: by reserving, they ensure the alias swap waits until they
     * call {@see reportChunkComplete()}.
     *
     * @return bool `false` if the run could not be found (already swapped or expired).
     */
    public function reserveChunks(int $siteId, string $runId, int $additionalChunks): bool
    {
        if ($additionalChunks <= 0) {
            return true;
        }

        return $this->mutateRunState($siteId, $runId, function (array $state) use ($additionalChunks): array {
            $state['expectedChunks'] = ($state['expectedChunks'] ?? 0) + $additionalChunks;
            return $state;
        }) !== null;
    }

    /**
     * Report that a single chunk has finished. When `completedChunks` catches
     * up to `expectedChunks`, the alias is swapped, the state is cleared, and
     * {@see EVENT_AFTER_REINDEX} fires.
     *
     * @return bool `true` if this call performed the swap, `false` otherwise.
     */
    public function reportChunkComplete(int $siteId, string $runId): bool
    {
        $swapped = false;

        $this->mutateRunState($siteId, $runId, function (array $state) use ($siteId, &$swapped): ?array {
            $state['completedChunks'] = ($state['completedChunks'] ?? 0) + 1;

            if ($state['completedChunks'] < $state['expectedChunks']) {
                return $state;
            }

            // Final chunk: swap the alias, clear state, fire event.
            $this->swapAlias($siteId, $state['targetSuffix']);
            Craft::$app->getCache()->delete(self::reindexCacheKey($siteId));

            ElasticsearchRecord::$siteId = $siteId;
            $event = new ReindexEvent([
                'siteId'       => $siteId,
                'runId'        => $state['runId'],
                'aliasName'    => ElasticsearchRecord::aliasName(),
                'targetIndex'  => $state['targetIndex'],
                'targetSuffix' => $state['targetSuffix'],
            ]);
            $this->trigger(self::EVENT_AFTER_REINDEX, $event);
            // Backwards-compat: the legacy event lived on ElasticsearchRecord.
            (new ElasticsearchRecord)->trigger(ElasticsearchRecord::EVENT_AFTER_INDEX, $event);

            $swapped = true;
            return null; // signal: cache already cleared
        });

        return $swapped;
    }

    /**
     * Atomically read-modify-write the run state under a mutex. The mutator
     * receives the current state and returns either:
     *   - a new state array (will be stored), or
     *   - `null` (no further action — caller has handled persistence/clearing).
     *
     * Returns the resulting state, or `null` if the run wasn't found / runId
     * didn't match / the mutator opted out.
     */
    protected function mutateRunState(int $siteId, string $runId, callable $mutator): ?array
    {
        $cache = Craft::$app->getCache();
        $cacheKey = self::reindexCacheKey($siteId);
        $mutexKey = $cacheKey . '.lock';
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($mutexKey, 10)) {
            Craft::error("Could not acquire reindex mutex for site #{$siteId}", __METHOD__);
            return null;
        }

        try {
            $state = $cache->get($cacheKey);
            if (!is_array($state) || ($state['runId'] ?? null) !== $runId) {
                return null;
            }

            $next = $mutator($state);
            if ($next === null) {
                return null;
            }

            $cache->set($cacheKey, $next, 24 * 60 * 60);
            return $next;
        } finally {
            $mutex->release($mutexKey);
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
