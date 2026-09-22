import 'dart:io';

import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:clinic_patient_app/onboarding/onboarding_controller.dart';
import 'package:clinic_patient_app/onboarding/onboarding_screen.dart';
import 'package:clinic_patient_app/onboarding/review_holding_screen.dart';
import 'package:clinic_patient_app/profile/edit_demographics_controller.dart';
import 'package:clinic_patient_app/profile/edit_demographics_screen.dart';
import 'package:clinic_patient_app/profile/profile_screen.dart';
import 'package:clinic_patient_app/providers.dart';
import 'package:clinic_patient_app/session/patient_session.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/harness.dart';

/// Widget tests stub dart:io [HttpClient] to HTTP 400. This live Core
/// journey must use a real client, and every Dio call must run inside
/// [WidgetTester.runAsync] so completions are not trapped in fake async.
class _RealNetworkHttpOverrides extends HttpOverrides {}

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
      final livePhone = phone!;
      final livePassword = password!;
      final liveNationalId = nationalId!;
      const strings = ClinicStrings(Locale('en'));
      HttpOverrides.global = _RealNetworkHttpOverrides();
      addTearDown(() {
        HttpOverrides.global = null;
      });
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

      await tester.runAsync(() async {
        await auth.login(
          phone: livePhone,
          password: livePassword,
          platform: 'android',
          deviceLabel: 'patient-e2e',
        );
        await container.read(patientSessionProvider.notifier).onAuthenticated();
      });
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
      await tester.pump();
      await tester.enterText(
        find.byKey(const Key('onboarding-national-id')),
        liveNationalId,
      );
      await tester.enterText(
        find.byKey(const Key('onboarding-full-name')),
        'E2E Patient',
      );
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pump();
      await tester.tap(find.text('Female'));
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pump();
      await tester.enterText(find.byKey(const Key('onboarding-height')), '166');
      await tester.tap(find.byKey(const Key('primary-action')));
      await tester.pump();
      expect(find.byKey(const Key('onboarding-review')), findsOneWidget);
      expect(find.text(liveNationalId), findsNothing);
      expect(find.textContaining('National ID is not shown'), findsOneWidget);

      late PatientOnboardingResult onboarded;
      await tester.runAsync(() async {
        final result = await container
            .read(onboardingProvider.notifier)
            .submit(strings);
        final failure = container.read(onboardingProvider).failure;
        expect(
          result,
          isNotNull,
          reason: failure?.code.name ?? 'onboarding returned null',
        );
        onboarded = result!;
        expect(onboarded.status, PatientOnboardingStatus.profileReady);
        await container
            .read(patientSessionProvider.notifier)
            .applyOnboarding(onboarded);
      });
      expect(container.read(onboardingProvider).draft.nationalId, isEmpty);

      final profileState = container.read(patientSessionProvider);
      expect(profileState, isA<PatientRouteProfile>());
      final profile = (profileState as PatientRouteProfile).profile;
      expect(profile.fullName, 'E2E Patient');
      expect(profile.version, 1);
      expect(profile.heightCm, isNotNull);

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: materialHost(ProfileScreen(profile: profile)),
        ),
      );
      await tester.pump();
      expect(find.text('E2E Patient'), findsOneWidget);
      expect(find.text(liveNationalId), findsNothing);

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: materialHost(EditDemographicsScreen(profile: profile)),
        ),
      );
      await tester.pump();
      await tester.pump();
      await tester.enterText(find.byKey(const Key('edit-weight')), '70');
      await tester.pump();
      await tester.runAsync(() async {
        container.read(editDemographicsProvider.notifier).setWeight('70');
        final saved = await container
            .read(editDemographicsProvider.notifier)
            .save(strings);
        expect(saved, isTrue);
      });
      final afterEdit =
          container.read(patientSessionProvider) as PatientRouteProfile;
      expect(afterEdit.profile.version, 2);
      expect(afterEdit.profile.weightKg, isNotNull);

      await tester.runAsync(() async {
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
          phone: livePhone,
          password: livePassword,
          platform: 'android',
          deviceLabel: 'stale-sibling',
        );
        final advanced = await PatientApi(otherClient).updateDemographics(
          version: afterEdit.profile.version,
          maritalStatus: 'married',
        );
        expect(advanced.version, 3);
      });

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: materialHost(
            EditDemographicsScreen(profile: afterEdit.profile),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      await tester.enterText(find.byKey(const Key('edit-height')), '171');
      await tester.pump();
      await tester.runAsync(() async {
        container.read(editDemographicsProvider.notifier).setHeight('171');
        final saved = await container
            .read(editDemographicsProvider.notifier)
            .save(strings);
        expect(saved, isFalse);
        expect(container.read(editDemographicsProvider).conflict, isTrue);
      });
      await tester.pump();
      expect(find.byKey(const Key('version-conflict')), findsOneWidget);
      expect(find.textContaining('updated elsewhere'), findsOneWidget);

      await tester.runAsync(() async {
        await container.read(editDemographicsProvider.notifier).refresh();
      });
      await tester.pump();
      expect(find.byKey(const Key('version-conflict')), findsNothing);
      final refreshed =
          container.read(patientSessionProvider) as PatientRouteProfile;
      expect(refreshed.profile.version, 3);
      expect(refreshed.profile.maritalStatus, 'married');

      if (switchPhone != null && switchName != null) {
        final liveSwitchPhone = switchPhone;
        final liveSwitchName = switchName;
        await tester.runAsync(() async {
          await container.read(patientSessionProvider.notifier).signOut();
          await auth.login(
            phone: liveSwitchPhone,
            password: livePassword,
            platform: 'android',
            deviceLabel: 'patient-e2e-b',
          );
          await container
              .read(patientSessionProvider.notifier)
              .onAuthenticated();
        });
        final switched = container.read(patientSessionProvider);
        expect(switched, isA<PatientRouteProfile>());
        expect(
          (switched as PatientRouteProfile).profile.fullName,
          liveSwitchName,
        );
        expect(switched.profile.fullName, isNot('E2E Patient'));
        await tester.pumpWidget(
          UncontrolledProviderScope(
            container: container,
            child: materialHost(ProfileScreen(profile: switched.profile)),
          ),
        );
        await tester.pump();
        expect(find.text('E2E Patient'), findsNothing);
        expect(find.text(liveSwitchName), findsOneWidget);
        expect(find.text(liveNationalId), findsNothing);
      }

      if (challengerPhone != null && ownerNationalId != null) {
        final liveChallengerPhone = challengerPhone;
        final liveOwnerNationalId = ownerNationalId;
        late PatientOnboardingResult outcome;
        await tester.runAsync(() async {
          await container.read(patientSessionProvider.notifier).signOut();
          await auth.login(
            phone: liveChallengerPhone,
            password: livePassword,
            platform: 'android',
            deviceLabel: 'patient-e2e-c',
          );
          await container
              .read(patientSessionProvider.notifier)
              .onAuthenticated();
          expect(
            container.read(patientSessionProvider),
            isA<PatientRouteOnboarding>(),
          );
          outcome = await patients.onboard(
            nationalId: liveOwnerNationalId,
            fullName: 'Challenger',
            gender: 'male',
          );
          expect(outcome.status, PatientOnboardingStatus.manualReviewRequired);
          await container
              .read(patientSessionProvider.notifier)
              .applyOnboarding(outcome);
        });
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
        await tester.pump();
        expect(find.byKey(const Key('manual-review-body')), findsOneWidget);
        expect(find.textContaining('already exists'), findsNothing);
        expect(find.textContaining('National ID'), findsNothing);
        expect(find.textContaining('unlinked'), findsNothing);
        expect(find.text(liveOwnerNationalId), findsNothing);
      }
    },
    skip: !enabled,
    timeout: const Timeout(Duration(minutes: 3)),
  );
}
