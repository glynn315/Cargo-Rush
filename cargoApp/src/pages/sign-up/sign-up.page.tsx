import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon } from '@/components/ui/icon';
import { Wordmark } from '@/components/ui/wordmark';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { useSession } from '@/services/identity/session';
import { apiBaseUrl, ApiRequestError } from '@/services/shared/api.service';

/**
 * Sign up as a customer — the app's way in.
 *
 * A **customer**, and only a customer. The web is where a haulier registers a
 * company, with a fleet, a roster and a set of books behind it; nothing like
 * that is asked for here, and asking would be asking the wrong person. This
 * screen is for somebody with a load and nobody carrying it.
 *
 * There are two ways a customer account comes to exist and this is the one that
 * does not involve a phone call. A haulier's office can add a customer to its
 * books and hand over credentials, and that account is theirs — it books with
 * them and sees nobody else. This is the other end.
 *
 * **Four fields, and that is the whole form.** A name, a number, an email
 * address and a password. Two things it deliberately does not ask:
 *
 *   *Who should carry it.* That is a decision per load, made on the request
 *   form from the hauliers near wherever the load is going out from, and a firm
 *   that registered here rather than with a company may answer differently
 *   every week. Asked here it would be a commitment made before there was
 *   anything to commit about — and a price nobody could see yet.
 *
 *   *Where the load is.* Also per load. A firm with two warehouses does not
 *   have *an* address, and one pinned once at sign-up would quietly become the
 *   origin of every request afterwards. The request form pins it, from the
 *   handset's own position or the map, which is where somebody actually knows.
 *
 * So this account belongs to no haulier when it is created, and their first
 * request is what makes them somebody's customer. Nothing about the screen after
 * this one depends on having chosen already.
 *
 * One name does for both the login and the customer record a carrier will
 * eventually open. A trading name, a TIN and a VAT treatment are the haulier's
 * record to keep and the office's form to fill in; a person signing up on a
 * phone should type their name once.
 *
 * Registering signs you in. The API answers in the same shape a login does, so
 * the app carries on into the portal down the same code path.
 */
