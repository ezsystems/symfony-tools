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

use Symfony\Component\Cache\Marshaller\MarshallerInterface;
//use Symfony\Component\Cache\Marshaller\TagAwareMarshaller;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\Traits\FilesystemTrait;

/**
 * Stores tag id <> cache id relationship as a symlink, and lookup on invalidation calls.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 * @author André Rømcke <andre.romcke+symfony@gmail.com>
 */
class FilesystemTagAwareAdapter extends AbstractTagAwareAdapter implements PruneableInterface
{
    use FilesystemTrait {
        doClear as private doClearCache;
    }

    /**
     * Folder used for tag symlinks.
     */
    private const TAG_FOLDER = 'tags';

    public function __construct(string $namespace = '', int $defaultLifetime = 0, string $directory = null, MarshallerInterface $marshaller = null)
    {
        parent::__construct($namespace, $defaultLifetime, $marshaller);
        $this->init($namespace, $directory);
    }

    /**
     * {@inheritdoc}
     */
    protected function doClear($namespace)
    {
        $ok = $this->doClearCache($namespace);

        if ('' !== $namespace) {
            return $ok;
        }

        set_error_handler(static function () {});
        $chars = '+-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        try {
            foreach ($this->scanHashDir($this->directory.self::TAG_FOLDER.\DIRECTORY_SEPARATOR) as $dir) {
                if (rename($dir, $renamed = substr_replace($dir, bin2hex(random_bytes(4)), -8))) {
                    $dir = $renamed.\DIRECTORY_SEPARATOR;
                } else {
                    $dir .= \DIRECTORY_SEPARATOR;
                    $renamed = null;
                }

                for ($i = 0; $i < 38; ++$i) {
                    if (!file_exists($dir.$chars[$i])) {
                        continue;
                    }
                    for ($j = 0; $j < 38; ++$j) {
                        if (!file_exists($d = $dir.$chars[$i].\DIRECTORY_SEPARATOR.$chars[$j])) {
                            continue;
                        }
                        foreach (scandir($d, SCANDIR_SORT_NONE) ?: [] as $link) {
                            if ('.' !== $link && '..' !== $link && (null !== $renamed || !realpath($d.\DIRECTORY_SEPARATOR.$link))) {
                                unlink($d.\DIRECTORY_SEPARATOR.$link);
                            }
                        }
                        null === $renamed ?: rmdir($d);
                    }
                    null === $renamed ?: rmdir($dir.$chars[$i]);
                }
                null === $renamed ?: rmdir($renamed);
            }
        } finally {
            restore_error_handler();
        }

        return $ok;
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
    protected function doSave(array $values, int $lifetime, array $addTagData = [], array $removeTagData = []): array
    {
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

        // Add Tags as symlinks
        foreach ($addTagData as $tagId => $ids) {
            $tagFolder = $this->getTagFolder($tagId);
            foreach ($ids as $id) {
                if ($failed && \in_array($id, $failed, true)) {
                    continue;
                }

                $file = $this->getFile($id);

                if (!@symlink($file, $tagLink = $this->getFile($id, true, $tagFolder)) && !is_link($tagLink)) {
                    @unlink($file);
                    $failed[] = $id;
                }
            }
        }

        // Unlink removed Tags
        foreach ($removeTagData as $tagId => $ids) {
            $tagFolder = $this->getTagFolder($tagId);
            foreach ($ids as $id) {
                if ($failed && \in_array($id, $failed, true)) {
                    continue;
                }

                @unlink($this->getFile($id, false, $tagFolder));
            }
        }

        return $failed;
    }

    /**
     * {@inheritdoc}
     */
    protected function doDeleteTagRelations(array $tagData): bool
    {
        foreach ($tagData as $tagId => $idList) {
            $tagFolder = $this->getTagFolder($tagId);
            foreach ($idList as $id) {
                @unlink($this->getFile($id, false, $tagFolder));
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function doInvalidate(array $tagIds): bool
    {
        foreach ($tagIds as $tagId) {
            if (!file_exists($tagFolder = $this->getTagFolder($tagId))) {
                continue;
            }

            set_error_handler(static function () {});

            try {
                if (rename($tagFolder, $renamed = substr_replace($tagFolder, bin2hex(random_bytes(4)), -9))) {
                    $tagFolder = $renamed.\DIRECTORY_SEPARATOR;
                } else {
                    $renamed = null;
                }

                foreach ($this->scanHashDir($tagFolder) as $itemLink) {
                    unlink(realpath($itemLink) ?: $itemLink);
                    unlink($itemLink);
                }

                if (null === $renamed) {
                    continue;
                }

                $chars = '+-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

                for ($i = 0; $i < 38; ++$i) {
                    for ($j = 0; $j < 38; ++$j) {
                        rmdir($tagFolder.$chars[$i].\DIRECTORY_SEPARATOR.$chars[$j]);
                    }
                    rmdir($tagFolder.$chars[$i]);
                }
                rmdir($renamed);
            } finally {
                restore_error_handler();
            }
        }

        return true;
    }

    private function getTagFolder(string $tagId): string
    {
        return $this->getFile($tagId, false, $this->directory.self::TAG_FOLDER.\DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR;
    }

    /**
     * This method overrides {@see \Symfony\Component\Cache\Traits\FilesystemCommonTrait::getFile}.
     *
     * Backports Symfony 4 optimization of using md5 instead of sha1, given this is used on reads.
     *
     * {@inheritdoc}
     */
    private function getFile(string $id, bool $mkdir = false, string $directory = null)
    {
        // Use MD5 to favor speed over security, which is not an issue here
        $hash = str_replace('/', '-', base64_encode(hash('md5', static::class.$id, true)));
        $dir = ($directory ?? $this->directory).strtoupper($hash[0].\DIRECTORY_SEPARATOR.$hash[1].\DIRECTORY_SEPARATOR);

        if ($mkdir && !file_exists($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir.substr($hash, 2, 20);
    }

    private function scanHashDir(string $directory): \Generator
    {
        if (!file_exists($directory)) {
            return;
        }

        $chars = '+-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        for ($i = 0; $i < 38; ++$i) {
            if (!file_exists($directory.$chars[$i])) {
                continue;
            }

            for ($j = 0; $j < 38; ++$j) {
                if (!file_exists($dir = $directory.$chars[$i].\DIRECTORY_SEPARATOR.$chars[$j])) {
                    continue;
                }

                foreach (@scandir($dir, SCANDIR_SORT_NONE) ?: [] as $file) {
                    if ('.' !== $file && '..' !== $file) {
                        yield $dir.\DIRECTORY_SEPARATOR.$file;
                    }
                }
            }
        }
    }
}
