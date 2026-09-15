import {
  createContext,
  ReactNode,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';
import { Platform } from 'react-native';

import { Credentials, Me, ShipperRegistration } from '@/models/identity/identity.model';

import { api } from '../shared/api.service';
import { identityService } from './identity.service';
import { tokenStore } from './token-store';

export type SessionState = {
  /** Null until the stored token has been checked. */
  me: Me | null;
  /** True while restoring on launch — the app shows nothing rather than a flash of the sign-in form. */
  restoring: boolean;
  signIn: (credentials: Omit<Credentials, 'device_name'>) => Promise<void>;
  /**
   * Sign a new firm up and open the app on them.
   *
   * Beside `signIn` rather than inside the registration screen, because what it
   * has to do afterwards is identical — keep the token, and put a `me` in
   * context — and a second copy of that would be a second place for the token
   * to be forgotten.
   */
  register: (registration: Omit<ShipperRegistration, 'device_name'>) => Promise<void>;
  signOut: () => Promise<void>;
};

const SessionContext = createContext<SessionState | null>(null);

/**
 * Who is signed in, for the whole app.
 *
 * The token is restored from the keychain on launch and verified with
 * `GET /me` before the tabs render. Trusting a stored token without checking
 * it would put a driver into a dashboard that then 401s on every panel —
 * worse than showing the sign-in form.
 */
export function SessionProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  const [restoring, setRestoring] = useState(true);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      const token = await tokenStore.read();

      if (token === null) {
        if (!cancelled) setRestoring(false);

        return;
      }

      api.setToken(token);

      try {
        const user = await identityService.me();
        if (!cancelled) setMe(user);
      } catch {
        // Expired or revoked. Drop it rather than leaving a token that fails
        // every call for the rest of the session.
        api.setToken(null);
        await tokenStore.clear();
      } finally {
        if (!cancelled) setRestoring(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const signIn = useCallback(async (credentials: Omit<Credentials, 'device_name'>) => {
    const user = await identityService.login({
      ...credentials,
      // Names this handset on the token, so signing in here does not sign the
      // driver out of another device.
      device_name: deviceName(),
    });

    const token = api.token;
    if (token !== null) await tokenStore.write(token);

    setMe(user);
  }, []);

  const register = useCallback(
    async (registration: Omit<ShipperRegistration, 'device_name'>) => {
      const user = await identityService.registerCustomer({
        ...registration,
        device_name: deviceName(),
      });

      const token = api.token;
      if (token !== null) await tokenStore.write(token);

      setMe(user);
    },
    [],
  );

  const signOut = useCallback(async () => {
    // Cleared locally first: a driver who taps sign out is signed out whether
    // or not the network agrees.
    setMe(null);
    api.setToken(null);
    await tokenStore.clear();

    await identityService.logout().catch(() => undefined);
  }, []);

  const value = useMemo<SessionState>(
    () => ({ me, restoring, signIn, register, signOut }),
    [me, restoring, signIn, register, signOut],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionState {
  const session = useContext(SessionContext);

  if (session === null) {
    throw new Error('useSession must be used inside a SessionProvider.');
  }

  return session;
}

/** Something a person would recognise in a list of their signed-in devices. */
function deviceName(): string {
  return `cargoApp ${Platform.OS}`;
}
