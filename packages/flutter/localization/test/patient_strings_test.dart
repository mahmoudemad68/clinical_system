import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('patient profile strings exist in English and Arabic', () {
    const en = ClinicStrings(Locale('en'));
    const ar = ClinicStrings(Locale('ar'));

    expect(en.onboardingTitle, isNot(ar.onboardingTitle));
    expect(en.manualReviewTitle, isNot(ar.manualReviewTitle));
    expect(en.versionConflictTitle, isNot(ar.versionConflictTitle));
    expect(en.selfReportedLabel, isNot(ar.selfReportedLabel));
    expect(en.nationalIdNotShown.toLowerCase(), isNot(contains('exists')));
    expect(ar.nationalIdNotShown, isNot(contains('National ID')));
    expect(en.manualReviewBody.toLowerCase(), isNot(contains('national id')));
    expect(
      en.manualReviewBody.toLowerCase(),
      isNot(contains('already exists')),
    );
    expect(en.storageBoundsHint.toLowerCase(), isNot(contains('recommend')));
  });
}
