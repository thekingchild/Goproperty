<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankNameCheck;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bank account of the organization that tenants pay into
 * (ONBOARDING_COMPLIANCE.md § 4.1, DATABASE_SCHEMA.md § 4). Organization-
 * scoped. The account number is an encrypted cast and hidden from arrays;
 * screens show it masked (masked()). Written only through
 * App\Banking\BankAccounts (org-owners, `organization.payment_settings`),
 * which audits every change. Removed accounts are soft deleted: statement
 * imports and issued documents may still point at them.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $bank_code
 * @property string $bank_name
 * @property string $account_number decrypted
 * @property string $account_number_last4
 * @property string $account_fingerprint HMAC of bank code and number (App\Banking\BankAccounts::fingerprint())
 * @property string $account_name
 * @property BankNameCheck $name_check_status
 * @property string|null $name_check_result the account name the bank returned
 * @property CarbonImmutable|null $name_checked_at
 * @property bool $is_default the primary account, shown on invoices
 * @property bool $active
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Hidden(['account_number', 'account_fingerprint'])]
final class OrganizationBankAccount extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['bank_code', 'bank_name', 'account_number', 'account_number_last4', 'account_fingerprint', 'account_name', 'created_by_user_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['name_check_status' => 'unchecked', 'is_default' => false, 'active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'name_check_status' => BankNameCheck::class,
            'name_checked_at' => 'datetime',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** "···6789": what screens, receipts and audit entries show. */
    public function masked(): string
    {
        return '···'.$this->account_number_last4;
    }

    /** Active, and its name check does not stop tenants paying into it. */
    public function usable(): bool
    {
        return $this->active && $this->name_check_status->usable();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('active', true)->whereIn('name_check_status', [BankNameCheck::Matched->value, BankNameCheck::Unchecked->value]);
    }
}
