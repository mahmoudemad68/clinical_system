import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:clinic_secure_storage/clinic_secure_storage.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Base URL, supplied at build time.
const String kApiBaseUrl = String.fromEnvironment(
  'CLINIC_API_BASE_URL',
  defaultValue: 'http://localhost:8080',
);

final httpClientProvider = Provider<ClinicHttpClient>((ref) {
  final client = ClinicHttpClient(baseUrl: kApiBaseUrl);
  ref.onDispose(client.close);
  return client;
});

final credentialVaultProvider = Provider<CredentialVault>((ref) {
  return SecureStorageVault(ClinicSecureStorage());
});

final tokenStoreProvider = Provider<TokenStore>((ref) {
  return TokenStore(ref.watch(credentialVaultProvider));
});

final authApiProvider = Provider<AuthApi>((ref) {
  final client = ref.watch(httpClientProvider);
  final tokens = ref.watch(tokenStoreProvider);
  final api = AuthApi(client, tokens);
  final alreadyAttached = client.dio.interceptors.any(
    (interceptor) => interceptor is AuthInterceptor,
  );
  if (!alreadyAttached) {
    client.dio.interceptors.add(
      AuthInterceptor(store: tokens, client: client, refresh: api.refresh),
    );
  }
  return api;
});

final patientApiProvider = Provider<PatientApi>(
  (ref) => PatientApi(ref.watch(httpClientProvider)),
);

final platformApiProvider = Provider<PlatformApi>(
  (ref) => PlatformApi(ref.watch(httpClientProvider)),
);

class LocaleController extends Notifier<Locale> {
  LocaleController({Locale? initial})
    : _initial = initial ?? ClinicLocales.english;

  final Locale _initial;

  @override
  Locale build() => ClinicLocales.resolve(_initial);

  void select(Locale locale) {
    state = ClinicLocales.resolve(locale);
  }
}

final localeProvider = NotifierProvider<LocaleController, Locale>(
  LocaleController.new,
);
