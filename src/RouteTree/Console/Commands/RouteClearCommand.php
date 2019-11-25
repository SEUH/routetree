<?php

namespace Webflorist\RouteTree\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\RouteCollection;
use Throwable;
use Webflorist\RouteTree\Domain\RegisteredRoute;

class RouteClearCommand extends \Illuminate\Foundation\Console\RouteClearCommand
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'routetree:route-clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Remove the route and routetree cache files";

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws Throwable
     */
    public function handle()
    {
        parent::handle();

        $this->files->delete(route_tree()->getCachedRouteTreePath());

        $this->info('RouteTree cache cleared!');
    }


}
