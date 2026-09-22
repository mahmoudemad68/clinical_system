import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:test/test.dart';

import 'http_fakes.dart';
import 'synthetic_national_id.dart';

PatientOnboardingRequest sampleRequest({
  String nationalId = kSyntheticNationalId,
}) {
  return PatientOnboardingRequest(
    nationalId: nationalId,
    fullName: 'Synthetic Patient',
    gender: 'female',
    dateOfBirth: '1990-01-15',
    heightCm: 165.5,
    weightKg: 62.3,
    maritalStatus: 'single',
    bloodType: 'A+',
  );
}

Map<String, dynamic> profileWire({
  int version = 1,
  String name = 'Synthetic Patient',
}) {
  return {
    'patient_id': '0199a5c8-aaaa-7c3a-9b41-2f6d0c5e7c01',
    'full_name': name,
    'gender': 'female',
    'date_of_birth': '1990-01-15',
    'height_cm': '165.50',
    'weight_kg': '62.30',
    'marital_status': 'single',
    'blood_type': 'A+',
    'status': 'active',
    'version': version,
    'created_at': '2026-09-01T00:00:00.000000Z',
    'updated_at': '2026-09-01T00:00:00.000000Z',
  };
}

void main() {
  test(
    'onboarding serializes allowlisted fields including write-only national_id',
    () async {
      late Map<String, dynamic>? body;
      final adapter = ScriptedAdapter((options) async {
        body = requestJson(options);
        expect(options.path, OpenApiPaths.onboardPatientProfile);
        expect(options.method, 'POST');
        expect(options.headers['Idempotency-Key'], 'intent-1');
        return jsonEnvelope(201, {
          'status': 'profile_ready',
          'patient_id': '0199a5c8-aaaa-7c3a-9b41-2f6d0c5e7c01',
          'version': 1,
        });
      });
      final api = PatientApi(testClient(adapter));
      final result = await api.onboard(
        request: sampleRequest(),
        idempotencyKey: 'intent-1',
      );
      expect(result.status, PatientOnboardingStatus.profileReady);
      expect(result.patientId, isNotNull);
      expect(body, isNotNull);
      expect(body!['national_id'], kSyntheticNationalId);
      expect(body!['full_name'], 'Synthetic Patient');
      expect(body!['gender'], 'female');
      expect(body!.containsKey('user_id'), isFalse);
      expect(body!.containsKey('status'), isFalse);
      expect(body!.containsKey('version'), isFalse);
    },
  );

  test('onboarding result is compact and does not echo national_id', () async {
    final adapter = ScriptedAdapter((_) async {
      return jsonEnvelope(201, {
        'status': 'profile_ready',
        'patient_id': '0199a5c8-aaaa-7c3a-9b41-2f6d0c5e7c01',
        'version': 1,
        'national_id': kSyntheticNationalId,
        'full_name': 'should-not-surface',
      });
    });
    final result = await PatientApi(testClient(adapter))
        .onboard(request: sampleRequest(), idempotencyKey: 'intent-1');
    expect(result.toString(), isNot(contains(kSyntheticNationalId)));
    expect(result.toString(), isNot(contains('should-not-surface')));
    expect(result.status, PatientOnboardingStatus.profileReady);
  });

  test('manual_review_required is generic and omits patient_id', () async {
    final adapter = ScriptedAdapter((_) async {
      return jsonEnvelope(200, {'status': 'manual_review_required'});
    });
    final result = await PatientApi(testClient(adapter))
        .onboard(request: sampleRequest(), idempotencyKey: 'intent-1');
    expect(result.status, PatientOnboardingStatus.manualReviewRequired);
    expect(result.patientId, isNull);
    expect(result.version, isNull);
  });

  test(
    'own profile decode ignores extra national_id and ciphertext fields',
    () async {
      final adapter = ScriptedAdapter((options) async {
        expect(options.path, OpenApiPaths.getOwnPatientProfile);
        expect(options.path, isNot(contains('0199')));
        return jsonEnvelope(200, {
          ...profileWire(),
          'national_id': kSyntheticNationalId,
          'national_id_ciphertext': 'cipher',
          'national_id_lookup_hmac': 'hmac',
          'user_id': 'should-ignore',
        });
      });
      final lookup = await PatientApi(testClient(adapter)).getOwnProfile();
      expect(lookup, isA<OwnProfileFound>());
      final profile = (lookup as OwnProfileFound).profile;
      expect(profile.fullName, 'Synthetic Patient');
      expect(profile.toString(), isNot(contains(kSyntheticNationalId)));
      expect(profile.toString(), isNot(contains('cipher')));
    },
  );

  test('own profile 404 maps to absent rather than a by-id miss', () async {
    final adapter = ScriptedAdapter((_) async {
      return jsonEnvelope(
        404,
        null,
        errors: [
          {'code': 'NOT_FOUND', 'message': 'Not found.'},
        ],
      );
    });
    final lookup = await PatientApi(testClient(adapter)).getOwnProfile();
    expect(lookup, isA<OwnProfileAbsent>());
  });

  test(
    'demographics PATCH sends version and allowlisted fields only',
    () async {
      late Map<String, dynamic>? body;
      final adapter = ScriptedAdapter((options) async {
        body = requestJson(options);
        expect(options.path, OpenApiPaths.updateOwnPatientDemographics);
        expect(options.method, 'PATCH');
        return jsonEnvelope(200, profileWire(version: 2));
      });
      await PatientApi(testClient(adapter)).updateDemographics(
        const PatientDemographicsPatch(
          version: 1,
          heightCm: 170,
          maritalStatus: 'married',
        ),
      );
      expect(body!['version'], 1);
      expect(body!['height_cm'], 170);
      expect(body!['marital_status'], 'married');
      expect(body!.containsKey('national_id'), isFalse);
      expect(body!.containsKey('patient_id'), isFalse);
      expect(body!.containsKey('status'), isFalse);
      expect(body!.containsKey('user_id'), isFalse);
    },
  );

  test('VERSION_CONFLICT maps to ApiErrorCode.versionConflict', () async {
    final adapter = ScriptedAdapter((_) async {
      return jsonEnvelope(
        409,
        null,
        errors: [
          {'code': 'VERSION_CONFLICT', 'message': 'The resource was updated.'},
        ],
      );
    });
    try {
      await PatientApi(testClient(adapter)).updateDemographics(
        const PatientDemographicsPatch(version: 1, weightKg: 70),
      );
      fail('expected ApiFailure');
    } on ApiFailure catch (failure) {
      expect(failure.code, ApiErrorCode.versionConflict);
      expect(failure.statusCode, 409);
      expect(failure.message, isNot(contains('SQL')));
    }
  });

  test(
    'unknown extra response fields do not crash own-profile decode',
    () async {
      final adapter = ScriptedAdapter((_) async {
        return jsonEnvelope(200, {
          ...profileWire(),
          'unexpected_future_field': {'nested': true},
        });
      });
      final lookup = await PatientApi(testClient(adapter)).getOwnProfile();
      expect((lookup as OwnProfileFound).profile.version, 1);
    },
  );
}
