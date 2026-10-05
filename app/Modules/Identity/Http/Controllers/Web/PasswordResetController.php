<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Application\PasswordChanges;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «¿Olvidaste tu contraseña?» y crear la nueva desde el enlace (diseño de acceso, fase 2).
 *
 * Usa el broker de Laravel —`password_reset_tokens` existía desde el esqueleto sin uso—: el token se guarda cifrado, vence
 * a los 60 minutos, se gasta al usarse y entre dos enlaces del mismo correo pasa al menos un minuto.
 *
 * ## Nada de lo que responde dice si el correo tiene cuenta
 *
 * Pedir el enlace responde siempre lo mismo, y el correo sale por cola, así que tampoco el tiempo lo delata. Un enlace
 * inválido, vencido o de otro correo responde el mismo mensaje. Es la regla del acceso, que no distingue un correo
 * inexistente de una contraseña equivocada.
 */
final class PasswordResetController
{
    private const SENT = 'Si ese correo tiene cuenta en Comandia, te enviamos un enlace para crear una contraseña nueva. '
        .'Vence en 60 minutos; revisa también la carpeta de no deseado.';

    public function __construct(private readonly PasswordChanges $passwords) {}

    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * @throws ValidationException
     */
    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $request->throttle();

        // El estado que devuelve —enviado, correo desconocido, demasiado pronto— se descarta a propósito.
        Password::broker()->sendResetLink(['email' => $request->string('email')->toString()]);

        return back()->with('success', self::SENT);
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $request->throttle();

        $estado = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $this->passwords->reset($user, $password);
            },
        );

        if ($estado !== Password::PASSWORD_RESET) {
            // Token inválido, vencido, de otro correo o correo desconocido: el mismo mensaje para todo.
            throw ValidationException::withMessages([
                'token' => ['Este enlace ya no sirve: venció, ya se usó o no corresponde a ese correo.'],
            ]);
        }

        return redirect('/login')->with('success', 'Contraseña actualizada: entra con la nueva.');
    }
}
