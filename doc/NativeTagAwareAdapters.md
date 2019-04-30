# NativeTagAwareAdapters

This feature is an Incubator, and as such might change from minor release to the next depending on:
https://github.com/symfony/symfony/pull/30370

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

**Below are examples on how to configure these adapters with eZ Platform 2.5.x**

### File system cache

Enabled by default on eZ Platform 2.5+, this is done by means of a new cache adapter service:
https://github.com/ezsystems/ezplatform/blob/v2.5.1/app/config/cache_pool/cache.tagaware.filesystem.yml

And by default `CACHE_POOL` enviroment is set to `cache.tagaware.filesystem` to use it.

If you change to this adapter, clear cache and restart web server. You can verify if the adapter is in use on the Symfony web debug toolbar.

### Redis cache

Add a service for redis cache, on eZ Platform 2.5 and higher one is provided by default in [`app/config/cache_pool/cache.redis.ym`](https://github.com/ezsystems/ezplatform/blob/v2.5.1/app/config/cache_pool/cache.redis.ym).

Once that is done you can enable the handler, for instance by setting the following environment variable for PHP:
```bash
export CACHE_POOL="cache.redis"
```

If you don't have redis, for testing you can use:
- Run: `docker run --name my-redis -p 6379:6379 -d redis`.
- Stop + Remove: `docker rm -f my-redis`.
- Debug: `printf "PING\r\n" | nc localhost 6379`, should return `+PONG`.

_If you change to this adapter; clear cache and restart web server, you'll be able to verify it's in use on Symfony's web debug toolbar._
