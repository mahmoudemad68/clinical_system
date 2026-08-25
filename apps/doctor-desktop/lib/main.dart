import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_design_system/clinic_design_system.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Clinic Doctor client — Phase 00 health slice.
///
/// This app delivers no clinical, appointment, pharmacy, or AI capability. It
/// exists to prove that the client can reach the platform, negotiate Arabic or
/// English, and display core health and version.
///
/// Almost nothing here is app-specific: the panel, the strings, the transport,
/// and the models all come from shared packages. That is the monorepo earning
/// its keep (plan.md section 4).
void main() {
  runApp(const ProviderScope(child: ClinicApp()));
}

/// Base URL, supplied at build time.
///
/// A compile-time constant rather than a runtime lookup so a release build
/// cannot be pointed at a different backend by editing a file on the device.
const String kApiBaseUrl = String.fromEnvironment(
  'CLINIC_API_BASE_URL',
  defaultValue: 'http://localhost:8080',
);

final httpClientProvider = Provider<ClinicHttpClient>((ref) {
  final client = ClinicHttpClient(baseUrl: kApiBaseUrl);
  ref.onDispose(client.close);
  return client;
});

final platformApiProvider = Provider<PlatformApi>(
  (ref) => PlatformApi(ref.watch(httpClientProvider)),
);

/// The selected locale.
///
/// A [Notifier] rather than the old `StateProvider`, which Riverpod 3 removed.
/// The explicit notifier is better here anyway: locale changes are a named
/// operation with a validation rule (only supported locales), not an arbitrary
/// assignment any caller can make.
class LocaleController extends Notifier<Locale> {
  @override
  Locale build() => ClinicLocales.english;

  /// Switch language, ignoring anything unsupported.
  ///
  /// Resolving rather than trusting means a locale from the platform, a deep
  /// link, or a restored preference cannot put the app into a language it has
  /// no strings for.
  void select(Locale locale) {
    state = ClinicLocales.resolve(locale);
  }
}

final localeProvider = NotifierProvider<LocaleController, Locale>(
  LocaleController.new,
);

final healthProvider = FutureProvider.autoDispose<PlatformHealth>((ref) async {
  // Language is negotiated server-side so the message comes back localized and
  // the client keeps no second copy of the catalogue.
  final locale = ref.watch(localeProvider);
  ref.watch(httpClientProvider).setLocale(locale.languageCode);

  return ref.watch(platformApiProvider).health();
});

class ClinicApp extends ConsumerWidget {
  const ClinicApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeProvider);

    return MaterialApp(
      title: 'Clinic Doctor',
      debugShowCheckedModeBanner: false,
      theme: ClinicTheme.light(),
      darkTheme: ClinicTheme.dark(),
      locale: locale,
      supportedLocales: ClinicLocales.supported,
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: const HealthScreen(),
    );
  }
}

class HealthScreen extends ConsumerWidget {
  const HealthScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    final health = ref.watch(healthProvider);
    final locale = ref.watch(localeProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(strings.appTitleDoctor),
        actions: [
          // Language switch. Flutter flips layout direction automatically from
          // the locale, so Arabic renders right-to-left without manual work.
          PopupMenuButton<Locale>(
            key: const Key('language-menu'),
            icon: const Icon(Icons.translate),
            tooltip: strings.language,
            initialValue: locale,
            onSelected: (value) =>
                ref.read(localeProvider.notifier).select(value),
            itemBuilder: (context) => const [
              PopupMenuItem(
                value: ClinicLocales.english,
                child: Text('English'),
              ),
              PopupMenuItem(
                value: ClinicLocales.arabic,
                child: Text('العربية'),
              ),
            ],
          ),
        ],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: switch (health) {
              AsyncData(:final value) => HealthPanel(
                health: value,
                isLoading: false,
              ),
              AsyncError(:final error) => HealthPanel(
                health: null,
                isLoading: false,
                errorMessage: error is ApiFailure
                    ? error.message
                    : strings.healthUnreachable,
                requestId: error is ApiFailure ? error.requestId : null,
                onRetry: () => ref.invalidate(healthProvider),
              ),
              _ => const HealthPanel(health: null, isLoading: true),
            },
          ),
        ),
      ),
    );
  }
}
