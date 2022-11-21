<?php

namespace TN\Experience\Service\Search;

use SearchIndex;
use Session;
use TN\Cms\Helper\SpotHelper;
use TN\Cms\Model\Tag;
use TN\Experience\Model\Category;
use TN\Experience\Service\Search\Indices\ElasticIndices;
use TN\Location\Model\Region;

class ElasticEngine extends BaseEngine
{
    /**
     * @var mixed|string
     */
    public $index;

    /**
     * @var bool
     */
    public $strict = true;

    /**
     * @var string
     */
    public $needle;

    /**
     * @var array
     */
    public $config = [];

    /**
     * @var array
     */
    public $categories = [];

    /**
     * @var array
     */
    public $regions = [];

    /**
     * @var array
     */
    public $tags = [];

    /**
     * @var array
     */
    public $filter = [];

    /**
     * @var array
     */
    public $sort = [];

    /**
     * @var array
     */
    public $query = [];

    /**
     * @var array
     */
    public $functions = [];

    public function __construct($service)
    {
        parent::__construct($service);

        $this->index = (new ElasticIndices())->getActiveIndex($service->criteria);

        if ($this->debug('index')) {
            $this->msg(['index' => $this->index]);
        }
    }

    public function getConfig($key, $default = null)
    {
        return array_get($this->config, $key, $default);
    }

    public function setConfig($key, $value = null)
    {
        array_set($this->config, $key, $value);
    }

    public function reset()
    {
        $this->filter = [];
        $this->sort = [];
        $this->query = [
            'bool' => [
                'must' => [],
                'should' => [],
                'must_not' => [],
                'filter' => [],
            ],
        ];

        $this->strict = $this->criteria('strict');

        $this->needle = $this->criteria('q');

        $this->categories = $this->criteria('category') ?: $this->categories();

        $this->regions = $this->criteria('region') ?: $this->regions();

        $this->tags = $this->criteria('tag') ?: [];

        $this->config = [
            'needle' => [
                'strict' => false,
            ],
            'categories' => [
                'boost' => 2,
                'strict' => true,
            ],
            'regions' => [
                'boost' => 2,
                'strict' => true,
            ],
            'tags' => [
                'boost' => 2,
                'strict' => true,
            ],
        ];
    }

    public function search()
    {

        $mode = $this->service->mode ?: [];

        # default non-strict config, including child pages
        $configs = [
            ['regions.strict' => false], # all category.sub-category
        ];

        if (in_array('parent', $mode)) {
            # landing page
            if ($this->service->parentCategories->contains('slug', 'places-to-stay')) {
                $configs = [
                    ['categories.parents' => true], # all category in city
                    ['categories.parents' => true, 'regions.parents' => true], # all category in region
                    ['categories.parents' => true, 'regions.strict' => false], # all category
                ];
            }
        }

        if ($this->criteria('strict')) {
            $configs = [
                ['needle.strict' => true],
            ];
        }

        foreach ($configs as $config) {

            $this->reset();

            if (is_array($config)) {
                foreach ($config as $key => $value) {
                    $this->setConfig($key, $value);
                }
            }

            $this->buildQuery();
            $items = $this->getItems();
            if ($items) {
                return $items;
            }
        }

        return [];
    }

    public function buildQuery()
    {
        $this->applyNeedle();
        $this->applyCategories();
        $this->applyRegions();
        $this->applyTags();
        $this->applyDateRange();
        $this->applyPriority();
        $this->applyProximity();
    }

    public function categories()
    {

        $query['bool']['should']['multi_match'] = [
            'query' => $this->needle,
            'fields' => ['name.lower_case_sort^20', 'name^10', 'intro^5', 'body', 'meta_title', 'meta_keywords^5', 'meta_description^5'],
            'type' => 'best_fields',
            'tie_breaker' => 0.3,
        ];

        $results = SearchIndex::getResults([
            'index' => $this->index,
            'type' => 'categories',
            'body' => [
                'from' => 0,
                'size' => 3,
                'query' => $query
            ]
        ]);

        $ids = [];
        $hits = array_get($results, 'hits.hits', []);
        foreach ($hits as $hit) {
            if (array_get($hit, '_score', 0) > 0.5) {
                $ids[] = array_get($hit, '_id');
            }
        }

        return $this->service->categories($ids);
    }

