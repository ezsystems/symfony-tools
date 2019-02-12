<?php

/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

declare(strict_types=1);

namespace EzSystems\SymfonyTools\Incubator\Cache\TagAware;

use Predis;
use Predis\Response\Status;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Traits\RedisTrait;

/**
 * Class RedisTagAwareAdapter, stores tag <> id relationship as a Set so we don't need to fetch tags on get* operations.
 *
 * Requirements/Limitations:
 * - Redis configured with `noeviction` or any `volatile-*` eviction policy
 *   - This is to guarantee that tags ("relations") survives cache items so we can reliably invalidate on them.
 * - As we use Redis "SPOP" command with count argument for invalidation:
 *   - Redis 3.2 or higher
 *   - PHP Redis 3.1.3 or higher, or Predis
 *
 * Design
 * - Cache items are stored with:
 *   - Expiry in Redis is set to 10days when no lifetime is set, to make sure they get evicted before tags
 *   - Symfony Marshaller is used so we can use for instance Igbinary for smaller size & faster unserialization
 * - For tags instead of time based invalidation which needs to retrieve the timestamps all the time, use invalidation:
 *   - Use Redis Sets for Tags, appending related keys on the tags, with no expiry on the Set
 *   - Fetches and resets Set on invalidation by tag, in a pipeline operation.
 *   - Uses Redis "Set" datatype limited to 4 billion ids per tag
 */
final class RedisTagAwareAdapter extends AbstractTagAwareAdapter implements TagAwareAdapterInterface
{
    use RedisTrait;

    /**
     * Limit for how many items are popped from tags per iteration to not run out of memory pipelining deletes.
     */
    private const BULK_INVALIDATION_POP_LIMIT = 10000;

    /**
     * On cache items without a lifetime set, we force it to 10 days.
     * This is to make sure cache items are *never* cleared before tags are cleared (risking in-consistent cache).
     */
    private const FORCED_ITEM_TTL = 864000;

    /**
     * @param \Redis|\RedisArray|\RedisCluster|\Predis\Client $redisClient     The redis client
     * @param string                                          $namespace       The default namespace
     * @param int                                             $defaultLifetime The default lifetime
     */
    public function __construct($redisClient, string $namespace = '', int $defaultLifetime = 0)
    {
        $this->init($redisClient, $namespace, $defaultLifetime);

        // Make sure php-redis is 3.1.3 or higher configured for Redis classes
        if (!$this->redis instanceof Predis\Client && version_compare(phpversion('redis'), '3.1.3', '<')) {
            throw new \Exception('RedisTagAwareAdapter requries php-redis 3.1.3 or higher, alternativly use predis/predis');
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
        $failed = [];
        $serialized = $this->marshaller->marshall($values, $failed);
        if (empty($serialized)) {
            return $failed;
        }

        // Prepare tag data we want to store for use when needed by invalidateTags().
        $getId = $this->getId;
        $tagSets = [];
        foreach ($values as $id => $value) {
            foreach ($value['tags'] as $tag) {
                $tagSets[$getId(self::TAGS_PREFIX . $tag)][] = $id;
            }
        }

        // While pipeline isn't supported on RedisCluster, other setups will at least benefit from doing this in one op
        $results = $this->pipeline(function () use ($serialized, $lifetime, $tagSets) {
            // 1: Store cache items
            foreach ($serialized as $id => $value) {
                // Note: There is no MSETEX so we need to set each one
                yield 'setEx' => [
                    $id,
                    0 >= $lifetime ? self::FORCED_ITEM_TTL : $lifetime,
                    $value,
                ];
            }

            // 2: append tag sets, method to add with array values differs on PHP Redis clients
            $method = $this->redis instanceof Predis\Client ? 'sAdd' : 'sAddArray';
            foreach ($tagSets as $tagId => $ids) {
                yield $method => [$tagId, $ids];
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
     * This method overrides @see \Symfony\Component\Cache\Traits\RedisTrait::doFetch in order to use mget & marshaller.
     *
     * {@inheritdoc}
     */
    protected function doFetch(array $ids)
    {
        if (empty($ids)) {
            return [];
        }

        // Using MGET for speed on RedisCluster as pipeline is not supported there
        $values = $this->redis->mget($ids);
        foreach ($values as $key => $v) {
            // Not found items will have value as false, key will be same as on $ids
            if ($v) {
                yield $ids[$key] => $this->marshaller->unmarshall($v);
            }
        }
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

        // Retrive and delete items in bulk of 10.000 at a time to not overflow buffers
        // NOTE: Nicolas wants to look into rather finding a way to do invalidation with Lua on the Redis server
        //       Reason is that the design here can risk ending up in a endless loop if a items are rapidly added
        //       with the tag(s) we try to invalidate. On RedisCluster a Lua approach would need to run on all servers.
        $getId = $this->getId;
        do {
            $tagIdSets = $this->pipeline(function () use ($tags, $getId) {
                foreach (array_unique($tags) as $tag) {
                    // Requires Predis or PHP Redis 3.1.3+ (https://github.com/phpredis/phpredis/commit/d2e203a6)
                    yield 'sPop' => [$getId(self::TAGS_PREFIX . $tag), self::BULK_INVALIDATION_POP_LIMIT];
                }
            });

            // flatten generator result from pipleline, cache keys should already be prefixed as id's from doSave()
            $ids = [];
            foreach ($tagIdSets as $tagIds) {
                $ids = array_merge($tagIds, $ids);
            }

            $this->doDelete(array_unique($ids));
        } while (count($ids) >= self::BULK_INVALIDATION_POP_LIMIT);

        return true;
    }
}
