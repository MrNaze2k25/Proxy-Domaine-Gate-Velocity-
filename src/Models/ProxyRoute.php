<?php

namespace Julie\ProxyDomains\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property string $subdomain
 * @property-read Server $server
 */
class ProxyRoute extends Model
{
    protected $table = 'proxy_routes';

    protected $fillable = [
        'server_id',
        'subdomain',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function getFullHostAttribute(): string
    {
        $baseDomain = ltrim((string) config('proxy-domains.base_domain', 'pelican.dev'), '.');

        return strtolower($this->subdomain) . '.' . $baseDomain;
    }

    public function getBackendAddressAttribute(): ?string
    {
        $allocation = $this->server?->allocation;
        if (!$allocation) {
            return null;
        }

        $useAlias = (bool) config('proxy-domains.use_ip_alias', false);
        $ip = ($useAlias && !empty($allocation->ip_alias)) ? $allocation->ip_alias : $allocation->ip;

        return $ip . ':' . $allocation->port;
    }
}
