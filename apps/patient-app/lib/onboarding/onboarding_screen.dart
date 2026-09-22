import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../shell/patient_chrome.dart';
import 'demographic_validation.dart';
import 'onboarding_controller.dart';

class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final _nationalId = TextEditingController();
  final _fullName = TextEditingController();
  final _dateOfBirth = TextEditingController();
  final _height = TextEditingController();
  final _weight = TextEditingController();
  String? _identityError;
  String? _demographicsError;
  String? _measurementsError;

  @override
  void initState() {
    super.initState();
    final draft = ref.read(onboardingDraftProvider);
    _fullName.text = draft.fullName;
    _dateOfBirth.text = draft.dateOfBirth;
    _height.text = draft.heightCm;
    _weight.text = draft.weightKg;
  }

  @override
  void dispose() {
    _nationalId.clear();
    _nationalId.dispose();
    _fullName.dispose();
    _dateOfBirth.dispose();
    _height.dispose();
    _weight.dispose();
    super.dispose();
  }

  ClinicValidationCopy _copy(ClinicStrings strings) {
    return ClinicValidationCopy(
      required: strings.validationRequired,
      nationalId: strings.validationNationalId,
      fullName: strings.validationFullName,
      date: strings.validationDate,
      bounds: strings.validationHeight,
      enumeration: strings.validationEnum,
    );
  }

  String? _messageFor(String? code, ClinicStrings strings, {String? bounds}) {
    if (code == 'bounds' || code == 'number') {
      return bounds ?? strings.validationHeight;
    }
    return localizeValidation(code, _copy(strings)).isEmpty
        ? null
        : localizeValidation(code, _copy(strings));
  }

  void _syncDraft() {
    ref
        .read(onboardingDraftProvider.notifier)
        .update(
          (current) => current.copyWith(
            fullName: _fullName.text,
            dateOfBirth: _dateOfBirth.text,
            heightCm: _height.text,
            weightKg: _weight.text,
          ),
        );
  }

  void _go(OnboardingStep step) {
    _syncDraft();
    ref.read(onboardingDraftProvider.notifier).goTo(step);
  }

  Future<void> _continueIdentity(ClinicStrings strings) async {
    final nid = DemographicValidation.nationalId(_nationalId.text);
    final name = DemographicValidation.fullName(_fullName.text);
    if (nid != null || name != null) {
      setState(() {
        _identityError = _messageFor(nid ?? name, strings);
      });
      return;
    }
    setState(() => _identityError = null);
    _go(OnboardingStep.demographics);
  }

  void _continueDemographics(ClinicStrings strings) {
    final draft = ref.read(onboardingDraftProvider);
    final gender = DemographicValidation.gender(draft.gender);
    final dob = DemographicValidation.dateOfBirth(
      _dateOfBirth.text,
      nowUtc: DateTime.now().toUtc(),
    );
    final marital = DemographicValidation.maritalStatus(draft.maritalStatus);
    if (gender != null || dob != null || marital != null) {
      setState(() {
        _demographicsError = _messageFor(gender ?? dob ?? marital, strings);
      });
      return;
    }
    setState(() => _demographicsError = null);
    _go(OnboardingStep.measurements);
  }

  void _continueMeasurements(ClinicStrings strings) {
    final height = DemographicValidation.heightCm(_height.text);
    final weight = DemographicValidation.weightKg(_weight.text);
    final blood = DemographicValidation.bloodType(
      ref.read(onboardingDraftProvider).bloodType,
    );
    if (height != null || weight != null || blood != null) {
      setState(() {
        if (height != null) {
          _measurementsError = strings.validationHeight;
        } else if (weight != null) {
          _measurementsError = strings.validationWeight;
        } else {
          _measurementsError = strings.validationEnum;
        }
      });
      return;
    }
    setState(() => _measurementsError = null);
    _go(OnboardingStep.review);
  }

  Future<void> _submit() async {
    _syncDraft();
    final status = await ref
        .read(onboardingSubmitProvider.notifier)
        .submit(nationalId: _nationalId.text);
    if (status != null) {
      _nationalId.clear();
      ref.read(onboardingDraftProvider.notifier).reset();
    } else {
      final failure = ref.read(onboardingSubmitProvider);
      // Session loss: do not keep identity input around.
      // Network retries keep the in-memory controller so the same intent can
      // be resubmitted with the same Idempotency-Key.
      if (failure.field == null && failure.error != null) {
        // Keep National ID for retry of the same payload.
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);
    final draft = ref.watch(onboardingDraftProvider);
    final submit = ref.watch(onboardingSubmitProvider);
    final theme = Theme.of(context);

    final footer = switch (draft.step) {
      OnboardingStep.identity => FilledButton(
        key: const Key('onboarding-continue'),
        onPressed: submit.busy ? null : () => _continueIdentity(strings),
        child: Text(strings.continueAction),
      ),
      OnboardingStep.demographics => Row(
        children: [
          Expanded(
            child: OutlinedButton(
              key: const Key('onboarding-back'),
              onPressed: submit.busy
                  ? null
                  : () => _go(OnboardingStep.identity),
              child: Text(strings.backAction),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: FilledButton(
              key: const Key('onboarding-continue'),
              onPressed: submit.busy
                  ? null
                  : () => _continueDemographics(strings),
              child: Text(strings.continueAction),
            ),
          ),
        ],
      ),
      OnboardingStep.measurements => Row(
        children: [
          Expanded(
            child: OutlinedButton(
              key: const Key('onboarding-back'),
              onPressed: submit.busy
                  ? null
                  : () => _go(OnboardingStep.demographics),
              child: Text(strings.backAction),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: FilledButton(
              key: const Key('onboarding-continue'),
              onPressed: submit.busy
                  ? null
                  : () => _continueMeasurements(strings),
              child: Text(strings.continueAction),
            ),
          ),
        ],
      ),
      OnboardingStep.review => Row(
        children: [
          Expanded(
            child: OutlinedButton(
              key: const Key('onboarding-back'),
              onPressed: submit.busy
                  ? null
                  : () => _go(OnboardingStep.measurements),
              child: Text(strings.backAction),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: FilledButton(
              key: const Key('onboarding-submit'),
              onPressed: submit.busy ? null : _submit,
              child: submit.busy
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(strings.submitAction),
            ),
          ),
        ],
      ),
    };

    return PatientChrome(
      title: strings.onboardingTitle,
      showSignOut: true,
      footer: footer,
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            strings.formatStep(
              draft.step.index + 1,
              OnboardingStep.values.length,
            ),
            key: const Key('onboarding-step-label'),
            style: theme.textTheme.labelLarge,
          ),
          const SizedBox(height: 8),
          LinearProgressIndicator(
            value: (draft.step.index + 1) / OnboardingStep.values.length,
          ),
          const SizedBox(height: 16),
          ...switch (draft.step) {
            OnboardingStep.identity => _identityFields(strings, theme),
            OnboardingStep.demographics => _demographicsFields(strings, draft),
            OnboardingStep.measurements => _measurementFields(strings, draft),
            OnboardingStep.review => _reviewFields(strings, draft),
          },
          if (submit.error != null) ...[
            const SizedBox(height: 12),
            Text(
              submit.field == 'national_id'
                  ? strings.validationNationalId
                  : (submit.error == 'failed'
                        ? strings.onboardingFailed
                        : submit.error!),
              key: const Key('onboarding-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
            if (submit.requestId != null)
              Text('${strings.requestId}: ${submit.requestId}'),
          ],
        ],
      ),
    );
  }

  List<Widget> _identityFields(ClinicStrings strings, ThemeData theme) {
    return [
      Text(strings.onboardingIdentityHint),
      const SizedBox(height: 16),
      TextField(
        key: const Key('onboarding-full-name'),
        controller: _fullName,
        textInputAction: TextInputAction.next,
        autofillHints: const [AutofillHints.name],
        decoration: InputDecoration(labelText: strings.fieldFullName),
      ),
      const SizedBox(height: 12),
      TextField(
        key: const Key('onboarding-national-id'),
        controller: _nationalId,
        keyboardType: TextInputType.number,
        enableSuggestions: false,
        autocorrect: false,
        decoration: InputDecoration(labelText: strings.nationalId),
      ),
      if (_identityError != null) ...[
        const SizedBox(height: 8),
        Text(
          _identityError!,
          key: const Key('onboarding-identity-error'),
          style: TextStyle(color: theme.colorScheme.error),
        ),
      ],
    ];
  }

  List<Widget> _demographicsFields(
    ClinicStrings strings,
    OnboardingDraft draft,
  ) {
    return [
      Text(strings.onboardingDemographicsHint),
      const SizedBox(height: 16),
      DropdownButtonFormField<String>(
        key: const Key('onboarding-gender'),
        // ignore: deprecated_member_use
        value: draft.gender.isEmpty ? null : draft.gender,
        decoration: InputDecoration(labelText: strings.fieldGender),
        items: [
          DropdownMenuItem(value: 'male', child: Text(strings.genderMale)),
          DropdownMenuItem(value: 'female', child: Text(strings.genderFemale)),
        ],
        onChanged: (value) {
          ref
              .read(onboardingDraftProvider.notifier)
              .update((current) => current.copyWith(gender: value ?? ''));
        },
      ),
      const SizedBox(height: 12),
      TextField(
        key: const Key('onboarding-dob'),
        controller: _dateOfBirth,
        keyboardType: TextInputType.datetime,
        decoration: InputDecoration(
          labelText: strings.fieldDateOfBirth,
          hintText: 'YYYY-MM-DD',
          helperText: strings.optionalField,
        ),
      ),
      const SizedBox(height: 12),
      DropdownButtonFormField<String>(
        key: const Key('onboarding-marital'),
        // ignore: deprecated_member_use
        value: draft.maritalStatus.isEmpty ? null : draft.maritalStatus,
        decoration: InputDecoration(
          labelText: strings.fieldMaritalStatus,
          helperText: strings.optionalField,
        ),
        items: [
          DropdownMenuItem(value: 'single', child: Text(strings.maritalSingle)),
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
          ref
              .read(onboardingDraftProvider.notifier)
              .update(
                (current) => current.copyWith(maritalStatus: value ?? ''),
              );
        },
      ),
      if (_demographicsError != null) ...[
        const SizedBox(height: 8),
        Text(
          _demographicsError!,
          key: const Key('onboarding-demographics-error'),
          style: TextStyle(color: Theme.of(context).colorScheme.error),
        ),
      ],
    ];
  }

  List<Widget> _measurementFields(
    ClinicStrings strings,
    OnboardingDraft draft,
  ) {
    return [
      Text(strings.onboardingMeasurementsHint),
      const SizedBox(height: 8),
      Text(strings.selfReportedHint, key: const Key('self-reported-hint')),
      const SizedBox(height: 8),
      Text(strings.boundsHint, key: const Key('bounds-hint')),
      const SizedBox(height: 16),
      TextField(
        key: const Key('onboarding-height'),
        controller: _height,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        decoration: InputDecoration(
          labelText: '${strings.fieldHeight} (${strings.selfReported})',
          helperText: strings.optionalField,
        ),
      ),
      const SizedBox(height: 12),
      TextField(
        key: const Key('onboarding-weight'),
        controller: _weight,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        decoration: InputDecoration(
          labelText: '${strings.fieldWeight} (${strings.selfReported})',
          helperText: strings.optionalField,
        ),
      ),
      const SizedBox(height: 12),
      DropdownButtonFormField<String>(
        key: const Key('onboarding-blood-type'),
        // ignore: deprecated_member_use
        value: draft.bloodType.isEmpty ? null : draft.bloodType,
        decoration: InputDecoration(
          labelText: '${strings.fieldBloodType} (${strings.selfReported})',
          helperText: strings.bloodTypeSelfReportedHint,
        ),
        items: [
          for (final type in PatientBloodType.supported)
            DropdownMenuItem(value: type.wire, child: Text(type.wire)),
        ],
        onChanged: (value) {
          ref
              .read(onboardingDraftProvider.notifier)
              .update((current) => current.copyWith(bloodType: value ?? ''));
        },
      ),
      if (_measurementsError != null) ...[
        const SizedBox(height: 8),
        Text(
          _measurementsError!,
          key: const Key('onboarding-measurements-error'),
          style: TextStyle(color: Theme.of(context).colorScheme.error),
        ),
      ],
    ];
  }

  List<Widget> _reviewFields(ClinicStrings strings, OnboardingDraft draft) {
    return [
      Text(strings.onboardingReviewHint, key: const Key('onboarding-review')),
      const SizedBox(height: 16),
      _ReviewRow(label: strings.fieldFullName, value: draft.fullName),
      _ReviewRow(
        label: strings.fieldGender,
        value: strings.genderLabel(draft.gender),
      ),
      _ReviewRow(
        label: strings.fieldDateOfBirth,
        value: draft.dateOfBirth.isEmpty
            ? strings.notProvided
            : draft.dateOfBirth,
      ),
      _ReviewRow(
        label: strings.fieldMaritalStatus,
        value: strings.maritalLabel(draft.maritalStatus),
      ),
      _ReviewRow(
        label: '${strings.fieldHeight} (${strings.selfReported})',
        value: draft.heightCm.isEmpty ? strings.notProvided : draft.heightCm,
      ),
      _ReviewRow(
        label: '${strings.fieldWeight} (${strings.selfReported})',
        value: draft.weightKg.isEmpty ? strings.notProvided : draft.weightKg,
      ),
      _ReviewRow(
        label: '${strings.fieldBloodType} (${strings.selfReported})',
        value: draft.bloodType.isEmpty ? strings.notProvided : draft.bloodType,
      ),
    ];
  }
}

class _ReviewRow extends StatelessWidget {
  const _ReviewRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: Theme.of(context).textTheme.labelMedium),
          Text(value, style: Theme.of(context).textTheme.bodyLarge),
        ],
      ),
    );
  }
}
