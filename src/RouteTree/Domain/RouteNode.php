<?php

namespace Webflorist\RouteTree\Domain;

use Closure;
use Webflorist\RouteTree\Domain\Traits\CanHaveParameterRegex;
use Webflorist\RouteTree\Domain\Traits\CanHaveSegments;
use Webflorist\RouteTree\Exceptions\ActionNotFoundException;
use Webflorist\RouteTree\Exceptions\NodeAlreadyHasChildWithSameNameException;
use Webflorist\RouteTree\Exceptions\NodeNotFoundException;
use Webflorist\RouteTree\Exceptions\UrlParametersMissingException;
use Webflorist\RouteTree\RouteTree;
use Webflorist\RouteTree\Services\RouteUrlBuilder;

class RouteNode
{

    use CanHaveSegments, CanHaveParameterRegex;

    /**
     * Parent node of this node.
     *
     * @var RouteNode|null
     */
    protected $parentNode = null;

    /**
     * Child nodes of this node.
     *
     * @var array
     */
    protected $childNodes = [];

    /**
     * Name of this node.
     * (e.g. 'team').
     *
     * @var string
     */
    protected $name = '';

    /**
     * Id of this node.
     * This is the name of all parents and this node, separated by dots.
     * (e.g. 'about.company.team')
     *
     * The Id gets set automatically.
     *
     * @var string
     */
    protected $id = '';

    /**
     * An associative array with the languages as keys and the full path to this node as values.
     *
     * Gets generated automatically
     *
     * @var array
     */
    protected $paths;

    /**
     * Should the path-segment of this node be inherited to it's children (default=true)?
     * This way a node can have it's own path (e.g. about/company),
     * but it's children will not have the 'company' in their paths (e.g. about/team instead of about/company/team).
     *
     * @var bool
     */
    protected $inheritSegment = true;

    /**
     * The namespace, controllers should be registered with.
     *
     * @var string|null
     */
    protected $namespace = '\App\Http\Controllers';

    /**
     * Array of RouteAction objects, this route-node should have.
     *
     * @var RouteAction[]
     */
    protected $actions = [];

    /**
     * The language-file-key to be used for auto-translation of normal page-content.
     *
     * Gets determined automatically.
     *
     * @var string
     */
    protected $contentLangFile = null;

    /**
     * If this route-node has a route-parameter,
     * the corresponding RouteParameter object is stored here.
     *
     * @var null|RouteParameter
     */
    public $parameter = null;

    public $isResourceChild = false;

    /**
     * Array of custom-data.
     *
     * @var \Callable[]|array[]|null
     */
    protected $data = [];

    /**
     * Array of middleware, actions of this node should be registered with.
     *
     * @var array
     */
    protected $middleware = [];

    /**
     * Array of middleware that should be inherited to child-nodes.
     *
     * @var array
     */
    protected $inheritMiddleware = [];

    /**
     * Array of inherited middleware that should be skipped by actions of this node.
     *
     * @var array
     */
    protected $skipMiddleware = [];

    /**
     * Disable prefixing of path with locale for this node and all child-nodes?
     *
     * @var bool
     */
    public $noLocalePrefix = false;

    /**
     * @var RouteResource
     */
    public $resource;

    /**
     * @var RoutePayload
     */
    public $payload;

    private $locales;

    /**
     * XML-Sitemap related data.
     *
     * @var SitemapUrl
     */
    public $sitemap;

    /**
     * RouteNode constructor.
     * @param string $name
     * @param RouteNode $parentNode
     * @param null $segment
     * @throws NodeAlreadyHasChildWithSameNameException
     */
    public function __construct(string $name, RouteNode $parentNode = null, $segment = null)
    {
        // Set the name of this node.
        $this->name = $name;

        // Set parentNode and overtake certain data from parent.
        if (!is_null($parentNode)) {
            $this->setParentNode($parentNode);
        }

        // Append the route-name to the id.
        $this->id .= $this->name;

        $this->locales = RouteTree::getLocales();

        // Sets the language-files location.
        $this->setLangFiles();

        // Set the path-segment(s).
        $this->segment($segment);

        $this->payload = new RoutePayload($this);
        $this->sitemap = new SitemapUrl($this);

        return $this;

    }

