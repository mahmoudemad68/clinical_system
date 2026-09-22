import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:clinic_secure_storage/clinic_secure_storage.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'shell/locale_controller.dart';

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

final tokenStoreProvider = Provider<TokenStore>((ref) {
  return TokenStore(SecureStorageVault(ClinicSecureStorage()));
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

final platformApiProvider = Provider<PlatformApi>(
  (ref) => PlatformApi(ref.watch(httpClientProvider)),
);

final patientApiProvider = Provider<PatientApi>(
  (ref) => PatientApi(ref.watch(httpClientProvider)),
);

final healthProvider = FutureProvider<PlatformHealth>((ref) async {
  final locale = ref.watch(localeProvider);
  ref.watch(httpClientProvider).setLocale(locale.languageCode);
  return ref.watch(platformApiProvider).health();
});
