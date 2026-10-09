<?php

namespace Tests\Feature\Auth;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Autenticacion administrativa y su limite de intentos
 * (ARCHITECTURE.md §5.1 y §6, CA-01, CA-12).
 */
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'admin@encuestalive.test',
            'password' => Hash::make('contrasena-larga-123'),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // El limitador vive en la cache y persiste entre pruebas del mismo
        // proceso. Sin esto, la prueba del limite contaminaria a las demas.
        RateLimiter::clear('login');
    }

    public function test_an_admin_can_log_in_with_valid_credentials(): void
    {
        $admin = $this->admin();

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'contrasena-larga-123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    /**
     * El mensaje de error debe ser IDENTICO con un correo que existe y con uno
     * que no. Si difiriera, el login seria un oraculo que confirma que
     * direcciones tienen cuenta.
     */
    public function test_the_error_message_does_not_reveal_whether_the_email_exists(): void
    {
        $admin = $this->admin();

        $wrongPassword = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'contrasena-equivocada',
        ]);

        $unknownEmail = $this->post('/login', [
            'email' => 'nadie@encuestalive.test',
            'password' => 'contrasena-equivocada',
        ]);

        // Exactamente el mismo mensaje en ambos casos: con un correo que
        // existe y con uno que no.
        $expected = ['email' => 'Las credenciales no son correctas.'];

        $wrongPassword->assertInvalid($expected);
        $unknownEmail->assertInvalid($expected);

        $this->assertGuest();
    }

    public function test_a_failed_login_is_recorded_in_the_security_island(): void
    {
        $this->admin();

        $this->post('/login', [
            'email' => 'admin@encuestalive.test',
            'password' => 'mal',
        ]);

        $event = SecurityEvent::query()
            ->where('event_type', SecurityEvent::TYPE_AUTH_FAILED)
            ->first();

        $this->assertNotNull($event, 'El intento fallido debe quedar registrado.');

        // La isla B es el UNICO sitio con IP, y aun asi nunca debe guardar
        // nada que apunte a datos de participacion.
        $this->assertNull($event->getAttribute('participant_session_id'));
        $this->assertNull($event->getAttribute('token_hash'));
    }

    /**
     * Rate limiting del login: 5/min por `email + IP` (ARCHITECTURE.md §6).
     *
     * Aqui el limite estricto es correcto: el riesgo de fuerza bruta supera al
     * de molestar a un administrador legitimo, y solo hay un punado de
     * cuentas. Es el caso OPUESTO al del participante, donde limitar por IP
     * castigaria a toda una sala.
     */
    public function test_the_login_is_rate_limited_after_five_attempts(): void
    {
        $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'admin@encuestalive.test',
                'password' => 'mal',
            ])->assertStatus(302);
        }

        // El sexto ya no es un fallo de credenciales: es un 429.
        $this->post('/login', [
            'email' => 'admin@encuestalive.test',
            'password' => 'mal',
        ])->assertStatus(429);

        // Y el bloqueo persiste aunque la contrasena sea la CORRECTA: si no,
        // el limitador no serviria de nada contra la fuerza bruta.
        $this->post('/login', [
            'email' => 'admin@encuestalive.test',
            'password' => 'contrasena-larga-123',
        ])->assertStatus(429);

        $this->assertGuest();
    }

    public function test_there_is_no_public_registration_route(): void
    {
        // Sin registro publico (ARCHITECTURE.md §5.1): elimina de raiz toda
        // una clase de abuso.
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();

        // Y sin recuperacion por correo: no hay SMTP, y una pantalla que
        // promete enviar un enlace que nunca llega seria mentir.
        $this->get('/forgot-password')->assertNotFound();
        $this->get('/password/reset')->assertNotFound();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_create_admin_command_creates_and_resets_an_account(): void
    {
        $this->artisan('encuestalive:crear-admin', [
            '--email' => 'nueva@encuestalive.test',
            '--name' => 'Nueva',
            '--password' => 'contrasena-larga-123',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'nueva@encuestalive.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('contrasena-larga-123', $user->password));

        // Reejecutar restablece la contrasena en lugar de duplicar la cuenta.
        $this->artisan('encuestalive:crear-admin', [
            '--email' => 'nueva@encuestalive.test',
            '--name' => 'Nueva',
            '--password' => 'otra-contrasena-larga-456',
        ])->assertSuccessful();

        $this->assertSame(1, User::query()->where('email', 'nueva@encuestalive.test')->count());
        $this->assertTrue(
            Hash::check('otra-contrasena-larga-456', $user->fresh()->password),
        );
    }

    public function test_the_command_rejects_a_short_password(): void
    {
        $this->artisan('encuestalive:crear-admin', [
            '--email' => 'corta@encuestalive.test',
            '--name' => 'Corta',
            '--password' => 'corta',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'corta@encuestalive.test']);
    }
}
