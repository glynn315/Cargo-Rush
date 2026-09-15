import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { AbstractControl, FormBuilder, ReactiveFormsModule, ValidationErrors, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { environment } from '../../../environments/environment';
import { GeoPoint } from '../../models/geo/geo.model';
import { IdentityService } from '../../services/identity/identity.service';
import { Field } from '../../shared/field';
import { MapPicker } from '../../shared/map-picker';
import { Wordmark } from '../../shared/wordmark';

/**
 * Register a company — the way onto the platform.
 *
 * Sits beside sign-in, outside the layout, for the same reason: there is no
 * sidebar to render until somebody is signed in, and both calls that fill the
 * shell are authenticated ones.
 *
 * It asks for two things at once, a company and a person, and the form says so
 * with two headings rather than presenting nine fields in a row. They are one
 * act: the API creates both in a single transaction, because a company nobody
 * can sign in to is a row and an account with no company has nowhere to put
 * anything.
 *
 * There is no field for the company's code or its plan. The code is derived
 * from the name server-side — it is a handle rather than a choice — and asking
 * somebody to invent one before they have seen the product is a question they
 * have no basis to answer.
 *
 * It does ask where the yard is, on a map. That is the one field here that does
 * something the company cannot do for itself later without knowing to look: a
 * pinned company appears on the carrier list customers pick from in the app, and
 * an unpinned one is invisible to every shipper who has not already got the
 * phone number. Optional even so — whoever registers may be sitting nowhere near
 * the depot — and movable afterwards on the company card.
 */
@Component({
  selector: 'app-register',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Field, MapPicker, Wordmark],
  templateUrl: './register.page.html',
})
export class RegisterPage {
  private readonly identity = inject(IdentityService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  protected readonly submitting = signal(false);
  protected readonly failure = signal<string | null>(null);

  /**
   * Where the yard is, as a pin.
   *
   * Held here rather than as two form controls because it arrives as one thing
   * from the map and is read back as one thing: a latitude that outlived a
   * change of place is the bug this shape cannot have. The form carries the
   * fields it types; this carries the field it points at.
   */
  protected readonly pin = signal<GeoPoint | null>(null);

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly form = this.fb.nonNullable.group(
    {
      company_name: ['', [Validators.required, Validators.maxLength(120)]],
      contact_phone: ['', Validators.maxLength(40)],
      address: ['', Validators.maxLength(200)],

      name: ['', [Validators.required, Validators.maxLength(120)]],
      email: ['', [Validators.required, Validators.email, Validators.maxLength(160)]],
      // Eight is the API's floor too. Matching it here means the mismatch is
      // caught before a round trip rather than coming back as a 422.
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', Validators.required],
    },
    { validators: RegisterPage.passwordsMatch },
  );

  /**
   * The two password fields have to agree.
   *
   * A group validator rather than a field one, because the error belongs to the
   * pair — neither box is wrong on its own, and marking the second one invalid
   * while the first is untouched reads as a typo in the wrong place.
   */
  private static passwordsMatch(group: AbstractControl): ValidationErrors | null {
    const password = group.get('password')?.value;
    const confirmation = group.get('password_confirmation')?.value;

    if (!confirmation) return null;

    return password === confirmation ? null : { mismatch: true };
  }

  /**
   * The map reporting a pin, or the pin being cleared.
   *
   * The looked-up place name fills the address box when it is still empty and
   * never overwrites one somebody typed: "Km 9, Sasa" beats "Barangay Sasa",
   * and the person who wrote the first one knows their own yard better than a
   * geocoder does.
   */
  protected pinned(point: GeoPoint | null): void {
    this.pin.set(point);

    const address = this.form.controls.address;

    if (point?.place && !address.value.trim()) {
      address.setValue(point.place);
    }
  }

  protected errorFor(name: string): string | null {
    const control = this.form.get(name);
    if (!control || !(control.touched || control.dirty)) return null;

    if (name === 'password_confirmation' && this.form.hasError('mismatch') && control.value) {
      return 'The two passwords do not match.';
    }

    if (control.valid) return null;
    if (control.hasError('required')) return 'This field is required.';
    if (control.hasError('email')) return 'Enter a valid email address.';
    if (control.hasError('minlength')) return 'Use at least 8 characters.';
    if (control.hasError('maxlength')) return 'That is too long.';

    return 'Check this value.';
  }

  /**
   * Say which thing went wrong.
   *
   * The same reasoning as the sign-in form's: status 0 is the only one that
   * really means unreachable, and lumping the others in with it sends somebody
   * to restart an API that is already running.
   *
   * A 422 here is worth reading field by field, unlike a login's. Registration
   * has seven fields and the server is the only thing that knows some of the
   * rules — that the address already has an account, or that the password is on
   * the breached list — so "check your details" would hide the one sentence
   * that fixes it.
   */
  private messageFor(error: HttpErrorResponse): string {
    if (error.status === 422) {
      const errors = error.error?.errors as Record<string, string[]> | undefined;
      const first = errors && Object.values(errors)[0]?.[0];

      return first ?? error.error?.message ?? 'Some of those details were not accepted.';
    }

    if (error.status === 0) {
      return 'Cannot reach the server. Check that CargoApi is running on ' + environment.apiUrl + '.';
    }

    if (error.status === 419) {
      return 'The session token was rejected. Reload the page and try again.';
    }

    /**
     * The registration endpoint is metered per hour, per address — and it
     * counts the attempts that *failed* validation too, so a few passes at
     * getting the form right can use the budget up.
     *
     * Said plainly, because "the server refused that request (429)" reads as a
     * broken form rather than as "wait a few minutes", and somebody who thinks
     * the form is broken will keep pressing the button.
     */
    if (error.status === 429) {
      return 'Too many registration attempts from this connection. Wait a few minutes and try again.';
    }

    if (error.status >= 500) {
      return 'The server hit an error handling that. Check the CargoApi log.';
    }

    return error.error?.message ?? `The server refused that request (${error.status}).`;
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();

      return;
    }

    this.submitting.set(true);
    this.failure.set(null);

    const { contact_phone, address, ...required } = this.form.getRawValue();
    const pin = this.pin();

    this.identity
      .register({
        ...required,
        // Omitted rather than sent empty. A blank string would be stored as
        // the company's phone number, and "" is not a phone number nobody
        // gave — it is a phone number somebody typed.
        ...(contact_phone ? { contact_phone } : {}),
        ...(address ? { address } : {}),
        // A pair or neither — the API refuses half a coordinate, and there is
        // no half a place to send.
        ...(pin ? { latitude: pin.lat, longitude: pin.lng } : {}),
      })
      .subscribe({
        next: () => {
          // Registering signs you in, so this goes straight into the app —
          // the same landing a sign-in gets. No `next` to honour: nobody
          // arrives at registration from a deep link into a company that did
          // not exist yet.
          this.identity.loadNavigation().subscribe();
          this.router.navigateByUrl('/dashboard');
        },
        error: (error: HttpErrorResponse) => {
          this.submitting.set(false);
          this.failure.set(this.messageFor(error));
        },
      });
  }
}
