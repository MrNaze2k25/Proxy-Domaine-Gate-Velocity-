<?php

namespace Julie\ProxyDomains;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Julie\ProxyDomains\Enums\ProxyType;
use Julie\ProxyDomains\Services\ProxySyncService;

class ProxyDomainsPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'proxy-domains';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/{$id}/Pages"),
            "Julie\\ProxyDomains\\Filament\\{$id}\\Pages"
        );

        $panel->discoverResources(
            plugin_path($this->getId(), "src/Filament/{$id}/Resources"),
            "Julie\\ProxyDomains\\Filament\\{$id}\\Resources"
        );
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        $blacklistRaw = (string) config('proxy-domains.blacklist', '');

        return [
            'proxy_server_id' => config('proxy-domains.proxy_server_id'),
            'proxy_type' => config('proxy-domains.proxy_type', ProxyType::Gate->value),
            'base_domain' => config('proxy-domains.base_domain', 'pelican.dev'),
            'config_path' => config('proxy-domains.config_path', 'config.yml'),
            'only_minecraft' => (bool) config('proxy-domains.only_minecraft', true),
            'use_ip_alias' => (bool) config('proxy-domains.use_ip_alias', false),
            'blacklist' => array_values(array_filter(array_map('trim', explode(',', $blacklistRaw)))),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            Select::make('proxy_server_id')
                ->label(trans('proxy-domains::strings.admin.proxy_server'))
                ->helperText(trans('proxy-domains::strings.admin.proxy_server_help'))
                ->options(fn () => ProxySyncService::getAvailableProxyServers())
                ->searchable()
                ->preload()
                ->required(),

            Select::make('proxy_type')
                ->label(trans('proxy-domains::strings.admin.proxy_type'))
                ->options(ProxyType::class)
                ->required(),

            TextInput::make('base_domain')
                ->label(trans('proxy-domains::strings.admin.base_domain'))
                ->prefix('.')
                ->required(),

            TextInput::make('config_path')
                ->label(trans('proxy-domains::strings.admin.config_path'))
                ->required(),

            Toggle::make('only_minecraft')
                ->label(trans('proxy-domains::strings.admin.only_minecraft')),

            Toggle::make('use_ip_alias')
                ->label(trans('proxy-domains::strings.admin.use_ip_alias')),

            TagsInput::make('blacklist')
                ->label(trans('proxy-domains::strings.admin.blacklist')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $proxyType = $data['proxy_type'] instanceof ProxyType
            ? $data['proxy_type']->value
            : (string) ($data['proxy_type'] ?? 'gate');

        $baseDomain = ltrim(trim((string) ($data['base_domain'] ?? 'pelican.dev')), '.');
        $blacklist = is_array($data['blacklist'] ?? null)
            ? implode(',', $data['blacklist'])
            : (string) ($data['blacklist'] ?? '');

        $this->writeToEnvironment([
            'PROXY_DOMAINS_SERVER_ID' => (string) ($data['proxy_server_id'] ?? ''),
            'PROXY_DOMAINS_PROXY_TYPE' => $proxyType,
            'PROXY_DOMAINS_BASE_DOMAIN' => $baseDomain,
            'PROXY_DOMAINS_CONFIG_PATH' => trim((string) ($data['config_path'] ?? 'config.yml')),
            'PROXY_DOMAINS_ONLY_MINECRAFT' => !empty($data['only_minecraft']) ? 'true' : 'false',
            'PROXY_DOMAINS_USE_IP_ALIAS' => !empty($data['use_ip_alias']) ? 'true' : 'false',
            'PROXY_DOMAINS_BLACKLIST' => $blacklist,
        ]);

        Notification::make()
            ->title(trans('proxy-domains::strings.notifications.settings_saved'))
            ->success()
            ->send();
    }
}
