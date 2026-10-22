<?php

namespace Julie\ProxyDomains\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Julie\ProxyDomains\Enums\ProxyType;
use Julie\ProxyDomains\Models\ProxyRoute;
use Symfony\Component\Yaml\Yaml;

class ProxySyncService
{
    public function __construct(
        protected DaemonFileRepository $fileRepository
    ) {}

    /**
     * Checks if a given server is eligible to have a proxy domain configured.
     */
    public static function isEligibleBackendServer(Server $server): bool
    {
        $proxyServerId = (int) config('proxy-domains.proxy_server_id');

        // Exclude the proxy server itself
        if ($proxyServerId > 0 && $server->id === $proxyServerId) {
            return false;
        }

        $egg = $server->egg;
        if (!$egg) {
            return true;
        }

        $eggName = strtolower($egg->name ?? '');
        $tags = array_map('strtolower', $egg->tags ?? []);

        // Exclude known proxy eggs so they don't show the backend subdomain menu
        foreach (['velocity', 'gate', 'bungeecord', 'waterfall', 'proxy'] as $proxyKeyword) {
            if (str_contains($eggName, $proxyKeyword) || in_array($proxyKeyword, $tags, true)) {
                return false;
            }
        }

        // If configured to only allow Minecraft servers, check tags or egg name
        if ((bool) config('proxy-domains.only_minecraft', true)) {
            $isMinecraft = in_array('minecraft', $tags, true)
                || str_contains($eggName, 'minecraft')
                || str_contains($eggName, 'paper')
                || str_contains($eggName, 'spigot')
                || str_contains($eggName, 'purpur')
                || str_contains($eggName, 'forge')
                || str_contains($eggName, 'fabric')
                || str_contains($eggName, 'neoforge')
                || str_contains($eggName, 'folia')
                || str_contains($eggName, 'vanilla');

            if (!$isMinecraft && !empty($tags)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Query builder / collection helper to only list Gate Proxy or Velocity servers in the Admin Panel.
     *
     * @return array<int, string>
     */
    public static function getAvailableProxyServers(): array
    {
        $servers = Server::with(['egg', 'node', 'allocation'])->get();

        $proxyCandidates = $servers->filter(function (Server $server) {
            $egg = $server->egg;
            if (!$egg) {
                return false;
            }

            $name = strtolower($egg->name ?? '');
            $tags = array_map('strtolower', $egg->tags ?? []);
            $image = strtolower($server->image ?? '');
            $serverName = strtolower($server->name ?? '');

            foreach (['gate', 'velocity', 'proxy', 'bungeecord', 'waterfall'] as $keyword) {
                if (
                    str_contains($name, $keyword)
                    || in_array($keyword, $tags, true)
                    || str_contains($image, $keyword)
                    || str_contains($serverName, $keyword)
                ) {
                    return true;
                }
            }

            return false;
        });

        // Fallback: if no server specifically has gate/velocity in its name/egg yet, return all servers so admin is not stuck
        $list = $proxyCandidates->isNotEmpty() ? $proxyCandidates : $servers;

        return $list->mapWithKeys(function (Server $server) {
            $eggName = $server->egg?->name ?? 'Server';
            $address = $server->allocation?->address ?? 'No IP';

            return [$server->id => "{$server->name} ({$eggName} - {$address})"];
        })->toArray();
    }

    /**
     * Synchronizes all routes from the database to the selected Proxy server's configuration file.
     *
     * @throws Exception
     */
    public function syncAllRoutes(array $removedHosts = [], array $removedBackends = []): void
    {
        $proxyServerId = config('proxy-domains.proxy_server_id');
        if (!$proxyServerId) {
            throw new Exception(trans('proxy-domains::strings.errors.no_proxy_selected'));
        }

        /** @var Server|null $proxyServer */
        $proxyServer = Server::with(['node', 'allocation'])->find($proxyServerId);
        if (!$proxyServer) {
            throw new Exception(trans('proxy-domains::strings.errors.proxy_not_found'));
        }

        $proxyType = ProxyType::tryFrom((string) config('proxy-domains.proxy_type', 'gate')) ?? ProxyType::Gate;
        $configPath = trim((string) config('proxy-domains.config_path', $proxyType->getDefaultConfigPath()));
        if ($configPath === '') {
            $configPath = $proxyType->getDefaultConfigPath();
        }

        $routes = ProxyRoute::with(['server.allocation'])->get();

        if ($proxyType === ProxyType::Gate) {
            $this->syncGateConfig($proxyServer, $configPath, $routes, $removedHosts, $removedBackends);
        } else {
            $this->syncVelocityConfig($proxyServer, $configPath, $routes);
        }
    }

    /**
     * Updates Gate Proxy config.yml (Lite mode routes).
     * Gate automatically watches config.yml and hot-reloads routes without restarting.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ProxyRoute>  $routes
     *
     * @throws Exception
     */
    protected function syncGateConfig(Server $proxyServer, string $configPath, $routes, array $removedHosts = [], array $removedBackends = []): void
    {
        $fileRepo = $this->fileRepository->setServer($proxyServer);

        // Only a missing file counts as "empty". Any other error aborts so we never overwrite a config we could not read.
        $existingContent = '';
        try {
            $existingContent = (string) $fileRepo->getContent($configPath);
        } catch (FileNotFoundException) {
            $existingContent = '';
        }

        $parsed = [];
        if (trim($existingContent) !== '') {
            try {
                $parsed = Yaml::parse($existingContent);
            } catch (\Throwable $e) {
                throw new Exception("Unable to read {$configPath} (invalid YAML), no changes made: " . $e->getMessage());
            }

            if (!is_array($parsed)) {
                throw new Exception("The file {$configPath} could not be parsed, no changes made.");
            }

            // Safety backup before touching anything.
            try {
                $fileRepo->putContent($configPath . '.bak', $existingContent);
            } catch (\Throwable) {
                // Backup is best effort.
            }
        }

        if (!isset($parsed['config']) || !is_array($parsed['config'])) {
            $parsed['config'] = [];
        }

        if (!isset($parsed['config']['bind'])) {
            $port = $proxyServer->allocation?->port ?? 25565;
            $parsed['config']['bind'] = '0.0.0.0:' . $port;
        }

        if (!isset($parsed['config']['lite']) || !is_array($parsed['config']['lite'])) {
            $parsed['config']['lite'] = [];
        }

        $parsed['config']['lite']['enabled'] = true;

        $baseDomain = strtolower(ltrim((string) config('proxy-domains.base_domain', 'pelican.dev'), '.'));
        $suffix = '.' . $baseDomain;

        // Routes the plugin wants to write (only those with a usable backend).
        $managed = [];
        $managedHosts = [];
        $managedBackends = [];
        foreach ($routes as $route) {
            $backend = $route->backend_address;
            if (!$backend) {
                continue;
            }

            $managed[] = [
                'host' => $route->full_host,
                'backend' => $backend,
            ];
            $managedHosts[] = strtolower($route->full_host);
            $managedBackends[] = (string) $backend;
        }

        $removeHosts = array_map('strtolower', array_filter(array_map('strval', $removedHosts)));
        $removeBackends = array_filter(array_map('strval', $removedBackends));

        // Incremental update: never drop an existing route unless it is replaced by one of ours,
        // or was explicitly removed. Routes already in the file always survive.
        $preserved = [];
        $existingRoutes = $parsed['config']['lite']['routes'] ?? [];
        if (is_array($existingRoutes)) {
            foreach ($existingRoutes as $existing) {
                if (!is_array($existing)) {
                    $preserved[] = $existing;
                    continue;
                }

                $host = strtolower((string) ($existing['host'] ?? ''));
                $backend = (string) ($existing['backend'] ?? '');

                if ($host !== '' && (in_array($host, $managedHosts, true) || in_array($host, $removeHosts, true))) {
                    continue;
                }

                // Same backend under the plugin's domain = stale host of a renamed/removed subdomain.
                if ($backend !== '' && $host !== '' && str_ends_with($host, $suffix)
                    && (in_array($backend, $managedBackends, true) || in_array($backend, $removeBackends, true))) {
                    continue;
                }

                $preserved[] = $existing;
            }
        }

        $parsed['config']['lite']['routes'] = array_values(array_merge($preserved, $managed));

        $newYaml = Yaml::dump($parsed, 8, 2);

        $fileRepo->putContent($configPath, $newYaml);
    }

    /**
     * Updates Velocity velocity.toml ([servers] and [forced-hosts]) and sends 'velocity reload'.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ProxyRoute>  $routes
     *
     * @throws Exception
     */
    protected function syncVelocityConfig(Server $proxyServer, string $configPath, $routes): void
    {
        $fileRepo = $this->fileRepository->setServer($proxyServer);

        try {
            $content = $fileRepo->getContent($configPath);
        } catch (FileNotFoundException) {
            throw new Exception("The file {$configPath} could not be found on the Velocity server.");
        }

        $serverLines = [];
        $forcedHostLines = [];

        foreach ($routes as $route) {
            $backend = $route->backend_address;
            if (!$backend) {
                continue;
            }

            $serverKey = 'srv-' . strtolower($route->subdomain);
            $host = $route->full_host;

            $serverLines[] = "{$serverKey} = \"{$backend}\"";
            $forcedHostLines[] = "\"{$host}\" = [\"{$serverKey}\"]";
        }

        $serversBlock = "# BEGIN PELICAN PROXY DOMAINS SERVERS\n"
            . implode("\n", $serverLines) . ($serverLines ? "\n" : '')
            . "# END PELICAN PROXY DOMAINS SERVERS";

        $forcedHostsBlock = "# BEGIN PELICAN PROXY DOMAINS FORCED HOSTS\n"
            . implode("\n", $forcedHostLines) . ($forcedHostLines ? "\n" : '')
            . "# END PELICAN PROXY DOMAINS FORCED HOSTS";

        // Inject or replace in [servers]
        if (preg_match('/# BEGIN PELICAN PROXY DOMAINS SERVERS.*?# END PELICAN PROXY DOMAINS SERVERS/s', $content)) {
            $content = (string) preg_replace(
                '/# BEGIN PELICAN PROXY DOMAINS SERVERS.*?# END PELICAN PROXY DOMAINS SERVERS/s',
                $serversBlock,
                $content
            );
        } elseif (preg_match('/^\[servers\]\s*$/m', $content)) {
            $content = (string) preg_replace(
                '/^(\[servers\]\s*)$/m',
                "$1\n" . $serversBlock . "\n",
                $content,
                1
            );
        } else {
            $content .= "\n[servers]\n" . $serversBlock . "\n";
        }

        // Inject or replace in [forced-hosts]
        if (preg_match('/# BEGIN PELICAN PROXY DOMAINS FORCED HOSTS.*?# END PELICAN PROXY DOMAINS FORCED HOSTS/s', $content)) {
            $content = (string) preg_replace(
                '/# BEGIN PELICAN PROXY DOMAINS FORCED HOSTS.*?# END PELICAN PROXY DOMAINS FORCED HOSTS/s',
                $forcedHostsBlock,
                $content
            );
        } elseif (preg_match('/^\[forced-hosts\]\s*$/m', $content)) {
            $content = (string) preg_replace(
                '/^(\[forced-hosts\]\s*)$/m',
                "$1\n" . $forcedHostsBlock . "\n",
                $content,
                1
            );
        } else {
            $content .= "\n[forced-hosts]\n" . $forcedHostsBlock . "\n";
        }

        $fileRepo->putContent($configPath, $content);

        // If Velocity container is running, send `velocity reload` to console
        try {
            if ($proxyServer->retrieveStatus() === ContainerStatus::Running) {
                $proxyServer->send('velocity reload');
            }
        } catch (Exception) {
            // Ignore console command errors if proxy is offline
        }
    }
}
