<?php

namespace TN\Experience\Service\Search;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\Yaml\Yaml;
use TN;
use TN\Cms\Helper\MorphHelper;
use TN\Cms\Model\Tag;
use TN\Experience\Model\Category;
use TN\Location\Model\Region;

/**
 * Class SearchService
 * @package TN\Experience\Repo
 *
 * @resources
 *
 *  https://laracasts.com/discuss/channels/general-discussion/looking-for-a-search-engine-for-my-laravel-app?page=1
 *  https://murze.be/2015/01/using-elasticsearch-in-laravel/
 *  https://github.com/spatie/searchindex
 *  https://github.com/fideloper/Vaprobash/blob/master/scripts/elasticsearch.sh
 *  https://www.elastic.co/guide/en/elasticsearch/client/php-api/current/_search_operations.html
 *  http://okfnlabs.org/blog/2013/07/01/elasticsearch-query-tutorial.html
 *  https://www.elastic.co/blog/found-beginner-troubleshooting
 */
class SearchService
{

    /**
     * @var boolean|mixed
     */
    public $debug = ''; //category|hits|region|criteria|params

    /**
     * @var array
     */
    public $msg = [];

    /**
     * @var array
     */
    private $defaults = [
        'strict' => true,
        'q' => '',
        'page' => 1,
        'limit' => 12,
        'sort' => '-relevancy',
        'dir' => 'desc',
        'type' => 'adventures,deals,events,places,regions,discover,treks,posts,insiders',
        'min_score' => null,
        'priority' => null,
        'tag' => [],
        'starts_at' => null,
        'ends_at' => null,
        'id' => null,
        'adventure_id' => null,
        'dealable_id' => null,
        'dealable_type' => null,
        'partner_id' => null,
        'category' => [],
        'region' => [],

        // proximity
        'lat' => null,
        'lng' => null,
        'distance_offset' => null,
        'distance_scale' => null,
        'distance_max' => null,
    ];

    /**
     * @var array
     */
    private $sortable_by = [
        'relevancy',
        'popularity',
        'alpha',
        'random',
        'id',
        'post_at',
        'starts_at',
        'priority',
        'duration',
    ];

    /**
     * @var int
     */
    public $total = 0;

    /**
     * @var array
     */
    public $criteria;

    /**
     * @var string
     */
    public $default;

    /**
     * @var BaseEngine[]
     */
    public $engines;

    /**
     * @var Collection|Region[]
     */
    public $regions;

    /**
     * @var Collection|Region[]
     */
    public $cities;

    /**
     * @var Collection|Category[]
     */
    public $parentCategories;

    /**
     * @var Collection|Category[]
     */
    public $childCategories;

    /**
     * @var Collection|Tag[]
     */
    public $tags;

    /**
     * @var array
     */
    public $mode = [];

    public function __construct($criteria = [])
    {
        $this->regions = new Collection();
        $this->cities = new Collection();
        $this->parentCategories = new Collection();
        $this->childCategories = new Collection();
        $this->tags = new Collection();

        $this->debug = array_get($criteria, 'debug', env('ELASTIC_DEBUG'));

        $this->setCriteria($criteria);

        $this->default = array_get($criteria, 'engine', env('SEARCH_ENGINE_DEFAULT', 'local'));

        $this->engines['local'] = new LocalEngine($this);

        $this->engines['elastic'] = new ElasticEngine($this);
    }

    /**
     * @param null $key
     * @return BaseEngine
     */
    public function engine($key = null)
    {
        return $key ? $this->engines[$key] : $this->engines[$this->default];
    }

