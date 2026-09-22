import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:test/test.dart';

void main() {
  test('maps a VERSION_CONFLICT envelope', () {
    final failure = ApiFailure.fromEnvelope(409, {
      'data': null,
      'errors': [
        {
          'code': 'VERSION_CONFLICT',
          'message': 'The resource was updated by another request.',
        },
      ],
      'request_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
    });

    expect(failure.code, ApiErrorCode.versionConflict);
    expect(failure.statusCode, 409);
    expect(failure.requestId, '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01');
    expect(failure.toString(), isNot(contains('updated by another')));
  });

  test('does not surface a raw body as the message', () {
    final failure = ApiFailure.fromEnvelope(500, 'SQLSTATE[23505] DETAIL: Key');
    expect(failure.message, 'The request failed.');
    expect(failure.message, isNot(contains('SQLSTATE')));
  });

  test('redacts an echoed national id from a validation message', () {
    const canary = '29201011234567';
    final failure = ApiFailure.fromEnvelope(422, {
      'errors': [
        {
          'code': 'VALIDATION_FAILED',
          'message': 'Duplicate $canary',
          'field': 'national_id',
        },
      ],
    }).redacting(canary);

    expect(failure.message, isNot(contains(canary)));
    expect(failure.message, 'The request could not be completed.');
  });
}
