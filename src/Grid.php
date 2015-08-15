<?php namespace Reillo\Grid;

use Countable;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Contracts\JsonableInterface;
use Illuminate\Support\Contracts\ArrayableInterface;
use Reillo\Grid\Helpers\Utils;
use Reillo\Grid\Interfaces\GridRendererInterface;
use Reillo\Grid\Renderer\ListRenderer;

abstract class Grid implements ArrayableInterface, Countable, JsonableInterface {

    protected $page = 0;
    protected $perPage = 25;
    protected $perPageSelection = [5,10,25,50,100];

    /**
     * @var $query
     */
    protected $query;

    /**
     * Query to select
     *
     * @var mixed
     */
    protected $querySelect = '*';

    /**
     * @var $query \Illuminate\Pagination\Paginator
     */
    protected $paginator;

    /**
     * @var $totalCount int
     */
    protected $totalCount = 0;

    /**
     * @var $itemCollections Collection
     */
    protected $itemCollections = [];

    /**
     * @var $renderer GridRendererInterface
     */
    protected $renderer;

    /**
     * url fragment
     *
     * @var $fragment string
     */
    protected $fragment;

    /**
     * Create new instance of grid
     *
     */
    function __construct()
    {
        $this->page = Utils::config('page');
        $this->perPage = Utils::config('per_page');
        $this->perPageSelection = Utils::config('per_page_selection');
    }

    /**
     * Set Query Builder
     *
     * @param $query
     * @return $this
     */
    public function setQuery($query)
    {
        $this->query = $query;
        return $this;
    }

    /**
     * Return query builder
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Set columns to select
     *
     * @param $querySelect
     * @return $this
     */
    public function setQuerySelect($querySelect)
    {
        $this->querySelect = $querySelect;
        return $this;
    }

    /**
     * @return string|array
     */
    public function getQuerySelect()
    {
        return $this->querySelect;
    }

    /**
     * Set renderer handler
     *
     * @param GridRendererInterface $renderer
     * @return $this
     */
    public function setRenderer(GridRendererInterface $renderer)
    {
        $this->renderer = $renderer;

        return $this;
    }

    /**
     * get renderer
     *
     * @return GridRendererInterface
     */
    public function getRenderer()
    {
        return $this->renderer;
    }

    /**
     * Prepare Grid
     *
     * @return $this
     */
    public function prepareGrid()
    {
        $this->prepareQuery();
        $this->prepareFilters();
        $this->preparePagination();
        $this->prepareGridRenderer();

        return $this;
    }

    /**
     * Prepare query
     *
     * @return $this
     */
    abstract protected function prepareQuery();

    /**
     * Prepare query for pagination
     *
     * @return $this
     */
    protected function preparePagination()
    {
        $this->setTotalCount();
        $this->setQuerySortable();
        $this->setQueryOffset();
        $this->prepareItemCollection();

        return $this;
    }

    /**
     * set query sortable
     *
     * @return mixed
     */
    abstract protected function setQuerySortable();

    /**
     * Prepare filters
     *
     * @return mixed
     */
    abstract protected function prepareFilters();

    /**
     * Prepare the grid renderer
     *
     * @return $this
     */
    protected function prepareGridRenderer()
    {
        $this->getRenderer()
            ->setItems($this->getItemCollections())
            ->setGrid($this);

        return $this;
    }

    /**
     * Do render grid
     *
     * @return string
     */
    public function renderGrid()
    {
        return $this->getRenderer()->render();
    }

    /**
     * Set the total count of the result
     *
     * @return void
     */
    private function setTotalCount()
    {
        $this->totalCount = $this->query->count();
    }

    /**
     * This will just set the offset and limit of the query
     *
     * @return void
     */
    private function setQueryOffset()
    {
        // create paginator instance
        $this->setPaginator();

        $limit = $this->getPaginator()->getPerPage();
        $offset = $this->getPaginator()->getPerPage() * ($this->getPaginator()->getCurrentPage() - 1);

        $this->getQuery()->skip($offset)->take($limit);
    }

    /**
     * Execute query and set the item collections
     *
     * @return void
     */
    private function prepareItemCollection()
    {
        $this->getQuery()->select($this->getQuerySelect());

        // set item collections
        $this->itemCollections = $this->getQuery()->get();
        $this->getPaginator()->setItems($this->getItemCollections());
    }

    /**
     * Get the collections of items
     *
     * @return \Illuminate\Support\Collection
     */
    public function getItemCollections()
    {
        return $this->itemCollections;
    }

