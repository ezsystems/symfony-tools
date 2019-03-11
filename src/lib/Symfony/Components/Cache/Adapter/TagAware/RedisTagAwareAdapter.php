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
use Predis\Connection\Aggregate\ClusterInterface;
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
        // Extract tag operations
        $tagOperations = ['sAdd' => [], 'sRem' => []];
        foreach ($values as $id => $value) {
            foreach ($value['tag-operations']['add'] as $tag => $tagId) {
                $tagOperations['sAdd'][$tagId][] = $id;
            }

            foreach ($value['tag-operations']['remove'] as $tag => $tagId) {
                $tagOperations['sRem'][$tagId][] = $id;
            }

            unset($value['tag-operations']);
        }

        // serilize values
        if (!$serialized = self::$marshaller->marshall($values, $failed)) {
            return $failed;
        }

        // While pipeline isn't supported on RedisCluster, other setups will at least benefit from doing this in one op
        $results = $this->pipeline(static function () use ($serialized, $lifetime, $tagOperations) {
            // Store cache items, force a ttl if none is set, as there is no MSETEX we need to set each one
            foreach ($serialized as $id => $value) {
                yield 'setEx' => [
                    $id,
                    0 >= $lifetime ? self::FORCED_ITEM_TTL : $lifetime,
                    $value,
                ];
            }

            // Add and Remove Tags
            foreach ($tagOperations as $command => $tagSet) {
                foreach ($tagSet as $tagId => $ids) {
                    yield $command => array_merge([$tagId], $ids);
                }
            }
        });

        foreach ($results as $id => $result) {
            // Skip results of SADD/SREM operations, they'll be 1 or 0 depending on if set value already existed or not
            if (is_numeric($result)) {
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
     * {@inheritdoc}
     */
    protected function doDelete(array $ids, array $tagData = [])
    {
        if (!$ids) {
            return true;
        }

        $predisCluster = $this->redis instanceof \Predis\Client && $this->redis->getConnection() instanceof ClusterInterface;
        $this->pipeline(static function () use ($ids, $tagData, $predisCluster) {
            if ($predisCluster) {
                foreach ($ids as $id) {
                    yield 'del' => [$id];
                }
            } else {
                yield 'del' => $ids;
            }

            foreach ($tagData as $tagId => $idMap) {
                yield 'sRem' => array_merge([$tagId], $idMap);
            }
        })->rewind();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function doInvalidate(array $tagIds): bool
    {
        // Pop all tag info at once to avoid race conditions
        $tagIdSets = $this->pipeline(static function () use ($tagIds) {
            foreach ($tagIds as $tagId) {
                // Client: Predis or PHP Redis 3.1.3+ (https://github.com/phpredis/phpredis/commit/d2e203a6)
                // Server: Redis 3.2 or higher (https://redis.io/commands/spop)
                yield 'sPop' => [$tagId, self::POP_MAX_LIMIT];
            }
        });

        // Flatten generator result from pipleline, ignore keys (tag ids)
        $ids = array_unique(array_merge(...iterator_to_array($tagIdSets, false)));

        // Delete cache in chunks to avoid overloading the connection
        foreach (\array_chunk($ids, self::BULK_DELETE_LIMIT) as $chunkIds) {
            $this->doDelete($chunkIds);
        }

        return true;
    }
}