    /**
     * Adds a single middleware to this node.
     *
     * @param string $name Name of the middleware.
     * @param array $parameters Parameters the middleware should be called with.
     * @param bool $inherit Should this middleware be inherited to all child-nodes?
     * @return RouteNode
     */
    public function middleware(string $name, array $parameters = [], bool $inherit = true)
    {
        $this->middleware[$name] = $parameters;
        if ($inherit) {
            $this->inheritMiddleware[] = $name;
        }
        return $this;
    }

    /**
     * Skip an inherited middleware.
     *
     * @param string $name Name of the middleware.
     * @return RouteNode
     */
    public function skipMiddleware(string $name)
    {
        if (array_search($name, $this->skipMiddleware) === false) {
            $this->skipMiddleware[] = $name;
        }
        if (isset($this->middleware[$name])) {
            unset($this->middleware[$name]);
        }
        return $this;
    }

    /**
     * Get this RouteNode's middleware.
     *
     * @param bool $inheritOnly
     * @return array
     */
    public function getMiddleware(bool $inheritOnly = false)
    {
        $middleware = $this->middleware;
        if ($inheritOnly) {
            foreach ($middleware as $middlewareKey => $middlewareParameters) {
                if (array_search($middlewareKey, $this->inheritMiddleware) === false) {
                    unset($middleware[$middlewareKey]);
                }
            }
        }
        return $middleware;
    }

    /**
     * Get the namespace to be used for controllers.
     *
     * @return null|string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * Set the namespace to be used for controllers.
     *
     * If the $namespace starts with a backslash,
     * any inherited namespace is overwritten.
     *
     * Otherwise it is appended to the inherited namespace.
     *
     * @param null|string $namespace
     * @return RouteNode
     */
    public function namespace(string $namespace): RouteNode
    {
        if (substr($namespace, 0, 1) === '\\') {
            $this->namespace = $namespace;
        } else {
            $this->namespace .= '\\' . $namespace;
        }
        return $this;
    }

    /**
     * Sets the parent node of this node and overtakes certain data.
     *
     * @param RouteNode $parentNode
     * @throws NodeAlreadyHasChildWithSameNameException
     */
    protected function setParentNode(RouteNode $parentNode)
    {

        // Set the parent node.
        $this->parentNode = $parentNode;

        // Set this node as a child node of the parent.
        $this->parentNode->addChildNode($this);

        // Overtake id from parentNode into current node.
        if (strlen($this->parentNode->id) > 0) {
            $this->id = $this->parentNode->id . '.';
        }

        // Overtake namespace from parentNode into current node.
        if (strlen($this->parentNode->namespace) > 0) {
            $this->namespace = $this->parentNode->namespace;
        }

        // Overtake noLocalePrefix from parentNode into current node.
        if ($this->parentNode->noLocalePrefix === true) {
            $this->noLocalePrefix = true;
        }

        // Overtake middleware from parentNode into current node.
        foreach ($parentNode->getMiddleware(true) as $middlewareKey => $middlewareParameters) {
            if (array_search($middlewareKey, $this->skipMiddleware) === false) {
                $this->middleware($middlewareKey, $middlewareParameters, true);
            }
        }
    }

    public function resource(string $name, string $controller): RouteResource
    {
        $this->resource = new RouteResource($name, $controller, $this);
        return $this->resource;
    }

    public function isResource(): bool
    {
        return $this->resource instanceof RouteResource;
    }

    public function exceptLocales(array $exceptLocales)
    {
        foreach ($exceptLocales as $locale) {
            $localeKey = array_search($locale, $this->locales);
            if ($localeKey !== false) {
                unset($this->locales[$localeKey]);
            }
        }
        return $this;
    }

    public function onlyLocales(array $onlyLocales)
    {
        foreach ($this->locales as $localeKey => $locale) {
            if (array_search($locale, $onlyLocales) === false) {
                unset($this->locales[$localeKey]);
            }
        }
        return $this;
    }