    /**
     * Get the total count of the query
     *
     * @return int
     */
    public function getTotalCount()
    {
        return $this->totalCount;
    }

    /**
     * Get the total count of the query
     *
     * @return int
     */
    public function count()
    {
        return $this->getTotalCount();
    }

    /**
     * Get Pagination Links
     *
     * @param string $view
     * @return mixed
     */
    public function getPagination($view = null)
    {
        return $this->getPaginator()->links($view);
    }

    /**
     * Set Paginator Instance
     *
     * @return void
     */
    protected function setPaginator()
    {
        $this->paginator = Paginator::make([], $this->getTotalCount(), $this->getPerPage());
        $this->paginator->fragment($this->fragment());

        // append paginator request
        $this->paginator->appends(Request::except('ajax'));
        $this->paginator->getFactory()->setCurrentPage($this->getPage());
    }

    /**
     * Get paginator instance
     *
     * @return \Illuminate\Pagination\Paginator
     */
    public function getPaginator()
    {
        return $this->paginator;
    }

    /**
     * Set per page
     *
     * @param int $perPage
     * @return $this
     */
    public function setPerPage($perPage)
    {
        $this->perPage = $perPage;
        return $this;
    }

    /**
     * Get per page
     *
     * @return int
     */
    public function getPerPage()
    {
        $per_page = Input::get('per_page', $this->perPage);

        if ($this->isValidPageNumber($per_page)) {
            return $per_page;
        }

        return $this->perPage;
    }

    /**
     * Set current page
     *
     * @param int $page
     * @return $this
     */
    public function setPage($page)
    {
        $this->page = $page;
        return $this;
    }

    /**
     * Get current page
     *
     * @return int
     */
    public function getPage()
    {
        return $this->page;
    }

    /**
     * set Per page selection
     *
     * @param int $perPageSelection
     * @return $this
     */
    public function setPerPageSelection($perPageSelection)
    {
        $this->perPageSelection = $perPageSelection;
        return $this;
    }

    /**
     * Get per page selection
     *
     * @return array
     */
    public function getPerPageSelection()
    {
        return $this->perPageSelection;
    }

    /**
     * Check if number is valid for paginator
     *
     * @param mixed $page
     * @return bool
     */
    public function isValidPageNumber($page)
    {
        return $page >= 1 && filter_var($page, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Create url for navigation
     *
     * @param array $parameters
     * @return string
     */
    public function createUrl(array $parameters = [])
    {
        $baseUrl = $this->getPaginator()->getFactory()->getCurrentUrl();

        // create and merge parameters
        $parameters = array_merge(Request::except('ajax'), $parameters);

        // build url query string
        $query_string = http_build_query($parameters, null, '&');
        $raw_param =  !empty($query_string) ? "?" . $query_string : null;

        return $baseUrl . $raw_param . $this->buildFragment();
    }

    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @param  string $fragment
     * @return $this|string
     */
    public function fragment($fragment = null)
    {
        if (is_null($fragment)) return $this->fragment;

        $this->fragment = $fragment;

        return $this;
    }

    /**
     * Build the full fragment portion of a URL.
     *
     * @return string
     */
    protected function buildFragment()
    {
        return $this->fragment ? '#'.$this->fragment : '';
    }

    /**
     * Get base url
     *
     * @return string
     */
    public function getBaseURL()
    {
        return $this->getPaginator()->getFactory()->getCurrentUrl();
    }

    /**
     * Set paginator target/base url
     *
     * @param string $url - The target url that the list will request or submitted to
     * @return $this
     */
    public function setBaseURL($url)
    {
        $this->getPaginator()->setBaseUrl($url);

        return $this;
    }

    /**
     * Add a query string value to the paginator.
     *
     * @param  string  $key
     * @param  string  $value
     * @return $this
     */
    public function addParameter($key, $value)
    {
        $this->getPaginator()->addQuery($key, $value);

        return $this;
    }

    /**
     * Render view and pass the grid instance
     *
     * @param $view
     * @return string
     */
    public function render($view)
    {
        return View::make($view)->with(['grid'=>$this])->render();
    }

    /**
     * Check if ajax request
     *
     * @return bool
     */
    public function isAjax()
    {
        return Request::ajax() && Input::get('ajax');
    }

    /**
     * Should have method to array
     *
     * @return mixed
     */
    abstract public function toArray();

    /**
     * Get the collection of items as JSON.
     *
     * @param  int  $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Create json response
     *
     * @return string
     */
    public function ajaxResponse()
    {
        return Response::json($this->toArray());
    }

}