    public function regions()
    {
        $query['bool']['should']['multi_match'] = [
            'query' => $this->needle,
            'fields' => ['name.lower_case_sort^20', 'name^10', 'intro', 'body', 'meta_title', 'meta_keywords', 'meta_description'],
            'type' => 'best_fields',
            'tie_breaker' => .5,
            'minimum_should_match' => '2<25%'
        ];

        $results = SearchIndex::getResults([
            'index' => $this->index,
            'type' => 'regions',
            'body' => [
                'from' => 0,
                'size' => 1,
                'query' => $query
            ]
        ]);

        $region = array_get($results, 'hits.hits.0');

        if ($region) {
            if (array_get($region, '_score', 0) > 0.25) {
                return $this->service->regions([array_get($region, '_id')]);
            }
        }

        return [];
    }

    public function minScore()
    {
        $min_score = $this->criteria('min_score') ?: 0;

        if (!$min_score && $this->needle && $this->getConfig('needle.strict')) {
            $min_score = .25;
        }

        return $min_score;
    }

    public function addProximity($params = [])
    {
        $lat = array_get($params, 'lat');
        $lng = array_get($params, 'lng');

        if ($lat && $lng) {

            $offset = array_get($params, 'distance_offset', $this->criteria('distance_offset'));
            $scale = array_get($params, 'distance_scale', $this->criteria('distance_scale'));
            $max = array_get($params, 'distance_max', $this->criteria('distance_max'));

            $this->service->criteria['lat'] = $lat;
            $this->service->criteria['lng'] = $lng;
            $this->service->criteria['distance_offset'] = $offset;
            $this->service->criteria['distance_scale'] = $scale;
            $this->service->criteria['distance_max'] = $max;
            $this->service->criteria['sort'] = '-relevancy';
        }

    }

    public function applyNeedle($params = [])
    {
        if (!$this->needle) {
            return;
        }

        $strict = $this->getConfig('needle.strict', true);

        $this->query['bool'][$strict ? 'must' : 'should'][] = [
            'multi_match' => [
                'query' => $this->needle,
                'fields' => ['name.lower_case_sort^20', 'name^10', 'intro^5', 'body', 'meta_title', 'meta_keywords^5', 'meta_description^5'],
                'type' => 'best_fields',
                'tie_breaker' => 0.3,
            ]
        ];
    }

    public function applyCategories($params = [])
    {
        $categories = $this->categories;

        if ($this->getConfig('categories.parents')) {
            $categories = $this->service->parentCategories->pluck('id')->all();
        }

        if (!$categories) {
            return;
        }

        $boost = $this->getConfig('categories.boost', 2);
        $strict = $this->getConfig('categories.strict', true);

        if (!$strict || !$this->criteria('category')) {
            $this->query['bool']['should'][] = ['terms' => ['categories' => $categories, 'boost' => $boost]];
        } else {
            $this->filter[]['bool']['must'][] = ['terms' => ['categories' => $categories]];
        }
    }

    public function applyRegions($params = [])
    {

        $regions = $this->regions;

        if ($this->getConfig('regions.parents')) {
            $regions = $this->service->regions->pluck('id')->all();
        }

        if (!$regions) {
            return;
        }

        $boost = $this->getConfig('regions.boost', 2);
        $strict = $this->getConfig('regions.strict', true);

        if (!$strict || !$this->criteria('region')) {

            $this->query['bool']['should'][] = ['terms' => ['region_id' => $regions, 'boost' => $boost]];

            if (count($regions) === 1 && $regionID = array_get($regions, 0)) {
                if ($region = Region::find($regionID)) {
                    $this->addProximity([
                        'lat' => $region->lat,
                        'lng' => $region->lng,
                        'distance_max' => null,
                    ]);
                }
            }

        } else {
            $this->filter[]['bool']['must'][] = ['terms' => ['region_id' => $regions]];
        }
    }

    public function applyTags($params = [])
    {
        if (!$this->tags) {
            return;
        }

        $boost = $this->getConfig('tags.boost', 2);
        $strict = $this->getConfig('tags.strict', true);

        if ($strict) {
            $this->filter[]['bool']['must'][] = ['terms' => ['tags' => $this->tags]];
        } else {
            $this->query['bool']['should'][] = ['terms' => ['tags' => $this->tags, 'boost' => $boost]];
        }
    }

