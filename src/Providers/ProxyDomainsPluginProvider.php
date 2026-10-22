<?php

namespace Julie\ProxyDomains\Providers;

use App\Models\Role;
use App\Models\Server;
use Exception;
use Illuminate\Support\ServiceProvider;
use Julie\ProxyDomains\Models\ProxyRoute;
use Julie\ProxyDomains\Services\ProxySyncService;

class ProxyDomainsPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        Role::registerCustomPermissions([
            'proxyRouter' => [
                'view',
                'update',
            ],
        ]);

        Role::registerCustomModelIcon('proxyRouter', 'tabler-route');
    }

    public function boot(): void
    {
        Server::resolveRelationUsing(
            'proxyRoute',
            fn (Server $server) => $server->hasOne(ProxyRoute::class, 'server_id', 'id')
        );

        // When a server is deleted, remove its route and sync the proxy config
        Server::deleted(function (Server $server) {
            try {
                $existing = ProxyRoute::where('server_id', $server->id)->first();
                $removedHost = $existing?->full_host;
                $removedBackend = $existing?->backend_address;

                $deleted = ProxyRoute::where('server_id', $server->id)->delete();
                if ($deleted > 0) {
                    app(ProxySyncService::class)->syncAllRoutes(
                        array_filter([$removedHost]),
                        array_filter([$removedBackend])
                    );
                }
            } catch (Exception $exception) {
                report($exception);
            }
        });
    }
}
