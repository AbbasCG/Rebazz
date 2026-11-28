<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsletterCampaign extends Model
{
    protected $fillable = ['subject', 'body', 'cta_label', 'cta_url', 'lang', 'status', 'sent_count', 'failed_count', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NewsletterDelivery::class, 'campaign_id');
    }

    /** Bevestigde abonnees voor deze campagne (eventueel beperkt tot één taal). */
    public function audience()
    {
        return NewsletterSubscriber::confirmed()->when($this->lang, fn ($q, $l) => $q->where('lang', $l));
    }
}