    public function setCriteria($criteria)
    {
        $input = $criteria;

        # reformat / fix
        if (isset($criteria['sort'])) {
            $criteria['sort'] = strtolower($criteria['sort']);
        }
        if (isset($criteria['data-type'])) {
            $criteria['type'] = $criteria['data-type'];
            unset($criteria['data-type']);
        }
        if (isset($criteria['types'])) {
            $criteria['type'] = $criteria['types'];
            unset($criteria['types']);
        }
        if (isset($criteria['start-date'])) {
            $criteria['starts_at'] = $criteria['start-date'];
            unset($criteria['start-date']);
        }
        if (isset($criteria['end-date'])) {
            $criteria['ends_at'] = $criteria['end-date'];
            unset($criteria['end-date']);
        }
        if (isset($criteria['region_id'])) {
            $criteria['region'] = $criteria['region_id'];
            unset($criteria['region_id']);
        }
        if (isset($criteria['city'])) {
            $criteria['region'] = $criteria['city'];
            unset($criteria['city']);
        }
        if (isset($criteria['region'])) {
            $criteria['location'] = $criteria['region'];
            unset($criteria['region']);
        }
        if (isset($criteria['perPage'])) {
            $criteria['limit'] = $criteria['perPage'];
            unset($criteria['perPage']);
        }

        # merge
        $criteria = array_merge($this->defaults, $criteria);

        # set
        if ($mode = array_get($input, 'mode')) {
            $this->mode = explode(',', $mode);
        }

        if (!$criteria['page'] || !is_numeric($criteria['page']) || $criteria['page'] < 1) {
            $criteria['page'] = $this->defaults['page'];
        }

        if (!$criteria['limit'] || !is_numeric($criteria['limit']) || $criteria['limit'] < 1) {
            $criteria['limit'] = $this->defaults['limit'];
        }

        if (!$criteria['sort']) {
            $criteria['sort'] = $this->defaults['sort'];
        }
//        if (!$criteria['q'] && $criteria['sort'] == '-relevancy') {
//            $criteria['sort'] = '-popularity';
//        }

        $sorts = [];
        foreach (explode(',', $criteria['sort']) as $sort) {
            $_sort = ltrim($sort, '-');
            if (in_array($_sort, $this->sortable_by)) {
                $sorts[] = $sort;
            }
            $criteria['sort'] = implode(',', $sorts);
        }

        if (array_get($input, 'starts_at') || array_get($input, 'ends_at')) {
            $criteria['starts_at'] = date('Y-m-d 00:00:00', strtotime(array_get($input, 'starts_at', 'now')));
            $criteria['ends_at'] = date('Y-m-d 23:59:59', strtotime(array_get($input, 'ends_at', '+1 year')));
            if (strtotime($criteria['starts_at']) >= strtotime($criteria['ends_at'])) {
                $criteria['ends_at'] = date('Y-m-d 23:59:59', strtotime($criteria['starts_at']) + 365 * 24 * 60 * 60);
            }
        }

        if (array_get($input, 'region')) {
            $criteria['region'] = $this->regions($input['region']);
        }

        if (array_get($input, 'category')) {
            $criteria['category'] = $this->categories($input['category']);
        }

        if (array_get($input, 'tag')) {
            $criteria['tag'] = $this->tags($input['tag']);
        }

        $criteria['offset'] = $criteria['page'] * $criteria['limit'] - $criteria['limit'];

        if ($criteria['q']) {
            $criteria['q'] = str_replace(["'", '"'], ' ', urldecode($criteria['q']));
            $criteria['q'] = preg_replace('/\s+/', ' ', $criteria['q']);
            $criteria['q'] = preg_replace("/[^0-9a-zA-Z ]/", '', $criteria['q']);
        }

        if (array_get($input, 'tag') == 'event' && !$criteria['q'] && !$criteria['starts_at']) {
            $starts_at = array_get($input, 'starts_at', 'now') ?: 'now';
            $ends_at = array_get($input, 'ends_at', '+1 year') ?: '+1 year';
            $criteria['starts_at'] = date('Y-m-d 00:00:00', strtotime($starts_at));
            $criteria['ends_at'] = date('Y-m-d 23:59:59', strtotime($ends_at));
        }

        if (array_get($input, 'lat') && array_get($input, 'lng')) {
            $criteria['lat'] = array_get($input, 'lat');
            $criteria['lng'] = array_get($input, 'lng');
        }

        $criteria['distance_offset'] = array_get($input, 'distance_offset', env('ELASTIC_DEFAULT_DISTANCE_OFFSET', 0));
        $criteria['distance_scale'] = array_get($input, 'distance_scale', env('ELASTIC_DEFAULT_DISTANCE_SCALE', 75));
        $criteria['distance_max'] = array_get($input, 'distance_max', env('ELASTIC_DEFAULT_DISTANCE_MAX', 75));

        if (isset($input['strict'])) {
            $criteria['strict'] = $input['strict'];
        }

        if ($this->debug('criteria')) {
            $this->msg['criteria'] = $criteria;
        }

        $this->criteria = $criteria;

        return $this;
    }

