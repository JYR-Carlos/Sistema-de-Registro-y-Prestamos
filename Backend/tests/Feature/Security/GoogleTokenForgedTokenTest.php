<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regresión de C2 (bypass de autenticación por ID token de Google falsificado).
 *
 * Antes, el controlador decodificaba el payload del JWT sin verificar la firma RS256,
 * así que cualquiera podía fabricar un token con aud/iss/exp/email_verified correctos y
 * autenticarse como cualquier usuario. Ahora la firma se valida contra el JWKS de Google
 * (Google\Auth\AccessToken::verify), por lo que un token con firma falsa debe rechazarse.
 *
 * No usa base de datos: el token se rechaza antes de tocar la tabla de usuarios.
 */
class GoogleTokenForgedTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.google.client_id', 'test-client-id.apps.googleusercontent.com');
    }

    public function test_rechaza_un_id_token_de_google_con_firma_falsificada(): void
    {
        // Token bien formado con TODAS las claims que el código anterior validaba
        // (aud/iss/exp/email_verified) pero con una firma inventada. La defensa real
        // es la verificación criptográfica de la firma, no las claims.
        $forged = $this->forgeToken([
            'iss'            => 'https://accounts.google.com',
            'aud'            => 'test-client-id.apps.googleusercontent.com',
            'sub'            => '999',
            'email'          => 'alice.admin@uta.cl',
            'email_verified' => true,
            'name'           => 'Attacker',
            'exp'            => time() + 3600,
        ]);

        $response = $this->postJson('/api/auth/google', ['token' => $forged]);

        // Debe fallar la autenticación (401), nunca emitir un token (200).
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'Un ID token de Google con firma falsificada NO debe autenticar. '
            .'Respuesta recibida: '.$response->getStatusCode().' '.$response->getContent()
        );

        $response->assertJsonPath('message', 'Token de Google inválido');
    }

    public function test_rechaza_un_token_que_no_es_un_jwt(): void
    {
        $response = $this->postJson('/api/auth/google', ['token' => 'esto-no-es-un-jwt']);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Construye un JWT RS256 sintáctico (header.payload.firma) con una firma arbitraria.
     *
     * @param array<string,mixed> $claims
     */
    private function forgeToken(array $claims): string
    {
        $b64 = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        $header    = $b64(['alg' => 'RS256', 'kid' => 'forged', 'typ' => 'JWT']);
        $payload   = $b64($claims);
        $signature = rtrim(strtr(base64_encode('firma-invalida-de-atacante'), '+/', '-_'), '=');

        return "{$header}.{$payload}.{$signature}";
    }
}