    /**
     * Does this node have a parent node?
     *
     * @return RouteNode[]
     */
    public function hasParentNode()
    {

        return is_a($this->parentNode, RouteNode::class);
    }

    /**
     * Gets the parent node of this node.
     *
     * @return RouteNode|null
     */
    public function getParentNode()
    {

        return $this->parentNode;
    }

    /**
     * Gets an array of all hierarchical parent-nodes of this node
     * (with the root-node as the first element).
     *
     * @param bool $includeCurrent
     * @return RouteNode[]
     */
    public function getRootLineNodes(bool $includeCurrent=false)
    {

        $rootLineNodes = [];

        $this->accumulateParentNodes($rootLineNodes);

        $rootLineNodes = array_reverse($rootLineNodes);

        if ($includeCurrent) {
            array_push($rootLineNodes, $this);
        }

        return $rootLineNodes;
    }

    /**
     * Accumulate all parent-nodes up the hierarchy.
     *
     * @param $parentNodes
     */
    protected function accumulateParentNodes(&$parentNodes)
    {
        if (is_a($this->parentNode, RouteNode::class)) {
            array_push($parentNodes, $this->parentNode);
            $this->parentNode->accumulateParentNodes($parentNodes);
        }
    }

    /**
     * Does this node have a parameter?
     *
     * @return bool
     */
    public function hasParameter()
    {
        return !is_null($this->parameter);
    }

    /**
     * Returns an array of all RouteParameters used by this node or one of it's parents.
     *
     * @return RouteParameter[]
     */
    public function getRootLineParameters()
    {

        // Initialize the return-array.
        $parameters = [];

        // Get all parent nodes including the current node.
        $rootLineNodes = $this->getRootLineNodes(true);

        // For each node of the rootline, we check, if it is a parameter-node
        // and add it to $parameters.
        foreach ($rootLineNodes as $node) {
            if ($node->hasParameter()) {
                $parameters[$node->parameter->getName()] = $node->parameter;
            }
        }

        return $parameters;

    }

    /**
     * Tries to get the action of this node, that is currently lowest
     * in the hierarchy of root-line-actions (mostly relevant for resource actions).
     *
     * e.g. the edit action with path "/user/{user}/edit" is lower in the root-line
     * as the show action with "/user/{user}", which is again lower
     * as the index action with "/user".
     *
     * Returns false, if no action of the current node is currently in the rootline.
     *
     * @return RouteAction|bool
     * @throws NodeNotFoundException
     */
    public function getLowestRootLineAction()
    {

        $currentActionUrl = (string) route_tree()->getCurrentAction()->getUrl();

        $mostActiveRootLineAction = false;
        $mostActiveRootLineActionUrlLength = 0;
        foreach ($this->actions as $action) {
            $actionUrl = (string) $action->getUrl();
            $actionUrlLength = strlen($actionUrl);

            if ((strpos($currentActionUrl, $actionUrl) === 0) && (strlen($actionUrl) > $mostActiveRootLineActionUrlLength)) {
                $mostActiveRootLineAction = $action;
                $mostActiveRootLineActionUrlLength = $actionUrlLength;
            }
        }

        return $mostActiveRootLineAction;

    }

    /**
     * Get the name of this node.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $parameter
     * @return RouteParameter
     */
    public function parameter(string $parameter, bool $setSegment=true) : RouteParameter
    {
        $this->parameter = new RouteParameter($parameter, $this);
        if ($setSegment) {
            $this->segment("{".$parameter."}");
        }
        return $this->parameter;
    }

    /**
     * Set the language files of this node,
     * representing the hierarchical structure of it's parents as a folder-structure.
     */
    protected function setLangFiles()
    {
        $this->setContentLangFile();
    }

    /**
     * Set the location of the language-file to be used for the translation of page-content.
     */
    protected function setContentLangFile()
    {

        // Set the base-folder for localization-files as stated in the config.
        $this->contentLangFile = config('routetree.localization.base_folder') . '/';

        // We only have to replace dots with slashes in this node's id to get the rest.
        $this->contentLangFile .= str_replace('.', '/', $this->id);
    }

