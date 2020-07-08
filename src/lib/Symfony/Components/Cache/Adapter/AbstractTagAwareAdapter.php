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

use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Component\Cache\Traits\AbstractTrait;

/**
 * Abstract for native TagAware adapters.
 *
 * To keep info on tags, the tags are both serialized as part of cache value and provided as tag ids
 * to Adapters on operations when needed for storage to doSave(), doDelete() & doInvalidate().
 *
 * @author Nicolas Grekas <p@tchwork.com>
 * @author André Rømcke <andre.romcke+symfony@gmail.com>
 *
 * @internal
 */
abstract class AbstractTagAwareAdapter implements TagAwareAdapterInterface, LoggerAwareInterface, ResettableInterface
{
    use AbstractTrait;

    private const TAGS_PREFIX = "\0tags\0";

    /**
     * @var \Closure needs to be set by class, signature is function(string <key>, mixed <value>, bool <isHit>)
     */
    private $createCacheItem;

    /**
     * @var \Closure needs to be set by class, signature is function(array <deferred>, string <namespace>, array <&expiredIds>)
     */
    private $mergeByLifetime;

    /**
     * @var \Symfony\Component\Cache\Marshaller\MarshallerInterface
     * NOTE: Not relevant in this way in Symfony 4+ where Abstract trait already uses this
     */
    protected static $marshaller;

    /**
     * @param string $namespace
     * @param int $defaultLifetime
     * @param MarshallerInterface|null $marshaller
     *
     * @throws \Symfony\Component\Cache\Exception\CacheException
     */
    protected function __construct(string $namespace = '', int $defaultLifetime = 0, MarshallerInterface $marshaller = null)
    {
        self::$marshaller = $marshaller ?? new DefaultMarshaller();

        $this->namespace = '' === $namespace ? '' : CacheItem::validateKey($namespace).':';
        if (null !== $this->maxIdLength && \strlen($namespace) > $this->maxIdLength - 24) {
            throw new InvalidArgumentException(sprintf('Namespace must be %d chars max, %d given ("%s").', $this->maxIdLength - 24, \strlen($namespace), $namespace));
        }
        $this->createCacheItem = \Closure::bind(
            static function ($key, $value, $isHit) use ($defaultLifetime) {
                $item = new CacheItem();
                $item->key = $key;
                $item->defaultLifetime = $defaultLifetime;
                //<diff:AbstractAdapter> extract Value and Tags from the cache value
                // If structure does not match what we expect return item as is (no value and not a hit)
                if (!\is_array($value) || !\array_key_exists('value', $value)) {
                    return $item;
                }
                $item->isHit = $isHit;
                // Extract value and tags from the cache value
                $item->value = $value['value'];
                $item->prevTags = $value['tags'] ?? [];
                //</diff:AbstractAdapter>

                return $item;
            },
            null,
            CacheItem::class
        );
        $getId = \Closure::fromCallable([$this, 'getId']);
        $tagPrefix = self::TAGS_PREFIX;
        $this->mergeByLifetime = \Closure::bind(
            static function ($deferred, &$expiredIds) use ($getId, $tagPrefix) {
                $byLifetime = [];
                $now = time();
                $expiredIds = [];

                foreach ($deferred as $key => $item) {
                    //<diff:AbstractAdapter> store Value and Tags on the cache value
                    $key = (string) $key;
                    $id = $getId($key);
                    $value = ['value' => $item->value, 'tags' => $item->tags];

                    // Extract tag changes, these should be removed from values in doSave()
                    $value['tag-operations'] = ['add' => [], 'remove' => []];
                    $oldTags = $item->prevTags ?? [];
                    foreach (array_diff($value['tags'], $oldTags) as $addedTag) {
                        $value['tag-operations']['add'][] = $getId($tagPrefix.$addedTag);
                    }
                    foreach (array_diff($oldTags, $value['tags']) as $removedTag) {
                        $value['tag-operations']['remove'][] = $getId($tagPrefix.$removedTag);
                    }

                    if (null === $item->expiry) {
                        $byLifetime[0 < $item->defaultLifetime ? $item->defaultLifetime : 0][$id] = $value;
                    } elseif ($item->expiry > $now) {
                        $byLifetime[$item->expiry - $now][$id] = $value;
                    } else {
                        $expiredIds[] = $id;
                    }
                    //</diff:AbstractAdapter>
                }

                return $byLifetime;
            },
            null,
            CacheItem::class
        );
    }

