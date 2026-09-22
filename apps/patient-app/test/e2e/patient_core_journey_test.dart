import 'dart:io';

import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:clinic_patient_app/onboarding/onboarding_screen.dart';
import 'package:clinic_patient_app/onboarding/review_holding_screen.dart';
import 'package:clinic_patient_app/profile/edit_demographics_screen.dart';
import 'package:clinic_patient_app/profile/profile_screen.dart';
import 'package:clinic_patient_app/providers.dart';
import 'package:clinic_patient_app/session/patient_session.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/harness.dart';

/// Real local-Core journey. Skipped unless CLINIC_E2E_BASE_URL is set.
///
/// Distinguishes widget coverage (always run) from a live Core journey
/// (this file). Android/iOS simulators are not launched here.
void main() {
  final baseUrl = Platform.environment['CLINIC_E2E_BASE_URL'];
  final phone = Platform.environment['CLINIC_E2E_PHONE'];
  final password = Platform.environment['CLINIC_E2E_PASSWORD'];
  final nationalId = Platform.environment['CLINIC_E2E_NATIONAL_ID'];
  final challengerPhone = Platform.environment['CLINIC_E2E_CHALLENGER_PHONE'];
  final ownerNationalId = Platform.environment['CLINIC_E2E_OWNER_NATIONAL_ID'];
  final switchPhone = Platform.environment['CLINIC_E2E_SWITCH_PHONE'];
  final switchName = Platform.environment['CLINIC_E2E_SWITCH_NAME'];

  final enabled =
      baseUrl != null &&
      baseUrl.isNotEmpty &&
      phone != null &&
      password != null &&
      nationalId != null;

  testWidgets(
    'fresh patient onboards, edits, conflicts, and isolates accounts against Core',
    (tester) async {
      final vault = MemoryVault();
      final client = ClinicHttpClient(baseUrl: baseUrl!);
      addTearDown(client.close);
      final tokens = TokenStore(vault);
      final auth = AuthApi(client, tokens);
      client.dio.interceptors.add(
        AuthInterceptor(store: tokens, client: client, refresh: auth.refresh),
      );
      final patients = PatientApi(client);

      final container = ProviderContainer(
        overrides: [
          credentialVaultProvider.overrideWithValue(vault),
          httpClientProvider.overrideWithValue(client),
          authApiProvider.overrideWithValue(auth),
          patientApiProvider.overrideWithValue(patients),
        ],
      );
      addTearDown(container.dispose);

      await auth.login(
        phone: phone!,
        password: password!,
        platform: 'android',
        deviceLabel: 'patient-e2e',
      );
      await container.read(patientSessionProvider.notifier).onAuthenticated();
      expect(
        container.read(patientSessionProvider),
        isA<PatientRouteOnboarding>(),
      );

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: materialHost(const OnboardingScreen()),
        ),
      );
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('onboarding-national-id')),
        nationalId!,
      );
      await tester.enterText(
        find.byKey(const Key('onboarding-full-name')),
        'E2E Patient',
      );
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Female'));
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('onboarding-height')), '166');
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pumpAndSettle();
      expect(find.text(nationalId), findsNothing);
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pumpAndSettle(const Duration(seconds: 8));

      final profileState = container.read(patientSessionProvider);
      expect(profileState, isA<PatientRouteProfile>());
      final profile = (profileState as PatientRouteProfile).profile;
      expect(profile.fullName, 'E2E Patient');
      expect(profile.version, 1);

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: materialHost(EditDemographicsScreen(profile: profile)),
        ),
      );
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('edit-weight')), '70');
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pumpAndSettle(const Duration(seconds: 8));
      final afterEdit =
          container.read(patientSessionProvider) as PatientRouteProfile;
      expect(afterEdit.profile.version, 2);

      final otherClient = ClinicHttpClient(baseUrl: baseUrl);
      addTearDown(otherClient.close);
      final otherTokens = TokenStore(MemoryVault());
      final otherAuth = AuthApi(otherClient, otherTokens);
      otherClient.dio.interceptors.add(
        AuthInterceptor(
          store: otherTokens,
          client: otherClient,
          refresh: otherAuth.refresh,
        ),
      );
      await otherAuth.login(
        phone: phone,
        password: password,
        platform: 'android',
        deviceLabel: 'stale-sibling',
      );
      await PatientApi(otherClient).updateDemographics(
        version: afterEdit.profile.version,
        maritalStatus: 'married',
      );

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: materialHost(
            EditDemographicsScreen(profile: afterEdit.profile),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('edit-height')), '171');
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pumpAndSettle(const Duration(seconds: 8));
      expect(find.byKey(const Key('version-conflict')), findsOneWidget);
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pumpAndSettle(const Duration(seconds: 8));
      expect(find.byKey(const Key('version-conflict')), findsNothing);

      if (switchPhone != null && switchName != null) {
        await container.read(patientSessionProvider.notifier).signOut();
        await auth.login(
          phone: switchPhone,
          password: password,
          platform: 'android',
          deviceLabel: 'patient-e2e-b',
        );
        await container.read(patientSessionProvider.notifier).onAuthenticated();
        final switched = container.read(patientSessionProvider);
        expect(switched, isA<PatientRouteProfile>());
        expect((switched as PatientRouteProfile).profile.fullName, switchName);
        expect(switched.profile.fullName, isNot('E2E Patient'));
        await tester.pumpWidget(
          UncontrolledProviderScope(
            container: container,
            child: materialHost(ProfileScreen(profile: switched.profile)),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('E2E Patient'), findsNothing);
        expect(find.text(switchName), findsOneWidget);
      }

      if (challengerPhone != null && ownerNationalId != null) {
        await container.read(patientSessionProvider.notifier).signOut();
        await auth.login(
          phone: challengerPhone,
          password: password,
          platform: 'android',
          deviceLabel: 'patient-e2e-c',
        );
        await container.read(patientSessionProvider.notifier).onAuthenticated();
        expect(
          container.read(patientSessionProvider),
          isA<PatientRouteOnboarding>(),
        );
        final outcome = await patients.onboard(
          nationalId: ownerNationalId,
          fullName: 'Challenger',
          gender: 'male',
        );
        expect(outcome.status, PatientOnboardingStatus.manualReviewRequired);
        await container
            .read(patientSessionProvider.notifier)
            .applyOnboarding(outcome);
        expect(
          container.read(patientSessionProvider),
          isA<PatientRouteManualReview>(),
        );
        await tester.pumpWidget(
          UncontrolledProviderScope(
            container: container,
            child: materialHost(const ReviewHoldingScreen()),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.byKey(const Key('manual-review-body')), findsOneWidget);
        expect(find.textContaining('already exists'), findsNothing);
        expect(find.textContaining('National ID'), findsNothing);
        expect(find.textContaining('unlinked'), findsNothing);
      }
    },
    skip: !enabled,
    timeout: const Timeout(Duration(minutes: 3)),
  );
}