    public function applyDateRange()
    {
        if (!$this->criteria('starts_at')) {
            return;
        }
        $this->filter[] = [
            'bool' => [
                'should' => [
                    # event.starts_at happens between posted date range
                    [
                        'range' => [
                            'starts_at' => [
                                'gte' => strtotime($this->criteria('starts_at')),
                                'lte' => strtotime($this->criteria('ends_at')),
                            ],
                        ],
                    ],
                    # event.ends_at happens between posted date range
                    [
                        'range' => [
                            'ends_at' => [
                                'gte' => strtotime($this->criteria('starts_at')),
                                'lte' => strtotime($this->criteria('ends_at')),
                            ]
                        ],
                    ],
                    # event has already started before posted starts_at
                    # && event stops after posted ends_at. in other words, it is
                    # happening the entire time during the posted date range
                    [
                        'bool' => [
                            'must' => [
                                [
                                    'range' => [
                                        'starts_at' => [
                                            'lte' => strtotime($this->criteria('starts_at')),
                                        ],
                                    ],
                                ],
                                [
                                    'range' => [
                                        'ends_at' => [
                                            'gte' => strtotime($this->criteria('ends_at')),
                                        ]
                                    ],
                                ],
                            ],
                        ],
                    ]
                ]
            ]
        ];
    }

    public function applyPriority()
    {
        if ($this->criteria('priority')) {
            $this->query['bool']['must'][] = ['terms' => ['priority' => [$this->criteria('priority')]]];
        }
    }

    public function applyProximity()
    {
        $lat = $this->criteria('lat');
        $lng = $this->criteria('lng');

        if ($lat && $lng) {

            $offset = $this->criteria('distance_offset');
            $scale = $this->criteria('distance_scale');

            $this->functions[] = [
                'gauss' => [
                    'location' => [
                        'origin' => ['lat' => $lat, 'lon' => $lng],
                        'offset' => $offset . 'mi',
                        'scale' => $scale . 'mi',
                    ]
                ],
                'weight' => 10,
            ];

            if ($max = $this->criteria('distance_max')) {
                $filter = [
                    'distance' => $max . 'mi',
                    'location' => [
                        'lat' => $lat,
                        'lon' => $lng,
                    ],
                ];
                $this->filter[]['bool']['must'][]['geo_distance'] = $filter;
            }

        }

    }

    public function applyFilters()
    {
        if ($this->filter) {
            $this->query['bool']['filter'] = $this->filter;
        }
    }

    public function applyFunctions()
    {
        if ($this->functions) {

            $min_score = $this->minScore();

            $query = $this->query;
            if (!$query['bool']['must']) {
                $query['bool']['must'] = ['match_all' => ['boost' => .99]];
                $min_score += 1.00;
            }

            $this->query = [
                'function_score' => [
                    'query' => $query,
                    'functions' => $this->functions,
                    'min_score' => $min_score,
                    'boost_mode' => 'sum',
                    //'score_mode' => 'sum',
                ]
            ];
        }
    }

    public function sort()
    {
        foreach (explode(',', $this->criteria('sort')) as $sort) {

            $prefix = substr($sort, 0, 1);
            $order = $prefix == '-' ? 'desc' : 'asc';
            $_sort = ltrim($sort, '-');

            if ($_sort == 'relevancy') {
                $this->sort['_score'] = ['order' => $order];
                continue;
            }

            if ($_sort == 'random') {
                $unique = crc32(Session::getId() ?: uniqid());
                $this->functions[] = [
                    'random_score' => [
                        'seed' => $unique,
                    ]
                ];
                continue;
            }

            if ($_sort == 'alpha') {
                $this->sort['name.keyword'] = ['order' => $order];
                continue;
            }

            $this->sort[$_sort] = ['order' => $order];
        }

    }

