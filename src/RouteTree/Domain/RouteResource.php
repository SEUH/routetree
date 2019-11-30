<?php

namespace Webflorist\RouteTree\Domain;

use Closure;
use Illuminate\Support\Facades\Lang;
use Webflorist\RouteTree\Exceptions\NodeAlreadyHasChildWithSameNameException;
use Webflorist\RouteTree\Exceptions\NodeNotFoundException;

class RouteResource
{

    /**
     * The route-node this resource belongs to.
     *
     * @var RouteNode
     */
    protected $routeNode = null;

    /**
     * The controller-method to be used for actions of this resource.
     *
     * @var string
     */
    protected $controller = null;

    /**
     * The name of the resource.
     *
     * @var string
     */
    private $name;
    /**
     * @var RouteAction
     */
    private $index;
    /**
     * @var Traits\CanHaveSegments
     */
    private $create;

    /**
     * @var string
     */
    private $transKey;

    /**
     * RouteAction constructor.
     *
     * @param string $name
     * @param $controller
     * @param RouteNode $routeNode
     * @throws \Webflorist\RouteTree\Exceptions\NodeAlreadyHasChildWithSameNameException
     * @throws \Webflorist\RouteTree\Exceptions\NodeNotFoundException
     */
    public function __construct(string $name, $controller, $routeNode)
    {
        $this->name = $name;
        $this->controller = $controller;
        $this->routeNode = $routeNode;
        $this->transKey = 'Webflorist-RouteTree::routetree.resource';
        $this->routeNode->parameter($name, false);

        $this->setupActions();

        return $this;
    }

    /**
     * Get the name of this resource.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the translation-key for a resource.
     *
     * @param string $transKey
     * @return RouteResource
     */
    public function transKey(string $transKey)
    {
        $this->transKey = $transKey;
        return $this;
    }

    private function setupActions()
    {
        $controller = $this->controller;
        $paramSegment = '{' . $this->name . '}';

        $this->routeNode->get("$controller@index", 'index');
        $this->routeNode->get("$controller@create", 'create')->segment($this->getCreateActionSegments());
        $this->routeNode->post("$controller@store", 'store');
        $this->routeNode->get("$controller@show", 'show')->segment($paramSegment);
        $this->routeNode->get("$controller@edit", 'edit')->segment($this->getEditActionSegments());
        $this->routeNode->put("$controller@update", 'update')->segment($paramSegment);
        $this->routeNode->delete("$controller@destroy", 'destroy')->segment($paramSegment);
    }

    private function getCreateActionSegments()
    {
        $segments = LanguageMapping::create();
        foreach ($this->routeNode->getLocales() as $locale) {
            $translationKey = 'Webflorist-RouteTree::routetree.createPathSegment';
            $translationLocale = Lang::hasForLocale($translationKey, $locale) ? $locale : 'en';
            $segments->set($locale, __($translationKey, [], $translationLocale));
        }
        return $segments;
    }

    private function getEditActionSegments()
    {
        $paramSegment = '{' . $this->name . '}';
        $segments = LanguageMapping::create();
        foreach ($this->routeNode->getLocales() as $locale) {
            $translationKey = 'Webflorist-RouteTree::routetree.editPathSegment';
            $translationLocale = Lang::hasForLocale($translationKey, $locale) ? $locale : 'en';
            $segments->set($locale, $paramSegment . '/' . __($translationKey, [], $translationLocale));
        }
        return $segments;
    }

    public function only(array $actionsOnly)
    {
        foreach ($this->routeNode->getActions() as $routeAction) {
            if (array_search($routeAction->getName(), $actionsOnly) === false) {
                $this->routeNode->removeAction($routeAction->getName());
            }
        }
        return $this;
    }

    public function except(array $actionsExcept)
    {
        foreach ($this->routeNode->getActions() as $routeAction) {
            if (array_search($routeAction->getName(), $actionsExcept) !== false) {
                $this->routeNode->removeAction($routeAction->getName());
            }
        }
        return $this;
    }

    public function getActionTitle(string $actionName, ?array $parameters = null, ?string $locale = null)
    {
        $resourceSingular = trans_choice($this->transKey, 1, [], $locale);
        $resourcePlural = trans_choice($this->transKey, 2, [], $locale);
        switch ($actionName) {
            case 'create':
                return trans('Webflorist-RouteTree::routetree.createTitle', ['resource' => $resourceSingular], $locale);
            case 'show':
                return trans('Webflorist-RouteTree::routetree.showTitle', ['resource' => $resourceSingular], $locale);
            case 'edit':
                return trans('Webflorist-RouteTree::routetree.editTitle', ['resource' => $resourceSingular], $locale);
            default:
                return $resourcePlural;
        }
    }

    public function getActionNavTitle(string $actionName, ?array $parameters = null, ?string $locale = null)
    {
        switch ($actionName) {
            case 'create':
                return trans('Webflorist-RouteTree::routetree.createNavTitle', [], $locale);
            case 'show':
                return $this->routeNode->getActiveValue();
            case 'edit':
                return trans('Webflorist-RouteTree::routetree.editNavTitle', [], $locale);
            default:
                return $this->routeNode->payload->getNavTitle(null, $locale, false);
        }
    }

    public function model(string $class)
    {
        $this->routeNode->parameter->model($class);
        return $this;
    }

    public function routeKeys(array $routeKeys)
    {
        $this->routeNode->parameter->routeKeys($routeKeys);
        return $this;
    }

    /**
     * Create a new resource-child-node.
     *
     * @param string $name
     * @param Closure $callback
     * @return RouteNode
     * @throws NodeNotFoundException
     * @throws NodeAlreadyHasChildWithSameNameException
     */
    public function child(string $name, Closure $callback)
    {
        $child = $this->routeNode->child($name, $callback);
        $child->isResourceChild = true;
        return $child;
    }

}