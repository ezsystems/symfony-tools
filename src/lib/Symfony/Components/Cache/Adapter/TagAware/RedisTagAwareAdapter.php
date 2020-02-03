<?php

declare(strict_types=1);

namespace Symfony\Component\Cache\Adapter\TagAware;

use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter as SymfonyBackportedRedisTagAwareAdapter;

/**
 * @deprecated This adapter was a Incubator while being proposed to Symfony, change to use the backported adapter instead.
 */
final class RedisTagAwareAdapter extends SymfonyBackportedRedisTagAwareAdapter
{
}
