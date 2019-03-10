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
    use FilesystemTrait {
        doDelete as deleteCache;
    }

    /**
     * Folder used for tag symlinks.
     */
    private const TAG_FOLDER = 'tags';

    /**
     * @var \Symfony\Component\Filesystem\Filesystem|null
     */
    private $fs;

    public function __construct(string $namespace = '', int $defaultLifetime = 0, string $directory = null)
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
        // Extract and remove tag operations form values
        $tagOperations = ['add' => [], 'remove' => []];
        foreach ($values as $id => $value) {
            $tagOperations['add'][$id] = $value['tag-operations']['add'];
            $tagOperations['remove'][$id] = $value['tag-operations']['remove'];
            unset($value['tag-operations']);
        }

        $failed = [];
        $serialized = self::$marshaller->marshall($values, $failed);
        if (empty($serialized)) {
            return $failed;
        }

        $expiresAt = $lifetime ? (time() + $lifetime) : 0;
        foreach ($serialized as $id => $value) {
            $file = $this->getFile($id, true);
            if (!$this->write($file, $expiresAt . "\n" . rawurlencode($id) . "\n" . $value, $expiresAt)) {
                $failed[] = $id;
                continue;
            }
        }

        if (!empty($failed) && !is_writable($this->directory)) {
            throw new CacheException(sprintf('Cache directory is not writable (%s)', $this->directory));
        }

        $fs = $this->getFilesystem();
        // Add Tags as symlinks
        foreach ($tagOperations['add'] as $id => $tagIds) {
            if (!empty($failed) && \in_array($id, $failed)) {
                continue;
            }

            $file = $this->getFile($id);
            $itemFileName = $this->getItemLinkFileName($id);
            foreach ($tagIds as $tagId) {
                $fs->symlink($file, $this->getTagFolder($tagId).$itemFileName);
            }
        }

        // Unlink removed Tags
        $files = [];
        foreach ($tagOperations['remove'] as $id => $tagIds) {
            if (!empty($failed) && \in_array($id, $failed)) {
                continue;
            }

            $itemFileName = $this->getItemLinkFileName($id);
            foreach ($tagIds as $tagId) {
                $files[] = $this->getTagFolder($tagId).$itemFileName;
            }
        }
        $fs->remove($files);

        return $failed;
    }

    /**
     * {@inheritdoc}
     */
    protected function doDelete(array $ids, array $tagData = [])
    {
        $ok = $this->deleteCache($ids);

        // Remove tags
        $files = [];
        $fs = $this->getFilesystem();
        foreach ($tagData as $tagId => $idMap) {
            $tagFolder = $this->getTagFolder($tagId);
            foreach ($idMap as $id) {
                $files[] = $tagFolder.$this->getItemLinkFileName($id);
            }
        }
        $fs->remove($files);

        return $ok;
    }

    /**
     * {@inheritdoc}
     */
    public function doInvalidate(array $tagIds): bool
    {
        foreach ($tagIds as $tagId) {
            $tagsFolder = $this->getTagFolder($tagId);
            if (!is_dir($tagsFolder)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tagsFolder, \FilesystemIterator::SKIP_DOTS)) as $itemLink) {
                if (!$itemLink->isLink()) {
                    throw new \Exception('Tag link is not a link: '.$itemLink);
                }

                $valueFile = $itemLink->getRealPath();
                if ($valueFile && file_exists($valueFile)) {
                    @unlink($valueFile);
                }

                @unlink((string) $itemLink);
            }
        }

        return true;
    }

    private function getFilesystem(): Filesystem
    {
        return $this->fs ?? $this->fs = new Filesystem();
    }

    private function getTagFolder(string $tagId): string
    {
        return $this->directory.self::TAG_FOLDER.\DIRECTORY_SEPARATOR.str_replace('/', '-', $tagId).\DIRECTORY_SEPARATOR;
    }

    private function getItemLinkFileName(string $keyId): string
    {
        // Use MD5 to favor speed over security, which is not an issue here
        $hash = str_replace('/', '-', base64_encode(hash('md5', static::class.$keyId, true)));

        return substr($hash, 0, 20);
    }

    /**
     * This method overrides {@see \Symfony\Component\Cache\Traits\FilesystemCommonTrait::getFile}.
     *
     * Backports Symfony 4 optimization of using md5 instead of sha1, given this is used on reads.
     *
     * {@inheritdoc}
     */
    private function getFile($id, $mkdir = false)
    {
        // Use MD5 to favor speed over security, which is not an issue here
        $hash = str_replace('/', '-', base64_encode(hash('md5', static::class . $id, true)));
        $dir = $this->directory.strtoupper($hash[0].\DIRECTORY_SEPARATOR.$hash[1].\DIRECTORY_SEPARATOR);

        if ($mkdir && !file_exists($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir.substr($hash, 2, 20);
    }
}
