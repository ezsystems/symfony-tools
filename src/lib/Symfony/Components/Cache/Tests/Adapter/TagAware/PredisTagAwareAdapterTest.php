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

namespace Symfony\Component\Cache\Tests\Adapter\TagAware;

use Predis\Connection\StreamConnection;
use Symfony\Component\Cache\Adapter\TagAware\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Tests\Adapter\PredisAdapterTest;
use Symfony\Component\Cache\Tests\Traits\TagAwareTestTrait;

class PredisTagAwareAdapterTest extends PredisAdapterTest
{
    use TagAwareTestTrait;

    protected function setUp()
    {
        parent::setUp();
        $this->skippedTests['testTagItemExpiry'] = 'Testing expiration slows down the test suite';
    }

    public function createCachePool($defaultLifetime = 0)
    {
        $this->assertInstanceOf(\Predis\Client::class, self::$redis);
        $adapter = new RedisTagAwareAdapter(self::$redis, str_replace('\\', '.', __CLASS__), $defaultLifetime);

        return $adapter;
    }

    /**
     * @todo Drop this overloading when RedisTrait is removedin the future (IF cluster improvments are backported to 3.4)
     */
    public function testCreateConnection()
    {
        $redisHost = getenv('REDIS_HOST');

        $redis = RedisAdapter::createConnection('redis://'.$redisHost.'/1', ['class' => \Predis\Client::class, 'timeout' => 3]);
        $this->assertInstanceOf(\Predis\Client::class, $redis);

        $connection = $redis->getConnection();
        $this->assertInstanceOf(StreamConnection::class, $connection);

        $params = [
            'scheme' => 'tcp',
            'host' => 'localhost',
            'port' => 6379,
            'persistent' => 0,
            'timeout' => 3,
            'read_write_timeout' => 0,
            'tcp_nodelay' => true,
            'database' => '1',
        ];
        $this->assertSame($params, $connection->getParameters()->toArray());
    }
}