    /**
     * Setup the node using handed callback.
     *
     * @param Closure $callback
     * @return RouteNode
     */
    public function setUp(Closure $callback)
    {
        $callback($this);
        return $this;
    }

    /**
     * Register a new GET action with this RouteNode.
     *
     * @param string|callable $action
     * @param string|null $name Name of the action (defaults to method-name).
     * @return RouteAction
     */
    public function get($action, string $name = null)
    {
        return $this->addAction('get', $action, $name);
    }

    /**
     * Register a new POST action with this RouteNode.
     *
     * @param string|callable $action
     * @param string|null $name Name of the action (defaults to method-name).
     * @return RouteAction
     */
    public function post($action, string $name = null)
    {
        return $this->addAction('post', $action, $name);
    }

    /**
     * Register a new PUT action with this RouteNode.
     *
     * @param string|callable $action
     * @param string|null $name Name of the action (defaults to method-name).
     * @return RouteAction
     */
    public function put($action, string $name = null)
    {
        return $this->addAction('put', $action, $name);
    }

    /**
     * Register a new PATCH action with this RouteNode.
     *
     * @param string|callable $action
     * @param string|null $name Name of the action (defaults to method-name).
     * @return RouteAction
     */
    public function patch($action, string $name = null)
    {
        return $this->addAction('patch', $action, $name);
    }

    /**
     * Register a new DELETE action with this RouteNode.
     *
     * @param string|callable $action
     * @param string|null $name Name of the action (defaults to method-name).
     * @return RouteAction
     */
    public function delete($action, string $name = null)
    {
        return $this->addAction('delete', $action, $name);
    }

    /**
     * Register a new OPTIONS action with this RouteNode.
     *
     * @param string|callable $action
     * @return RouteAction
     */
    public function options($action, string $name = null)
    {
        return $this->addAction('options', $action);
    }

    /**
     * Register a new ANY action with this RouteNode.
     *
     * @param string|callable $action
     * @return RouteAction
     */
    public function any($action)
    {
        return $this->addAction('any', $action);
    }

    /**
     * Register a new GET action for a View Route with this RouteNode.
     *
     * @param string $view
     * @param array $data
     * @return RouteAction
     */
    public function view(string $view, array $data = [])
    {
        return $this->addAction('get', [
            'view' => $view,
            'data' => $data
        ]);
    }


    /**
     * Create a redirect from one URI to another.
     *
     * @param string $destination
     * @param int $status
     * @return RouteAction
     */
    public function redirect(string $destination, int $status = 302)
    {
        return $this->addAction('get', [
            'redirect' => $destination,
            'status' => $status
        ]);
    }

    /**
     * Create a permanent redirect from one URI to another.
     *
     * @param string $destination
     * @return RouteAction
     */
    public function permanentRedirect(string $destination)
    {
        return $this->redirect($destination, 301);
    }

    /**
     * Create a new child-node.
     *
     * @param string $name
     * @param Closure $callback
     * @return RouteNode
     * @throws NodeNotFoundException
     * @throws Exceptions\NodeAlreadyHasChildWithSameNameException
     * @throws NodeAlreadyHasChildWithSameNameException
     */
    public function child(string $name, Closure $callback)
    {
        return route_tree()->node($name, $callback, $this);
    }

    /**
     * Disable prefixing of path with locale for this node and all child-nodes.
     */
    public function noLocalePrefix()
    {
        $this->noLocalePrefix = true;
    }

    /**
     * Get all locales this RouteNode should be registered with.
     *
     * @return array
     */
    public function getLocales()
    {
        $locales = $this->locales;

        if (config('routetree.no_locale_prefix') || $this->noLocalePrefix) {
            return [config('app.locale')];
        }
        return $locales;
    }

    /**
     * Is this RouteNode registered in the stated language?
     *
     * @return array
     */
    public function hasLocale(string $locale) : bool
    {
        return array_search($locale, $this->getLocales()) !== false;
    }

