# NSQPHP

PHP client for [NSQ](https://github.com/nsqio/nsq).

该客户端针对 Laravel 框架做了一些事情。

### NSQ basics

You can read all about NSQ via the [readme on Github](https://github.com/nsqio/nsq),
or via the [Bitly blog post](http://word.bitly.com/post/33232969144/nsq)
describing it. More details on nsqd, nsqlookupd are provided within
each folder within the project.

Here's some thing I have learned:

  - Clustering is provided only in the sense that nsqlookupd will discover
    which machines hosts messages for a particular topic. To consume from a
    cluster, you simply ask an instance of `nslookupd` where to find messages
    and then connect to every `nsqd` it tells you to (this is one of the things
    that makes nsq good).
  - HA (for pub) is easy due to the fact that each `nsqd` instance is isolated;
    you can simply connect to _any_ and send publish your message (I have built
    this into the client).
  - Resilience is provided by simply writing to more than one `nsqd` and then
    de-duplicating on subscribe (I have built this into the client).
  - nsq is not designed as a _work queue_ (for long running tasks) out of the
    box. The default setting of `msg-timeout` is 60,000ms (60 seconds). This is
    the time before nsq will automatically consider a message to have failed
    and hence requeue it. Our "work" should take much less time than this.
    Additionally, PHP is a blocking language, and although we are using a
    non-blocking IO event loop, any work you do to process a message will
    block the client from being able to reply to any heartbeats etc.


### Installation

`nsqphp` is available to add to your project via composer. Simply add the
following to your composer.json.

    {
        ...
        "require": {
            ...
            "per3evere/nsqphp": "dev-master"
        }
        ...
    }

You can also simply clone it into your project:

    git clone git://github.com/persevereVon/nsqphp.git

To use `nsqphp` in your projects, just setup autoloading via composer. The design lends itself to a dependency injection container (all dependencies are constructor injected), although you can just
setup the dependencies manually when you use it.

### Testing it out

Follow the [getting started guide](https://nsq.io/overview/quick_start.html)
to install nsq on localhost.

Publish some events:

    php cruft/test-pub.php 10

Fire up a subscriber in one shell:

    php cruft/test-sub.php mychannel > /tmp/processed-messages

Then tail the redirected STDOUT in another shell, so you can see the messages
received and processed:

    tail -f /tmp/processed-messages

#### Note

In these tests I'm publishing _first_ since I haven't yet got the client to
automatically rediscover which nodes have messages for a given topic; hence
if you sub first, there are no nodes found with messages for the topic.


### Other tests

#### Multiple channels

The blog post describes a channel:

  | Each channel receives a copy of all the messages for a topic. In
  | practice, a channel maps to a downstream service consuming a topic.

So each message in a `topic` will be delivered to each `channel`.

Fire up two subscribers with different channels (one in each shell):

    php cruft/test-sub.php mychannel
    php cruft/test-sub.php otherchannel

Publish some messages:

    php cruft/test-pub.php 10

Each message will be delivered to each channel. It's also worth noting that
the API allows you to subscribe to multiple topics/channels within the same
process.


#### Multiple nsqds

Setup a bunch of servers running `nsqd` and `nsqlookupd` with hostnames
`nsq1`, `nsq2` ... Now publish a bunch of messages to both:

    php cruft/test-pub.php 10 nsq1
    php cruft/test-pub.php 10 nsq2

Now subscribe:

    php cruft/test-sub.php mychannel > /tmp/processed-messages

You will receive 20 messages.


#### Resilient delivery

Same test as before, but this time we deliver the _same message_ to two `nsqd`
instances and then de-duplicate on subscribe.

    php cruft/test-pub.php 10 nsq1,nsq2
    php cruft/test-sub.php mychannel > /tmp/processed-messages

This time you should receive **only 10 messages**.


### To do

  - Requeue failed messages using a back-off strategy (currently only simple
    fixed-delay requeue strategy)
  - Continuously re-evaluate which nodes contain messages for a given topic
    (that is subscribed to) and establish new connections for those clients
    (via event loop timer)


## The PHP client interface

### Messages

Messages are encapsulated by the Per3evere\Nsq\Message\Message class and are referred
to by interface within the code (so you could implement your own).

Interface:

    public function getPayload();
    public function getId();
    public function getAttempts();
    public function getTimestamp();

### Publishing

The client supports publishing to N `nsqd` servers, which must be specified
explicitly by hostname. Unlike with subscription, there is no facility to
lookup the hostnames via `nslookupd` (and we probably wouldn't want to anyway
for speed).

Minimal approach:

```php
    // 原生的方式
    $nsq = new Per3evere\Nsq\nsqphp;
    $nsq->publishTo('localhost')
        ->publish('mytopic', new Per3evere\Nsq\Message\Message('some message payload'));

    // Laravel 方式
    app('nsq')->publish('mytopic', new Per3evere\Nsq\Message\Message('some message payload'));
```
It's up to you to decide if/how to encode your payload (eg: JSON).

HA publishing:

```php
    $nsq = new Per3evere\Nsq\nsqphp;
    $nsq->publishTo(array('nsq1', 'nsq2', 'nsq3'), Per3evere\Nsq\nsqphp::PUB_QUORUM)
        ->publish('mytopic', new Per3evere\Nsq\Message\Message('some message payload'));
```

We will require a quorum of the `publishTo` nsqd daemons to respond to consider
this operation a success (currently that happens in series). This is assuming
I have 3 `nsqd`s running on three hosts which are contactable via `nsq1` etc.

This technique is going to log messages twice, which will require
de-duplication on subscribe.

### Subscribing

The client supports subscribing from N `nsqd` servers, each of which will be
auto-discovered from one or more `nslookupd` servers. The way this works is
that `nslookupd` is able to provide a list of auto-discovered nodes hosting
messages for a given topic. This feature decouples our clients from having
to know where to find messages.

So when subscribing, the first thing we need to do is initialise our
lookup service object:
```php
    $lookup = new Per3evere\Nsq\Lookup\Nsqlookupd;
```
Or alternatively:
```php
    $lookup = new Per3evere\Nsq\Lookup\Nsqlookupd('nsq1,nsq2');
```
We can then use this to subscribe:
```php
    $lookup = new Per3evere\Nsq\Lookup\Nsqlookupd;
    $nsq = new Per3evere\Nsq\nsqphp($lookup);
    $nsq->subscribe('mytopic', 'somechannel', function($msg) {
        echo $msg->getId() . "\n";
        })->run();
```
**Warning: if our callback were to throw any Exceptions, the messages would
not be retried using these settings - read on to find out more.**

Or a bit more in the style of PHP (?):
```php
    $lookup = new Per3evere\Nsq\Lookup\Nsqlookupd;
    $nsq = new Per3evere\Nsq\nsqphp($lookup);
    $nsq->subscribe('mytopic', 'somechannel', 'msgCallback')
        ->run();

    function msgCallback($msg)
    {
        echo $msg->getId() . "\n";
    }
```

We can also subscribe to more than one channel/stream:

```php
    $lookup = new Per3evere\Nsq\Lookup\Nsqlookup;
    $nsq = new Per3evere\Nsq\nsqphp($lookup);
    $nsq->subscribe('mytopic', 'somechannel', 'msgCallback')
        ->subscribe('othertopic', 'somechannel', 'msgCallback')
        ->run();
```

### Laravel 方式订阅

由于订阅处理可能有很多，但是放到一个文件不是很合理。我们可以建立一个代码目录存放订阅类，该订阅类继承 `Per3evere\Nsq\Subscribe`，`topic` 属性对应订阅的主题，`channel` 属性对应订阅的频道，`callback` 方法对应回调方法。

比如现在有两个订阅需求 `SubscribeA`，`SubscribeB`，首先建立这两个文件:

`app/Api/V1/Subscribes/SubscribeA.php`:

```php
<?php

namespace App\Api\V1\Subscribes;

use Per3evere\Nsq\Subscribe;
use Per3evere\Nsq\Message\Message;

class SubscribeA extends Subscribe
{
    /**
     * 订阅的主题.
     *
     * @var string
     */
    protected $topic = 'test';

    /**
     * 订阅的频道.
     *
     * @var string
     */
    protected $channel = 'ch';

    /**
     * 监听消息回调处理
     *
     * @return void
     */
    public function callback(Message $msg)
    {
        var_dump($msg);
    }
}
```

`app/Api/V1/Subscribes/SubscribeB.php`:

```php
<?php

namespace App\Api\V1\Subscribes;

use Per3evere\Nsq\Subscribe;
use Per3evere\Nsq\Message\Message;

class SubscribeB extends Subscribe
{
    /**
     * 订阅的主题.
     *
     * @var string
     */
    protected $topic = 'test';

    /**
     * 订阅的频道.
     *
     * @var string
     */
    protected $channel = 'ch';

    /**
     * 监听消息回调处理
     *
     * @return void
     */
    public function callback(Message $msg)
    {
        var_dump($msg);
    }
}
```

然后需要在 `nsq.php` 配置文件中填写配置项：

```php
    /*
    |--------------------------------------------------------------------------
    | 订阅类列表
    |--------------------------------------------------------------------------
    |
    | 所有需要启动的订阅类，需继承 Per3evere\Nsq\Subscribe 抽象类
    |
    */
    'subscribes' => [
        App\Api\V1\Subscribes\SubscribeA::class,
        App\Api\V1\Subscribes\SubscribeB::class,
    ],
```

最后直接执行 `php artisan nsq`，监听程序就开始正常执行了。


### Retrying failed messages

默认采取 `Per3evere\Nsq\RequeueStrategy\FixedDelay` 策略，最多尝试 5 次，每次延迟 2 秒。

The PHP client will catch any thrown Exceptions that happen within the
callback and then either (a) retry, or (b) discard the messages. Usually you
won't want to discard the messages.

To fix this, we need a **requeue strategy** - this is in the form of any
object that implements `Per3evere\Nsq\RequeueStrategy\RequeueStrategyInterface`:

```php
    public function shouldRequeue(MessageInterface $msg);
```

The client currently ships with one; a fixed delay strategy:

```php
    $requeueStrategy = new Per3evere\Nsq\RequeueStrategy\FixedDelay;
    $lookup = new Per3evere\Nsq\Lookup\Nsqlookupd;
    $nsq = new Per3evere\Nsq\nsqphp($lookup, NULL, $requeueStrategy);
    $nsq->subscribe('mytopic', 'somechannel', 'msgCallback')
        ->run();

    function msgCallback($msg)
    {
        if (rand(1,3) == 1) {
            throw new \Exception('Argh, something bad happened');
        }
        echo $msg->getId() . "\n";
    }
```

### De-duplication on subscribe

Recall that to achieve HA we simply duplicate on publish into
two different `nsqd` servers. To perform de-duplication we simply need to
supply an object that implements `Per3evere\Nsq\Dedupe\DedupeInterface`.

```php
    public function containsAndAdd($topic, $channel, MessageInterface $msg);
```

The PHP client ships with two mechanisms for de-duplicating messages on subscribe.
Both are based around [the opposite of a bloom filter](http://www.davegardner.me.uk/blog/2012/11/06/stream-de-duplication/).
One maintains a hash map as a PHP array (and hence bound to a single
process); the other calls out to Memcached and hence can share the data
structure between many processes.

We can use this thus:

```php
    $requeueStrategy = new Per3evere\Nsq\RequeueStrategy\FixedDelay;
    $dedupe = new Per3evere\Nsq\Dedupe\OppositeOfBloomFilterMemcached;
    $lookup = new Per3evere\Nsq\Lookup\Nsqlookupd;
    $nsq = new Per3evere\Nsq\nsqphp($lookup, $dedupe, $requeueStrategy);
    $nsq->subscribe('mytopic', 'somechannel', 'msgCallback')
        ->run();

    function msgCallback($msg)
    {
        if (rand(1,3) == 1) {
            throw new \Exception('Argh, something bad happened');
        }
        echo $msg->getId() . "\n";
    }
```

You can [read more about de-duplication on my blog](http://www.davegardner.me.uk/blog/2012/11/06/stream-de-duplication/),
however it's worth keeping the following in mind:

  - With Memcached de-duplication we can then happily launch N processes to
    subscribe to the same topic and channel, and only process the messages once.
  - De-duplication is not guaranteed (in fact far from it) - the implementations
    shipped are based on a lossy hash map, and hence are probabilistic in how
    they will perform. For events fed down at a similar time, they will usually
    perform acceptably (and they can be tuned to trade off memory usage for
    de-duplication abilities)
  - nsq is designed around the idea of idempotent subscribers - eg: your
    subscriber **must** be able to cope with processing a duplicated message
    (writing into Cassandra is an example of a system that copes well with
    executing something twice).


### Logging

The final optional dependency is a logger, in the form of some object that
implements `Per3evere\Nsq\Logger\LoggerInterface` (there is no standard logger
interface shipped with PHP to the best of my knowledge):

```php
    public function error($msg);
    public function warn($msg);
    public function info($msg);
    public function debug($msg);
```

The PHP client ships with a logger that dumps all logging information to STDERR.
Putting all of this together we'd have something similar to the `test-sub.php`
file:

```php
    $requeueStrategy = new Per3evere\Nsq\RequeueStrategy\FixedDelay;
    $dedupe = new Per3evere\Nsq\Dedupe\OppositeOfBloomFilterMemcached;
    $lookup = new Per3evere\Nsq\Lookup\Nsqlookupd;
    $logger = new Per3evere\Nsq\Logger\Stderr;
    $nsq = new Per3evere\Nsq\nsqphp($lookup, $dedupe, $requeueStrategy, logger);
    $nsq->subscribe('mytopic', 'somechannel', 'msgCallback')
        ->run();

    function msgCallback($msg)
    {
        if (rand(1,3) == 1) {
            throw new \Exception('Argh, something bad happened');
        }
        echo $msg->getId() . "\n";
    }
```

## Design log

  - main client based on event loop (powered by React PHP) to allow us to
    handle multiple connections to multiple `nsqd` instances

