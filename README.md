# ezsystems/symfony-tools
Collection of polyfill (backported) and incubator (proposed) features for Symfony.

Backports Symfony features so they can be used in earlier versions of Symfony, and 
proposed features improving Symfony further.

This bundle is first and foremost aiming to cover needs of [eZ Platform](https://ezplatform.com),
but is placed in own bundle under MIT as we think others can benefit and help collaborate, and
to simplify forward and backport ports to and from Symfony itself.

### Requriments

- Symfony 3.4
- PHP 7.1+ _(due to backported Symfony 4 code being written for PHP 7.1+)_

#### Semantic Versioning exception

Bundle follows [SemVer](https://semver.org/) with one exception:
- Incubator features are allowed to break BC also in Minor versions (x.Y.z), __when__ needed in order to align with
changes to the feature when it gets accepted to Symfony.


!! Tip:  As such if you rely on incubator features, make sure to require specific minor versions in composer, like
`~1.1.0` or `~1.1.2 || ~1.2.0`

### Features

**Polyfill (backport) features:**
- [Redis session handler](doc/RedisSessionHandler.md) _(for Symfony3, native in Symfony4)_

**Incubator (proposed) features**
- [NativeTagAwareAdapters](doc/NativeTagAwareAdapters.md)


### Contributing

Make sure as much as possible the feature is forward compatible for users, so when they upgrade to Symfony version where
it's included, they should ideally not need to adapt their code/config. _(see `Semantic Versioning exception` for how
this works for incubators)_

**Polyfill (Backports)**
When contributing Symfony backports to this bundle, be aware you commit to help maintain that feature in case there are
bug fixes or improvements to that feature in Symfony itself.

**Incubator (Proposed)**
Incubator features should only be proposed here if also proposed against Symfony itself, and there is at least some
certainty it will be accepted. And you also commit to adapt the feature here, if changes are requested once proposed to
Symfony. Essentially aiming for the feature here becoming a polyfill/backport feature in the end.

As such it's only applicable for smaller features _(e.g. new cache adapter(s))_, not a complete new component or larger
changes across Symfony itself for instance.

### License

[The MIT License](LICENSE).
