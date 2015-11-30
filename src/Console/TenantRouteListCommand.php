<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace Somnambulist\Tenancy\Console;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Controller;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class TenantRouteListCommand
 *
 * Copy of RouteListCommand, except it is tenant aware allowing the routes for the specified
 * tenant to be shown. Requires that Domain Tenancy is enabled as this is the only form that
 * uses domains to define the routes.
 *
 * @package    Somnambulist\Tenancy\Console
 * @subpackage Somnambulist\Tenancy\Console\TenantRouteListCommand
 * @author     Dave Redfern
 */
class TenantRouteListCommand extends AbstractTenantCommand
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'tenant:route:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all registered routes in the specified tenant';

    /**
     * An array of all the registered routes.
     *
     * @var \Illuminate\Routing\RouteCollection
     */
    protected $routes;

    /**
     * The table headers for the command.
     *
     * @var array
     */
    protected $headers = ['Domain', 'Method', 'URI', 'Name', 'Action', 'Middleware'];



    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        $this->resolveTenantRoutes($this->argument('domain'));

        if (count($this->routes) == 0) {
            return $this->error("The specified tenant does not have any routes.");
        }

        $this->displayRoutes($this->getRoutes());
    }

    /**
     * Compile the routes into a displayable format.
     *
     * @return array
     */
    protected function getRoutes()
    {
        $results = [];

        foreach ($this->routes as $route) {
            $results[] = $this->getRouteInformation($route);
        }

        if ($sort = $this->option('sort')) {
            $results = array_sort(
                $results,
                function ($value) use ($sort) {
                    return $value[$sort];
                }
            );
        }

        if ($this->option('reverse')) {
            $results = array_reverse($results);
        }

        return array_filter($results);
    }

    /**
     * Get the route information for a given route.
     *
     * @param  \Illuminate\Routing\Route $route
     *
     * @return array
     */
    protected function getRouteInformation(Route $route)
    {
        return $this->filterRoute(
            [
                'host'       => $route->domain(),
                'method'     => implode('|', $route->methods()),
                'uri'        => $route->uri(),
                'name'       => $route->getName(),
                'action'     => $route->getActionName(),
                'middleware' => $this->getMiddleware($route),
            ]
        );
    }

    /**
     * Display the route information on the console.
     *
     * @param  array $routes
     *
     * @return void
     */
    protected function displayRoutes(array $routes)
    {
        $this->table($this->headers, $routes);
    }

    /**
     * Get before filters.
     *
     * @param  \Illuminate\Routing\Route $route
     *
     * @return string
     */
    protected function getMiddleware($route)
    {
        $middlewares = array_values($route->middleware());

        $middlewares = array_unique(
            array_merge($middlewares, $this->getPatternFilters($route))
        );

        $actionName = $route->getActionName();

        if (!empty($actionName) && $actionName !== 'Closure') {
            $middlewares = array_merge($middlewares, $this->getControllerMiddleware($actionName));
        }

        return implode(',', $middlewares);
    }

    /**
     * Get the middleware for the given Controller@action name.
     *
     * @param  string $actionName
     *
     * @return array
     */
    protected function getControllerMiddleware($actionName)
    {
        Controller::setRouter($this->laravel['router']);

        $segments = explode('@', $actionName);

        return $this->getControllerMiddlewareFromInstance(
            $this->laravel->make($segments[0]),
            $segments[1]
        );
    }

    /**
     * Get the middlewares for the given controller instance and method.
     *
     * @param  \Illuminate\Routing\Controller $controller
     * @param  string                         $method
     *
     * @return array
     */
    protected function getControllerMiddlewareFromInstance($controller, $method)
    {
        $middleware = $this->router->getMiddleware();

        $results = [];

        foreach ($controller->getMiddleware() as $name => $options) {
            if (!$this->methodExcludedByOptions($method, $options)) {
                $results[] = Arr::get($middleware, $name, $name);
            }
        }

        return $results;
    }

    /**
     * Determine if the given options exclude a particular method.
     *
     * @param  string $method
     * @param  array  $options
     *
     * @return bool
     */
    protected function methodExcludedByOptions($method, array $options)
    {
        return (!empty($options['only']) && !in_array($method, (array)$options['only'])) ||
        (!empty($options['except']) && in_array($method, (array)$options['except']));
    }

    /**
     * Get all of the pattern filters matching the route.
     *
     * @param  \Illuminate\Routing\Route $route
     *
     * @return array
     */
    protected function getPatternFilters($route)
    {
        $patterns = [];

        foreach ($route->methods() as $method) {
            // For each method supported by the route we will need to gather up the patterned
            // filters for that method. We will then merge these in with the other filters
            // we have already gathered up then return them back out to these consumers.
            $inner = $this->getMethodPatterns($route->uri(), $method);

            $patterns = array_merge($patterns, array_keys($inner));
        }

        return $patterns;
    }

    /**
     * Get the pattern filters for a given URI and method.
     *
     * @param  string $uri
     * @param  string $method
     *
     * @return array
     */
    protected function getMethodPatterns($uri, $method)
    {
        return $this->router->findPatternFilters(
            Request::create($uri, $method)
        );
    }

    /**
     * Filter the route by URI and / or name.
     *
     * @param  array $route
     *
     * @return array|null
     */
    protected function filterRoute(array $route)
    {
        if (($this->option('name') && !Str::contains($route['name'], $this->option('name'))) ||
            $this->option('path') && !Str::contains($route['uri'], $this->option('path')) ||
            $this->option('method') && !Str::contains($route['method'], $this->option('method'))
        ) {
            return;
        }

        return $route;
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['method', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by method.'],
            ['name', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by name.'],
            ['path', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by path.'],
            ['reverse', 'r', InputOption::VALUE_NONE, 'Reverse the ordering of the routes.'],
            [
                'sort',
                null,
                InputOption::VALUE_OPTIONAL,
                'The column (host, method, uri, name, action, middleware) to sort by.',
                'uri'
            ],
        ];
    }
}