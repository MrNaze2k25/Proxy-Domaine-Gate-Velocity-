<?php

namespace Julie\ProxyDomains\Filament\Server\Pages;

use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Julie\ProxyDomains\Models\ProxyRoute;
use Julie\ProxyDomains\Services\ProxySyncService;

class ProxySubdomainPage extends ServerFormPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-world-www';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'proxy-domain';

    public static function canAccess(): bool
    {
        /** @var Server|null $server */
        $server = Filament::getTenant();
        if (!$server) {
            return false;
        }

        if (!ProxySyncService::isEligibleBackendServer($server)) {
            return false;
        }

        return parent::canAccess();
    }

    public function getTitle(): string
    {
        return trans('proxy-domains::strings.server.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('proxy-domains::strings.server.nav_label');
    }

    protected function fillForm(): void
    {
        $server = $this->getRecord();
        $route = ProxyRoute::where('server_id', $server->id)->first();

        $this->form->fill([
            'subdomain' => $route?->subdomain ?? '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $baseDomain = ltrim((string) config('proxy-domains.base_domain', 'pelican.dev'), '.');
        $server = $this->getRecord();

        return parent::form($schema)
            ->components([
                Section::make(trans('proxy-domains::strings.server.section_title'))
                    ->description(trans('proxy-domains::strings.server.section_desc'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('subdomain')
                            ->label(trans('proxy-domains::strings.server.subdomain_label'))
                            ->helperText(trans('proxy-domains::strings.server.subdomain_help'))
                            ->placeholder('point-zero')
                            ->suffix('.' . $baseDomain)
                            ->required()
                            ->maxLength(63)
                            ->regex('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/')
                            ->rules([
                                fn () => function (string $attribute, mixed $value, \Closure $fail) use ($server) {
                                    $taken = ProxyRoute::query()
                                        ->where('subdomain', strtolower(trim((string) $value)))
                                        ->where('server_id', '!=', $server->id)
                                        ->exists();

                                    if ($taken) {
                                        $fail(trans('proxy-domains::strings.errors.taken'));
                                    }
                                },
                            ])
                            ->live()
                            ->columnSpanFull(),

                        TextEntry::make('preview_domain')
                            ->label(trans('proxy-domains::strings.server.preview_label'))
                            ->state(function (Get $get) use ($baseDomain) {
                                $sub = strtolower(trim((string) $get('subdomain')));

                                return $sub !== ''
                                    ? "{$sub}.{$baseDomain}"
                                    : trans('proxy-domains::strings.server.preview_example', ['domain' => $baseDomain]);
                            }),

                        TextEntry::make('backend_target')
                            ->label(trans('proxy-domains::strings.server.backend_label'))
                            ->state(function () use ($server) {
                                $allocation = $server->allocation;
                                if (!$allocation) {
                                    return trans('proxy-domains::strings.server.no_allocation');
                                }

                                $useAlias = (bool) config('proxy-domains.use_ip_alias', false);
                                $ip = ($useAlias && !empty($allocation->ip_alias)) ? $allocation->ip_alias : $allocation->ip;

                                return "{$ip}:{$allocation->port}";
                            }),
                    ])
                    ->footerActions([
                        Action::make('save_subdomain')
                            ->label(trans('proxy-domains::strings.actions.save'))
                            ->icon('tabler-device-floppy')
                            ->color('primary')
                            ->action('save'),

                        Action::make('delete_subdomain')
                            ->label(trans('proxy-domains::strings.actions.delete'))
                            ->icon('tabler-trash')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->visible(fn () => ProxyRoute::where('server_id', $this->getRecord()->id)->exists())
                            ->action('deleteRoute'),
                    ]),
            ]);
    }

    protected function getDefaultHeaderActions(): array
    {
        return [
            Action::make('header_save')
                ->label(trans('proxy-domains::strings.actions.save'))
                ->icon('tabler-device-floppy')
                ->color('primary')
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        $server = $this->getRecord();
        $data = $this->form->getState();
        $subdomain = strtolower(trim((string) ($data['subdomain'] ?? '')));

        // Check blacklist
        $blacklistRaw = (string) config('proxy-domains.blacklist', '');
        $blacklist = array_filter(array_map('strtolower', array_map('trim', explode(',', $blacklistRaw))));

        if (in_array($subdomain, $blacklist, true)) {
            Notification::make()
                ->title(trans('proxy-domains::strings.errors.blacklisted'))
                ->danger()
                ->send();

            return;
        }

        if (!$server->allocation) {
            Notification::make()
                ->title(trans('proxy-domains::strings.server.no_allocation'))
                ->danger()
                ->send();

            return;
        }

        try {
            ProxyRoute::updateOrCreate(
                ['server_id' => $server->id],
                ['subdomain' => $subdomain]
            );

            app(ProxySyncService::class)->syncAllRoutes();

            $baseDomain = ltrim((string) config('proxy-domains.base_domain', 'pelican.dev'), '.');

            Notification::make()
                ->title(trans('proxy-domains::strings.notifications.route_saved'))
                ->body("{$subdomain}.{$baseDomain} -> {$server->allocation->ip}:{$server->allocation->port}")
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

    public function deleteRoute(): void
    {
        $server = $this->getRecord();

        try {
            $existing = ProxyRoute::with('server.allocation')->where('server_id', $server->id)->first();
            $removedHost = $existing?->full_host;
            $removedBackend = $existing?->backend_address;

            ProxyRoute::where('server_id', $server->id)->delete();

            app(ProxySyncService::class)->syncAllRoutes(
                array_filter([$removedHost]),
                array_filter([$removedBackend])
            );

            $this->form->fill(['subdomain' => '']);

            Notification::make()
                ->title(trans('proxy-domains::strings.notifications.route_deleted'))
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
