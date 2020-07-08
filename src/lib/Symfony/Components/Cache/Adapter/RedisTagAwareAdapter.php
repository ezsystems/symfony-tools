<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Original source: https://github.com/symfony/symfony/pull/30370
 *                  Adapted for Symfony 3.4LTS
 *                  Last updated from checksum: ddf795390a
 */

namespace Symfony\Component\Cache\Adapter;

use Predis\Connection\Aggregate\ClusterInterface;
use Predis\Connection\Aggregate\PredisCluster;
use Predis\Connection\Aggregate\RedisCluster;
use Predis\Response\Status;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Exception\LogicException;
//use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
//use Symfony\Component\Cache\Marshaller\TagAwareMarshaller;
use Symfony\Component\Cache\Traits\RedisProxy;
use Symfony\Component\Cache\Traits\RedisTrait;

/**
 * Stores tag id <> cache id relationship as a Redis Set, lookup on invalidation using RENAME+SMEMBERS.
 *
 * Set (tag relation info) is stored without expiry (non-volatile), while cache always gets an expiry (volatile) even
 * if not set by caller. Thus if you configure redis with the right eviction policy you can be safe this tag <> cache
 * relationship survives eviction (cache cleanup when Redis runs out of memory).
 *
 * Requirements:
 *  - Client: PHP Redis or Predis
 *            Note: Due to lack of RENAME support it is NOT recommended to use Cluster on Predis, instead use phpredis.
 *  - Server: Redis 2.8+
 *            Configured with any `volatile-*` eviction policy, OR `noeviction` if it will NEVER fill up memory
 *
 * Design limitations:
 *  - Max 4 billion cache keys per cache tag as limited by Redis Set datatype.
 *    E.g. If you use a "all" items tag for expiry instead of clear(), that limits you to 4 billion cache items also.
 *
 * @see https://redis.io/topics/lru-cache#eviction-policies Documentation for Redis eviction policies.
 * @see https://redis.io/topics/data-types#sets Documentation for Redis Set datatype.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 * @author André Rømcke <andre.romcke+symfony@gmail.com>
 */
class RedisTagAwareAdapter extends AbstractTagAwareAdapter
{
    use RedisTrait;

    /**
     * Limits for how many keys are deleted in batch.
     */
    private const BULK_DELETE_LIMIT = 10000;

    /**
     * On cache items without a lifetime set, we set it to 100 days. This is to make sure cache items are
     * preferred to be evicted over tag Sets, if eviction policy is configured according to requirements.
     */
    private const DEFAULT_CACHE_TTL = 8640000;

    /**
     * @var string|null detected eviction policy used on Redis server
     */
    private $redisEvictionPolicy;

