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
    use FilesystemTrait;

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
        $failed = [];
        $serialized = self::$marshaller->marshall($values, $failed);
        if (empty($serialized)) {
            return $failed;
        }

        $expiresAt = $lifetime ? (time() + $lifetime) : 0;
        $tagSet = [];
        foreach ($serialized as $id => $value) {
            $file = $this->getFile($id, true);
            if (!$this->write($file, $expiresAt . "\n" . rawurlencode($id) . "\n" . $value, $expiresAt)) {
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
        foreach ($tagSet as $tag => $itemsData) {
            $tagFolder = $this->getTagFolder($tag);
            foreach ($itemsData as $data) {
                $fs->symlink($data['file'], $tagFolder . $this->getTagIdFile($data['id']));
            }
        }

        return $failed;
    }

    /**
     * {@inheritdoc}
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

        // Commit deferred changes after invalidation to emulate logic in TagAwareAdapter
        $this->commit();

        return true;
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

    private function getFilesystem(): Filesystem
    {
        return $this->fs ?? $this->fs = new Filesystem();
    }

    private function getTagFolder($tag): string
    {
        return $this->directory.self::TAG_FOLDER.\DIRECTORY_SEPARATOR.str_replace('/', '-', $tag).\DIRECTORY_SEPARATOR;
    }

    private function getTagIdFile($id): string
    {
        // Use MD5 to favor speed over security, which is not an issue here
        $hash = str_replace('/', '-', base64_encode(hash('md5', static::class.$id, true)));

        return substr($hash, 0, 20);
    }
}