    public function getItems()
    {
        $this->applyFilters();
        $this->applyFunctions();
        $this->sort();

        $params = [
            'index' => $this->index,
            'type' => $this->criteria('type'),
            'body' => [
                'from' => $this->criteria('offset'),
                'size' => $this->criteria('limit'),
                'sort' => $this->sort,
                'query' => $this->query,
                'min_score' => $this->minScore(),
            ]
        ];

//        dump($this->service->criteria);
//        dump($params);
//        exit;

        $results = SearchIndex::getResults($params);

        $hits = array_get($results, 'hits.hits');

        if ($this->debug('params')) {
            $this->msg(['params' => $params]);
        }
        $this->setDebugMsg($hits);

        $this->service->total = array_get($results, 'hits.total');

        $items = [];
        foreach ($hits as $hit) {
            $items[] = [
                'id' => $hit['_id'],
                'type' => $hit['_type'],
                'score' => $hit['_score'],
            ];
        }

        return $items;
    }

    public function setDebugMsg($hits)
    {
        if ($this->debug('hit')) {

            $debug['hits'] = [];

            $categories = Category::all()->pluck('name', 'id')->all();
            $regions = Region::all()->pluck('name', 'id')->all();
            $tags = Tag::all()->pluck('name', 'id')->all();

            $fromLat = $this->criteria('lat');
            $fromLng = $this->criteria('lng');

            foreach ($hits as $hit) {

                $id = array_get($hit, '_id');
                $type = array_get($hit, '_type');
                $name = array_get($hit, '_source.name');
                $score = number_format(array_get($hit, '_score'), 2);
                $category_ids = array_get($hit, '_source.categories');
                $tag_ids = array_get($hit, '_source.tags');
                $region_ids = array_get($hit, '_source.region_id');
                $starts_at = array_get($hit, '_source.starts_at');
                $ends_at = array_get($hit, '_source.ends_at');
                $post_at = array_get($hit, '_source.post_at');
                $duration = array_get($hit, '_source.duration');
                $priority = array_get($hit, '_source.priority');
                $location = array_get($hit, '_source.location');

                $msg = [];

                if ($name) {
                    $msg['name'] = $name;
                }
                if ($type && $id) {
                    $msg['type'] = sprintf('%s:%s', $type, $id);
                }
                if ($score) {
                    $msg['score'] = $score;
                }
                if ($region_ids) {
                    $region = null;
                    if (!is_array($region_ids)) {
                        $msg['region'] = sprintf('%s: %s', $region_ids, $regions[$region_ids]);
                    } else {
                        foreach ($region_ids as $region_id) {
                            $msg['region'][] = sprintf('%s: %s', $region_id, $regions[$region_id]);
                        }
                    }
                }
                if ($category_ids) {
                    foreach ($category_ids as $category_id) {
                        $msg['categories'][] = sprintf('%s: %s', $category_id, $categories[$category_id]);
                    }
                }
                if ($tag_ids) {
                    foreach ($tag_ids as $tag_id) {
                        $msg['tags'][] = sprintf('%s: %s', $tag_id, $tags[$tag_id]);
                    }
                }
                if ($starts_at) {
                    $msg['starts'] = sprintf('%s (%s)', date('Y-m-d H:i:s', $starts_at), $starts_at);
                    $msg['ends__'] = sprintf('%s (%s)', date('Y-m-d H:i:s', $ends_at), $ends_at);
                    $msg['duration'] = $duration;
                }
                if ($post_at) {
                    $msg['post_at'] = date('Y-m-d H:i:s', $post_at);
                }
                if ($priority) {
                    $msg['priority'] = $priority;
                }
                if ($location) {
                    $msg['location'] = $location;
                    if ($fromLat && $fromLat) {
                        try {
                            $bits = explode(',', $location);
                            $toLat = trim($bits[0]);
                            $toLng = trim($bits[1]);
                            $distance = SpotHelper::haversineGreatCircleDistance($fromLat, $fromLng, $toLat, $toLng);
                            $msg['distance'] = number_format($distance, 2);
                        } catch (\Exception $e) {

                        }
                    }


                }

                $debug['hits'][] = $msg;

            }

            $this->msg($debug);

        }
    }

    public function removeFromIndex($hit)
    {
        try {
            $msg = sprintf('index: %s, type: %s, id: %s',
                $this->index,
                array_get($hit, 'type'),
                array_get($hit, 'id')
            );

            \TN\Cms\Helper\DebugHelper::debug($msg);

            if (env('APP_ENV') != 'local') {
                SearchIndex::getClient()->delete([
                    'index' => $this->index,
                    'type' => array_get($hit, 'type'),
                    'id' => array_get($hit, 'id'),
                ]);
            }

        } catch (\Exception $e) {

        }
    }

}