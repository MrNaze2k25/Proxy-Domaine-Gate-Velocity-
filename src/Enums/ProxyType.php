<?php

namespace Julie\ProxyDomains\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProxyType: string implements HasLabel
{
    case Gate = 'gate';
    case Velocity = 'velocity';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Gate => trans('proxy-domains::strings.proxy_types.gate'),
            self::Velocity => trans('proxy-domains::strings.proxy_types.velocity'),
        };
    }

    public function getDefaultConfigPath(): string
    {
        return match ($this) {
            self::Gate => 'config.yml',
            self::Velocity => 'velocity.toml',
        };
    }
}
