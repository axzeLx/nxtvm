<?php

namespace App\Http\Middleware;

use App\Support\VcScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages under /vc/{vc}/…: resolves the selected vCenter from the URL. A list page whose {vc} is not valid
 * (deleted vCenter, "all" with a single vCenter) is redirected to the vCenter actually shown.
 * Detail pages keep their {vc}: it is the vCenter of the object (an unknown one ends in a 404).
 */
class ResolveVcScope
{
    public function __construct(private VcScope $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $key = (string) $this->scope->key();

        if ($request->isMethod('GET') && ! $request->routeIs('*.show') && $route->getName()
            && (string) $route->parameter('vc') !== $key) {
            return redirect()->route($route->getName(), ['vc' => $key] + $request->query());
        }

        return $next($request);
    }
}
