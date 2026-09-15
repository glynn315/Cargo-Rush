import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { environment } from '../../../environments/environment';
import { IdentityService } from '../../services/identity/identity.service';
import { Field } from '../../shared/field';
import { Wordmark } from '../../shared/wordmark';

/**
 * "I've forgotten my password."
 *
 * Until this existed there was no answer to that: every account was created by
 * somebody else, so a forgotten password meant a person with server access
 * running a command. Fine for one office, impossible once strangers register
 * their own company.
 *
 * **The confirmation is deliberately non-committal.** The API answers the same
 * way whether or not the address has an account, because saying otherwise
 * would make this a way of asking who is on the platform — and this one hosts
 * hauliers who compete with each other. So the screen says *if that address
 * has an account*, and never "we've sent you an email", which would be a claim
 * the server refused to make.
 */
@Component({
  selector: 'app-forgot-password',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Field, Wordmark],
  templateUrl: './forgot-password.page.html',
})
export class ForgotPasswordPage {
  private readonly identity = inject(IdentityService);
  private readonly fb = inject(FormBuilder);

  protected readonly submitting = signal(false);
  protected readonly failure = signal<string | null>(null);
  protected readonly sent = signal<string | null>(null);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
  });

  protected errorFor(name: string): string | null {
    const control = this.form.get(name);
    if (!control || control.valid || !(control.touched || control.dirty)) return null;
    if (control.hasError('required')) return 'This field is required.';
    if (control.hasError('email')) return 'Enter a valid email address.';

    return 'Check this value.';
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.submitting.set(true);
    this.failure.set(null);

    this.identity.forgotPassword(this.form.getRawValue().email).subscribe({
      next: (result) => {
        this.submitting.set(false);
        this.sent.set(result.message);
      },
      error: (error: HttpErrorResponse) => {
        this.submitting.set(false);

        if (error.status === 429) {
          // The one failure worth its own sentence. A generic message here
          // reads as "it is broken" when the truth is "you have asked several
          // times already and the emails are on their way".
          this.failure.set('Too many attempts. Wait a minute and try again.');

          return;
        }

        if (error.status === 0) {
          this.failure.set(
            'Cannot reach the server. Check that CargoApi is running on ' + environment.apiUrl + '.',
          );

          return;
        }

        this.failure.set(
          error.error?.errors?.email?.[0] ?? error.error?.message ?? 'Could not send that. Try again.',
        );
      },
    });
  }
}
