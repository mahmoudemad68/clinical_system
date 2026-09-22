import 'dart:io';

import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:clinic_patient_app/onboarding/onboarding_controller.dart';
import 'package:clinic_patient_app/onboarding/onboarding_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';

const canary = '29201011234567';

void main() {
  test('national id is not written to the credential vault', () async {
    final vault = MemoryVault();
    final store = TokenStore(vault);
    await store.write(access: 'access-material', refresh: 'refresh-material');

    final adapter = ScriptedAdapter((options) async {
      expect(options.path, isNot(contains(canary)));
      expect(options.uri.toString(), isNot(contains(canary)));
      if (isPath(options, 'onboarding')) {
        return jsonEnvelope(201, {
          'status': 'profile_ready',
          'patient_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
          'version': 1,
        });
      }
      return jsonEnvelope(200, profileWire());
    });
    final container = ProviderContainer(
      overrides: patientOverrides(vault: vault, client: testClient(adapter)),
    );
    addTearDown(container.dispose);

    container.read(onboardingProvider.notifier).update((draft) {
      draft.nationalId = canary;
      draft.fullName = 'Ada';
      draft.gender = 'female';
    });

    await container
        .read(onboardingProvider.notifier)
        .submit(const ClinicStrings(Locale('en')));

    for (final value in vault.values.values) {
      expect(value, isNot(contains(canary)));
    }
    expect(container.read(onboardingProvider).draft.nationalId, isEmpty);
    expect(adapter.requests, isNotEmpty);
    expect(adapter.requests.first.path, contains('/patients/onboarding'));
    expect(adapter.requests.first.path, isNot(contains(canary)));
    expect(adapter.bodies.join(), contains('"national_id"'));
  });

  test('two collision outcomes map to the same client status', () async {
    Future<String> outcome(String reason) async {
      final vault = MemoryVault();
      final adapter = ScriptedAdapter((options) async {
        return jsonEnvelope(200, {
          'status': 'manual_review_required',
          'reason': reason,
        });
      });
      final container = ProviderContainer(
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      );
      addTearDown(container.dispose);
      container.read(onboardingProvider.notifier).update((draft) {
        draft.nationalId = canary;
        draft.fullName = 'Ada';
        draft.gender = 'female';
      });
      final result = await container
          .read(onboardingProvider.notifier)
          .submit(const ClinicStrings(Locale('en')));
      return result!.status.name;
    }

    expect(
      await outcome('national_id_owned'),
      await outcome('unlinked_profile_claim_disabled'),
    );
  });

  test('patient app does not persist profiles through local packages', () {
    final pubspec = File('pubspec.yaml').readAsStringSync();
    expect(pubspec, isNot(contains('shared_preferences')));
    expect(pubspec, isNot(contains('clinic_local_database')));
    expect(pubspec, isNot(contains('hive')));
    expect(
      File('../../packages/flutter/networking/lib/src/clinic_http_client.dart')
          .readAsStringSync(),
      isNot(contains('LogInterceptor')),
    );
    final profileModel = File(
      '../../packages/flutter/common_models/lib/src/patient_profile.dart',
    ).readAsStringSync();
    expect(profileModel, isNot(contains('national_id')));
    expect(profileModel, isNot(contains('final String nationalId')));
    expect(profileModel, isNot(contains('this.nationalId')));
    expect(profileModel, contains('nationalIdMaxLength'));
  });

  test('named routes never accept a patient id or national id parameter', () {
    final main = File('lib/main.dart').readAsStringSync();
    expect(main, contains("'/onboarding/profile'"));
    expect(main, contains("'/onboarding/review'"));
    expect(main, contains("'/profile'"));
    expect(main, isNot(contains('/patients/')));
    expect(main, isNot(contains('nationalId')));
    expect(main, isNot(contains('national_id')));
    final api = File(
      '../../packages/flutter/api_client/lib/src/patient_api.dart',
    ).readAsStringSync();
    expect(api, isNot(contains('getById')));
    expect(api, isNot(contains('/patients/{')));
  });

  testWidgets(
    'language switch keeps the in-memory draft and does not persist national id',
    (tester) async {
      final vault = MemoryVault();
      final adapter = ScriptedAdapter(
        (_) async => jsonEnvelope(200, profileWire()),
      );
      await tester.pumpWidget(
        wrap(
          const OnboardingScreen(),
          overrides: patientOverrides(
            vault: vault,
            client: testClient(adapter),
          ),
        ),
      );
      await tester.enterText(
        find.byKey(const Key('onboarding-national-id')),
        canary,
      );
      await tester.enterText(
        find.byKey(const Key('onboarding-full-name')),
        'Ada Lovelace',
      );
      await tester.tap(find.byKey(const Key('language-menu')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('العربية'));
      await tester.pumpAndSettle();
      final field = tester.widget<TextField>(
        find.byKey(const Key('onboarding-national-id')),
      );
      expect(field.obscureText, isTrue);
      expect(field.controller?.text, canary);
      expect(
        find.byWidgetPredicate(
          (widget) => widget is Text && widget.data == canary,
        ),
        findsNothing,
      );
      for (final value in vault.values.values) {
        expect(value, isNot(contains(canary)));
      }
      final context = tester.element(find.byType(OnboardingScreen));
      final container = ProviderScope.containerOf(context);
      expect(container.read(onboardingProvider).draft.nationalId, canary);
      expect(container.read(onboardingProvider).draft.fullName, 'Ada Lovelace');
      expect(Directionality.of(context), TextDirection.rtl);
    },
  );
}
