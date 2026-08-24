import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { take } from 'rxjs';
import { AuthService } from '../../../services/auth.service';

@Component({
  selector: 'app-sso-callback',
  standalone: true,
  imports: [CommonModule],
  template: `
    <section class="sso-callback-container">
      <h2>Iniciando sesión...</h2>
      <p>{{ message }}</p>
    </section>
  `,
  styles: [`
    .sso-callback-container {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      text-align: center;
      padding: 2rem;
    }
  `]
})
export class SsoCallbackComponent implements OnInit {
  message = 'Validando acceso con UTAmed...';

  constructor(
    private readonly route: ActivatedRoute,
    private readonly router: Router,
    private readonly authService: AuthService
  ) {}

  ngOnInit(): void {
    this.route.queryParamMap.pipe(take(1)).subscribe(params => {
      const ticket = params.get('ticket');
      const token = params.get('token');
      const error = params.get('error');

      if (error) {
        this.failRedirect('UTAmed devolvió un error de autenticación.');
        return;
      }

      if (!ticket && !token) {
        this.failRedirect('No se recibió el token SSO.');
        return;
      }

      if (ticket) {
        this.authService.exchangeSsoTicket(ticket).pipe(take(1)).subscribe({
          next: (response) => this.startSession(response),
          error: () => this.failRedirect('No fue posible validar la sesión SSO.'),
        });

        return;
      }

      this.authService.loginWithSso(token as string).pipe(take(1)).subscribe({
        next: (response) => this.startSession(response),
        error: () => {
          this.failRedirect('No fue posible validar la sesión SSO.');
        }
      });
    });
  }

  private startSession(response: {
    token: string;
    user: { rol: { nombre: string | null } };
  }): void {
    // Las mismas tres claves que escribe el login normal (LoginComponent):
    // el resto de la aplicacion lee la sesion de ahi.
    sessionStorage.clear();
    sessionStorage.setItem('token', response.token);
    sessionStorage.setItem('user', JSON.stringify(response.user));
    sessionStorage.setItem('rol', response.user.rol.nombre ?? 'ALUMNO');

    const role = (response.user.rol.nombre ?? '').toLowerCase();
    const target = role === 'admin' || role === 'super_usuario'
      ? '/admin/dashboard'
      : '/equipos/catalogo';

    this.router.navigate([target], { replaceUrl: true });
  }

  private failRedirect(message: string): void {
    this.message = message + ' Redirigiendo al login...';
    sessionStorage.clear();

    setTimeout(() => {
      this.router.navigate(['/auth/login'], { replaceUrl: true });
    }, 1500);
  }
}