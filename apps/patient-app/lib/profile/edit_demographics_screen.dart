import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../onboarding/onboarding_draft.dart';
import '../providers.dart';
import '../session/patient_session.dart';
import '../widgets/patient_chrome.dart';

class EditDemographicsScreen extends ConsumerStatefulWidget {
  const EditDemographicsScreen({super.key, required this.profile});

  final PatientProfile profile;

  @override
  ConsumerState<EditDemographicsScreen> createState() =>
      _EditDemographicsScreenState();
}

class _EditDemographicsScreenState
    extends ConsumerState<EditDemographicsScreen> {
  late PatientProfile _profile;
  late final TextEditingController _fullName;
  late final TextEditingController _height;
  late final TextEditingController _weight;
  String? _gender;
  DateTime? _dateOfBirth;
  String? _maritalStatus;
  String? _bloodType;
  FieldErrors _errors = const FieldErrors({});
  ApiFailure? _failure;
  bool _conflict = false;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _profile = widget.profile;
    _fullName = TextEditingController(text: _profile.fullName);
    _height = TextEditingController(text: _profile.heightCm ?? '');
    _weight = TextEditingController(text: _profile.weightKg ?? '');
    _gender = _profile.gender;
    _dateOfBirth = _parseDate(_profile.dateOfBirth);
    _maritalStatus = _profile.maritalStatus;
    _bloodType = _profile.bloodType;
  }

  @override
  void dispose() {
    _fullName.dispose();
    _height.dispose();
    _weight.dispose();
    super.dispose();
  }

  DateTime? _parseDate(String? raw) {
    if (raw == null || raw.isEmpty) {
      return null;
    }
    return DateTime.tryParse(raw);
  }

  Future<void> _refreshAuthoritative() async {
    setState(() => _busy = true);
    try {
      final latest = await ref.read(patientApiProvider).getOwnProfile();
      await ref.read(patientSessionProvider.notifier).replaceProfile(latest);
      if (!mounted) {
        return;
      }
      setState(() {
        _profile = latest;
        _fullName.text = latest.fullName;
        _height.text = latest.heightCm ?? '';
        _weight.text = latest.weightKg ?? '';
        _gender = latest.gender;
        _dateOfBirth = _parseDate(latest.dateOfBirth);
        _maritalStatus = latest.maritalStatus;
        _bloodType = latest.bloodType;
        _conflict = false;
        _failure = null;
        _busy = false;
      });
    } on ApiFailure catch (failure) {
      if (!mounted) {
        return;
      }
      setState(() {
        _failure = failure;
        _busy = false;
      });
    }
  }

  Future<void> _save() async {
    final strings = ClinicStrings.of(context);
    final errors = validateDemographicPatch(
      fullName: _fullName.text,
      gender: _gender,
      dateOfBirth: _dateOfBirth,
      heightCm: _height.text,
      weightKg: _weight.text,
      maritalStatus: _maritalStatus,
      bloodType: _bloodType,
      strings: strings,
    );
    if (!errors.isEmpty) {
      setState(() => _errors = errors);
      return;
    }
    setState(() {
      _busy = true;
      _failure = null;
      _errors = const FieldErrors({});
    });
    try {
      final updated = await ref
          .read(patientApiProvider)
          .updateDemographics(
            version: _profile.version,
            fullName: _fullName.text.trim(),
            gender: _gender,
            dateOfBirth: _dateOfBirth == null ? null : isoDate(_dateOfBirth!),
            heightCm: parseOptionalNumber(_height.text),
            weightKg: parseOptionalNumber(_weight.text),
            maritalStatus: _maritalStatus,
            bloodType: _bloodType,
          );
      await ref.read(patientSessionProvider.notifier).replaceProfile(updated);
      if (mounted) {
        Navigator.of(context).pop();
      }
    } on ApiFailure catch (failure) {
      if (!mounted) {
        return;
      }
      setState(() {
        _busy = false;
        _failure = failure;
        _conflict = failure.code == ApiErrorCode.versionConflict;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = ClinicStrings.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(strings.editDemographicsTitle),
        actions: const [LanguageMenuButton()],
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (_conflict)
                Card(
                  key: const Key('version-conflict'),
                  color: Theme.of(context).colorScheme.errorContainer,
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          strings.versionConflictTitle,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: 8),
                        Text(strings.versionConflictBody),
                      ],
                    ),
                  ),
                ),
              TextField(
                key: const Key('edit-full-name'),
                controller: _fullName,
                enabled: !_conflict,
                decoration: InputDecoration(
                  labelText: strings.fullName,
                  errorText: _errors['full_name'],
                ),
              ),
              const SizedBox(height: 12),
              SegmentedButton<String>(
                segments: [
                  ButtonSegment(
                    value: 'female',
                    label: Text(strings.genderFemale),
                  ),
                  ButtonSegment(value: 'male', label: Text(strings.genderMale)),
                ],
                selected: {?_gender},
                onSelectionChanged: _conflict
                    ? null
                    : (value) => setState(() => _gender = value.first),
              ),
              FieldErrorText(_errors['gender']),
              ListTile(
                contentPadding: EdgeInsets.zero,
                enabled: !_conflict,
                title: Text(strings.dateOfBirth),
                subtitle: Text(
                  _dateOfBirth == null
                      ? strings.optionalField
                      : isoDate(_dateOfBirth!),
                ),
                onTap: _conflict
                    ? null
                    : () async {
                        final now = DateTime.now();
                        final picked = await showDatePicker(
                          context: context,
                          initialDate: _dateOfBirth ?? DateTime(1990, 1, 15),
                          firstDate: DateTime(1850, 1, 1),
                          lastDate: DateTime(now.year, now.month, now.day),
                        );
                        if (picked != null) {
                          setState(() => _dateOfBirth = picked);
                        }
                      },
              ),
              DropdownButtonFormField<String>(
                initialValue: _maritalStatus,
                decoration: InputDecoration(labelText: strings.maritalStatus),
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
                onChanged: _conflict
                    ? null
                    : (value) => setState(() => _maritalStatus = value),
              ),
              const SizedBox(height: 12),
              Text(strings.selfReportedHint),
              const SizedBox(height: 8),
              TextField(
                key: const Key('edit-height'),
                controller: _height,
                enabled: !_conflict,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: InputDecoration(
                  labelText:
                      '${strings.heightCm} (${strings.selfReportedLabel})',
                  errorText: _errors['height_cm'],
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('edit-weight'),
                controller: _weight,
                enabled: !_conflict,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: InputDecoration(
                  labelText:
                      '${strings.weightKg} (${strings.selfReportedLabel})',
                  errorText: _errors['weight_kg'],
                ),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                initialValue: _bloodType,
                decoration: InputDecoration(
                  labelText:
                      '${strings.bloodType} (${strings.selfReportedLabel})',
                ),
                items: const ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
                    .map((v) => DropdownMenuItem(value: v, child: Text(v)))
                    .toList(),
                onChanged: _conflict
                    ? null
                    : (value) => setState(() => _bloodType = value),
              ),
              if (_failure != null && !_conflict) ...[
                const SizedBox(height: 12),
                Text(
                  _failure!.message,
                  key: const Key('edit-error'),
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
              const SizedBox(height: 88),
            ],
          ),
        ),
      ),
      bottomNavigationBar: PrimaryBottomBar(
        primaryLabel: _conflict ? strings.refreshAction : strings.saveAction,
        onPrimary: _busy
            ? null
            : () async {
                if (_conflict) {
                  await _refreshAuthoritative();
                  return;
                }
                await _save();
              },
        secondaryLabel: strings.cancelAction,
        onSecondary: _busy ? null : () => Navigator.of(context).pop(),
        busy: _busy,
      ),
    );
  }
}
