<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileClientsController;
use Illuminate\Support\Facades\Route;
use Tests\Support\MobileClientsReportsTestSchema;
use Tests\TestCase;

class MobileClientsEndpointTest extends TestCase
{
    use MobileClientsReportsTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMobileClientsReportsSchema();
        Route::middleware('auth:api')->get('/api/mobile/clients-test', MobileClientsController::class);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/mobile/clients-test')->assertStatus(401);
    }

    public function test_denies_user_without_customers_view_permission(): void
    {
        \App\Models\Permission::firstOrCreate(['name' => 'Customers_view'], ['label' => 'Customers_view']);
        $user = $this->user(['role_id' => 2], []);
        $this->actingAs($user, 'api')->getJson('/api/mobile/clients-test')->assertStatus(403);
    }

    public function test_allows_user_with_customers_view_permission(): void
    {
        $user = $this->user(['role_id' => 2], ['Customers_view']);
        $this->actingAs($user, 'api')->getJson('/api/mobile/clients-test')->assertStatus(200);
    }

    public function test_uses_the_same_search_service_as_pos_client_search(): void
    {
        $user = $this->user(['role_id' => 2], ['Customers_view']);
        $this->client(['name' => 'Juan Perez']);
        $this->client(['name' => 'Otro Cliente']);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/mobile/clients-test?search=Juan')
            ->assertStatus(200);

        $names = collect($response->json('data.items'))->pluck('name');
        $this->assertTrue($names->contains('Juan Perez'));
        $this->assertFalse($names->contains('Otro Cliente'));
    }

    public function test_paginates_results(): void
    {
        $user = $this->user(['role_id' => 2], ['Customers_view']);
        for ($i = 1; $i <= 3; $i++) $this->client(['name' => "Cliente {$i}"]);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/mobile/clients-test?per_page=2&page=1')
            ->assertStatus(200);

        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertTrue($response->json('data.pagination.has_more'));
        $this->assertCount(2, $response->json('data.items'));
    }
}
