import 'dart:convert';
import 'dart:io';

import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:dio/dio.dart';
import 'package:test/test.dart';

import 'fakes.dart';

const canaryNid = '29201011234567';

Map<String, dynamic> profileData({int version = 1, String name = 'Own Name'}) {
  return {
    'patient_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
    'full_name': name,
    'gender': 'female',
    'date_of_birth': '1990-01-15',
    'height_cm': '165.50',
    'weight_kg': '62.30',
    'marital_status': 'single',
    'blood_type': 'A+',
    'status': 'active',
    'version': version,
    'created_at': '2026-09-01T12:00:00Z',
    'updated_at': '2026-09-01T12:00:00Z',
  };
}

void main() {
  test('onboarding serializes allowlisted fields only', () async {
    late RequestOptions captured;
    final adapter = ScriptedAdapter((options) async {
      captured = options;
      return jsonEnvelope(201, {
        'status': 'profile_ready',
        'patient_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
        'version': 1,
      });
    });
    final api = PatientApi(testClient(adapter));

    final result = await api.onboard(
      nationalId: canaryNid,
      fullName: 'Synthetic Patient',
      gender: 'female',
      dateOfBirth: '1990-01-15',
      heightCm: 165.5,
      weightKg: 62.3,
      maritalStatus: 'single',
      bloodType: 'A+',
    );

    expect(result.status, PatientOnboardingStatus.profileReady);
    expect(captured.path, OpenApiPaths.onboardPatientProfile);
    expect(captured.method, 'POST');
    expect(headerOf(captured, 'Idempotency-Key'), isNotEmpty);
    final body = jsonDecode(await readBody(captured)) as Map<String, dynamic>;
    expect(
      body.keys,
      unorderedEquals([
        'national_id',
        'full_name',
        'gender',
        'date_of_birth',
        'height_cm',
        'weight_kg',
        'marital_status',
        'blood_type',
      ]),
    );
    expect(body.containsKey('user_id'), isFalse);
    expect(body.containsKey('status'), isFalse);
    expect(body.containsKey('version'), isFalse);
    expect(body.containsKey('patient_id'), isFalse);
  });

  test('national id is write-only and never mapped from responses', () async {
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('onboarding')) {
        return jsonEnvelope(201, {
          'status': 'profile_ready',
          'patient_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
          'version': 1,
          'national_id': canaryNid,
        });
      }
      final data = profileData();
      data['national_id'] = canaryNid;
      data['national_id_lookup_hmac'] = 'abc';
      return jsonEnvelope(200, data);
    });
    final api = PatientApi(testClient(adapter));
    final onboarded = await api.onboard(
      nationalId: canaryNid,
      fullName: 'Synthetic Patient',
      gender: 'female',
    );
    expect(onboarded.toString(), isNot(contains(canaryNid)));

    final profile = await api.getOwnProfile();
    expect(profile.toString(), isNot(contains(canaryNid)));
    expect(profile.fullName, 'Own Name');
  });

  test(
    'reuses the idempotency key across retries of the same payload',
    () async {
      var calls = 0;
      final adapter = ScriptedAdapter((options) async {
        calls += 1;
        if (calls == 1) {
          throw DioException(
            requestOptions: options,
            type: DioExceptionType.connectionTimeout,
          );
        }
        return jsonEnvelope(201, {
          'status': 'profile_ready',
          'patient_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
          'version': 1,
        });
      });
      final client = testClient(adapter);
      final api = PatientApi(client);

      try {
        await api.onboard(
          nationalId: canaryNid,
          fullName: 'Synthetic Patient',
          gender: 'female',
        );
        fail('expected timeout');
      } catch (_) {}
      final retained = api.onboardingKeyForTest;
      expect(retained, isNotEmpty);

      await api.onboard(
        nationalId: canaryNid,
        fullName: 'Synthetic Patient',
        gender: 'female',
      );
      expect(adapter.requests, hasLength(2));
      expect(headerOf(adapter.requests[0], 'Idempotency-Key'), retained);
      expect(headerOf(adapter.requests[1], 'Idempotency-Key'), retained);
      expect(api.onboardingKeyForTest, isNull);
    },
  );

  test('mints a new key after a material payload change', () async {
    final adapter = ScriptedAdapter((options) async {
      throw DioException(
        requestOptions: options,
        type: DioExceptionType.connectionTimeout,
      );
    });
    final api = PatientApi(testClient(adapter));
    try {
      await api.onboard(
        nationalId: canaryNid,
        fullName: 'Ada',
        gender: 'female',
      );
    } catch (_) {}
    final first = api.onboardingKeyForTest;
    try {
      await api.onboard(
        nationalId: canaryNid,
        fullName: 'Grace',
        gender: 'female',
      );
    } catch (_) {}
    expect(api.onboardingKeyForTest, isNot(first));
  });

  test('generic manual_review_required ignores speculative extras', () async {
    final adapter = ScriptedAdapter((_) async {
      return jsonEnvelope(200, {
        'status': 'manual_review_required',
        'reason': 'national_id_owned_by_other',
        'match': 'unlinked_profile',
        'patient_id': 'should-not-surface',
      });
    });
    final api = PatientApi(testClient(adapter));
    final result = await api.onboard(
      nationalId: canaryNid,
      fullName: 'Synthetic Patient',
      gender: 'male',
    );
    expect(result.status, PatientOnboardingStatus.manualReviewRequired);
    expect(result.patientId, isNull);
    expect(result.version, isNull);
  });

  test('own-profile decode ignores extra protected fields', () async {
    final adapter = ScriptedAdapter((_) async {
      final data = profileData();
      data['user_id'] = 'u1';
      data['national_id_ciphertext'] = 'cipher';
      data['audit'] = {'actor': 'x'};
      return jsonEnvelope(200, data);
    });
    final profile = await PatientApi(testClient(adapter)).getOwnProfile();
    expect(profile.patientId, isNotEmpty);
    expect(profile.version, 1);
  });

  test(
    'demographics PATCH sends version and allowlisted fields only',
    () async {
      late Map<String, dynamic> body;
      final adapter = ScriptedAdapter((options) async {
        body = Map<String, dynamic>.from(options.data as Map);
        return jsonEnvelope(200, profileData(version: 2, name: 'Updated'));
      });
      final profile = await PatientApi(
        testClient(adapter),
      ).updateDemographics(version: 1, heightCm: 170, maritalStatus: 'married');
      expect(profile.version, 2);
      expect(
        body.keys,
        unorderedEquals(['version', 'height_cm', 'marital_status']),
      );
      expect(body['version'], 1);
      expect(body.containsKey('national_id'), isFalse);
      expect(body.containsKey('status'), isFalse);
      expect(body.containsKey('user_id'), isFalse);
      expect(body.containsKey('patient_id'), isFalse);
    },
  );

  test('maps HTTP 409 to VERSION_CONFLICT', () async {
    final adapter = ScriptedAdapter((_) async {
      return jsonEnvelope(
        409,
        null,
        errors: [
          {
            'code': 'VERSION_CONFLICT',
            'message': 'The resource was updated by another request.',
          },
        ],
      );
    });
    expect(
      () =>
          PatientApi(testClient(adapter))
              .updateDemographics(version: 1, weightKg: 70),
      throwsA(
        isA<ApiFailure>().having(
          (f) => f.code,
          'code',
          ApiErrorCode.versionConflict,
        ),
      ),
    );
  });

  test('unknown onboarding status fails closed', () async {
    final adapter = ScriptedAdapter((_) async {
      return jsonEnvelope(200, {'status': 'already_exists'});
    });
    expect(
      () => PatientApi(testClient(adapter)).onboard(
        nationalId: canaryNid,
        fullName: 'Synthetic Patient',
        gender: 'female',
      ),
      throwsA(isA<ApiFailure>()),
    );
  });

  test('validation failures do not echo national id', () async {
    final adapter = ScriptedAdapter((_) async {
      return jsonEnvelope(
        422,
        null,
        errors: [
          {
            'code': 'VALIDATION_FAILED',
            'message': 'Value $canaryNid is invalid',
            'field': 'national_id',
          },
        ],
      );
    });
    try {
      await PatientApi(testClient(adapter)).onboard(
        nationalId: canaryNid,
        fullName: 'Synthetic Patient',
        gender: 'female',
      );
      fail('expected failure');
    } on ApiFailure catch (failure) {
      expect(failure.message, isNot(contains(canaryNid)));
      expect(failure.toString(), isNot(contains(canaryNid)));
    }
  });

  test('retires the intent after a terminal validation outcome', () async {
    final adapter = ScriptedAdapter((_) async {
      return jsonEnvelope(
        422,
        null,
        errors: [
          {
            'code': 'VALIDATION_FAILED',
            'message': 'Invalid.',
            'field': 'gender',
          },
        ],
      );
    });
    final api = PatientApi(testClient(adapter));
    try {
      await api.onboard(
        nationalId: canaryNid,
        fullName: 'Synthetic Patient',
        gender: 'female',
      );
    } on ApiFailure catch (_) {}
    expect(api.onboardingKeyForTest, isNull);
  });

  test('there is no get-by-patient-id API in the adapter source', () {
    final source = File('lib/src/patient_api.dart').readAsStringSync();
    expect(source, contains('getOwnProfile'));
    expect(source, contains('OpenApiPaths.getOwnPatientProfile'));
    expect(source, isNot(contains('getById')));
    expect(source, isNot(contains(r'/patients/$')));
    expect(source, isNot(contains('/patients/{')));
    expect(source, isNot(contains('patientId)')));
  });

  test('getOwnProfile uses the me path, never a caller-supplied id', () async {
    late RequestOptions captured;
    final adapter = ScriptedAdapter((options) async {
      captured = options;
      return jsonEnvelope(200, profileData());
    });
    await PatientApi(testClient(adapter)).getOwnProfile();
    expect(captured.path, OpenApiPaths.getOwnPatientProfile);
    expect(captured.method, 'GET');
    expect(captured.path.contains('0199a5c8'), isFalse);
    expect(captured.queryParameters, isEmpty);
  });
}