    public function categories($terms)
    {

        $criteria = [];

        $ids = is_array($terms) ? $terms : explode(',', $terms);

        $categories = $explicit_categories = Category::whereIn('id', $ids)->orWhereIn('slug', $ids)->get(['id', 'parent_id', 'slug']);

        foreach ($categories as $category) {
            if ($category->parent_id) {
                $this->parentCategories->put($category->parent_id, $category->parent);
                $this->childCategories->put($category->id, $category);
            } else {
                $this->parentCategories->put($category->id, $category);
                foreach ($category->children as $child) {
                    if (!$this->childCategories->has($child->id)) {
                        $this->childCategories->put($child->id, $child);
                    }
                }
            }
        }

        $categories = $this->childCategories->count() ? $this->childCategories : $categories;

        if ($categories->count()) {
            foreach ($categories as $category) {
                if ($this->debug('category')) {
                    $this->msg['categories'][] = sprintf('%s: %s', $category->id, $category->slug);
                }
                if (!in_array($category->id, $criteria)) {
                    $criteria[] = $category->id;
                }
                foreach ($category->children as $child) {
                    if ($this->debug('category')) {
                        $this->msg['categories'][] = sprintf('%s: %s', $child->id, $child->slug);
                    }
                    if (!in_array($child->id, $criteria)) {
                        $criteria[] = $child->id;
                    }
                    if (!$this->childCategories->has($child->id)) {
                        $this->childCategories->put($child->id, $child);
                    }
                }
            }
        }

        /**
         * Add missing categories
         */
        if ($this->mode('categories')) {
            foreach ($explicit_categories as $category) {
                if (!in_array($category->id, $criteria)) {
                    if ($this->debug('category')) {
                        $this->msg['categories'][] = sprintf('%s: %s', $category->id, $category->slug);
                    }
                    $criteria[] = $category->id;
                }
            }
        }

        return $criteria;
    }

    public function regions($terms)
    {

        $criteria = [];

        $ids = is_array($terms) ? $terms : explode(',', $terms);

        $locations = Region::whereIn('id', $ids)->orWhereIn('slug', $ids)->get([
            'id',
            'parent_id',
            'slug',
            '_lft',
            '_rgt',
            'nlat',
            'slat',
            'wlng',
            'elng'
        ]);

        foreach ($locations as $location) {
            if ($location->parent_id) {
                $this->cities->put($location->id, $location);
            } else {
                $this->regions->put($location->id, $location);
            }
        }

        $locations = $this->cities->count() ? $this->cities : $locations;

        if ($locations->count()) {
            foreach ($locations as $location) {
                if ($this->debug('region')) {
                    $this->msg['regions'][] = sprintf('%s: %s', $location->id, $location->slug);
                }
                if (!in_array($location->id, $criteria)) {
                    $criteria[] = $location->id;
                }
                foreach ($location->children as $city) {
                    if ($this->debug('region')) {
                        $this->msg['regions'][] = sprintf('%s: %s', $city->id, $city->slug);
                    }
                    if (!in_array($city->id, $criteria)) {
                        $criteria[] = $city->id;
                    }
                    if (!$this->cities->has($city->id)) {
                        $this->cities->put($city->id, $city);
                    }
                }
            }
        }

        return $criteria;
    }

