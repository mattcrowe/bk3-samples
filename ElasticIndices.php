<?php

namespace TN\Experience\Service\Search\Indices;

use Cache;
use Illuminate\Support\Facades\Storage;
use SearchIndex;
use Symfony\Component\Yaml\Yaml;
use TN;

class ElasticIndices
{
    public $errors = [];

    /* @var \Illuminate\Filesystem\FilesystemAdapter */
    private $disk;

    /* @var \Elasticsearch\Namespaces\IndicesNamespace */
    private $conn;

    public function __construct()
    {
        $this->disk = Storage::disk('local');

        $this->conn = SearchIndex::getClient()->indices();
    }

    public function getActiveIndex($criteria = [])
    {

        $index = env('ELASTIC_DEFAULT_INDEX');

        $index = $index ?: Cache::get('elastic-active-index');

        if (!$index) {
            $index = sprintf('%s_ying', env('APP_ENV'));
            Cache::put('elastic-active-index', $index, strtotime('+1 year'));
        }

        $index = isset($criteria['index']) ? $criteria['index'] : $index;

        return $index;
    }

    public function getInactiveIndex()
    {
        $index = $this->getActiveIndex();

        if (str_contains($index, 'yang')) {
            $other_index = sprintf('%s_ying', env('APP_ENV'));
        } else {
            $other_index = sprintf('%s_yang', env('APP_ENV'));
        }

        return $other_index;
    }

    public function toggleActiveIndex()
    {
        $new_index = $this->getInactiveIndex();

        Cache::put('elastic-active-index', $new_index, strtotime('+1 year'));

        return $new_index;
    }

    private function configPath($index)
    {

        $path = "config/elastic/$index.yml";

        $path = str_replace(['_ying', '_yang'], '', $path);

        return $path;
    }

    private function yamlDump($contents)
    {
        return (new Yaml())->dump($contents, 10);
    }

    private function yamlParse($from)
    {
        return (new Yaml())->parse($this->disk->get($this->configPath($from)));
    }

    public function copyConfig($from, $to)
    {
        $this->disk->copy($this->configPath($from), $this->configPath($to));
    }

    public function pullConfig($from, $to = null)
    {
        $to = $to ?: $from;

        try {
            $settings = $this->conn->getSettings(['index' => $from]);
            $mappings = $this->conn->getMapping(['index' => $from]);
        } catch (\Exception $e) {
            $this->errors($e);
        }

        if (isset($settings, $mappings)) {

            array_set($settings, "$from.settings.analysis", array_get($settings, "$from.settings.index.analysis"));
            array_forget($settings, "$from.settings.index.analysis");

            $contents = [
                'settings' => array_get($settings, "$from.settings"),
                'mappings' => array_get($mappings, "$from.mappings"),
            ];

            $this->disk->put($this->configPath($to), $this->yamlDump($contents));
        }
    }

    public function pushConfig($from, $to = null)
    {
        $to = $to ?: $from;

        $content = $this->yamlParse($from);

        try {
            $this->conn->delete(['index' => $to]);
        } catch (\Exception $e) {

        }

        try {

            $this->conn->create([
                'index' => $to,
                'body' => [
                    'number_of_replicas' => array_get($content, "settings.index.number_of_replicas", 1),
                    'refresh_interval' => array_get($content, "settings.index.refresh_interval", 0),
                    'analysis' => array_get($content, "settings.analysis", []),
                ]
            ]);

            $mappings = array_get($content, 'mappings', []);

            foreach ($mappings as $type => $mapping) {
                $this->conn->putMapping([
                    'index' => $from,
                    'type' => $type,
                    'body' => $mapping
                ]);
            }

        } catch (\Exception $e) {
            $this->errors($e);
        }

    }

    public function resetIndex($to)
    {
        $this->pushConfig($to, $to);
    }

    public function deleteIndex($to)
    {
        try {
            $this->conn->delete(['index' => $to]);
        } catch (\Exception $e) {
            $this->errors($e);
        }
    }

    public function errors($e)
    {

        dump($e->getMessage());

        $error = array_get(json_decode($e->getMessage(), true), 'error');
        $this->errors[] = [
            'type' => array_get($error, 'type'),
            'reason' => array_get($error, 'reason'),
        ];
    }

}