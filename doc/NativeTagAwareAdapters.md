# NativeTagAwareAdapters

This feature is an Incubator, and as such might change from minor release to the next.

Contains a set of optimized TagAwareAdapters that cuts number of cache lookups down by half
compared to usage of Symfony's TagAwareAdapter. In short, for Filesystem symlinks for tags are used,
and for Redis a Set is used to keep track of ids connected to a tag, and instead of tag lookups to
find expiry info on each request this info is used to do it on-demand when calling invalidation buy tags.
_See Adapters for further details._

It also backports `Marshaller` feature from Symfony 4 in order to support serialization with igbinary.
The `MarshallerInterface` and `DefaultMarshaller` class is taken from the following revision: d2098d7
See: https://github.com/symfony/symfony/commits/master/src/Symfony/Component/Cache/Marshaller

## Requirements
- Symfony 3.4, PHP 7.1+
- For usage eZ Platform v2: `ezsystems/ezpublish-kernel` v7.3.5, v7.4.3 or higher.
- For `RedisTagAwareAdapter` usage:
    - [PHP Redis](https://pecl.php.net/package/redis) extension v3.1.3 or higher, _or_ [Predis](https://packagist.org/packages/predis/predis)
    - Redis 3.2 or higher, configured with `noeviction` or any `volatile-*` eviction policy

## Configuration
After installing the bundle, you have to configure proper services in order to use this.

**Here is an example on how to do that with eZ Platform:**


### File system cache

In `app/config/cache_pool/app.cache.tagaware.filesystem.yml`, place the following:
```yaml
services:
    app.cache.tagaware.filesystem:
        class: Symfony\Component\Cache\Adapter\TagAware\FilesystemTagAwareAdapter
        parent: cache.adapter.filesystem
        tags:
            - name: cache.pool
              clearer: cache.app_clearer
              # Cache namespace prefix overriding the one used by Symfony by default
              # This makes sure cache is reliably shared across whole cluster and all Symfony env's
              # Can be used for blue/green deployment strategies when changes affect content cache.
              # For multi db setup adapt this to be unique per pool (one pool per database)
              # If you prefer default behaviour set this to null or comment out, and consider for instance:
              # https://symfony.com/doc/current/reference/configuration/framework.html#prefix-seed
              namespace: '%cache_namespace%'
```

Once that is done you can enable the handler, for instance by setting the following environment variable for PHP:
```bash
export CACHE_POOL="app.cache.tagaware.filesystem"
```

_Then clear cache and restart web server, you'll be able to verify it's in use on Symfony's web debug toolbar._


### Redis cache

In `app/config/cache_pool/app.cache.tagaware.redis.yml`, place the following:
```yaml
services:
    app.cache.tagaware.redis:
        class: Symfony\Component\Cache\Adapter\TagAware\RedisTagAwareAdapter
        parent: cache.adapter.redis
        tags:
            - name: cache.pool
              clearer: cache.app_clearer
              # Examples from vendor/symfony/symfony/src/Symfony/Component/Cache/Traits/RedisTrait.php:
              # redis://localhost:6379
              # redis://secret@example.com:1234/13
              # redis://secret@/var/run/redis.sock/13?persistent_id=4&class=Redis&timeout=3&retry_interval=3
              # Example using Predis: redis://%cache_dsn%?class=\Predis\Client
              provider: 'redis://%cache_dsn%'
              # Cache namespace prefix overriding the one used by Symfony by default
              # This makes sure cache is reliably shared across whole cluster and all Symfony env's
              # Can be used for blue/green deployment strategies when changes affect content cache.
              # For multi db setup adapt this to be unique per pool (one pool per database)
              # If you prefer default behaviour set this to null or comment out, and consider for instance:
              # https://symfony.com/doc/current/reference/configuration/framework.html#prefix-seed
              namespace: '%cache_namespace%'
```

Once that is done you can enable the handler, for instance by setting the following environment variable for PHP:
```bash
export CACHE_POOL="app.cache.tagaware.redis"
```
If you don't have redis, for testing you can use:
- Run: `docker run --name my-redis -p 6379:6379 -d redis`.
- Stop + Remove: `docker rm -f my-redis`.
- Debug: `printf "PING\r\n" | nc localhost 6379`, should return `+PONG`.


_Then clear cache and restart web server, you'll be able to verify it's in use on Symfony's web debug toolbar._