    /**
     * Add a child-node.
     *
     * @param RouteNode $childNode
     * @throws NodeAlreadyHasChildWithSameNameException
     */
    protected function addChildNode(RouteNode $childNode)
    {
        if (isset($this->childNodes[$childNode->name])) {
            throw new NodeAlreadyHasChildWithSameNameException('RouteNode with ID "' . $this->id . '" already has a child named "' . $childNode->name . '".');
        }
        $this->childNodes[$childNode->name] = $childNode;
    }

    /**
     * Does this node have children?
     *
     * @return bool
     */
    public function hasChildNodes()
    {
        if (count($this->childNodes) > 0) {
            return true;
        }
        return false;
    }


    /**
     * Get array of all child-nodes.
     *
     * @return RouteNode[]
     */
    public function getChildNodes()
    {
        return $this->childNodes;
    }


    /**
     * Gets a specific child-node.
     *
     * @param string $nodeName
     * @return RouteNode
     */
    public function getChildNode($nodeName = '')
    {
        if ($this->hasChildNode($nodeName)) {
            return $this->childNodes[$nodeName];
        } else {
            return false;
        }
    }

    /**
     * Does this node have a specific child?
     *
     * @param string $nodeName
     * @return bool
     */
    public function hasChildNode($nodeName = '')
    {
        if (isset($this->childNodes[$nodeName])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get the Id of this node.
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the path for this node (for a specific language).
     * If no language is stated, the current locale is used.
     *
     * @param string $locale
     * @return string
     */
    public function getPath($locale = null)
    {
        // If no language is specifically stated, we use the current locale
        RouteTree::establishLocale($locale);

        return $this->paths[$locale];
    }

    /**
     * Get url to the most suitable action of this node,
     * using the following order: 'index' -> 'get' -> the first element of the action-array.
     *
     * @param array $parameters An associative array of [parameterName => parameterValue] pairs to be used for any route-parameters in the url (default=current route-parameters).
     * @param string $locale The language this url should be generated for (default=current locale).
     * @param bool $absolute Create absolute paths instead of relative paths (default=true/configurable).
     * @return RouteUrlBuilder
     * @throws ActionNotFoundException
     * @throws NodeNotFoundException
     */
    public function getUrl($parameters = null, $locale = null, $absolute = null) : RouteUrlBuilder
    {

        $action = $this->hasAction('index') ? 'index' :
            ($this->hasAction('get') ? 'get' :
                (count($this->actions) > 0 ? $this->actions[0]->getName() : null)
            );

        if ($action === null) {
            throw new ActionNotFoundException('Node with Id "' . $this->getId() . '" does not have any action to generate an URL to.');
        }

        return $this->getUrlByAction($action, $parameters, $locale, $absolute );
    }

    /**
     * Gets the url of a certain action of this node.
     *
     * @param string $action The action name (e.g. index|show|get|post|update,etc.)
     * @param array $parameters An associative array of [parameterName => parameterValue] pairs to be used for any route-parameters in the url (default=current route-parameters).
     * @param string $locale The language this url should be generated for (default=current locale).
     * @param bool $absolute Create absolute paths instead of relative paths (default=true/configurable).
     * @return RouteUrlBuilder
     * @throws ActionNotFoundException
     * @throws NodeNotFoundException
     */
    public function getUrlByAction($action, $parameters = null, $locale = null, $absolute = null) : RouteUrlBuilder
    {
        if ($this->hasAction($action)) {
            return $this->getAction($action)->getUrl($parameters, $locale, $absolute);
        }

        throw new ActionNotFoundException('Node with Id "' . $this->getId() . '" does not have the action "' . $action . '""');
    }

    /**
     * Checks, if the current node is active (optionally with the desired parameters).
     *
     * @param null $parameters
     * @return string
     */
    public function isActive($parameters = null)
    {

        // Check, if the current node is identical to this node.
        if (route_tree()->getCurrentNode() === $this) {

            // If no parameters are specifically requested, we immediately return true.
            if (is_null($parameters)) {
                return true;
            }

            // If a set of parameters should also be checked, we get the current route-parameters,
            // check if each one is indeed set, and return the boolean result.
            $currentParameters = \Route::current()->parameters();
            $allParametersSet = true;
            foreach ($parameters as $desiredParameterName => $desiredParameterValue) {
                if (!isset($currentParameters[$desiredParameterName]) || ($currentParameters[$desiredParameterName] !== $desiredParameterValue)) {
                    $allParametersSet = false;
                }
            }
            return $allParametersSet;
        }

        return false;
    }

    /**
     * Checks, if the current node is active (optionally with the desired parameters).
     *
     * @param null $parameters
     * @return string
     */
    public function nodeOrChildIsActive($parameters = null)
    {

        // Check, if this node is active.
        if ($this->isActive($parameters)) {
            return true;
        }

        if ($this->hasChildNodes()) {
            foreach ($this->getChildNodes() as $nodeName => $node) {
                if ($node->nodeOrChildIsActive($parameters)) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Get the possible parameter-values and their slugs, if this node is a parameter-node.
     *
     * @param string $language The language the values should be fetched for (default=current locale).
     * @return array
     */
    public function getValues($language = null)
    {
        return $this->getData('values', null, $language);
    }

    /**
     * Get the currently active raw (untranslated/unsluggified) parameter-value.
     *
     * @return string
     */
    public function getActiveValue()
    {

        if ($this->parameter->isActive()) {

            // We get the currently active value for the parameter of this node.
            $activeValue = \Route::current()->parameter($this->parameter->getName());

            return $activeValue;

        }

        return false;
    }

    /**
     * Adds a specific action to this node.
     *
     * @param string $method
     * @param \Closure|array|string|callable|null $action
     * @param string|null $name
     * @return RouteAction
     */
    protected function addAction(string $method, $action, ?string $name = null)
    {
        $routeAction = new RouteAction($method, $action, $this, $name);
        $this->actions[] = $routeAction;
        return $routeAction;
    }

    /**
     * Gets a specific action from this node by it's name.
     *
     * @param $action
     * @return RouteAction
     * @throws ActionNotFoundException
     */
    public function getAction(string $actionName) : RouteAction
    {
        foreach ($this->actions as $action) {
            if ($action->getName() === $actionName) {
                return $action;
            }
        }

        throw new ActionNotFoundException('Node with Id "' . $this->getId() . '" does not have an action with name "' . $actionName . '""');
    }

    /**
     * Gets all actions from this node.
     *
     * @return RouteAction[]
     */
    public function getActions()
    {
        return $this->actions;
    }

    /**
     * Removes a specific action from this node.
     *
     * @param string $actionName
     */
    public function removeAction(string $actionName)
    {
        foreach ($this->actions as $key => $action) {
            if ($action->getName() === $actionName) {
                unset($this->actions[$key]);
            }
        }
    }

    /**
     * Checks if a specific action is present in this node.
     *
     * @param $actionName
     * @return bool
     */
    public function hasAction(string $actionName) : bool
    {
        foreach ($this->actions as $action) {
            if ($action->getName() === $actionName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Automatically sets all path-segments, that have not yet specifically set.
     * It checks for each-language, if an auto-translation is set,
     * otherwise it uses the node-name as the path-segment.     *
     */
    protected function setAutoSegments()
    {

        // Set the translation key to be used for getting localized path-segments.
        $segmentTranslationKey = 'segment.' . $this->name;

        // Iterate through configured languages.
        foreach (RouteTree::getLocales() as $locale) {

            if (!isset($this->segments[$locale])) {

                // Standard path segment is the name of this route node.
                $pathSegment = $this->name;

                // If a auto-translation segment for this locale exists, we use that as path segment.
                $autoTranslatedSegment = $this->payload->performAutoTranslation($segmentTranslationKey, [], $locale);
                if ($autoTranslatedSegment !== false) {
                    $pathSegment = $autoTranslatedSegment;
                }

                $this->setSegmentForLanguage($pathSegment, $locale);

            }
        }
    }


    /**
     * Generates the full paths to be used for this node in all languages.
     *
     */
    protected function generateFullPaths()
    {
        $this->paths = [];
        foreach ($this->getLocales() as $locale) {
            $this->paths[$locale] = $this->compilePath($locale);
        }
    }

    /**
     * Generates the routes for all actions of this node and it's child-nodes.
     */
    public function generateRoutesOfNodeAndChildNodes()
    {

        // Make sure, paths for all languages are set.
        $this->setAutoSegments();

        // Generate the full-paths for this node.
        $this->generateFullPaths();

        if ($this->hasChildNodes()) {
            foreach ($this->getChildNodes() as $childNode) {
                $childNode->generateRoutesOfNodeAndChildNodes();
            }
        }

        $this->generateRoutes();

    }

    /**
     * Generate the routes of all actions for this node.
     */
    protected function generateRoutes()
    {
        if (count($this->actions) > 0) {
            foreach ($this->actions as $method => $routeAction) {
                $routeAction->generateRoutes($method);
            }
        }
    }

    /**
     * Sets, if the path-segment of this node should be inherited to it's children (default=true).
     *
     * @param boolean $inheritSegment
     * @return RouteNode
     */
    public function inheritSegment(bool $inheritSegment = true)
    {
        $this->inheritSegment = $inheritSegment;
        return $this;
    }

    /**
     * Returns the language-file to be used for the translation of page-content.
     *
     * @return string
     */
    public function getContentLangFile()
    {
        return $this->contentLangFile;
    }

    /**
     * @param $locale
     * @return string
     */
    protected function compilePath($locale): string
    {

        // Paths always start with locale....
        $segments = [$locale];

        // .... except no_locale_prefix is set via config or on this route.
        if (config('routetree.no_locale_prefix') || $this->noLocalePrefix) {
            $segments = [];
        }

        // Add segments of parent nodes.
        foreach ($this->getRootLineNodes() as $parentNode) {
            if ($parentNode->inheritSegment) {
                $parentNodeSegment = $parentNode->getSegment($locale);
                if (strlen($parentNodeSegment) > 0) {
                    $segments[] = $parentNodeSegment;
                }
            }
        }

        // If this is a resource child,
        // we append the show-action's
        // segment of the parent.
        if ($this->isResourceChild) {
            $segments[] = '{' . $this->parentNode->resource->getName() . '}';
        }

        // And finally the segment of $this RouteNode.
        $thisNodeSegment = $this->getSegment($locale);
        if (strlen($thisNodeSegment) > 0) {
            $segments[] = $thisNodeSegment;
        }

        return implode('/', $segments);

    }

    /**
     * Get the page title of this node (defaults to the ucfirst-ified node-name).
     *
     * @param array $parameters An associative array of [parameterName => parameterValue] pairs to be used for any route-parameters in the title-generation (default=current route-parameters).
     * @param string $locale The language the title should be fetched for (default=current locale).
     * @param string|null $forAction Will look for action-specific title (e.g. 'show', 'index', etc.), if stated.
     * @return string
     * @throws ActionNotFoundException
     */
    public function getTitle(?array $parameters = null, ?string $locale = null, ?string $forAction=null): string
    {
        $title = null;

        // For active nodes, we try getting the action-specific title automatically.
        if (is_null($forAction) && $this->isActive() && route_tree()->getCurrentAction() !== null) {
            $forAction = route_tree()->getCurrentAction()->getName();
        }

        // Get action-specific title, if requested.
        if (!is_null($forAction) && $this->hasAction($forAction)) {
            $title =  $this->getAction($forAction)->payload->get('title', $parameters, $locale);
        }

        // Get title payload.
        if (is_null($title)) {
            $title = $this->payload->get('title', $parameters, $locale);
        }

        if (is_string($title)) {
            return $title;
        }

        // Fallback for resources is to get the action specific default-title from the RouteResource,
        // if $forAction set.
        if (!is_null($forAction) && $this->isResource()) {
            return $this->resource->getActionTitle($forAction, $parameters, $locale);
        }

        // Per default we fall back to the upper-cased node-name.
        return ucfirst($this->getName());
    }

}
