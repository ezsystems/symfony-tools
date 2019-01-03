# RedisSessionHandler

This feature has been backported from Symfony 4. The `RedisSessionHandler` allows to configure Symfony's Redis-based session storage handler instead of using the native one provided by `Redis`.
Last revision: https://github.com/symfony/symfony/commit/239a022cc01cca52c3f6ddde3231199369cf34c2

## Requirements
PHP >= 5.6
Symfony 3.x
Redis extension (or alternatively one of the following: RedisArray / RedisCluster / Predis\Client / RedisProxy / RedisClusterProxy)

## Configuration
After installing the bundle, you have to configure proper services on your own to be able to use RedisSessionHandler.

At first, you have to define service for Redis connection. It can be done in `app/config/services.yml`. Configuration should look like the following:
```yaml
    redis_session_handler_connection:
        class: 'Redis' # RedisArray/RedisCluster/Predis\Client/RedisProxy/RedisClusterProxy are also supported
        calls:
            - method: connect
              arguments:
                  - 'host'
                  - 'port'
```

Then, you need to define a proper service for handler itself:
```yaml
    redis_session_handler:
        class: EzSystems\SymfonyTools\Symfony\Components\HttpFoundation\Session\Storage\Handler\RedisSessionHandler
        arguments:
            - '@redis_session_handler_connection'
```

After that, you can use newly defined session handler in the eZ Platform configuration. Typically it can be done in `app/config/default_parameters.yml`:
```yaml
ezplatform.session.handler_id: redis_session_handler
```