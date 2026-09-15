import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { ApiService } from '../shared/api.service';
import { Company } from '../../models/identity/identity.model';
import { IdentityService } from './identity.service';

/**
 * The caller's own company.
 *
 * No id on any call, because there is none on any of the routes: the company
 * comes off the account server-side, exactly as the driver and customer
 * endpoints are scoped to the token. There is nothing here that could be
 * pointed at somebody else's firm.
 *
 * Every write feeds the answer back into `IdentityService`, because the sidebar
 * draws the logo from `me` rather than from this service. Without that the new
 * mark would not appear until the next full page load, which reads as an upload
 * that silently failed.
 */
@Injectable({ providedIn: 'root' })
export class CompanyService {
  private readonly api = inject(ApiService);
  private readonly identity = inject(IdentityService);

  show(): Observable<Company> {
    return this.api.get<Company>('company');
  }

  /**
   * Change the company's own details — how to reach it, and where its yard is.
   *
   * A PATCH, so a screen that only moves the pin does not have to resend the
   * contact details it never showed. The answer feeds back into
   * `IdentityService` like the logo writes do: the shell reads the company from
   * `me`, and two copies that can disagree is the bug where the card shows the
   * new value and the header keeps the old one.
   */
  updateProfile(attributes: Record<string, unknown>): Observable<Company> {
    return this.api
      .patch<Company>('company', attributes)
      .pipe(tap((c) => this.identity.applyCompany(c)));
  }

  /**
   * Replace the logo.
   *
   * The file goes up as it is — no resizing here. The API normalises every
   * upload to a 64px square PNG, and a client that cropped first would be
   * guessing at rules it does not own, while the handset and any future client
   * would each need their own copy of the guess.
   */
  uploadLogo(file: File): Observable<Company> {
    const form = new FormData();
    form.append('logo', file);

    return this.api.postForm<Company>('company/logo', form).pipe(tap((c) => this.identity.applyCompany(c)));
  }

  removeLogo(): Observable<Company> {
    return this.api.deleteItem<Company>('company/logo').pipe(tap((c) => this.identity.applyCompany(c)));
  }
}
