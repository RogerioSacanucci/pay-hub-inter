# Baseline Migration + Eloquent Models Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace default Laravel migrations with a production-matching baseline schema, and create User + Transaction Eloquent models with proper auth, casts, and relationships.

**Architecture:** Single baseline migration captures the exact production schema (users + transactions). A separate Sanctum migration for personal_access_tokens allows independent execution on existing DBs. Models use PHP 8 attributes, Sanctum's HasApiTokens, and custom auth column mapping.

**Tech Stack:** Laravel 13, PHP 8.4, Sanctum v4, PHPUnit v12, SQLite (test)

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Delete | `database/migrations/0001_01_01_000000_create_users_table.php` | Default Laravel users/sessions/password_resets — replaced by baseline |
| Delete | `database/migrations/0001_01_01_000001_create_cache_table.php` | Default cache tables — not needed |
| Delete | `database/migrations/0001_01_01_000002_create_jobs_table.php` | Default jobs tables — not needed |
| Delete | `database/migrations/2026_03_26_131549_create_personal_access_tokens_table.php` | Replaced by new timestamped Sanctum migration |
| Create | `database/migrations/2026_01_01_000000_create_baseline_schema.php` | Creates users + transactions tables matching production |
| Create | `database/migrations/2026_01_01_000001_create_personal_access_tokens.php` | Standard Sanctum personal_access_tokens table |
| Rewrite | `app/Models/User.php` | Auth model with password_hash, HasApiTokens, production columns |
| Create | `app/Models/Transaction.php` | Transaction model with TERMINAL_STATUSES, casts, belongsTo User |
| Rewrite | `database/factories/UserFactory.php` | Factory matching new User schema |
| Create | `database/factories/TransactionFactory.php` | Factory for Transaction model |
| Create | `tests/Feature/Models/UserModelTest.php` | Factory creation + API token tests |

---

### Task 1: Remove Default Migrations and Create Baseline Schema

**Files:**
- Delete: `database/migrations/0001_01_01_000000_create_users_table.php`
- Delete: `database/migrations/0001_01_01_000001_create_cache_table.php`
- Delete: `database/migrations/0001_01_01_000002_create_jobs_table.php`
- Delete: `database/migrations/2026_03_26_131549_create_personal_access_tokens_table.php`
- Create: `database/migrations/2026_01_01_000000_create_baseline_schema.php`
- Create: `database/migrations/2026_01_01_000001_create_personal_access_tokens.php`

- [ ] **Step 1: Delete the four default Laravel migrations**

```bash
cd hub-laravel
rm database/migrations/0001_01_01_000000_create_users_table.php
rm database/migrations/0001_01_01_000001_create_cache_table.php
rm database/migrations/0001_01_01_000002_create_jobs_table.php
rm database/migrations/2026_03_26_131549_create_personal_access_tokens_table.php
```

- [ ] **Step 2: Create baseline schema migration**

Create `database/migrations/2026_01_01_000000_create_baseline_schema.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('payer_email');
            $table->string('payer_name')->default('');
            $table->string('success_url', 500)->default('');
            $table->string('failed_url', 500)->default('');
            $table->string('pushcut_url', 500)->default('');
            $table->enum('role', ['user', 'admin'])->default('user');
            $table->enum('pushcut_notify', ['all', 'created', 'paid'])->default('all');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->enum('method', ['mbway', 'multibanco']);
            $table->enum('status', ['PENDING', 'COMPLETED', 'FAILED', 'EXPIRED', 'DECLINED'])->default('PENDING');
            $table->string('payer_email')->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_document', 50)->nullable();
            $table->string('payer_phone', 20)->nullable();
            $table->string('reference_entity', 20)->nullable();
            $table->string('reference_number', 50)->nullable();
            $table->string('reference_expires_at', 50)->nullable();
            $table->json('callback_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('users');
    }
};
```

- [ ] **Step 3: Create personal access tokens migration**

Create `database/migrations/2026_01_01_000001_create_personal_access_tokens.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
```

- [ ] **Step 4: Verify migrations run cleanly**

```bash
cd hub-laravel
php artisan migrate:fresh --no-interaction
```

Expected: All 2 migrations run successfully, 3 tables created (users, transactions, personal_access_tokens).

- [ ] **Step 5: Run Pint and commit**

```bash
cd hub-laravel
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: replace default migrations with production baseline schema"
```

---

### Task 2: Create User Model and Factory

**Files:**
- Rewrite: `app/Models/User.php`
- Rewrite: `database/factories/UserFactory.php`

- [ ] **Step 1: Rewrite User model**

Replace `app/Models/User.php` with:

```php
<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'email',
    'password_hash',
    'payer_email',
    'payer_name',
    'success_url',
    'failed_url',
    'pushcut_url',
    'role',
    'pushcut_notify',
])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    const UPDATED_AT = null;

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
        ];
    }
}
```

- [ ] **Step 2: Rewrite UserFactory**

Replace `database/factories/UserFactory.php` with:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => static::$password ??= Hash::make('password'),
            'payer_email' => fake()->safeEmail(),
            'payer_name' => fake()->name(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }
}
```

- [ ] **Step 3: Run Pint and commit**

```bash
cd hub-laravel
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: update User model and factory for production schema"
```

---

### Task 3: Create Transaction Model and Factory

**Files:**
- Create: `app/Models/Transaction.php`
- Create: `database/factories/TransactionFactory.php`

- [ ] **Step 1: Create Transaction model**

Create `app/Models/Transaction.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_id',
    'user_id',
    'amount',
    'currency',
    'method',
    'status',
    'payer_email',
    'payer_name',
    'payer_document',
    'payer_phone',
    'reference_entity',
    'reference_number',
    'reference_expires_at',
    'callback_data',
])]
class Transaction extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'EXPIRED', 'DECLINED'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'callback_data' => 'array',
        ];
    }
}
```

- [ ] **Step 2: Create TransactionFactory**

Create `database/factories/TransactionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 1, 500),
            'currency' => 'EUR',
            'method' => fake()->randomElement(['mbway', 'multibanco']),
            'status' => 'PENDING',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'COMPLETED',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'FAILED',
        ]);
    }
}
```

- [ ] **Step 3: Run Pint and commit**

```bash
cd hub-laravel
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: add Transaction model and factory"
```

---

### Task 4: Write and Run User Model Tests

**Files:**
- Create: `tests/Feature/Models/UserModelTest.php`

- [ ] **Step 1: Create test file**

Create `tests/Feature/Models/UserModelTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_user_via_factory(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => $user->email,
        ]);
        $this->assertNotEmpty($user->password_hash);
        $this->assertNotEmpty($user->payer_email);
    }

    public function test_can_create_api_token(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('test-token');

        $this->assertNotNull($token->plainTextToken);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
            'name' => 'test-token',
        ]);
    }
}
```

- [ ] **Step 2: Run the tests**

```bash
cd hub-laravel
php artisan test --compact tests/Feature/Models/UserModelTest.php
```

Expected: 2 tests pass.

- [ ] **Step 3: Run Pint and commit**

```bash
cd hub-laravel
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: add User model feature tests"
```

---

### Task 5: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
cd hub-laravel
php artisan test --compact
```

Expected: All tests pass (including existing ExampleTest files).

- [ ] **Step 2: Run Pint on everything**

```bash
cd hub-laravel
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Final commit (if Pint made changes)**

```bash
cd hub-laravel
git add -A
git commit -m "style: apply Pint formatting"
```
