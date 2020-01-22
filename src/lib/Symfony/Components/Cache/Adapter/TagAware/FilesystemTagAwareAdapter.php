<?php

declare(strict_types=1);

namespace Symfony\Component\Cache\Adapter\TagAware;

use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter as SymfonyBackportedFilesystemTagAwareAdapter;

/**
 * @deprecated This adapter was a Incubator while being proposed to Symfony, change to use the backported adapter instead.
 */
final class FilesystemTagAwareAdapter extends SymfonyBackportedFilesystemTagAwareAdapter
{
}
