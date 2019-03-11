<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Original source:
 * https://github.com/symfony/symfony/blob/master/src/Symfony/Component/Cache/Marshaller/MarshallerInterface.php
 * Last revision: https://github.com/symfony/symfony/commit/3cfdc9e9d739dda0bc8d222d458e6bbb34c10c72
 */

namespace Symfony\Component\Cache\Marshaller;

/**
 * Serializes/unserializes PHP values.
 *
 * Implementations of this interface MUST deal with errors carefully. They MUST
 * also deal with forward and backward compatibility at the storage format level.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
interface MarshallerInterface
{
    /**
     * Serializes a list of values.
     *
     * When serialization fails for a specific value, no exception should be
     * thrown. Instead, its key should be listed in $failed.
     */
    public function marshall(array $values, ?array &$failed): array;

    /**
     * Unserializes a single value and throws an exception if anything goes wrong.
     *
     * @return mixed
     *
     * @throws \Exception Whenever unserialization fails
     */
    public function unmarshall(string $value);
}
