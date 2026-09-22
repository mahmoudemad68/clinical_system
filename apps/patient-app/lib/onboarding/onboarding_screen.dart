import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../onboarding/onboarding_controller.dart';
import '../onboarding/onboarding_draft.dart';
import '../session/patient_session.dart';
import '../widgets/patient_chrome.dart';

class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final _nationalId = TextEditingController();
  final _fullName = TextEditingController();
  final _height = TextEditingController();
  final _weight = TextEditingController();

  @override
  void initState() {
    super.initState();
    final draft = ref.read(onboardingProvider).draft;
    _nationalId.text = draft.nationalId;
    _fullName.text = draft.fullName;
    _height.text = draft.heightCm;
    _weight.text = draft.weightKg;
    _nationalId.addListener(_syncIdentity);
    _fullName.addListener(_syncIdentity);
    _height.addListener(_syncMeasurements);
    _weight.addListener(_syncMeasurements);
  }

  void _syncIdentity() {
    ref.read(onboardingProvider.notifier).update((draft) {
      draft.nationalId = _nationalId.text;
      draft.fullName = _fullName.text;
    });
  }

  void _syncMeasurements() {
    ref.read(onboardingProvider.notifier).update((draft) {
      draft.heightCm = _height.text;
      draft.weightKg = _weight.text;
    });
  }

  @override
  void dispose() {
    _nationalId
      ..removeListener(_syncIdentity)
      ..clear()
      ..dispose();
    _fullName.dispose();
    _height.dispose();
    _weight.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final strings = ClinicStrings.of(context);
    final result = await ref.read(onboardingProvider.notifier).submit(strings);
    _nationalId.clear();
    if (!mounted) {
      return;
    }
    if (result != null) {
      await ref.read(patientSessionProvider.notifier).applyOnboarding(result);
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);
    final onboarding = ref.watch(onboardingProvider);
    final draft = onboarding.draft;
    final step = draft.step;

    return Scaffold(
      appBar: AppBar(
        title: Text(strings.onboardingTitle),
        actions: [
          const LanguageMenuButton(),
          SignOutButton(
            onPressed: () {
              _nationalId.clear();
              ref.read(patientSessionProvider.notifier).signOut();
            },
            enabled: !onboarding.busy,
          ),
        ],
      ),
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) {
            return SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: ConstrainedBox(
                constraints: BoxConstraints(
                  minHeight: constraints.maxHeight - 32,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      '${step + 1}/4 · ${_stepTitle(strings, step)}',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 8),
                    LinearProgressIndicator(value: (step + 1) / 4),
                    const SizedBox(height: 16),
                    if (step == 0) _identity(strings, onboarding.errors),
                    if (step == 1)
                      _demographics(strings, draft, onboarding.errors),
                    if (step == 2)
                      _measurements(strings, draft, onboarding.errors),
                    if (step == 3) _review(strings, draft),
                    if (onboarding.failure != null) ...[
                      const SizedBox(height: 12),
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          onboarding.failure!.code ==
                                  ApiErrorCode.networkUnavailable
                              ? strings.offlineCannotSubmit
                              : onboarding.failure!.message,
                          key: const Key('onboarding-error'),
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.error,
                          ),
                        ),
                      ),
                    ],
                    const SizedBox(height: 88),
                  ],
                ),
              ),
            );
          },
        ),
      ),
      bottomNavigationBar: PrimaryBottomBar(
        primaryLabel: step == 3
            ? strings.onboardingSubmit
            : strings.continueAction,
        onPrimary: onboarding.busy
            ? null
            : () async {
                if (step == 3) {
                  await _submit();
                  return;
                }
                ref
                    .read(onboardingProvider.notifier)
                    .continueFrom(step, strings);
              },
        secondaryLabel: step == 0 ? null : strings.backAction,
        onSecondary: onboarding.busy
            ? null
            : () => ref.read(onboardingProvider.notifier).back(),
        busy: onboarding.busy,
      ),
    );
  }

  String _stepTitle(ClinicStrings strings, int step) {
    return switch (step) {
      0 => strings.onboardingStepIdentity,
      1 => strings.onboardingStepDemographics,
      2 => strings.onboardingStepMeasurements,
      _ => strings.onboardingStepReview,
    };
  }

  Widget _identity(ClinicStrings strings, FieldErrors errors) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(strings.onboardingIntro),
        const SizedBox(height: 16),
        TextField(
          key: const Key('onboarding-national-id'),
          controller: _nationalId,
          obscureText: true,
          enableSuggestions: false,
          autocorrect: false,
          autofillHints: const <String>[],
          keyboardType: TextInputType.number,
          scrollPadding: const EdgeInsets.only(bottom: 120),
          decoration: InputDecoration(
            labelText: strings.nationalId,
            errorText: errors['national_id'],
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('onboarding-full-name'),
          controller: _fullName,
          textInputAction: TextInputAction.next,
          decoration: InputDecoration(
            labelText: strings.fullName,
            errorText: errors['full_name'],
          ),
        ),
      ],
    );
  }

  Widget _demographics(
    ClinicStrings strings,
    OnboardingDraft draft,
    FieldErrors errors,
  ) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(strings.onboardingStepDemographics),
        const SizedBox(height: 12),
        Text(strings.gender, style: Theme.of(context).textTheme.labelLarge),
        const SizedBox(height: 8),
        SegmentedButton<String>(
          key: const Key('onboarding-gender'),
          segments: [
            ButtonSegment(value: 'female', label: Text(strings.genderFemale)),
            ButtonSegment(value: 'male', label: Text(strings.genderMale)),
          ],
          emptySelectionAllowed: true,
          showSelectedIcon: false,
          selected: {?draft.gender},
          onSelectionChanged: (value) {
            ref.read(onboardingProvider.notifier).update((d) {
              d.gender = value.isEmpty ? null : value.first;
            });
          },
        ),
        FieldErrorText(errors['gender']),
        const SizedBox(height: 16),
        ListTile(
          key: const Key('onboarding-dob'),
          contentPadding: EdgeInsets.zero,
          title: Text(strings.dateOfBirth),
          subtitle: Text(
            draft.dateOfBirth == null
                ? strings.optionalField
                : isoDate(draft.dateOfBirth!),
          ),
          trailing: const Icon(Icons.event),
          onTap: () async {
            final now = DateTime.now();
            final picked = await showDatePicker(
              context: context,
              initialDate: draft.dateOfBirth ?? DateTime(1990, 1, 15),
              firstDate: DateTime(1850, 1, 1),
              lastDate: DateTime(now.year, now.month, now.day),
            );
            if (picked != null) {
              ref.read(onboardingProvider.notifier).update((d) {
                d.dateOfBirth = picked;
              });
            }
          },
        ),
        FieldErrorText(errors['date_of_birth']),
        DropdownButtonFormField<String>(
          key: const Key('onboarding-marital'),
          initialValue: draft.maritalStatus,
          decoration: InputDecoration(
            labelText: '${strings.maritalStatus} (${strings.optionalField})',
            errorText: errors['marital_status'],
          ),
          items: [
            DropdownMenuItem(
              value: 'single',
              child: Text(strings.maritalSingle),
            ),
            DropdownMenuItem(
              value: 'married',
              child: Text(strings.maritalMarried),
            ),
            DropdownMenuItem(
              value: 'divorced',
              child: Text(strings.maritalDivorced),
            ),
            DropdownMenuItem(
              value: 'widowed',
              child: Text(strings.maritalWidowed),
            ),
          ],
          onChanged: (value) {
            ref.read(onboardingProvider.notifier).update((d) {
              d.maritalStatus = value;
            });
          },
        ),
      ],
    );
  }

  Widget _measurements(
    ClinicStrings strings,
    OnboardingDraft draft,
    FieldErrors errors,
  ) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(strings.selfReportedHint),
        const SizedBox(height: 8),
        Text(strings.storageBoundsHint),
        const SizedBox(height: 16),
        TextField(
          key: const Key('onboarding-height'),
          controller: _height,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: '${strings.heightCm} (${strings.selfReportedLabel})',
            errorText: errors['height_cm'],
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('onboarding-weight'),
          controller: _weight,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: '${strings.weightKg} (${strings.selfReportedLabel})',
            errorText: errors['weight_kg'],
          ),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          key: const Key('onboarding-blood-type'),
          initialValue: draft.bloodType,
          decoration: InputDecoration(
            labelText: '${strings.bloodType} (${strings.selfReportedLabel})',
            helperText: strings.selfReportedHint,
            errorText: errors['blood_type'],
          ),
          items: const [
            'A+',
            'A-',
            'B+',
            'B-',
            'AB+',
            'AB-',
            'O+',
            'O-',
          ].map((v) => DropdownMenuItem(value: v, child: Text(v))).toList(),
          onChanged: (value) {
            ref.read(onboardingProvider.notifier).update((d) {
              d.bloodType = value;
            });
          },
        ),
      ],
    );
  }

  Widget _review(ClinicStrings strings, OnboardingDraft draft) {
    return Column(
      key: const Key('onboarding-review'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          strings.reviewHeading,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 8),
        Text(strings.nationalIdNotShown),
        const SizedBox(height: 16),
        _row(strings.fullName, draft.fullName),
        _row(strings.gender, strings.genderLabel(draft.gender ?? '')),
        _row(
          strings.dateOfBirth,
          draft.dateOfBirth == null
              ? strings.notProvided
              : isoDate(draft.dateOfBirth!),
        ),
        _row(strings.maritalStatus, strings.maritalLabel(draft.maritalStatus)),
        _row(
          '${strings.heightCm} (${strings.selfReportedLabel})',
          draft.heightCm.trim().isEmpty ? strings.notProvided : draft.heightCm,
        ),
        _row(
          '${strings.weightKg} (${strings.selfReportedLabel})',
          draft.weightKg.trim().isEmpty ? strings.notProvided : draft.weightKg,
        ),
        _row(
          '${strings.bloodType} (${strings.selfReportedLabel})',
          draft.bloodType ?? strings.notProvided,
        ),
      ],
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: Text(label)),
          Expanded(child: Text(value, textAlign: TextAlign.end)),
        ],
      ),
    );
  }
}
