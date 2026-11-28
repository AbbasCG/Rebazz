<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/** Klantaccount (zonder wachtwoord). Tokens hebben het recht 'customer', nooit 'admin'. */
class Customer extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['email', 'name', 'last_login_at'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    public function wishlist(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** Bestellingen van deze klant: gekoppeld aan het account, of geplaatst met hetzelfde e-mailadres (het account bewijst dat het adres van de klant is). */
    public function orders()
    {
        return Order::where(fn ($q) => $q->where('customer_id', $this->id)->orWhereRaw('lower(email) = ?', [$this->email]));
    }
}