    public function tags($terms)
    {

        $criteria = [];

        $ids = is_array($terms) ? $terms : explode(',', $terms);

        $tags = Tag::whereIn('id', $ids)->orWhereIn('slug', $ids)->get(['id', 'slug']);

        if ($tags->count()) {
            foreach ($tags as $tag) {
                if ($this->debug('tag')) {
                    $this->msg['tags'][] = sprintf('%s: %s', $tag->id, $tag->slug);
                }
                if (!in_array($tag->id, $criteria)) {
                    $criteria[] = $tag->id;
                }
            }
        }

        $this->tags = $tags;

        return $criteria;
    }

    public function search()
    {
        if (isset($this->criteria['id'])) {
            $results = $this->engine('local')->find($this->criteria);
        }

        if (isset($this->criteria['adventure_id'])) {
            $results = $this->engine('local')->adventurePoints($this->criteria['adventure_id']);
        }

        if (isset($this->criteria['dealable_id']) && isset($this->criteria['dealable_type'])) {
            $results = $this->engine('local')->deals($this->criteria['dealable_id'], $this->criteria['dealable_type']);
        }

        if (isset($this->criteria['partner_id']) && $partner_id = $this->criteria['partner_id']) {
            $results = $this->engine('local')->partnered($partner_id);
        }

        if (!isset($results)) {
            $results = $this->engine()->search();
        }

        if ($this->debug('print') && $this->msg) {
            echo Yaml::dump($this->msg, 99);
        }

        $hits = $this->paginate($results);

        return $hits;
    }

    public function paginate($hits)
    {

        $items = new Collection();

        foreach ($hits as $hit) {

            $morphClass = MorphHelper::abbr2Class($hit['type']);

            $item = $morphClass::find($hit['id']);

            if ($item) {
                $item->indexable_type = $morphClass;
                $item->score = array_get($hit, 'score');
                $item->imagesrc = $item->image->src('teaser');

                if ($morphClass == 'TN\Experience\Model\Place') {
                    $item->lat = $item->getLatAttribute();
                    $item->lng = $item->getLngAttribute();
                }

                if ($item instanceof TN\Experience\Behavior\HasCategories\HasCategoriesInterface) {
                    $item->categories;
                }

                if ($item instanceof TN\Cms\Behavior\Fileable\FileableInterface) {
                    $item->files;
                }

                # add regions data
                if ($item instanceof TN\Location\Behavior\HasRegions\HasRegionsInterface) {
                    $item->regions;
                } elseif (method_exists($item, 'region')) {
                    $item->regions = new Collection([$item->region]);
                }

                $items->add($item);
            } else {
                $this->engine()->removeFromIndex($hit);
            }

        }

        return $this->getPaginator($items);
    }

    public function getPaginator($items)
    {
        $items = new LengthAwarePaginator($items, $this->total, $this->criteria['limit'], $this->criteria['page']);

        $items->appends($this->criteria);

        return $items;
    }

    public function debug($key)
    {
        $conditions = explode('|', $this->debug);

        return in_array($key, $conditions) ?: in_array('all', $conditions);
    }

    public function criteria($key)
    {
        $value = array_get($this->criteria, $key);

        if ($value === 'false') {
            $value = false;
        }

        if ($value === 'true') {
            $value = true;
        }

        return $value;
    }

    public function strict($key)
    {
        if ($this->criteria('strict')) {
            return true;
        }

        if ($key == 'regions') {
            return $this->parentCategories->contains('slug', 'places-to-stay');
        }

        if ($key == 'categories') {
            return true;
        }
    }

    public function mode($key)
    {
        return in_array($key, $this->mode);
    }

}