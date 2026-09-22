import 'package:clinic_design_system/clinic_design_system.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app_providers.dart';
import '../auth_panel.dart';
import '../onboarding/manual_review_screen.dart';
import '../onboarding/onboarding_controller.dart';
import '../onboarding/onboarding_screen.dart';
import '../profile/demographics_edit_controller.dart';
import '../profile/own_profile_controller.dart';
import '../profile/patient_surface.dart';
import '../profile/profile_screen.dart';
import '../session/session_controller.dart';
import 'locale_controller.dart';
import 'patient_chrome.dart';
import 'unsupported_account_screen.dart';

class PatientShell extends ConsumerWidget {
  const PatientShell({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.listen(sessionProvider, (previous, next) {
      final previousId = previous?.asData?.value?.userId;
      final nextId = next.asData?.value?.userId;
      if (previousId != null && previousId != nextId) {
        ref.read(manualReviewProvider.notifier).clear();
        ref.read(onboardingDraftProvider.notifier).reset();
        ref.read(demographicsEditProvider.notifier).reset();
        ref.invalidate(ownProfileProvider);
      }
    });

    final strings = ClinicStrings.of(context);
    final surface = ref.watch(patientSurfaceProvider);
    final locale = ref.watch(localeProvider);
    ref.watch(httpClientProvider).setLocale(locale.languageCode);

    return switch (surface) {
      PatientSurface.auth => _AuthSurface(strings: strings),
      PatientSurface.resolving => PatientChrome(
        title: strings.appTitlePatient,
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text(
              strings.resolvingProfile,
              key: const Key('profile-resolving'),
            ),
          ),
        ),
      ),
      PatientSurface.unsupported => const UnsupportedAccountScreen(),
      PatientSurface.onboarding => const OnboardingScreen(),
      PatientSurface.manualReview => const ManualReviewScreen(),
      PatientSurface.profile => const ProfileScreen(),
      PatientSurface.loadError => _LoadErrorSurface(strings: strings),
    };
  }
}

class _AuthSurface extends ConsumerWidget {
  const _AuthSurface({required this.strings});

  final ClinicStrings strings;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final health = ref.watch(healthProvider);

    return PatientChrome(
      title: strings.appTitlePatient,
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          PatientAuthPanel(
            api: ref.watch(authApiProvider),
            onAuthenticated: () {
              ref.read(sessionProvider.notifier).markAuthenticated();
            },
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
    );
  }
}

class _LoadErrorSurface extends ConsumerWidget {
  const _LoadErrorSurface({required this.strings});

  final ClinicStrings strings;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(ownProfileProvider);
    final session = ref.watch(sessionProvider);
    final error = profile.asError?.error ?? session.asError?.error;
    final failure = error is ApiFailure ? error : null;

    return PatientChrome(
      title: strings.appTitlePatient,
      showSignOut: true,
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              strings.profileLoadFailed,
              key: const Key('profile-load-error'),
            ),
            if (failure?.requestId != null) ...[
              const SizedBox(height: 8),
              Text('${strings.requestId}: ${failure!.requestId}'),
            ],
            const SizedBox(height: 16),
            FilledButton(
              key: const Key('profile-retry'),
              onPressed: () {
                ref.invalidate(sessionProvider);
                ref.invalidate(ownProfileProvider);
              },
              child: Text(strings.retryAction),
            ),
          ],
        ),
      ),
    );
  }
}
