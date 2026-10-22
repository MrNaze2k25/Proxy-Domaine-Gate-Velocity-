<?php

namespace Julie\ProxyDomains\Filament\Admin\Pages;

use App\Traits\EnvironmentWriterTrait;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Julie\ProxyDomains\Enums\ProxyType;
use Julie\ProxyDomains\Models\ProxyRoute;
use Julie\ProxyDomains\Services\ProxySyncService;

/**
 * @property Schema $form
 */
class ProxyRouterManager extends Page
{
    use EnvironmentWriterTrait;
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-route';

    protected static ?string $slug = 'proxy-router';

    protected string $view = 'filament.server.pages.server-form-page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function getTitle(): string
    {
        return trans('proxy-domains::strings.admin.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('proxy-domains::strings.admin.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/dashboard.advanced');
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = ProxyRoute::query()->count();

            return $count > 0 ? (string) $count : null;
        } catch (Exception) {
            return null;
        }
    }

    public static function canAccess(): bool
    {
        return user()?->can('view proxyRouter') ?? true;
    }

    public function mount(): void
    {
        $blacklistRaw = (string) config('proxy-domains.blacklist', '');

        $this->form->fill([
            'proxy_server_id' => config('proxy-domains.proxy_server_id'),
            'proxy_type' => config('proxy-domains.proxy_type', ProxyType::Gate->value),
            'base_domain' => config('proxy-domains.base_domain', 'pelican.dev'),
            'config_path' => config('proxy-domains.config_path', 'config.yml'),
            'only_minecraft' => (bool) config('proxy-domains.only_minecraft', true),
            'use_ip_alias' => (bool) config('proxy-domains.use_ip_alias', false),
            'blacklist' => array_values(array_filter(array_map('trim', explode(',', $blacklistRaw)))),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(trans('proxy-domains::strings.admin.section_proxy'))
                    ->description(trans('proxy-domains::strings.admin.section_proxy_desc'))
                    ->columns(2)
                    ->schema([
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
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                $type = $state instanceof ProxyType ? $state : ProxyType::tryFrom((string) $state);
                                $currentPath = $get('config_path');
                                if ($type && in_array($currentPath, ['config.yml', 'velocity.toml', '', null], true)) {
                                    $set('config_path', $type->getDefaultConfigPath());
                                }
                            }),

                        TextInput::make('base_domain')
                            ->label(trans('proxy-domains::strings.admin.base_domain'))
                            ->helperText(trans('proxy-domains::strings.admin.base_domain_help'))
                            ->placeholder('pelican.dev')
                            ->prefix('.')
                            ->required(),

                        TextInput::make('config_path')
                            ->label(trans('proxy-domains::strings.admin.config_path'))
                            ->helperText(trans('proxy-domains::strings.admin.config_path_help'))
                            ->placeholder('config.yml')
                            ->required(),

                        Toggle::make('only_minecraft')
                            ->label(trans('proxy-domains::strings.admin.only_minecraft'))
                            ->helperText(trans('proxy-domains::strings.admin.only_minecraft_help'))
                            ->default(true),

                        Toggle::make('use_ip_alias')
                            ->label(trans('proxy-domains::strings.admin.use_ip_alias'))
                            ->helperText(trans('proxy-domains::strings.admin.use_ip_alias_help'))
                            ->default(false),

                        TagsInput::make('blacklist')
                            ->label(trans('proxy-domains::strings.admin.blacklist'))
                            ->helperText(trans('proxy-domains::strings.admin.blacklist_help'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_now')
                ->label(trans('proxy-domains::strings.admin.sync_now'))
                ->icon('tabler-refresh')
                ->color('gray')
                ->action(function (ProxySyncService $syncService) {
                    try {
                        $syncService->syncAllRoutes();

                        Notification::make()
                            ->title(trans('proxy-domains::strings.notifications.synced'))
                            ->success()
                            ->send();
                    } catch (Exception $exception) {
                        Notification::make()
                            ->title(trans('proxy-domains::strings.notifications.sync_error'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),

            Action::make('save')
                ->label(trans('proxy-domains::strings.actions.save'))
                ->icon('tabler-device-floppy')
                ->color('primary')
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(ProxySyncService $syncService): void
    {
        try {
            $data = $this->form->getState();

            $proxyType = $data['proxy_type'] instanceof ProxyType
                ? $data['proxy_type']->value
                : (string) $data['proxy_type'];

            $baseDomain = ltrim(trim((string) $data['base_domain']), '.');
            $blacklist = is_array($data['blacklist'] ?? null)
                ? implode(',', $data['blacklist'])
                : (string) ($data['blacklist'] ?? '');

            $this->writeToEnvironment([
                'PROXY_DOMAINS_SERVER_ID' => (string) $data['proxy_server_id'],
                'PROXY_DOMAINS_PROXY_TYPE' => $proxyType,
                'PROXY_DOMAINS_BASE_DOMAIN' => $baseDomain,
                'PROXY_DOMAINS_CONFIG_PATH' => trim((string) $data['config_path']),
                'PROXY_DOMAINS_ONLY_MINECRAFT' => $data['only_minecraft'] ? 'true' : 'false',
                'PROXY_DOMAINS_USE_IP_ALIAS' => $data['use_ip_alias'] ? 'true' : 'false',
                'PROXY_DOMAINS_BLACKLIST' => $blacklist,
            ]);

            // Update runtime config immediately so sync uses the fresh settings
            config([
                'proxy-domains.proxy_server_id' => $data['proxy_server_id'],
                'proxy-domains.proxy_type' => $proxyType,
                'proxy-domains.base_domain' => $baseDomain,
                'proxy-domains.config_path' => trim((string) $data['config_path']),
                'proxy-domains.only_minecraft' => (bool) $data['only_minecraft'],
                'proxy-domains.use_ip_alias' => (bool) $data['use_ip_alias'],
                'proxy-domains.blacklist' => $blacklist,
            ]);

            $syncService->syncAllRoutes();

            Notification::make()
                ->title(trans('proxy-domains::strings.notifications.settings_saved'))
                ->success()
                ->send();
        } catch (Exception $exception) {
            Notification::make()
                ->title(trans('proxy-domains::strings.notifications.sync_error'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
