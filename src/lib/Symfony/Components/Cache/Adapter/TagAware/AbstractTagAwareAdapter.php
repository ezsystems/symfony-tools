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
 */

declare(strict_types=1);

namespace Symfony\Component\Cache\Adapter\TagAware;

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
 * Re-implements Symfony's AbstractAdapter (as it uses private properties).
 *
 * In order to be able to store tags with values to avoid 2x lookups for tags, and to be able to use backported Marshaller
 * for serialization.
 */
abstract class AbstractTagAwareAdapter implements AdapterInterface, LoggerAwareInterface, ResettableInterface
{
    use AbstractTrait;

    private const TAG_PREFIX = 'tag-';

    private $createCacheItem;
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

        $this->namespace = '' === $namespace ? '' : CacheItem::validateKey($namespace) . ':';
        if (null !== $this->maxIdLength && \strlen($namespace) > $this->maxIdLength - 24) {
            throw new InvalidArgumentException(sprintf('Namespace must be %d chars max, %d given ("%s")', $this->maxIdLength - 24, \strlen($namespace), $namespace));
        }
        $this->createCacheItem = \Closure::bind(
            static function ($key, $value, $isHit) use ($defaultLifetime) {
                $item = new CacheItem();
                $item->key = $key;
                $item->isHit = $isHit;
                $item->defaultLifetime = $defaultLifetime;
                //<diff:AbstractAdapter> extract Value and Tags from the cache value
                $item->value = $value['value'];
                $item->prevTags = $value['tags'] ?? [];
                //</diff:AbstractAdapter>

                return $item;
            },
            null,
            CacheItem::class
        );
        $getId = \Closure::fromCallable([$this, 'getId']);
        $tagPrefix = self::TAG_PREFIX;
        $this->mergeByLifetime = \Closure::bind(
            static function ($deferred, $namespace, &$expiredIds) use ($getId, $tagPrefix) {
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
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch key "{key}"', ['key' => $key, 'exception' => $e]);
        }

        return $f($key, $value, $isHit);
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
            CacheItem::log($this->logger, 'Failed to fetch requested items', ['keys' => $keys, 'exception' => $e]);
            $items = [];
        }
        $ids = array_combine($ids, $keys);

        return $this->generateItems($items, $ids);
    }

    /**
     * {@inheritdoc}
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
     */
    public function saveDeferred(CacheItemInterface $item)
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $ok = true;
        $byLifetime = $this->mergeByLifetime;
        $byLifetime = $byLifetime($this->deferred, $this->namespace, $expiredIds);
        $retry = $this->deferred = [];

        if ($expiredIds) {
            $this->doDelete($expiredIds);
        }
        foreach ($byLifetime as $lifetime => $values) {
            try {
                $e = $this->doSave($values, $lifetime);
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
                    CacheItem::log($this->logger, 'Failed to save key "{key}" ({type})', ['key' => substr($id, \strlen($this->namespace)), 'type' => $type, 'exception' => $e instanceof \Exception ? $e : null]);
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
                    $e = $this->doSave([$id => $v], $lifetime);
                } catch (\Exception $e) {
                }
                if (true === $e || [] === $e) {
                    continue;
                }
                $ok = false;
                $type = \is_object($v) ? \get_class($v) : \gettype($v);
                CacheItem::log($this->logger, 'Failed to save key "{key}" ({type})', ['key' => substr($id, \strlen($this->namespace)), 'type' => $type, 'exception' => $e instanceof \Exception ? $e : null]);
            }
        }

        return $ok;
    }

    public function __destruct()
    {
        if ($this->deferred) {
            $this->commit();
        }
    }

    private function generateItems($items, &$keys)
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
            CacheItem::log($this->logger, 'Failed to fetch requested items', ['keys' => array_values($keys), 'exception' => $e]);
        }

        foreach ($keys as $key) {
            yield $key => $f($key, null, false);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Overloaded in order to deal with tags for adjusted doDelete() signature.
     */
    public function deleteItems(array $keys)
    {
        if (!$keys) {
            return true;
        }

        $ids = [];
        $tagData = [];

        foreach ($keys as $key) {
            $ids[$key] = $this->getId($key);
            unset($this->deferred[$key]);
        }

        foreach ($this->doFetch($ids) as $id => $value) {
            foreach ($value['tags'] ?? [] as $tag) {
                $tagData[$this->getId(self::TAG_PREFIX.$tag)][] = $id;
            }
        }

        try {
            if ($this->doDelete(array_values($ids), $tagData)) {
                return true;
            }
        } catch (\Exception $e) {
        }

        $ok = true;

        // When bulk-delete failed, retry each item individually
        foreach ($ids as $key => $id) {
            try {
                $e = null;
                if ($this->doDelete([$id])) {
                    continue;
                }
            } catch (\Exception $e) {
            }
            CacheItem::log($this->logger, 'Failed to delete key "{key}"', ['key' => $key, 'exception' => $e]);
            $ok = false;
        }

        return $ok;
    }

    /**
     * Removes multiple items from the pool and their corresponding tags.
     *
     * @param array $ids     An array of identifiers that should be removed from the pool
     * @param array $tagData Optional array of tag identifiers => key identifiers that should be removed from the pool
     *
     * @return bool True if the items were successfully removed, false otherwise
     */
    abstract protected function doDelete(array $ids, array $tagData = []);

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
            $tagIds[] = $this->getId(self::TAG_PREFIX.$tag);
        }

        if ($this->doInvalidate($tagIds)) {
            return true;
        }

        return false;
    }

    /**
     * Invalidates cached items using tags.
     *
     * @param string[] $tagIds an array of tags to invalidate, key is tag and value is tag id
     *
     * @return bool True on success
     */
    abstract protected function doInvalidate(array $tagIds): bool;

    /**
     * Overload unserialize() in order to use marshaller.
     */
    protected static function unserialize($value)
    {
        return self::$marshaller->unmarshall($value);
    }
}
