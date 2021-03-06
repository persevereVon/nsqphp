<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nsqlookupd addresses
    |--------------------------------------------------------------------------
    |
    | 服务发现地址，用于订阅，多个地址以逗号隔开
    |
    */
    'nsqlookupd_addrs' => explode(',', env('NSQLOOKUPDS', 'localhost:4161')),

    /*
    |--------------------------------------------------------------------------
    | Nsqd addresses
    |--------------------------------------------------------------------------
    |
    | Nsqd 实例地址，用于发布，多个地址以逗号隔开
    |
    */
    'nsqd_addrs' => explode(',', env('NSQDS', 'localhost:4150')),

    /*
    |--------------------------------------------------------------------------
    | 订阅类列表
    |--------------------------------------------------------------------------
    |
    | 所有需要启动的订阅类，需继承 Per3evere\Nsq\Subscribe 抽象类
    |
    */
    'subscribes' => [
        // App\Api\V1\Subscribes\SubscribeA::class,
        // App\Api\V1\Subscribes\SubscribeB::class,
    ],
];