    /**
     * @param \Redis|\RedisArray|\RedisCluster|\Predis\ClientInterface $redisClient     The redis client
     * @param string                                                   $namespace       The default namespace
     * @param int                                                      $defaultLifetime The default lifetime
     */
    public function __construct($redisClient, string $namespace = '', int $defaultLifetime = 0, MarshallerInterface $marshaller = null)
    {
        if ($redisClient instanceof \Predis\ClientInterface && $redisClient->getConnection() instanceof ClusterInterface && !$redisClient->getConnection() instanceof PredisCluster) {
            throw new InvalidArgumentException(sprintf('Unsupported Predis cluster connection: only "%s" is, "%s" given.', PredisCluster::class, \get_class($redisClient->getConnection())));
        }

        // <diff: Skipped for now, can consider to backport DeflateMarshaller to enable this>
        /**if (\defined('Redis::OPT_COMPRESSION') && ($redisClient instanceof \Redis || $redisClient instanceof \RedisArray || $redisClient instanceof \RedisCluster)) {
            $compression = $redisClient->getOption(\Redis::OPT_COMPRESSION);

            foreach (\is_array($compression) ? $compression : [$compression] as $c) {
                if (\Redis::COMPRESSION_NONE !== $c) {
                    throw new InvalidArgumentException(sprintf('phpredis compression must be disabled when using "%s", use "%s" instead.', static::class, DeflateMarshaller::class));
                }
            }
        }*/
        // </diff>

        // <diff: Backport from 4.4's RedisTrait>
        if ($redisClient instanceof \Predis\ClientInterface && $redisClient->getOptions()->exceptions) {
            $options = clone $redisClient->getOptions();
            \Closure::bind(function () { $this->options['exceptions'] = false; }, $options, $options)();
            $redisClient = new $redisClient($redisClient->getConnection(), $options);
        }
        // </diff>

        // <diff: Need to pass marshaller to abstract in our case>
        //$this->init($redisClient, $namespace, $defaultLifetime, $marshaller);

        parent::__construct($namespace, $defaultLifetime, $marshaller);

        if (preg_match('#[^-+_.A-Za-z0-9]#', $namespace, $match)) {
            throw new InvalidArgumentException(sprintf('RedisAdapter namespace contains "%s" but only characters in [-+_.A-Za-z0-9] are allowed.', $match[0]));
        }
        if (!$redisClient instanceof \Redis && !$redisClient instanceof \RedisArray && !$redisClient instanceof \RedisCluster && !$redisClient instanceof \Predis\Client && !$redisClient instanceof RedisProxy) {
            throw new InvalidArgumentException(sprintf('%s() expects parameter 1 to be Redis, RedisArray, RedisCluster or Predis\Client, %s given', __METHOD__, \is_object($redisClient) ? \get_class($redisClient) : \gettype($redisClient)));
        }
        $this->redis = $redisClient;
        // </diff>
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave(array $values, int $lifetime, array $addTagData = [], array $delTagData = []): array
    {
        $eviction = $this->getRedisEvictionPolicy();
        if ('noeviction' !== $eviction && 0 !== strpos($eviction, 'volatile-')) {
            throw new LogicException(sprintf('Redis maxmemory-policy setting "%s" is *not* supported by RedisTagAwareAdapter, use "noeviction" or  "volatile-*" eviction policies.', $eviction));
        }

        // serialize values
        if (!$serialized = self::$marshaller->marshall($values, $failed)) {
            return $failed;
        }

        // While pipeline isn't supported on RedisCluster, other setups will at least benefit from doing this in one op
        $results = $this->pipeline(static function () use ($serialized, $lifetime, $addTagData, $delTagData, $failed) {
            // Store cache items, force a ttl if none is set, as there is no MSETEX we need to set each one
            foreach ($serialized as $id => $value) {
                yield 'setEx' => [
                    $id,
                    0 >= $lifetime ? self::DEFAULT_CACHE_TTL : $lifetime,
                    $value,
                ];
            }

            // Add and Remove Tags
            foreach ($addTagData as $tagId => $ids) {
                if (!$failed || $ids = array_diff($ids, $failed)) {
                    yield 'sAdd' => array_merge([$tagId], $ids);
                }
            }

            foreach ($delTagData as $tagId => $ids) {
                if (!$failed || $ids = array_diff($ids, $failed)) {
                    yield 'sRem' => array_merge([$tagId], $ids);
                }
            }
        });

        foreach ($results as $id => $result) {
            // Skip results of SADD/SREM operations, they'll be 1 or 0 depending on if set value already existed or not
            if (is_numeric($result)) {
                continue;
            }
            // setEx results
            if (true !== $result && (!$result instanceof Status || Status::get('OK') !== $result)) {
                $failed[] = $id;
            }
        }

        return $failed;
    }

    /**
     * {@inheritdoc}
     */
    protected function doDeleteTagRelations(array $tagData): bool
    {
        $this->pipeline(static function () use ($tagData) {
            foreach ($tagData as $tagId => $idList) {
                array_unshift($idList, $tagId);
                yield 'sRem' => $idList;
            }
        })->rewind();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function doInvalidate(array $tagIds): bool
    {
        if (!$this->redis instanceof \Predis\ClientInterface || !$this->redis->getConnection() instanceof PredisCluster) {
            $movedTagSetIds = $this->renameKeys($this->redis, $tagIds);
        } else {
            $clusterConnection = $this->redis->getConnection();
            $tagIdsByConnection = new \SplObjectStorage();
            $movedTagSetIds = [];

            foreach ($tagIds as $id) {
                $connection = $clusterConnection->getConnectionByKey($id);
                $slot = $tagIdsByConnection[$connection] ?? $tagIdsByConnection[$connection] = new \ArrayObject();
                $slot[] = $id;
            }

            foreach ($tagIdsByConnection as $connection) {
                $slot = $tagIdsByConnection[$connection];
                $movedTagSetIds = array_merge($movedTagSetIds, $this->renameKeys(new $this->redis($connection, $this->redis->getOptions()), $slot->getArrayCopy()));
            }
        }

        // No Sets found
        if (!$movedTagSetIds) {
            return false;
        }

        // Now safely take the time to read the keys in each set and collect ids we need to delete
        $tagIdSets = $this->pipeline(static function () use ($movedTagSetIds) {
            foreach ($movedTagSetIds as $movedTagId) {
                yield 'sMembers' => [$movedTagId];
            }
        });

        // Return combination of the temporary Tag Set ids and their values (cache ids)
        $ids = array_merge($movedTagSetIds, ...iterator_to_array($tagIdSets, false));

        // Delete cache in chunks to avoid overloading the connection
        foreach (array_chunk(array_unique($ids), self::BULK_DELETE_LIMIT) as $chunkIds) {
            $this->doDelete($chunkIds);
        }

        return true;
    }

    /**
     * Renames several keys in order to be able to operate on them without risk of race conditions.
     *
     * Filters out keys that do not exist before returning new keys.
     *
     * @see https://redis.io/commands/rename
     * @see https://redis.io/topics/cluster-spec#keys-hash-tags
     *
     * @return array Filtered list of the valid moved keys (only those that existed)
     */
    private function renameKeys($redis, array $ids): array
    {
        $newIds = [];
        $uniqueToken = bin2hex(random_bytes(10));

        $results = $this->pipeline(static function () use ($ids, $uniqueToken) {
            foreach ($ids as $id) {
                yield 'rename' => [$id, '{'.$id.'}'.$uniqueToken];
            }
        }, $redis);

        foreach ($results as $id => $result) {
            if (true === $result || ($result instanceof Status && Status::get('OK') === $result)) {
                // Only take into account if ok (key existed), will be false on phpredis if it did not exist
                $newIds[] = '{'.$id.'}'.$uniqueToken;
            }
        }

        return $newIds;
    }

    private function getRedisEvictionPolicy(): string
    {
        if (null !== $this->redisEvictionPolicy) {
            return $this->redisEvictionPolicy;
        }

        foreach ($this->getHosts() as $host) {
            $info = $host->info('Memory');
            $info = isset($info['Memory']) ? $info['Memory'] : $info;

            return $this->redisEvictionPolicy = $info['maxmemory_policy'];
        }

        return $this->redisEvictionPolicy = '';
    }

    /**
     * Overloads method in RedisTrait to backport eval handling for Predis.
     */
    private function pipeline(\Closure $generator, $redis = null): \Generator
    {
        $ids = [];
        $redis = $redis ?? $this->redis;

        if ($redis instanceof \RedisCluster || ($redis instanceof \Predis\ClientInterface && $redis->getConnection() instanceof RedisCluster)) {
            // phpredis & predis don't support pipelining with RedisCluster
            // see https://github.com/phpredis/phpredis/blob/develop/cluster.markdown#pipelining
            // see https://github.com/nrk/predis/issues/267#issuecomment-123781423
            $results = [];
            foreach ($generator() as $command => $args) {
                $results[] = $redis->{$command}(...$args);
                $ids[] = 'eval' === $command ? ($redis instanceof \Predis\ClientInterface ? $args[2] : $args[1][0]) : $args[0];
            }
        } elseif ($redis instanceof \Predis\ClientInterface) {
            $results = $redis->pipeline(static function ($redis) use ($generator, &$ids) {
                foreach ($generator() as $command => $args) {
                    $redis->{$command}(...$args);
                    $ids[] = 'eval' === $command ? $args[2] : $args[0];
                }
            });
        } elseif ($redis instanceof \RedisArray) {
            $connections = $results = $ids = [];
            foreach ($generator() as $command => $args) {
                $id = 'eval' === $command ? $args[1][0] : $args[0];
                if (!isset($connections[$h = $redis->_target($id)])) {
                    $connections[$h] = [$redis->_instance($h), -1];
                    $connections[$h][0]->multi(\Redis::PIPELINE);
                }
                $connections[$h][0]->{$command}(...$args);
                $results[] = [$h, ++$connections[$h][1]];
                $ids[] = $id;
            }
            foreach ($connections as $h => $c) {
                $connections[$h] = $c[0]->exec();
            }
            foreach ($results as $k => list($h, $c)) {
                $results[$k] = $connections[$h][$c];
            }
        } else {
            $redis->multi(\Redis::PIPELINE);
            foreach ($generator() as $command => $args) {
                $redis->{$command}(...$args);
                $ids[] = 'eval' === $command ? $args[1][0] : $args[0];
            }
            $results = $redis->exec();
        }

        foreach ($ids as $k => $id) {
            yield $id => $results[$k];
        }
    }

    /**
     * Backport from RedisTrait in 4.4.
     */
    private function getHosts(): array
    {
        $hosts = [$this->redis];
        if ($this->redis instanceof \Predis\ClientInterface) {
            $connection = $this->redis->getConnection();
            if ($connection instanceof ClusterInterface && $connection instanceof \Traversable) {
                $hosts = [];
                foreach ($connection as $c) {
                    $hosts[] = new \Predis\Client($c);
                }
            }
        } elseif ($this->redis instanceof \RedisArray) {
            $hosts = [];
            foreach ($this->redis->_hosts() as $host) {
                $hosts[] = $this->redis->_instance($host);
            }
        } elseif ($this->redis instanceof \RedisCluster) {
            $hosts = [];
            foreach ($this->redis->_masters() as $host) {
                $hosts[] = $h = new \Redis();
                $h->connect($host[0], $host[1]);
            }
        }

        return $hosts;
    }
}
