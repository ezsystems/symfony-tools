<?php

/**
 * File containing the ContentHandler implementation.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace EzSystems\SymfonyTools\Incubator\Cache\TagAware;

use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Traits\FilesystemTrait;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class FilesystemTagAwareAdapter, stores tag <> id relationship as a symlink so we don't need to fetch tags on get*().
 *
 * Stores tag/id information as symlinks to the cache files they refer to, in order to:
 * - skip loading tags info on reads
 * - be able to iterate cache to clear on-demand when invalidation by tags
 */
final class FilesystemTagAwareAdapter extends AbstractTagAwareAdapter implements TagAwareAdapterInterface
{
    use FilesystemTrait;

    /**
     * Folder used for tag symlinks.
     */
    private const TAG_FOLDER = 'tags';

    /**
     * @var \Symfony\Component\Filesystem\Filesystem|null
     */
    private $fs;

    public function __construct($namespace = '', $defaultLifetime = 0, $directory = null)
    {
        parent::__construct('', $defaultLifetime);
        $this->init($namespace, $directory);
    }

    /**
     * This method overrides {@see \Symfony\Component\Cache\Traits\FilesystemTrait::doSave}.
     *
     * It needs to be overridden due to:
     * - usage of `serialize()` in the original method
     * - need to store tag information on save
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

        $expiresAt = $lifetime ? (time() + $lifetime) : 0;
        $tagSet = [];
        foreach ($serialized as $id => $value) {
            $file = $this->getFile($id, true);
            if (!$this->write($file, $expiresAt."\n".rawurlencode($id)."\n".$value, $expiresAt)) {
                $failed[] = $id;
                continue;
            }

            foreach ($values[$id]['tags'] as $tag) {
                $tagSet[$tag][] = ['file' => $file, 'id' => $id];
            }
        }

        if (!empty($failed) && !is_writable($this->directory)) {
            throw new CacheException(sprintf('Cache directory is not writable (%s)', $this->directory));
        }

        // Save Tags as symlinks, uses Filesystem Component to let it handle exceptions correctly
        $fs = $this->getFilesystem();
        foreach($tagSet as $tag => $itemsData) {
            $tagFolder = $this->getTagFolder($tag);
            foreach ($itemsData as $data) {
                $fs->symlink($data['file'], $tagFolder.$this->getTagKeyFile($data['id']));
            }
        }

        return $failed;
    }

    /**
     * This method overrides {@see \Symfony\Component\Cache\Traits\FilesystemTrait::doFetch}.
     *
     * It needs to be overridden due to the usage of `parent::unserialize()` in the original method.
     *
     * {@inheritdoc}
     */
    protected function doFetch(array $ids)
    {
        $values = [];
        $now = time();

        foreach ($ids as $id) {
            $file = $this->getFile($id);
            if (!file_exists($file) || !$h = @fopen($file, 'rb')) {
                continue;
            }
            if (($expiresAt = (int) fgets($h)) && $now >= $expiresAt) {
                fclose($h);
                @unlink($file);
            } else {
                $i = rawurldecode(rtrim(fgets($h)));
                $value = stream_get_contents($h);
                fclose($h);
                if ($i === $id) {
                    $values[$id] = $this->marshaller->unmarshall($value);
                }
            }
        }

        return $values;
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

        foreach (array_unique($tags) as $tag) {
            $tagsFolder = $this->getTagFolder($tag);
            if (!is_dir($tagsFolder)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->getTagFolder($tag), \FilesystemIterator::SKIP_DOTS)) as $itemLink) {
                if (!$itemLink->isLink()) {
                    throw new \Exception("Tag link is not a link: " . $itemLink);
                }

                $valueFile = $itemLink->getRealPath();
                if ($valueFile && file_exists($valueFile)) {
                    @unlink($valueFile);
                }

                @unlink((string)$itemLink);
            }
        }

        return true;
    }

    private function getFilesystem() : Filesystem
    {
        return $this->fs ?? $this->fs = new Filesystem();
    }

    private function getTagFolder($tag) : string
    {
        return $this->directory . self::TAG_FOLDER . \DIRECTORY_SEPARATOR . str_replace('/', '-', $tag). \DIRECTORY_SEPARATOR;
    }

    private function getTagKeyFile($key) : string
    {
        $hash = str_replace('/', '-', base64_encode(hash('sha256', static::class.$key, true)));

        return substr($hash, 0, 20);
    }

    /**
     * @internal For unit tests only.
     */
    public function setFilesystem(Filesystem $fs)
    {
        $this->fs = $fs;
    }
}
