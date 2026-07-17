<?php

namespace App\Models;

use App\Enums\ServiceKind;
use App\Enums\ServiceStatus;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'tagline',
        'image_url',
        'gallery',
        'before_image_url',
        'after_image_url',
        'kind',
        'external_url',
        'category',
        'service_secret',
        'webhook_signing_key',
        'status',
        'consecutive_failures',
        'max_concurrent',
        'current_version_id',
        'vote_up',
        'vote_down',
        'avg_latency_ms',
        'trending_rank',
    ];

    protected $hidden = [
        'service_secret',
        'webhook_signing_key',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ServiceKind::class,
            'status' => ServiceStatus::class,
            'service_secret' => 'hashed',
            'webhook_signing_key' => 'encrypted',
            'gallery' => 'array',
            'consecutive_failures' => 'integer',
            'max_concurrent' => 'integer',
            'vote_up' => 'integer',
            'vote_down' => 'integer',
            'avg_latency_ms' => 'integer',
            'trending_rank' => 'integer',
        ];
    }

    /**
     * Whether a secret has been pasted for this service. The raw value is never
     * exposed, so this boolean is how callers learn a secret is set.
     */
    protected function hasSecret(): Attribute
    {
        return Attribute::get(
            fn (): bool => ($this->attributes['service_secret'] ?? null) !== null,
        );
    }

    /**
     * A short, non-reversible fingerprint of the stored secret hash — enough to
     * tell two secrets apart in the UI, useless as a credential. Null when no
     * secret is set. Derived from the hash, so it never reveals the plaintext.
     */
    protected function secretPreview(): Attribute
    {
        return Attribute::get(function (): ?string {
            $stored = $this->attributes['service_secret'] ?? null;

            return $stored === null ? null : substr(hash('sha256', $stored), 0, 12);
        });
    }

    /**
     * Whether an encrypted webhook signing key has been pasted for this service.
     */
    protected function hasWebhookSigningKey(): Attribute
    {
        return Attribute::get(
            fn (): bool => ($this->attributes['webhook_signing_key'] ?? null) !== null,
        );
    }

    /**
     * A short, non-reversible fingerprint of the signing key. Because the key is
     * retrievable, the preview is derived from the decrypted value so it stays
     * stable across re-encryption — still just a fingerprint, never the key.
     */
    protected function webhookSigningKeyPreview(): Attribute
    {
        return Attribute::get(function (): ?string {
            $value = $this->webhook_signing_key;

            return ($value === null || $value === '')
                ? null
                : substr(hash('sha256', $value), 0, 12);
        });
    }

    /**
     * Verify an inbound webhook's HMAC signature against the RAW request body.
     * Signed with webhook_signing_key — the RETRIEVABLE secret — never
     * service_secret, which is bcrypt-hashed and can never yield the raw value
     * HMAC needs. Compared in constant time to avoid timing attacks.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '' || $this->webhook_signing_key === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhook_signing_key);

        return hash_equals($expected, $signature);
    }

    /**
     * Constant-time check of a presented service key (storage API Bearer).
     * Also checked against webhook_signing_key, for the same reason.
     */
    public function verifyServiceKey(?string $presented): bool
    {
        if ($presented === null || $presented === '' || $this->webhook_signing_key === null) {
            return false;
        }

        return hash_equals((string) $this->webhook_signing_key, $presented);
    }

    /**
     * @return BelongsTo<ServiceVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ServiceVersion::class, 'current_version_id');
    }

    /**
     * Annotates each row with is_bookmarked/my_vote for one user, via a
     * correlated EXISTS and a scalar subquery — no N+1 across the list.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeWithMarketplaceContext(Builder $query, string $userRef): Builder
    {
        return $query
            ->withExists(['bookmarks as is_bookmarked' => fn ($q) => $q->where('user_ref', $userRef)])
            ->addSelect(['my_vote' => ServiceVote::query()
                ->select('value')
                ->whereColumn('service_id', 'services.id')
                ->where('user_ref', $userRef)
                ->limit(1),
            ]);
    }

    /**
     * @return HasMany<ServiceVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ServiceVersion::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<ServiceVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(ServiceVote::class);
    }

    /**
     * @return HasMany<Bookmark, $this>
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    /**
     * @return HasMany<Interaction, $this>
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
