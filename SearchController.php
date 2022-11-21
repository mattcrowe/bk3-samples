<?php namespace TN\Experience\Controller\Api;

use Illuminate\Http\Request;
use Response;
use TN\Cms\Controller\BaseController;
use TN\Experience\Model\Category;
use TN\Experience\Service\Search\SearchService;

class SearchController extends BaseController
{

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $criteria = array_merge($request->query());

        $results = (new SearchService($criteria))->search();

        return Response::json($results);
    }

    /**
     * @param Request $request
     * @param $type
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $type, $id)
    {

        $types = [
            'adventures',
            'deals',
            'events',
            'places',
            'regions',
            'discover',
            'treks',
        ];

        if (!in_array($type, $types)) {
            return Response::json('invalid type', 422);
        }

        $request->merge([
            'id' => $id,
            'type' => $type,
        ]);

        $results = (new SearchService($request->query()))->search();

        try {
            $array = $results->items()[0]->toArray();
        } catch (\Exception $e) {
            $array = [];
        }

        return $array ? Response::json($array) : Response::json($array, 404);
    }

    public function category(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return Response::json('', 404);
        }

        $category->children;
        $category->files;

        return Response::json($category);
    }

}