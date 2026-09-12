<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileClientDetailController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MobileClientsReportsTestSchema;
use Tests\TestCase;

class MobileClientDetailEndpointTest extends TestCase
{
    use MobileClientsReportsTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMobileClientsReportsSchema();
        Route::middleware('auth:api')->get('/api/mobile/clients-test/{id}', MobileClientDetailController::class);
    }

    public function test_requires_authentication(): void
    {
        $client = $this->client();
        $this->getJson("/api/mobile/clients-test/{$client->id}")->assertStatus(401);
    }

    public function test_denies_user_without_customers_view_permission(): void
    {
        \App\Models\Permission::firstOrCreate(['name' => 'Customers_view'], ['label' => 'Customers_view']);
        $user = $this->user(['role_id' => 2], []);
        $client = $this->client();
        $this->actingAs($user, 'api')->getJson("/api/mobile/clients-test/{$client->id}")->assertStatus(403);
    }

    public function test_returns_404_for_a_nonexistent_client(): void
    {
        $user = $this->user(['role_id' => 1], ['Customers_view']);
        $this->actingAs($user, 'api')->getJson('/api/mobile/clients-test/999999')->assertStatus(404);
    }

    public function test_returns_only_real_fields_and_authoritative_balance(): void
    {
        $user = $this->user(['role_id' => 1], ['Customers_view']);
        $client = $this->client(['name' => 'Cliente Balance', 'tax_number' => '0801199912345', 'phone' => '9999-0000', 'email' => 'c@test.com', 'adresse' => 'Col. Centro', 'opening_balance' => 50]);
        $sale = $this->sale(['client_id' => $client->id, 'GrandTotal' => 200, 'paid_amount' => 120]);

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/mobile/clients-test/{$client->id}")
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame('Cliente Balance', $data['name']);
        $this->assertSame('0801199912345', $data['rtn']);
        $this->assertSame('9999-0000', $data['phone']);
        $this->assertSame('c@test.com', $data['email']);
        $this->assertSame('Col. Centro', $data['address']);
        // opening_balance(50) + due(200-120=80) - return_due(0) = 130.00
        $this->assertSame('130.00', $data['balance']);
    }

    public function test_includes_the_client_last_five_sales_reusing_the_scoped_history_query(): void
    {
        $owner = $this->user(['role_id' => 1], ['Customers_view']);
        $client = $this->client(['name' => 'Cliente Historial']);
        $other = $this->client(['name' => 'Otro cliente']);
        for ($i = 1; $i <= 6; $i++) $this->sale(['client_id' => $client->id, 'Ref' => "SALE-{$i}"]);
        $this->sale(['client_id' => $other->id, 'Ref' => 'NOT-THIS-CLIENT']);

        $response = $this->actingAs($owner, 'api')
            ->getJson("/api/mobile/clients-test/{$client->id}")
            ->assertStatus(200);

        $refs = collect($response->json('data.recent_sales'))->pluck('reference');
        $this->assertCount(5, $refs);
        $this->assertFalse($refs->contains('NOT-THIS-CLIENT'));
    }

    public function test_non_owner_only_sees_the_clients_sales_within_their_branch_scope(): void
    {
        $branchA = $this->branch();
        $branchB = $this->branch();
        $user = $this->user(['role_id' => 2], [], branchIds: [$branchA->id]);
        $client = $this->client(['name' => 'Cliente Multi Sucursal']);
        $this->sale(['client_id' => $client->id, 'branch_id' => $branchA->id, 'Ref' => 'VISIBLE-SALE']);
        $this->sale(['client_id' => $client->id, 'branch_id' => $branchB->id, 'Ref' => 'HIDDEN-SALE']);

        // Give this user Customers_view so they can open the detail screen too.
        $role = DB::table('role_user')->where('user_id', $user->id)->value('role_id');
        $permission = \App\Models\Permission::firstOrCreate(['name' => 'Customers_view'], ['label' => 'Customers_view']);
        DB::table('permission_role')->insert(['permission_id' => $permission->id, 'role_id' => $role]);

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/mobile/clients-test/{$client->id}")
            ->assertStatus(200);

        $refs = collect($response->json('data.recent_sales'))->pluck('reference');
        $this->assertTrue($refs->contains('VISIBLE-SALE'));
        $this->assertFalse($refs->contains('HIDDEN-SALE'));
    }

    public function test_endpoint_never_mutates_the_client_or_its_sales(): void
    {
        $user = $this->user(['role_id' => 1], ['Customers_view']);
        $client = $this->client(['name' => 'Cliente Inmutable', 'opening_balance' => 10]);
        $this->sale(['client_id' => $client->id]);

        $before = (array) DB::table('clients')->where('id', $client->id)->first();
        $this->actingAs($user, 'api')->getJson("/api/mobile/clients-test/{$client->id}")->assertStatus(200);
        $after = (array) DB::table('clients')->where('id', $client->id)->first();

        $this->assertSame($before, $after);
    }
}
