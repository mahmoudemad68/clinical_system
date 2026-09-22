import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_design_system/clinic_design_system.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth_panel.dart';
import 'onboarding/onboarding_screen.dart';
import 'onboarding/review_holding_screen.dart';
import 'profile/account_gate_screen.dart';
import 'profile/profile_screen.dart';
import 'providers.dart';
import 'session/patient_session.dart';
import 'widgets/patient_chrome.dart';

void main() {
  runApp(const ProviderScope(child: ClinicApp()));
}

final healthProvider = FutureProvider.autoDispose<PlatformHealth>((ref) async {
  final locale = ref.watch(localeProvider);
  ref.watch(httpClientProvider).setLocale(locale.languageCode);
  return ref.watch(platformApiProvider).health();
});

class ClinicApp extends ConsumerStatefulWidget {
  const ClinicApp({super.key});

  @override
  ConsumerState<ClinicApp> createState() => _ClinicAppState();
}

class _ClinicAppState extends ConsumerState<ClinicApp> {
  @override
  void initState() {
    super.initState();
    Future.microtask(() => ref.read(patientSessionProvider.notifier).restore());
  }

  @override
  Widget build(BuildContext context) {
    final locale = ref.watch(localeProvider);

    return MaterialApp(
      title: 'Clinic Patient',
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
      home: const PatientShell(),
      onGenerateRoute: (settings) {
        if (settings.name == '/onboarding/profile') {
          return MaterialPageRoute<void>(
            settings: settings,
            builder: (_) => const OnboardingScreen(),
          );
        }
        if (settings.name == '/onboarding/review') {
          return MaterialPageRoute<void>(
            settings: settings,
            builder: (_) => const ReviewHoldingScreen(),
          );
        }
        if (settings.name == '/profile') {
          final session = ref.read(patientSessionProvider);
          if (session is PatientRouteProfile) {
            return MaterialPageRoute<void>(
              settings: settings,
              builder: (_) => ProfileScreen(profile: session.profile),
            );
          }
        }
        return null;
      },
    );
  }
}

class PatientShell extends ConsumerWidget {
  const PatientShell({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final route = ref.watch(patientSessionProvider);
    return switch (route) {
      PatientRouteBooting() ||
      PatientRouteResolving() => const _LoadingScreen(),
      PatientRouteUnauthenticated(:final message) => AuthScreen(
        message: message,
      ),
      PatientRouteWrongAccount(:final accountType) => AccountGateScreen(
        accountType: accountType,
      ),
      PatientRouteOnboarding() => const OnboardingScreen(),
      PatientRouteManualReview() => const ReviewHoldingScreen(),
      PatientRouteProfile(:final profile) => ProfileScreen(profile: profile),
      PatientRouteFailure(:final failure) => _FailureScreen(failure: failure),
    };
  }
}

class _LoadingScreen extends StatelessWidget {
  const _LoadingScreen();

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);
    return Scaffold(
      body: Center(
        child: Semantics(
          label: strings.loadingProfile,
          child: const CircularProgressIndicator(),
        ),
      ),
    );
  }
}

class _FailureScreen extends ConsumerWidget {
  const _FailureScreen({required this.failure});

  final ApiFailure failure;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(strings.appTitlePatient),
        actions: [
          const LanguageMenuButton(),
          SignOutButton(
            onPressed: () =>
                ref.read(patientSessionProvider.notifier).signOut(),
          ),
        ],
      ),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              failure.isAuthentication
                  ? strings.sessionExpired
                  : strings.profileUnreachable,
              key: const Key('session-error'),
            ),
            if (failure.requestId != null) ...[
              const SizedBox(height: 8),
              Text('${strings.requestId}: ${failure.requestId}'),
            ],
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () =>
                  ref.read(patientSessionProvider.notifier).refresh(),
              child: Text(strings.retryAction),
            ),
          ],
        ),
      ),
    );
  }
}

class AuthScreen extends ConsumerWidget {
  const AuthScreen({super.key, this.message});

  final String? message;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    final health = ref.watch(healthProvider);
    final locale = ref.watch(localeProvider);
    ref.watch(httpClientProvider).setLocale(locale.languageCode);

    return Scaffold(
      appBar: AppBar(
        title: Text(strings.appTitlePatient),
        actions: const [LanguageMenuButton()],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: ListView(
              children: [
                if (message != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Text(message!),
                  ),
                PatientAuthPanel(
                  api: ref.watch(authApiProvider),
                  onAuthenticated: () => ref
                      .read(patientSessionProvider.notifier)
                      .onAuthenticated(),
                ),
                const SizedBox(height: 24),
                switch (health) {
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
              ],
            ),
          ),
        ),
      ),
    );
  }
}
