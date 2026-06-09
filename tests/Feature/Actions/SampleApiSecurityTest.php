<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\SamplePermissionApi;
use App\Actions\SampleRoleApi;
use App\Actions\SampleUserApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SampleApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Order-column whitelist tests
    // -------------------------------------------------------------------------

    /**
     * A malicious column name passed as the datatable sort column must fall
     * back to 'id' and must not cause an exception.
     */
    public function test_permission_datatable_rejects_malicious_order_column(): void
    {
        $request = Request::create('/api/v1/permissions', 'POST', [
            'draw'    => 1,
            'start'   => 0,
            'length'  => 10,
            'columns' => [
                ['data' => "name); DROP TABLE users;--"],
            ],
            'order'   => [['column' => '0', 'dir' => 'asc']],
            'search'  => ['value' => ''],
        ]);

        $action = app(SamplePermissionApi::class);
        $result = $action->datatableList($request);

        $this->assertSame('id', $result['orderColumnName'],
            'Malicious column must be replaced with id fallback');
    }

    public function test_role_datatable_rejects_malicious_order_column(): void
    {
        $request = Request::create('/api/v1/roles', 'POST', [
            'draw'    => 1,
            'start'   => 0,
            'length'  => 10,
            'columns' => [
                ['data' => "name); DROP TABLE users;--"],
            ],
            'order'   => [['column' => '0', 'dir' => 'asc']],
            'search'  => ['value' => ''],
        ]);

        $action = app(SampleRoleApi::class);
        $result = $action->datatableList($request);

        $this->assertSame('id', $result['orderColumnName'],
            'Malicious column must be replaced with id fallback');
    }

    public function test_user_datatable_rejects_malicious_order_column(): void
    {
        $request = Request::create('/api/v1/users', 'POST', [
            'draw'    => 1,
            'start'   => 0,
            'length'  => 10,
            'columns' => [
                ['data' => "email); DROP TABLE users;--"],
            ],
            'order'   => [['column' => '0', 'dir' => 'asc']],
            'search'  => ['value' => ''],
        ]);

        $action = app(SampleUserApi::class);
        $result = $action->datatableList($request);

        $this->assertSame('id', $result['orderColumnName'],
            'Malicious column must be replaced with id fallback');
    }

    public function test_datatable_accepts_valid_order_column(): void
    {
        $request = Request::create('/api/v1/permissions', 'POST', [
            'draw'    => 1,
            'start'   => 0,
            'length'  => 10,
            'columns' => [
                ['data' => 'name'],
            ],
            'order'   => [['column' => '0', 'dir' => 'desc']],
            'search'  => ['value' => ''],
        ]);

        $action = app(SamplePermissionApi::class);
        $result = $action->datatableList($request);

        $this->assertSame('name', $result['orderColumnName'],
            'A whitelisted column must pass through');
    }

    /**
     * A malicious order direction must be neutralized to a clean 'asc' or 'desc'.
     * The code hard-reduces: strtolower($dir) === 'desc' ? 'desc' : 'asc'.
     * We enable query logging and verify the injected string never appears in SQL.
     */
    public function test_datatable_orderdir_injection_is_neutralized(): void
    {
        DB::enableQueryLog();

        $maliciousDir = "asc); DROP TABLE users;--";

        $request = Request::create('/api/v1/permissions', 'POST', [
            'draw'    => 1,
            'start'   => 0,
            'length'  => 10,
            'columns' => [
                ['data' => 'name'],   // valid whitelisted column
            ],
            'order'   => [['column' => '0', 'dir' => $maliciousDir]],
            'search'  => ['value' => ''],
        ]);

        $action = app(SamplePermissionApi::class);
        $result = $action->datatableList($request);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The action must not throw and must return a valid result array
        $this->assertIsArray($result);

        // The injected string must not appear in any executed query
        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'DROP TABLE',
                $query['query'],
                'Injected SQL must not appear in any executed query'
            );
            $this->assertStringNotContainsString(
                $maliciousDir,
                $query['query'],
                'Raw malicious dir string must not appear in any executed query'
            );
        }

        // The executed ORDER BY must use a clean direction literal
        $orderQuery = collect($queries)->first(fn($q) => str_contains(strtolower($q['query']), 'order by'));
        if ($orderQuery !== null) {
            $sql = strtolower($orderQuery['query']);
            $this->assertMatchesRegularExpression('/order by .+ (asc|desc)/', $sql,
                'ORDER BY must use a clean asc or desc literal');
        }
        // Note: dir is hard-reduced to a literal 'asc'/'desc' in code before reaching the query builder,
        // so even if query logging is unavailable the injection path is closed at the PHP layer.
    }

    // -------------------------------------------------------------------------
    // Mass-assignment blocked tests
    // -------------------------------------------------------------------------

    /**
     * Calling Permission::create() via the action with an extra unvalidated
     * field must NOT persist that field.
     */
    public function test_permission_create_blocks_extra_fields(): void
    {
        // 'guard_name' is not in the validator rules — but it IS fillable on the
        // Permission model (Spatie sets it). The key test is that only validated
        // fields are passed, so any other injected key is silently dropped.
        $request = Request::create('/api/v1/permissions', 'POST', [
            'name'       => 'test-permission-' . uniqid(),
            'guard_name' => 'hacked',
            'extra_evil' => 'payload',
        ]);

        $action = app(SamplePermissionApi::class);
        $action->create($request);

        $permission = Permission::where('name', 'like', 'test-permission-%')->latest()->first();
        $this->assertNotNull($permission);
        // guard_name defaults to 'web' from Spatie's own logic — it should NOT be 'hacked'
        // because only validated() keys (which is just 'name') are passed to Permission::create().
        $this->assertNotSame('hacked', $permission->guard_name,
            'Injected guard_name must not be persisted from unvalidated input');
    }

    /**
     * Calling User::create() via the action must not persist an injected fillable
     * field that is excluded from only(['name','email','password']).
     *
     * Column used: is_active — it IS in User::$fillable and the DB default is true.
     * The attacker injects is_active=false. On the old $request->all() code this
     * would have persisted false. With only(['name','email','password']) it is
     * blocked, so the persisted value stays at the DB default (true).
     */
    public function test_user_create_blocks_extra_fields(): void
    {
        $email = 'test-' . uniqid() . '@example.com';

        $request = Request::create('/api/v1/users', 'POST', [
            'name'      => 'Test User',
            'email'     => $email,
            'password'  => 'secret123',
            'is_active' => false,   // injected fillable field — must be blocked
        ]);

        $action = app(SampleUserApi::class);
        $action->create($request);

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user, 'User should have been created successfully');

        // is_active was injected as false but only(['name','email','password']) excludes it,
        // so the persisted value must still be the DB default (true).
        $this->assertTrue(
            (bool) $user->is_active,
            'is_active injected as false must not be persisted; DB default (true) must remain'
        );
    }

    // -------------------------------------------------------------------------
    // Role sync does not accumulate test
    // -------------------------------------------------------------------------

    /**
     * Calling update() with role B on a user that already has role A must
     * result in the user having exactly one role (B), not both.
     */
    public function test_user_update_syncs_roles_instead_of_accumulating(): void
    {
        // Create two roles
        $roleA = Role::firstOrCreate(['name' => 'role-a-' . uniqid(), 'guard_name' => 'web']);
        $roleB = Role::firstOrCreate(['name' => 'role-b-' . uniqid(), 'guard_name' => 'web']);

        // Create a user and assign role A
        $user = User::factory()->create();
        $user->assignRole($roleA);
        $this->assertCount(1, $user->fresh()->roles);
        $this->assertTrue($user->fresh()->hasRole($roleA));

        // Call update() with role B
        $request = Request::create('/api/v1/users/' . $user->id, 'PUT', [
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $roleB->name,
        ]);

        $action = app(SampleUserApi::class);
        $action->update($user->id, $request);

        $freshUser = $user->fresh();
        $this->assertCount(1, $freshUser->roles,
            'User must have exactly 1 role after update, not an accumulation');
        $this->assertTrue($freshUser->hasRole($roleB),
            'User must have role B after update');
        $this->assertFalse($freshUser->hasRole($roleA),
            'User must NOT still have role A after update with role B');
    }
}
