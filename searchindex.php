<?php

return
    [
        /*
         * The engine that powers the search index. You can choose between 'elasticsearch'
         * and 'algolia'.
         */
        'engine' => 'elasticsearch',

        'elasticsearch' =>
            [
                /*
                 * Specify the host(s) where elasticsearch is running.
                 */
                'hosts' =>
                    [
                        env('ELASTIC_HOST', 'localhost:9200')
                    ],

                /*
                 * Specify the path where Elasticsearch will write it's logs.
                 */
                'logPath' => storage_path() . '/logs/elasticsearch.log',

                /*
                 * Specify how verbose the logging must be
                 * Possible values are listed here
                 * https://github.com/Seldaek/monolog/blob/master/src/Monolog/Logger.php
                 *
                 */
                'logLevel' => 200,

                /*
                 * The name of the index elasticsearch will write to.
                 */
                'defaultIndexName' => 'main'
            ],
    ];