<?php
/**
 * @link http://www.lahautesociete.com
 * @copyright Copyright (c) 2026 La Haute Société
 */

namespace lhs\elasticsearch\events;

use yii\base\Event;

/**
 * Fired around blue/green reindex lifecycle transitions.
 *
 * - `EVENT_BEFORE_REINDEX`: emitted after the inactive physical index has been
 *   provisioned and before chunk jobs are queued. Listeners may write
 *   supplemental data into `$targetIndex` and may call
 *   IndexManagementService::reserveChunks() to hold up the alias swap until
 *   their own jobs report completion.
 * - `EVENT_AFTER_INDEX`: emitted after the alias has been swapped to point at
 *   the newly built index.
 */
class ReindexEvent extends Event
{
    /** @var int */
    public $siteId;

    /** @var string|null Identifier shared by all jobs participating in this rebuild */
    public $runId;

    /** @var string Stable alias name for this site */
    public $aliasName;

    /** @var string|null Full physical index name for the rebuild target ('a' or 'b' suffix) */
    public $targetIndex;

    /** @var string|null Blue/green suffix of the target index ('a' or 'b') */
    public $targetSuffix;
}
