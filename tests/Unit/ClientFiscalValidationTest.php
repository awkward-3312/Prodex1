<?php

namespace Tests\Unit;

use App\Http\Controllers\ClientController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientFiscalValidationTest extends TestCase
{
    public function test_honduras_client_without_rtn_defaults_to_cliente_final(): void
    {
        $request = Request::create('/clients', 'POST', [
            'name' => '',
            'tax_number' => '',
            'country' => '',
        ]);

        $controller = new TestableClientFiscalController;
        $controller->normalizeFiscal($request, $this->hnConfig());
        $controller->validateFiscal($request, $this->hnConfig());

        $this->assertSame('Cliente Final', $request->input('name'));
        $this->assertSame('', $request->input('tax_number'));
        $this->assertSame('Honduras', $request->input('country'));
    }

    public function test_honduras_rtn_is_normalized_to_digits(): void
    {
        $request = Request::create('/clients', 'POST', [
            'name' => 'Juan Perez',
            'tax_number' => '0801-1990-123456',
        ]);

        $controller = new TestableClientFiscalController;
        $controller->normalizeFiscal($request, $this->hnConfig());
        $controller->validateFiscal($request, $this->hnConfig());

        $this->assertSame('08011990123456', $request->input('tax_number'));
    }

    public function test_honduras_rtn_requires_real_name_not_cliente_final(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create('/clients', 'POST', [
            'name' => 'Cliente Final',
            'tax_number' => '08011990123456',
        ]);

        $controller = new TestableClientFiscalController;
        $controller->normalizeFiscal($request, $this->hnConfig());
        $controller->validateFiscal($request, $this->hnConfig());
    }

    public function test_honduras_rejects_obviously_invalid_rtn(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create('/clients', 'POST', [
            'name' => 'Comercial La Esperanza',
            'tax_number' => '0000-0000-000000',
        ]);

        $controller = new TestableClientFiscalController;
        $controller->normalizeFiscal($request, $this->hnConfig());
        $controller->validateFiscal($request, $this->hnConfig());
    }

    private function hnConfig(): array
    {
        return [
            'country_code' => 'HN',
            'customer_tax_id_label' => 'RTN',
        ];
    }
}

class TestableClientFiscalController extends ClientController
{
    public function normalizeFiscal(Request $request, array $config): void
    {
        $this->normalizeClientFiscalInput($request, $config);
    }

    public function validateFiscal(Request $request, array $config): void
    {
        $this->validateClientFiscalInput($request, $config);
    }
}