export function SignUpPage({ onBack }: { onBack: () => void }) {
  const insets = useSafeAreaInsets();
  const { register } = useSession();

  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [secret, setSecret] = useState('');
  const [confirmation, setConfirmation] = useState('');

  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const submit = async () => {
    if (busy) return;

    // Checked here as well as by the API, so somebody is told which field is
    // missing before a round trip rather than after one.
    if (!name.trim() || !email.trim()) {
      setFailure('Fill in your name and your email address.');

      return;
    }

    if (secret.length < 8) {
      setFailure('Use a password of at least 8 characters.');

      return;
    }

    if (secret !== confirmation) {
      setFailure('The two passwords do not match.');

      return;
    }

    setBusy(true);
    setFailure(null);
    setFieldErrors({});

    try {
      await register({
        name: name.trim(),
        ...(phone.trim() ? { contact_phone: phone.trim() } : {}),
        email: email.trim(),
        password: secret,
        password_confirmation: confirmation,
      });
      // No `setBusy(false)` on success: the portal replaces this screen, and
      // re-enabling a button on an unmounting form is a warning for nothing.
    } catch (error) {
      if (error instanceof ApiRequestError) setFieldErrors(error.fieldErrors);
      setFailure(messageFor(error));
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView
      style={styles.root}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView
        contentContainerStyle={[
          styles.scroll,
          { paddingTop: insets.top + Spacing.four, paddingBottom: insets.bottom + Spacing.six },
        ]}
        keyboardShouldPersistTaps="handled">
        <View style={styles.brand}>
          <Wordmark size={24} />
        </View>

        <Pressable onPress={onBack} accessibilityRole="button" style={styles.backBtn}>
          <Icon name="chevron-left" size={16} color={Brand.blue} />
          <Text style={styles.backText}>Back to sign in</Text>
        </Pressable>

        <View style={styles.card}>
          <Text style={styles.heading}>Create a customer account</Text>
          <Text style={styles.sub}>
            Four fields and you are in. You pick who carries each load — and say where it is
            going out from — when you send your first pickup request.
          </Text>

          {failure ? (
            <Text style={styles.failure} accessibilityLiveRegion="polite" accessibilityRole="alert">
              {failure}
            </Text>
          ) : null}

          <Text style={styles.section}>YOUR DETAILS</Text>

          {/* One name, and it does for both: the login and the customer record
              a carrier opens the first time you send with them. This registers
              a customer, not a business, so there is no trading name to ask
              for — the office can rename the record if a firm needs to be
              billed under something else. */}
          <Field
            label="YOUR NAME"
            value={name}
            onChange={setName}
            placeholder="e.g. Rita Uy"
            error={fieldErrors['name']?.[0]}
          />

          <Field
            label="CONTACT NUMBER"
            value={phone}
            onChange={setPhone}
            placeholder="0917 000 1111"
            keyboard="phone-pad"
            hint="How a carrier's office reaches you about a pickup."
            error={fieldErrors['contact_phone']?.[0]}
          />

          <Text style={styles.section}>YOUR LOGIN</Text>

          <Field
            label="EMAIL"
            value={email}
            onChange={setEmail}
            placeholder="you@yourbusiness.ph"
            keyboard="email-address"
            autoCapitalize="none"
            error={fieldErrors['email']?.[0]}
          />

          <Field
            label="PASSWORD"
            value={secret}
            onChange={setSecret}
            placeholder="••••••••"
            secure
            hint="At least 8 characters."
            error={fieldErrors['password']?.[0]}
          />

          <Field
            label="CONFIRM PASSWORD"
            value={confirmation}
            onChange={setConfirmation}
            placeholder="••••••••"
            secure
          />

          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Create account"
            accessibilityState={{ disabled: busy }}
            disabled={busy}
            onPress={submit}
            style={({ pressed }) => [
              styles.submit,
              pressed && { backgroundColor: Brand.blueHover },
              busy && { opacity: 0.5 },
            ]}>
            {busy ? (
              <ActivityIndicator color={Brand.surface} />
            ) : (
              <Text style={styles.submitText}>Create account</Text>
            )}
          </Pressable>

          <Text style={styles.footnote}>
            Your account is not tied to one haulier. Every request shows you the carriers near
            your load, and you choose.
          </Text>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function Field({
  label,
  value,
  onChange,
  placeholder,
  keyboard,
  secure,
  autoCapitalize,
  hint,
  error,
}: {
  label: string;
  value: string;
  onChange: (next: string) => void;
  placeholder: string;
  keyboard?: 'default' | 'phone-pad' | 'email-address';
  secure?: boolean;
  autoCapitalize?: 'none' | 'sentences' | 'words';
  hint?: string;
  error?: string;
}) {
  return (
    <View style={{ marginTop: Spacing.three }}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor={Brand.inkMuted}
        keyboardType={keyboard ?? 'default'}
        secureTextEntry={secure}
        autoCapitalize={autoCapitalize ?? 'sentences'}
        autoCorrect={false}
        accessibilityLabel={label}
        style={[styles.input, error ? { borderColor: Brand.red } : null]}
      />
      {error ? (
        <Text style={styles.fieldError} accessibilityLiveRegion="polite">
          {error}
        </Text>
      ) : hint ? (
        <Text style={styles.hint}>{hint}</Text>
      ) : null}
    </View>
  );
}

/**
 * A rejected detail and an unreachable server are different problems.
 *
 * The 422 is read field by field rather than summarised, because the server is
 * the only thing that knows some of these rules — that the address already has
 * an account, for one — and "check your details" would hide the one sentence
 * that fixes it.
 */
function messageFor(error: unknown): string {
  if (error instanceof ApiRequestError) {
    if (error.status === 422) {
      const first = Object.values(error.fieldErrors)[0]?.[0];

      return first ?? error.body.message ?? 'Some of those details were not accepted.';
    }

    if (error.status === 429) {
      return 'Too many attempts from this connection. Try again in a little while.';
    }

    if (error.status >= 500) {
      return 'The server hit an error handling that. Try again shortly.';
    }

    return error.body.message || `The server refused that request (${error.status}).`;
  }

  return `Cannot reach ${apiBaseUrl}. Check your signal, and that the server is running.`;
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: Brand.tint },
  scroll: { flexGrow: 1, paddingHorizontal: Spacing.three },
  brand: { alignItems: 'center', marginBottom: Spacing.three },

  backBtn: {
    alignSelf: 'flex-start',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    minHeight: Hit.min,
    paddingRight: Spacing.two,
  },
  backText: { fontSize: 14, fontWeight: '600', color: Brand.blue },

  card: {
    backgroundColor: Brand.surface,
    borderRadius: Radius.panel,
    padding: Spacing.four,
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 2 },
    elevation: 3,
  },

  heading: { fontSize: 18, fontWeight: '700', color: Brand.ink },
  sub: { marginTop: 4, fontSize: 14, lineHeight: 20, color: Brand.inkMuted },

  failure: {
    marginTop: Spacing.three,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.redBg,
    color: Brand.red,
    fontSize: 13,
    fontWeight: '500',
  },

  section: {
    marginTop: Spacing.five,
    marginBottom: -Spacing.one,
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.8,
    color: Brand.blue,
  },

  label: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  input: {
    marginTop: 6,
    minHeight: 46,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    paddingHorizontal: Spacing.three,
    fontSize: 15,
    color: Brand.ink,
    backgroundColor: Brand.surface,
  },
  hint: { marginTop: 4, fontSize: 12, lineHeight: 17, color: Brand.inkMuted },
  fieldError: { marginTop: 4, fontSize: 12, fontWeight: '500', color: Brand.red },

  submit: {
    marginTop: Spacing.five,
    height: 48,
    borderRadius: Radius.control,
    backgroundColor: Brand.blue,
    alignItems: 'center',
    justifyContent: 'center',
  },
  submitText: { color: Brand.surface, fontSize: 15, fontWeight: '600' },

  footnote: {
    marginTop: Spacing.three,
    fontSize: 12,
    lineHeight: 17,
    color: Brand.inkMuted,
    textAlign: 'center',
  },
});
