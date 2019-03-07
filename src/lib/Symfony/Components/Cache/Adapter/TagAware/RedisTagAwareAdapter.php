<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Symfony\Component\Cache\Adapter\TagAware;

use Predis;
use Predis\Response\Status;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Traits\RedisTrait;

/**
 * Class RedisTagAwareAdapter, stores tag <> id relationship as a Set so we don't need to fetch tags on get* operations.
 *
 * Requirements/Limitations:
 *   - Redis 3.2+ (sPOP)
 *   - PHP Redis 3.1.3+ (sPOP) or Predis
 * - Redis configured with any `volatile-*` eviction policy, or `noeviction` if you are sure to never fill up memory
 *   - This is to guarantee that tags ("relations") survives cache items so we can reliably invalidate on them,
 *     which is archived by always storing cache with a expiry, while Set is without expiry (non-volatile).
 * - Max 2 billion keys per tag, so if you use a "all" items tag for expiry, that limits you to 2 billion items
 */
final class RedisTagAwareAdapter extends AbstractTagAwareAdapter implements TagAwareAdapterInterface
{
    use RedisTrait;

    /**
     * Redis "Set" can hold more than 4 billion members, here we limit ourselves to PHP's > 2 billion max int (32Bit).
     */
    private const POP_MAX_LIMIT = 2147483647 - 1;

    /**
     * Limits for how many keys are deleted in batch.
     */
    private const BULK_DELETE_LIMIT = 10000;

    /**
     * On cache items without a lifetime set, we force it to 10 days.
     * This is to make sure cache items are *never* cleared before tags are cleared (risking in-consistent cache).
     */
    private const FORCED_ITEM_TTL = 864000;

    /**
     * @param \Redis|\RedisArray|\RedisCluster|\Predis\Client $redisClient     The redis client
     * @param string                                          $namespace       The default namespace
     * @param int                                             $defaultLifetime The default lifetime
     *
     * @throws \Exception If phpredis is in use but with version lower then 3.1.3.
     */
    public function __construct($redisClient, string $namespace = '', int $defaultLifetime = 0)
    {
        $this->init($redisClient, $namespace, $defaultLifetime);

        // Make sure php-redis is 3.1.3 or higher configured for Redis classes
        if (!$this->redis instanceof Predis\Client && version_compare(phpversion('redis'), '3.1.3', '<')) {
            throw new \Exception('RedisTagAwareAdapter requries php-redis 3.1.3 or higher, alternatively use predis/predis');
        }
    }

    /**
     * This method overrides @see \Symfony\Component\Cache\Traits\RedisTrait::doSave.
     *
     * It needs to be overridden due to:
     * - usage of native `serialize` method in the original method.
     * - Need to store tags separately also, for invalidateTags() use.
     *
     * {@inheritdoc}
     */
    protected function doSave(array $values, $lifetime)
    {
        if (!$serialized = self::$marshaller->marshall($values, $failed)) {
            return $failed;
        }

        // Prepare tag data we want to store for use when needed by invalidateTags().
        $tagSets = [];
        foreach ($values as $id => $value) {
            foreach ($value['tags'] as $tag) {
                $tagSets[$this->getId(self::TAGS_PREFIX.$tag)][] = $id;
            }
        }

        // Redis method to add with array values differs among PHP Redis clients
        $addMethod = $this->redis instanceof Predis\Client ? 'sAdd' : 'sAddArray';

        // While pipeline isn't supported on RedisCluster, other setups will at least benefit from doing this in one op
        $results = $this->pipeline(static function () use ($serialized, $lifetime, $tagSets, $addMethod) {
            // Store cache items, force a ttl if none is set, as there is no MSETEX we need to set each one
            foreach ($serialized as $id => $value) {
                yield 'setEx' => [
                    $id,
                    0 >= $lifetime ? self::FORCED_ITEM_TTL : $lifetime,
                    $value,
                ];
            }

            // Append tag sets (tag id => [cacher ids...])
            foreach ($tagSets as $tagId => $ids) {
                yield $addMethod => [$tagId, $ids];
            }
        });

        foreach ($results as $id => $result) {
            // Skip results of "SADD" operation, they'll be 1 or 0 depending on if set value already existed or not
            if (is_numeric($result) && isset($tagSets[$id])) {
                continue;
            }
            // setEx results
            if (true !== $result && (!$result instanceof Status || $result !== Status::get('OK'))) {
                $failed[] = $id;
            }
        }

        return $failed;
    }

    /**
     * @param array $tags
     *
     * @return bool|void
     */
    public function invalidateTags(array $tags)
    {
        if (empty($tags)) {
            return;
        }

        // Pop all tag info at once to avoid race conditions
        $tagIdSets = $this->pipeline(function () use ($tags) {
            foreach (array_unique($tags) as $tag) {
                // Requires Predis or PHP Redis 3.1.3+: https://github.com/phpredis/phpredis/commit/d2e203a6
                // And Redis 3.2: https://redis.io/commands/spop
                yield 'sPop' => [$this->getId(self::TAGS_PREFIX.$tag), self::POP_MAX_LIMIT];
            }
        });

        // Flatten generator result from pipleline, ignore keys (tag ids)
        $ids = array_unique(array_merge(...iterator_to_array($tagIdSets, false)));

        // Delete chunks of id's
        foreach (\array_chunk($ids, self::BULK_DELETE_LIMIT) as $chunkIds) {
            $this->doDelete($chunkIds);
        }

        // Commit deferred changes after invalidation to emulate logic in TagAwareAdapter
        $this->commit();

        return true;
    }
}