    /**
     * Persists several cache items immediately.
     *
     * @param array   $values        The values to cache, indexed by their cache identifier
     * @param int     $lifetime      The lifetime of the cached values, 0 for persisting until manual cleaning
     * @param array[] $addTagData    Hash where key is tag id, and array value is list of cache id's to add to tag
     * @param array[] $removeTagData Hash where key is tag id, and array value is list of cache id's to remove to tag
     *
     * @return array The identifiers that failed to be cached or a boolean stating if caching succeeded or not
     */
    abstract protected function doSave(array $values, int $lifetime, array $addTagData = [], array $removeTagData = []): array;

    /**
     * Removes multiple items from the pool and their corresponding tags.
     *
     * @param array $ids An array of identifiers that should be removed from the pool
     *
     * @return bool True if the items were successfully removed, false otherwise
     */
    abstract protected function doDelete(array $ids);

    /**
     * Removes relations between tags and deleted items.
     *
     * @param array $tagData Array of tag => key identifiers that should be removed from the pool
     */
    abstract protected function doDeleteTagRelations(array $tagData): bool;

    /**
     * Invalidates cached items using tags.
     *
     * @param string[] $tagIds An array of tags to invalidate, key is tag and value is tag id
     *
     * @return bool True on success
     */
    abstract protected function doInvalidate(array $tagIds): bool;

    /**
     * Delete items and yields the tags they were bound to.
     */
    protected function doDeleteYieldTags(array $ids): iterable
    {
        foreach ($this->doFetch($ids) as $id => $value) {
            yield $id => \is_array($value) && \is_array($value['tags'] ?? null) ? $value['tags'] : [];
        }

        $this->doDelete($ids);
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        $ok = true;
        $byLifetime = $this->mergeByLifetime;
        $byLifetime = $byLifetime($this->deferred, $expiredIds);
        $retry = $this->deferred = [];

        if ($expiredIds) {
            // Tags are not cleaned up in this case, however that is done on invalidateTags().
            $this->doDelete($expiredIds);
        }
        foreach ($byLifetime as $lifetime => $values) {
            try {
                $values = $this->extractTagData($values, $addTagData, $removeTagData);
                $e = $this->doSave($values, $lifetime, $addTagData, $removeTagData);
            } catch (\Exception $e) {
            }
            if (true === $e || [] === $e) {
                continue;
            }
            if (\is_array($e) || 1 === \count($values)) {
                foreach (\is_array($e) ? $e : array_keys($values) as $id) {
                    $ok = false;
                    $v = $values[$id];
                    $type = \is_object($v) ? \get_class($v) : \gettype($v);
                    $message = sprintf('Failed to save key "{key}" of type %s%s', $type, $e instanceof \Exception ? ': '.$e->getMessage() : '.');
                    CacheItem::log($this->logger, $message, ['key' => substr($id, \strlen($this->namespace)), 'exception' => $e instanceof \Exception ? $e : null]);
                }
            } else {
                foreach ($values as $id => $v) {
                    $retry[$lifetime][] = $id;
                }
            }
        }

        // When bulk-save failed, retry each item individually
        foreach ($retry as $lifetime => $ids) {
            foreach ($ids as $id) {
                try {
                    $v = $byLifetime[$lifetime][$id];
                    $values = $this->extractTagData([$id => $v], $addTagData, $removeTagData);
                    $e = $this->doSave($values, $lifetime, $addTagData, $removeTagData);
                } catch (\Exception $e) {
                }
                if (true === $e || [] === $e) {
                    continue;
                }
                $ok = false;
                $type = \is_object($v) ? \get_class($v) : \gettype($v);
                $message = sprintf('Failed to save key "{key}" of type %s%s', $type, $e instanceof \Exception ? ': '.$e->getMessage() : '.');
                CacheItem::log($this->logger, $message, ['key' => substr($id, \strlen($this->namespace)), 'exception' => $e instanceof \Exception ? $e : null]);
            }
        }

        return $ok;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys): bool
    {
        if (!$keys) {
            return true;
        }

        $ok = true;
        $ids = [];
        $tagData = [];

        foreach ($keys as $key) {
            $ids[$key] = $this->getId($key);
            unset($this->deferred[$key]);
        }

        try {
            foreach ($this->doDeleteYieldTags(array_values($ids)) as $id => $tags) {
                foreach ($tags as $tag) {
                    $tagData[$this->getId(self::TAGS_PREFIX.$tag)][] = $id;
                }
            }
        } catch (\Exception $e) {
            $ok = false;
        }

        try {
            if ((!$tagData || $this->doDeleteTagRelations($tagData)) && $ok) {
                return true;
            }
        } catch (\Exception $e) {
        }

        // When bulk-delete failed, retry each item individually
        foreach ($ids as $key => $id) {
            try {
                $e = null;
                if ($this->doDelete([$id])) {
                    continue;
                }
            } catch (\Exception $e) {
            }
            $message = 'Failed to delete key "{key}"'.($e instanceof \Exception ? ': '.$e->getMessage() : '.');
            CacheItem::log($this->logger, $message, ['key' => $key, 'exception' => $e]);
            $ok = false;
        }

        return $ok;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateTags(array $tags)
    {
        if (empty($tags)) {
            return false;
        }

        $tagIds = [];
        foreach (array_unique($tags) as $tag) {
            $tagIds[] = $this->getId(self::TAGS_PREFIX.$tag);
        }

        if ($this->doInvalidate($tagIds)) {
            return true;
        }

        return false;
    }

    /**
     * Extracts tags operation data from $values set in mergeByLifetime, and returns values without it.
     */
    private function extractTagData(array $values, ?array &$addTagData, ?array &$removeTagData): array
    {
        $addTagData = $removeTagData = [];
        foreach ($values as $id => $value) {
            foreach ($value['tag-operations']['add'] as $tag => $tagId) {
                $addTagData[$tagId][] = $id;
            }

            foreach ($value['tag-operations']['remove'] as $tag => $tagId) {
                $removeTagData[$tagId][] = $id;
            }

            unset($values[$id]['tag-operations']);
        }

        return $values;
    }

    /**
     * {@inheritdoc}
     */
    public function getItem($key)
    {
        if ($this->deferred) {
            $this->commit();
        }
        $id = $this->getId($key);

        $f = $this->createCacheItem;
        $isHit = false;
        $value = null;

        try {
            foreach ($this->doFetch([$id]) as $value) {
                $isHit = true;
            }

            return $f($key, $value, $isHit);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch key "{key}": '.$e->getMessage(), ['key' => $key, 'exception' => $e]);
        }

        return $f($key, null, false);
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = [])
    {
        if ($this->deferred) {
            $this->commit();
        }
        $ids = [];

        foreach ($keys as $key) {
            $ids[] = $this->getId($key);
        }
        try {
            $items = $this->doFetch($ids);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch items: '.$e->getMessage(), ['keys' => $keys, 'exception' => $e]);
            $items = [];
        }
        $ids = array_combine($ids, $keys);

        return $this->generateItems($items, $ids);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function save(CacheItemInterface $item)
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return $this->commit();
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function saveDeferred(CacheItemInterface $item)
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function __sleep()
    {
        throw new \BadMethodCallException('Cannot serialize '.__CLASS__);
    }

    public function __wakeup()
    {
        throw new \BadMethodCallException('Cannot unserialize '.__CLASS__);
    }

    public function __destruct()
    {
        if ($this->deferred) {
            $this->commit();
        }
    }

    private function generateItems(iterable $items, array &$keys): iterable
    {
        $f = $this->createCacheItem;

        try {
            foreach ($items as $id => $value) {
                if (!isset($keys[$id])) {
                    $id = key($keys);
                }
                $key = $keys[$id];
                unset($keys[$id]);
                yield $key => $f($key, $value, true);
            }
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch items: '.$e->getMessage(), ['keys' => array_values($keys), 'exception' => $e]);
        }

        foreach ($keys as $key) {
            yield $key => $f($key, null, false);
        }
    }

    /**
     * Overload unserialize() in order to use marshaller.
     */
    protected static function unserialize($value)
    {
        return self::$marshaller->unmarshall($value);
    }
}
